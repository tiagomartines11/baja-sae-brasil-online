<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ScorePublisher;

/**
 * The fast path: what happens inside an upload request.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The server that runs this also serves the public results site, and it is
 * small. During a race six phones talk to it every five seconds for hours.
 * So the work is split in two:
 *
 * - A HEARTBEAT (a phone saying "still here, nothing new") must cost almost
 *   nothing: it reads the phone's token, updates one row, and is done. It
 *   never waits for anybody.
 * - AN UPLOAD WITH ENTRIES recounts only the cars it mentions, not the whole
 *   race, and publishes them before it answers.
 *
 * Everything that needs the whole race (review flags, lap times, best laps)
 * is done separately and a little later; see SnapshotTest.
 *
 * "The count lock" below is what makes sure two uploads never count at the
 * same time. A test can take it from a second database connection to play
 * "another request is busy right now".
 */
final class FastPathTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    /**
     * SITUATION: another request is in the middle of counting and holds the
     * count lock. A phone sends its regular heartbeat: no entries, queue
     * empty.
     *
     * EXPECTED: the heartbeat is answered at once with 200 and the phone's
     * "synced through" mark is updated. It did not wait for the lock,
     * because it has nothing to count. (If it had waited, this test would
     * hang for two seconds and then get a 503.)
     */
    public function testAHeartbeatNeverWaitsForTheCountLock(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $busyRequest = $this->holdLockFromAnotherRequest();

        $startedAt = microtime(true);
        $response = $this->upload($token, [], ['queue_empty' => true]);
        $elapsedSeconds = microtime(true) - $startedAt;
        $busyRequest = null;

        $this->assertSame(200, $response['status']);
        $this->assertLessThan(1.0, $elapsedSeconds);
        $this->assertSame(1, $this->countRows(
            'contagem_dispositivo',
            'fonte_id = ? AND sincronizado_ate_ms = ?',
            [$this->fontes[0]->getFonteId(), $this->at(600)]
        ));
    }

    /**
     * SITUATION: the same busy server, but this time the phone has an entry
     * to deliver.
     *
     * EXPECTED: after waiting two seconds for the lock the server answers
     * 503 ("busy") and stores NOTHING. The phone keeps the entry in its
     * queue and tries again a few seconds later; that costs less than
     * keeping one of the server's few workers waiting. When the lock is
     * free again the same upload goes through.
     */
    public function testAnUploadThatCannotGetTheLockAnswersBusyAndStoresNothing(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $batch = [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(60))];
        $busyRequest = $this->holdLockFromAnotherRequest();

        $refused = $this->upload($token, $batch);

        $this->assertSame(503, $refused['status']);
        $this->assertSame('ocupado', $refused['body']['erro']);
        $this->assertSame(0, $this->countRows('contagem_passagem', 'fonte_id = ?', [$this->fontes[0]->getFonteId()]));

        $busyRequest = null;
        $accepted = $this->upload($token, $batch);
        $this->assertSame(200, $accepted['status']);
        $this->assertSame(1, $this->countRows('contagem_passagem', 'fonte_id = ?', [$this->fontes[0]->getFonteId()]));
    }

    /**
     * SITUATION: cars 11 and 22 each have a pass zero and one lap, counted
     * and published. Then a new lap arrives for car 11 only.
     *
     * EXPECTED: car 11 goes to two laps. Car 22's rows are not rewritten:
     * its lap rows keep exactly the values they had (the test puts a marker
     * in one of them, and the marker is still there afterwards). An upload
     * recounts the cars it concerns and leaves the rest of the race alone.
     */
    public function testAnUploadRecountsOnlyTheCarsItMentions(): void
    {
        foreach ([self::CAR_A, self::CAR_B] as $car) {
            $this->recordCountedPass($car, $this->at(60));
            $this->recordCountedPass($car, $this->at(240));
        }
        $this->recompute();
        // A marker in a column the recount would reset if it rewrote the row.
        $this->executeSql(
            'UPDATE contagem_volta SET dispersao_ms = 777 WHERE evento_id = ? AND prova_id = ? AND equipe_numero = ?',
            [self::EVENTO_ID, self::PROVA_ID, self::CAR_B]
        );

        $token1 = $this->issueToken($this->fontes[0]);
        $token2 = $this->issueToken($this->fontes[1]);
        $this->upload($token1, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(420))]);
        $this->upload($token2, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(420))]);

        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertEquals(1, $this->publishedData(self::CAR_B)['VOLTAS']);
        $this->assertSame(2, $this->countRows(
            'contagem_volta',
            'evento_id = ? AND prova_id = ? AND equipe_numero = ? AND dispersao_ms = 777',
            [self::EVENTO_ID, self::PROVA_ID, self::CAR_B]
        ), 'car 22 has its two rows (pass zero and lap 1), untouched');
        $this->assertSame(3, $this->countRows(
            'contagem_volta',
            'evento_id = ? AND prova_id = ? AND equipe_numero = ? AND estado IN (?, ?)',
            [self::EVENTO_ID, self::PROVA_ID, self::CAR_A, 'inicial', 'contada']
        ), 'car 11 has pass zero and two laps');
    }

    /**
     * SITUATION: counter 1's phone uploaded a lap for car 11, the server
     * stored the entry, and then something failed before the count was
     * published (a crash, a lost connection to the database). The phone
     * never got its answer, so it sends the same entry again.
     *
     * WHY THIS IS A TRAP: the entry is no longer new. A server that only
     * recounted cars with NEW entries would answer "stored" and leave car
     * 11 one lap short for ever.
     *
     * EXPECTED: the resend recounts car 11 anyway, and the lap is published.
     */
    public function testAResendOfAStoredEntryStillGetsItsCarCounted(): void
    {
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recompute();
        $uuid = self::newUuid();
        $token1 = $this->issueToken($this->fontes[0]);
        $this->addEntry($this->fontes[1], self::CAR_A, $this->at(240));
        // The first attempt: the entry reached the table, nothing else happened.
        $this->executeSql(
            'INSERT INTO contagem_passagem (fonte_id, dispositivo_id, uuid_cliente, equipe_numero, ts_dispositivo_ms,
                 desvio_relogio_ms, ts_ms, modo_entrada, recebido_em_ms)
             SELECT fonte_id, dispositivo_id, ?, ?, ?, 0, ?, ?, ? FROM contagem_dispositivo WHERE fonte_id = ? LIMIT 1',
            [$uuid, self::CAR_A, $this->at(240), $this->at(240), 'teclado', $this->at(240), $this->fontes[0]->getFonteId()]
        );
        $this->assertNull($this->publishedData(self::CAR_A), 'nothing was published by the failed attempt');

        $response = $this->upload($token1, [$this->entryPayload($uuid, self::CAR_A, $this->at(240))]);

        $this->assertSame([$uuid], $response['body']['stored_uuids']);
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: a controller removes a counter while that counter's phone
     * is in the middle of an upload: the phone's request has passed the
     * first token check and is waiting for the count lock when the device
     * is revoked.
     *
     * EXPECTED: once the request gets the lock it checks the token again,
     * finds it revoked, stores nothing and answers 401. "Revoked" means no
     * more entries from that device, with no gap for a request already on
     * its way.
     */
    public function testADeviceRevokedWhileItsUploadWaitedForTheLockStoresNothing(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $deviceId = (int) $this->fetchValue(
            'SELECT MAX(dispositivo_id) FROM contagem_dispositivo WHERE fonte_id = ?',
            [$this->fontes[0]->getFonteId()]
        );

        // A second process plays the controller's request: it takes the
        // lock, keeps it for a second, revokes the device and lets go.
        $controllersRequest = $this->startAnotherRequest([
            ['sql', 'SELECT GET_LOCK(?, 5)', [$this->lockName()]],
            ['sleep', 1.0],
            ['sql', 'UPDATE contagem_dispositivo SET revogado_em_ms = ? WHERE dispositivo_id = ?', [$this->at(600), $deviceId]],
            ['sql', 'SELECT RELEASE_LOCK(?)', [$this->lockName()]],
        ]);
        // Give it a moment to take the lock. The phone's upload then starts
        // while the device is still valid, and has to wait.
        usleep(400000);
        $response = $this->upload($token, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(60))]);
        $this->waitForAnotherRequest($controllersRequest);

        $this->assertSame(401, $response['status']);
        $this->assertSame('token_invalido', $response['body']['erro']);
        $this->assertSame(0, $this->countRows('contagem_passagem', 'fonte_id = ?', [$this->fontes[0]->getFonteId()]));
    }

    /**
     * SITUATION: a phone has its clock set to the wrong year, and sends an
     * entry "timed" two years ago together with a good one.
     *
     * EXPECTED: the good entry is stored and acknowledged. The impossible
     * one is neither: it stays in the phone's queue, where the counter sees
     * "1 na fila" that never goes away, the visible sign that something is
     * wrong with that phone. Stored, it would sit years away from the race,
     * where no review screen would ever show it.
     */
    public function testAnEntryTimedYearsAwayIsNotStoredAndNotAcknowledged(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $good = self::newUuid();
        $impossible = self::newUuid();
        $twoYearsMs = 2 * 365 * 24 * 3600 * 1000;

        $response = $this->upload($token, [
            $this->entryPayload($good, self::CAR_A, $this->at(60)),
            $this->entryPayload($impossible, self::CAR_B, $this->at(60) - $twoYearsMs),
        ]);

        $this->assertSame([$good], $response['body']['stored_uuids']);
        $this->assertSame(1, $this->countRows('contagem_passagem', 'fonte_id = ?', [$this->fontes[0]->getFonteId()]));
    }

    /**
     * SITUATION: the heartbeats of a quiet minute: nothing new from any phone.
     *
     * EXPECTED: no points recalculation, no lap rows written, and the
     * answer still carries what the count page shows all the time: the
     * race state, the list of cars and the server's clock.
     */
    public function testAHeartbeatAnswersWithTheRaceStateAndCountsNothing(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $recalculationsBefore = ScorePublisher::refreshCount();

        $response = $this->upload($token);

        $this->assertSame(200, $response['status']);
        $this->assertSame($this->at(0), $response['body']['race']['largada_ms']);
        $this->assertSame('correndo', $response['body']['race']['estado']);
        $this->assertSame($this->at(600), $response['body']['server_now_ms']);
        $this->assertCount(3, $response['body']['valid_cars']);
        $this->assertSame($recalculationsBefore, ScorePublisher::refreshCount());
        $this->assertSame(0, $this->countRows('contagem_volta', 'evento_id = ? AND prova_id = ?', [self::EVENTO_ID, self::PROVA_ID]));
    }
}
