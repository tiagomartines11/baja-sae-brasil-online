<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Contagem\Sync\UploadFields;

/**
 * Uploads no honest phone would send: absurd times, enormous requests,
 * events for an area the prova does not use.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The upload address is open to anything that holds a device token: the
 * count pages, but also an old cached copy of a page, a broken phone, or
 * another organisation's timing system. The rule of this feature is that an
 * entry is never thrown away, because an entry that is refused blocks the
 * phone's queue and is evidence lost. So odd data must be STORED, in a form
 * that can do no harm, and shown to a person.
 *
 * "Harm" here was very concrete. Times are whole numbers of milliseconds.
 * A number near the largest a computer word can hold (about 9.2 followed by
 * eighteen digits) is stored without complaint, and then the first sum that
 * involves it overflows and the whole-race calculation stops with an error,
 * for every car, until somebody removes the row by hand in the database.
 */
final class HostileUploadTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    /** [stored time, quality, what the device said] of each entry of a source, oldest row first. */
    private function storedTimes(int $fonteId): array
    {
        $statement = \Propel\Runtime\Propel::getWriteConnection('resultados')->prepare(
            'SELECT ts_ms, qualidade_tempo, ts_dispositivo_ms FROM contagem_passagem WHERE fonte_id = ? ORDER BY passagem_id'
        );
        $statement->execute([$fonteId]);
        return array_map(fn(array $row): array => [(int) $row[0], (string) $row[1], (int) $row[2]], $statement->fetchAll(\PDO::FETCH_NUM));
    }

    /**
     * SITUATION: three entries arrive with times no clock could show: the
     * largest number a machine word holds, the same written as a decimal
     * with thirty digits, and a negative one.
     *
     * WHAT WENT WRONG BEFORE: they were stored as they came. From then on
     * every whole-race calculation failed, so the review screen was dead,
     * and since a decision needs that calculation, the entries could not
     * even be cancelled.
     *
     * EXPECTED: each is stored (never refused) with the nearest time that
     * can be computed with, marked as of unknown time, so it is never
     * counted or matched by itself; the number the device sent is kept
     * beside it. And the proof that matters: the whole-race calculation
     * still runs, and a reviewer can still cancel one of them.
     */
    public function testAnAbsurdTimeIsStoredHarmlesslyAndTheRaceStillComputes(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $largest = PHP_INT_MAX;
        $entries = [
            ['ts_ms' => $largest] + $this->entryPayload('bbbbbbbb-0000-4000-8000-000000000001', self::CAR_A, 0),
            ['ts_ms' => 1.0e30] + $this->entryPayload('bbbbbbbb-0000-4000-8000-000000000002', self::CAR_A, 0),
            ['ts_ms' => -5] + $this->entryPayload('bbbbbbbb-0000-4000-8000-000000000003', self::CAR_A, 0),
        ];

        $answer = $this->upload($token, $entries);

        $this->assertCount(3, $answer['body']['stored_uuids']);
        $limit = UploadFields::TIME_LIMIT_MS - 1;
        $stored = $this->storedTimes((int) $this->fontes[0]->getFonteId());
        $this->assertSame([$limit, 'desconhecido', $largest], $stored[0]);
        $this->assertSame([$limit, 'desconhecido'], array_slice($stored[1], 0, 2));
        $this->assertSame([0, 'desconhecido', -5], $stored[2]);

        // The whole-race calculation runs...
        $this->newRequest();
        $result = $this->service->compute();
        $this->assertSame(0, $result->cars[self::CAR_A]->voltas ?? 0, 'none of them counted');

        // ...and so does a decision.
        $entryId = (int) $this->fetchValue('SELECT passagem_id FROM contagem_passagem WHERE uuid_cliente = ?', ['bbbbbbbb-0000-4000-8000-000000000001']);
        $this->service->withLock(function () use ($entryId): void {
            $this->service->storeDecision(Decision::anular(0, $entryId), self::USER_PREFIX . 'revisor', null);
            $this->service->recomputeAndPublish();
        });
        $this->assertSame(1, $this->countRows('contagem_decisao', 'passagem_id = ?', [$entryId]));
    }

    /**
     * SITUATION: the same absurd time on a pit lane event.
     *
     * EXPECTED: stored the same way: clamped, marked as of unknown time
     * (which keeps it out of the pairing of entries and exits), with what
     * the device sent kept beside it.
     */
    public function testAnAbsurdTimeOnAStopEventIsStoredTheSameWay(): void
    {
        $token = $this->issuePitLogToken();
        $event = [
            'uuid' => self::newUuid(), 'car' => self::CAR_A, 'tipo' => 'box_entrada',
            'ts_ms' => PHP_INT_MAX, 'time_quality' => 'exato', 'ts_device_ms' => 1,
        ];

        $answer = $this->upload($token, [], ['pit_events' => [$event]]);

        $this->assertSame([$event['uuid']], $answer['body']['stored_uuids']);
        $statement = \Propel\Runtime\Propel::getWriteConnection('resultados')->prepare(
            'SELECT ts_ms, qualidade_tempo, ts_dispositivo_ms FROM contagem_ocorrencia WHERE uuid_cliente = ?'
        );
        $statement->execute([$event['uuid']]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        $this->assertSame([UploadFields::TIME_LIMIT_MS - 1, 'desconhecido', PHP_INT_MAX], [(int) $row[0], $row[1], (int) $row[2]]);
        $this->newRequest();
        $this->service->compute();
        $this->assertSame([], $answer['body']['cars_in_pit'], 'an event of unknown time puts no car in the pit lane');
    }

    /**
     * SITUATION: a pit-log phone records an entry with a good time, sent
     * with its quality ("estimado": typed offline across a reload), the
     * phone's own clock reading, and how it was entered.
     *
     * WHAT WENT WRONG BEFORE: stop events had no columns for any of that.
     * An estimated time looked exactly like an exact one, and a simulated
     * event could not be told from a real one once stored.
     *
     * EXPECTED: all three are in the row.
     */
    public function testAStopEventKeepsItsTimeQualityDeviceTimeAndMode(): void
    {
        $this->changeSettings(['permitir_simulacao' => true]);
        $token = $this->issuePitLogToken();
        $event = [
            'uuid' => self::newUuid(), 'car' => self::CAR_A, 'tipo' => 'box_entrada', 'mode' => 'simulacao',
            'ts_ms' => $this->at(120), 'time_quality' => 'estimado', 'ts_device_ms' => $this->at(120) + 4321,
        ];

        $this->upload($token, [], ['pit_events' => [$event]]);

        $statement = \Propel\Runtime\Propel::getWriteConnection('resultados')->prepare(
            'SELECT qualidade_tempo, ts_dispositivo_ms, modo_entrada FROM contagem_ocorrencia WHERE uuid_cliente = ?'
        );
        $statement->execute([$event['uuid']]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        $this->assertSame(['estimado', $this->at(120) + 4321, 'simulacao'], [$row[0], (int) $row[1], $row[2]]);
    }

    /**
     * SITUATION: the prova does not log the fueling station (the setting
     * is off). A pit-log phone whose page was opened earlier, when the
     * setting was on, still sends a fueling entry, together with a pit
     * lane entry.
     *
     * EXPECTED: the pit lane event is stored. The fueling event is not,
     * and the answer lists it as refused with its reason, so the page can
     * tell the volunteer instead of resending it for ever. Stored, it
     * would change that car's status and lap times in a race that has no
     * fueling station.
     */
    public function testFuelingEventsAreRefusedWhileTheProvaDoesNotLogFueling(): void
    {
        $token = $this->issuePitLogToken();
        $common = ['car' => self::CAR_A, 'ts_ms' => $this->at(120), 'time_quality' => 'exato', 'ts_device_ms' => $this->at(120)];
        $pit = ['uuid' => self::newUuid(), 'tipo' => 'box_entrada'] + $common;
        $fuel = ['uuid' => self::newUuid(), 'tipo' => 'abastecimento_entrada'] + $common;

        $answer = $this->upload($token, [], ['pit_events' => [$pit, $fuel]]);

        $this->assertSame([$pit['uuid']], $answer['body']['stored_uuids']);
        $this->assertSame([['uuid' => $fuel['uuid'], 'motivo' => 'abastecimento_desligado']], $answer['body']['refused']);
        $this->assertSame(0, $this->countRows('contagem_ocorrencia', 'uuid_cliente = ?', [$fuel['uuid']]));
    }

    /**
     * SITUATION: one request carries 620 entries. No count page sends
     * that many at once (a phone coming back from a long time offline
     * sends its backlog in batches).
     *
     * EXPECTED: the first 500 are stored and acknowledged. The rest are
     * neither stored nor acknowledged, so an honest client simply sends
     * them in its next request, and a request cannot tie a worker up
     * for as long as somebody likes.
     */
    public function testOnlyTheFirstFiveHundredEntriesOfARequestAreTaken(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $entries = [];
        for ($number = 0; $number < 620; $number++) {
            $entries[] = $this->entryPayload(sprintf('cccccccc-0000-4000-8000-%012d', $number), self::CAR_A, $this->at(100) + $number * 30000);
        }

        $answer = $this->upload($token, $entries);

        $this->assertCount(500, $answer['body']['stored_uuids']);
        $this->assertSame(500, $this->countRows('contagem_passagem', 'fonte_id = ?', [$this->fontes[0]->getFonteId()]));
        $this->assertNotContains($entries[500]['uuid'], $answer['body']['stored_uuids']);

        $next = $this->upload($token, array_slice($entries, 500));
        $this->assertCount(120, $next['body']['stored_uuids']);
    }

    /**
     * SITUATION: an ordinary upload.
     *
     * EXPECTED: the answer says when the request reached the server and
     * when the answer was written. The page anchors its clock to the
     * middle of the round trip; with both it can leave the server's own
     * work out of that (an upload that waited a second for its turn would
     * otherwise shift the page's clock by half a second).
     */
    public function testTheAnswerSaysWhenTheRequestArrivedAndWhenTheAnswerLeft(): void
    {
        $token = $this->issueToken($this->fontes[0]);

        $answer = $this->upload($token, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))])['body'];

        $this->assertSame(Clock::nowMs(), $answer['server_received_ms']);
        $this->assertSame($answer['server_now_ms'], $answer['server_received_ms']);
        $this->assertGreaterThanOrEqual($answer['server_received_ms'], $answer['server_sent_ms']);
    }
}
