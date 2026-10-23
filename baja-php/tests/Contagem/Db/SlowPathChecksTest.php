<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Service\ChangeCounter;
use Baja\Contagem\Service\Clock;

/**
 * The whole-race recalculation as a safety net for the quick count.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Lap counts are published by the "fast path": each upload recounts only
 * the cars it mentions. Every few seconds the "slow path" recalculates the
 * whole race from scratch, for the things that need all cars at once
 * (flags, lap times, best laps).
 *
 * The slow path therefore knows every car's count too. These tests are
 * about what it does with that knowledge, and about three ways in which a
 * slow calculation can do harm simply by finishing late: the world may have
 * changed while it was computing.
 *
 * Tests move the clock with snapshotAt(): a snapshot younger than five
 * seconds is served as it is, so "a reviewer's poll a little later" needs
 * a later time.
 */
final class SlowPathChecksTest extends DbTestCase
{
    private string $errorLogPath;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));

        // What the application writes to the PHP error log goes to a file
        // for the duration of a test, so a test can read it.
        $this->errorLogPath = (string) tempnam(sys_get_temp_dir(), 'contagem-test-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLogPath);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLogPath);
        parent::tearDown();
    }

    /** A reviewer's poll at the given second of the race; recalculates when the snapshot is stale. */
    private function snapshotAt(int $seconds): \Baja\Contagem\Service\RaceSnapshot
    {
        Clock::freezeAt($this->at($seconds));
        $this->newRequest();
        return $this->service->snapshot();
    }

    /** One more official pass of a car, through the same path an upload uses. */
    private function oneMorePass(int $car, int $seconds): void
    {
        $this->recordCountedPass($car, $this->at($seconds));
        $this->newRequest();
        $this->service->withLock(fn() => $this->service->recountCars([$car]));
    }

    /** Overwrites a published field behind the application's back. */
    private function corruptPublishedField(int $car, string $field, int $value): void
    {
        $this->executeSql(
            'UPDATE input SET dados = JSON_SET(dados, ?, ?) WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?',
            ['$.' . $field, $value, self::EVENTO_ID, self::PROVA_ID, $car]
        );
    }

    private function changesNow(): ?int
    {
        return (new ChangeCounter(self::EVENTO_ID, self::PROVA_ID))->current();
    }

    /**
     * SITUATION: car 11 has done two laps and the results page says so.
     * Then, somehow, the published count goes wrong: here the test writes
     * VOLTAS = 7 straight into the score row, standing in for any bug of
     * the quick count (or a crash between storing an entry and publishing
     * it).
     *
     * WHAT WENT WRONG BEFORE: the recalculation knew the car had two laps
     * and did nothing about it; it only ever wrote the best lap. A wrong
     * count stayed on the results page until somebody happened to make a
     * decision.
     *
     * EXPECTED: the next recalculation puts VOLTAS back to 2, and writes a
     * line naming the car to the error log, because a difference here means
     * a bug that somebody must look into.
     */
    public function testARecalculationCorrectsAPublishedCountThatWentWrong(): void
    {
        $this->oneMorePass(self::CAR_A, 60);
        $this->oneMorePass(self::CAR_A, 240);
        $this->oneMorePass(self::CAR_A, 410);
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->corruptPublishedField(self::CAR_A, 'VOLTAS', 7);

        $this->snapshotAt(610);

        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertSame([self::CAR_A], $this->service->lastPublishOutcome()->correctedCars);
        $log = (string) file_get_contents($this->errorLogPath);
        $this->assertStringContainsString('carro ' . self::CAR_A, $log);
        $this->assertStringContainsString('VOLTAS', $log);

        // A line in a server log reaches nobody on race day, so the review
        // screen is given the same fact to show.
        $shown = $this->service->lastReconcileCorrections();
        $this->assertCount(1, $shown);
        $this->assertEquals(
            ['carro' => self::CAR_A, 'campo' => 'VOLTAS', 'publicado' => 7, 'calculado' => 2, 'quando_ms' => $this->at(610)],
            $shown[0]
        );
    }

    /**
     * SITUATION: a slow recalculation has finished computing and is about
     * to store its snapshot. At that very moment another request holds the
     * count lock (a reviewer's decision is being applied).
     *
     * WHAT WENT WRONG BEFORE: "is the stored snapshot newer than mine?" and
     * "store mine" were two separate steps with no lock around them. The
     * decision could store its snapshot between the two, and the older one
     * landed on top of it.
     *
     * EXPECTED: the check and the write happen under the count lock. While
     * somebody else holds it, the recalculation does not store at all: the
     * stored snapshot stays as it was, and the next request tries again.
     */
    public function testASnapshotIsOnlyStoredWhileHoldingTheCountLock(): void
    {
        $this->oneMorePass(self::CAR_A, 60);
        $storedBefore = $this->snapshotAt(610);
        $this->oneMorePass(self::CAR_A, 240);
        Clock::freezeAt($this->at(620));
        $this->newRequest();
        $input = $this->service->loadInput();
        $result = \Baja\Contagem\Engine\Engine::run($input);
        $snapshots = new \Baja\Contagem\Service\SnapshotService($this->prova, $this->service, new ChangeCounter(self::EVENTO_ID, self::PROVA_ID));

        $theDecision = $this->holdLockFromAnotherRequest();
        $computed = $snapshots->storeFromRun($input, $result, $this->changesNow());
        $theDecision = null;

        $this->assertSame($this->at(620), $computed->geradoEmMs, 'the request that computed it still gets its answer');
        $this->assertSame($storedBefore->geradoEmMs, (new \Baja\Contagem\Service\SnapshotStore($this->prova))->get()->geradoEmMs, 'but nothing was stored');
    }

    /**
     * SITUATION: a judge saved car 22's classification on the scores page
     * before the car has turned a lap. Its score row exists and holds that
     * one field: no VOLTAS, no LAST.
     *
     * WHAT WENT WRONG BEFORE: the check on the fast path read "no VOLTAS"
     * as "differs from the computed 0" and reported a divergence, one
     * false alarm per such car and recalculation.
     *
     * EXPECTED: nothing is reported. "Nothing published" and "zero laps"
     * say the same thing.
     */
    public function testACarWithoutAPublishedCountAndWithoutLapsIsNotADivergence(): void
    {
        $this->setManualField(self::CAR_B, 'APTO', 'SIM');
        $this->oneMorePass(self::CAR_A, 60);

        $this->snapshotAt(610);

        $this->assertSame([], $this->service->lastPublishOutcome()->correctedCars);
        $this->assertSame([], $this->service->lastReconcileCorrections());
        $this->assertSame('', (string) file_get_contents($this->errorLogPath));
    }

    /**
     * SITUATION: car 11 has two published laps. Race control then cancels
     * the only start of the race (it asks for a specific confirmation
     * first, promising that the published results are cleared).
     *
     * WHAT WENT WRONG BEFORE: nothing was cleared. Without a start the
     * publisher stood back completely, as it does for a prova that never
     * started, and the two laps stayed on the results page.
     *
     * EXPECTED: the published count goes to zero. (A prova that never had
     * a start here is still left alone: its numbers may be imported.)
     */
    public function testCancellingTheOnlyStartClearsThePublishedCounts(): void
    {
        $this->oneMorePass(self::CAR_A, 60);
        $this->oneMorePass(self::CAR_A, 240);
        $this->oneMorePass(self::CAR_A, 410);
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $startId = (int) $this->fetchValue(
            'SELECT ocorrencia_id FROM contagem_ocorrencia WHERE evento_id = ? AND prova_id = ? AND tipo = ?',
            [self::EVENTO_ID, self::PROVA_ID, 'largada']
        );

        $this->newRequest();
        $this->service->withLock(function () use ($startId): void {
            $this->service->recordOcorrencia('cancelamento', $this->at(0), null, self::USER_PREFIX . 'controle', $startId);
        });

        $this->assertEquals(0, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertEquals(0, $this->publishedData(self::CAR_A)['LAST']);
    }

    /**
     * SITUATION: car 22's score row holds a best lap of 99 seconds, but the
     * car has no lap at all (its laps were cancelled by a reviewer, say,
     * after the best lap had been published).
     *
     * WHAT WENT WRONG BEFORE: cars with zero laps were skipped when the
     * best lap was published, so that 99 stayed for ever, and with it the
     * points a best lap is worth.
     *
     * EXPECTED: the next recalculation sets BEST back to 0.
     */
    public function testABestLapLeftBehindOnACarWithoutLapsIsCleared(): void
    {
        $this->setManualField(self::CAR_B, 'VOLTAS', 0);
        $this->setManualField(self::CAR_B, 'LAST', 0);
        $this->setManualField(self::CAR_B, 'BEST', 99);
        // Something must change for a recalculation to happen at all.
        $this->oneMorePass(self::CAR_A, 60);

        $this->snapshotAt(610);

        $this->assertEquals(0, $this->publishedData(self::CAR_B)['BEST']);
    }

    /**
     * SITUATION: a recalculation starts, loads the race, and takes a while.
     * While it is computing, a new pass of car 11 is uploaded and counted:
     * the results page now shows one lap. Then the recalculation finishes,
     * with a result in which car 11 has no lap yet.
     *
     * EXPECTED: it does not touch the count. Its picture of the race is
     * older than what is published, and "correcting" the results page from
     * it would take the lap away again. (It may only correct counts when
     * nothing at all has changed since it loaded the race.)
     */
    public function testARecalculationThatFinishesLateDoesNotPutAnOlderCountBack(): void
    {
        $this->oneMorePass(self::CAR_A, 60);
        $changesWhenItStarted = $this->changesNow();
        $this->newRequest();
        $resultOfTheSlowRun = $this->service->compute();

        $this->oneMorePass(self::CAR_A, 240);
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);

        $this->service->settleFullRun($resultOfTheSlowRun, $changesWhenItStarted);

        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertSame('', (string) file_get_contents($this->errorLogPath), 'nothing was "corrected"');
    }

    /**
     * SITUATION: a recalculation is running. While it computes, the person
     * in charge switches publishing off ("desligada": the old spreadsheets
     * take over the results page). The recalculation then finishes and
     * wants to publish what it found.
     *
     * WHAT WENT WRONG BEFORE: the settings were read when the request
     * began, so it published with the old mode and wrote over the
     * spreadsheet's numbers.
     *
     * EXPECTED: it reads the settings again once it holds the lock, sees
     * that publishing is off, and writes nothing. Here the score row is
     * given a value only the spreadsheet could have written (VOLTAS = 50),
     * and it must survive.
     */
    public function testPublishingSwitchedOffWhileARecalculationRanIsRespected(): void
    {
        $this->oneMorePass(self::CAR_A, 60);
        $this->oneMorePass(self::CAR_A, 240);
        $changesWhenItStarted = $this->changesNow();
        $this->newRequest();
        $resultOfTheSlowRun = $this->service->compute();

        // Another request, with its own copy of the prova, saves the setting.
        $this->executeSql(
            'UPDATE prova SET params = JSON_SET(params, ?, JSON_OBJECT(?, ?)) WHERE evento_id = ? AND prova_id = ?',
            ['$.contagem', 'modo_publicacao', 'desligada', self::EVENTO_ID, self::PROVA_ID]
        );
        $this->corruptPublishedField(self::CAR_A, 'VOLTAS', 50);

        $this->service->settleFullRun($resultOfTheSlowRun, $changesWhenItStarted);

        $this->assertEquals(50, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: the same switch to "desligada", this time while an UPLOAD
     * is waiting its turn: another request holds the count lock for most of
     * a second, and the setting is saved during that wait.
     *
     * EXPECTED: the upload stores its entry (raw data is always kept) but
     * publishes nothing: no score row appears for car 11.
     */
    public function testPublishingSwitchedOffWhileAnUploadWaitedIsRespected(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->addEntry($this->fontes[1], self::CAR_A, $this->at(240));
        $this->assertNull($this->publishedData(self::CAR_A), 'nothing published yet');

        $otherRequest = $this->startAnotherRequest([
            ['sql', 'SELECT GET_LOCK(?, 5)', [$this->lockName()]],
            ['sleep', 0.8],
            ['sql', 'UPDATE prova SET params = JSON_SET(params, ?, JSON_OBJECT(?, ?)) WHERE evento_id = ? AND prova_id = ?',
                ['$.contagem', 'modo_publicacao', 'desligada', self::EVENTO_ID, self::PROVA_ID]],
            ['sql', 'SELECT RELEASE_LOCK(?)', [$this->lockName()]],
        ]);
        // Give the other request time to take the lock first.
        usleep(300000);

        $uuid = self::newUuid();
        $answer = $this->upload($token, [$this->entryPayload($uuid, self::CAR_A, $this->at(240))]);
        $this->waitForAnotherRequest($otherRequest);

        $this->assertSame([$uuid], $answer['body']['stored_uuids']);
        $this->assertNull($this->publishedData(self::CAR_A), 'publishing was off by the time the upload got the lock');
    }

    /**
     * SITUATION: a recalculation starts and loads the race. While it
     * computes, a reviewer cancels an entry; the decision reruns the engine
     * itself and stores a fresh snapshot, so the reviewer sees the result
     * at once. Then the slow recalculation finishes and wants to store ITS
     * snapshot, made from the race as it was before the decision.
     *
     * WHAT WENT WRONG BEFORE: it stored it. For a few seconds every review
     * screen showed the race without the decision, as if it had been lost.
     *
     * EXPECTED: the older snapshot is thrown away; the stored one is still
     * the decision's.
     */
    public function testASnapshotOlderThanADecisionDoesNotOverwriteIt(): void
    {
        $this->oneMorePass(self::CAR_A, 60);
        [$entryOfCounter1] = $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->newRequest();
        $changesWhenItStarted = $this->changesNow();
        $inputOfTheSlowRun = $this->service->loadInput();
        $resultOfTheSlowRun = \Baja\Contagem\Engine\Engine::run($inputOfTheSlowRun);

        // The reviewer's decision, in another request.
        $this->newRequest();
        $this->service->withLock(function () use ($entryOfCounter1): void {
            $this->service->storeDecision(Decision::anular(0, $entryOfCounter1), self::USER_PREFIX . 'revisor', null);
            $this->service->recomputeAndPublish();
        });
        $snapshotOfTheDecision = $this->service->lastStoredSnapshot();

        $snapshots = new \Baja\Contagem\Service\SnapshotService($this->prova, $this->service, new ChangeCounter(self::EVENTO_ID, self::PROVA_ID));
        $kept = $snapshots->storeFromRun($inputOfTheSlowRun, $resultOfTheSlowRun, $changesWhenItStarted);

        $this->assertSame($snapshotOfTheDecision->token, $kept->token);
        $this->assertSame($snapshotOfTheDecision->token, (new \Baja\Contagem\Service\SnapshotStore($this->prova))->get()->token);
    }
}
