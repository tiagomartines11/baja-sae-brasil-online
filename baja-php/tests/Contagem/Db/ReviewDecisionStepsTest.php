<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\DecisionValidator;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;

/**
 * Round 3 of the decision endpoint: decisions that are several rows in one
 * step, and what a decision costs.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A decision is sent by the review screen to contagem_decidir.php, which
 * calls DecisionValidator::decide(). That takes the "count lock": while it
 * is held, no phone's upload and no other decision can change the race, so
 * the check and the storing are one step. The price is that everybody else
 * waits, so as little as possible must happen inside it.
 *
 * Two kinds of request exist only on the review screen and are stored as
 * ordinary decisions:
 *
 *   descartar_passagem  "this pass did not happen": one void per entry of
 *                       the pass, all together
 *   encerrar_exclusao   "the counter is back": an exclusion with no end is
 *                       replaced by one that ends at the given moment
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewDecisionStepsTest extends ReviewDbTestCase
{
    /**
     * SITUATION: a phone is in the middle of a large upload and holds the
     * count lock. A reviewer's browser sends a request that makes no sense
     * (an unknown kind of decision; a void that names no entry).
     *
     * WHAT WENT WRONG: the request was only looked at after the lock had
     * been obtained and the whole race loaded and calculated. A request
     * that could never be accepted waited for the lock (up to its time-out)
     * and then cost a full run of the engine, with every phone waiting
     * behind it.
     *
     * EXPECTED: the shape of a request is checked first. A malformed one
     * is refused at once with 400, while another request still holds the
     * lock: it never asks for the lock at all.
     */
    public function testAMalformedRequestIsRefusedWithoutWaitingForTheLock(): void
    {
        $this->startRace(self::RACE_START_MS);
        $lockHeldByAnUpload = $this->holdLockFromAnotherRequest();
        $startedAt = microtime(true);

        $unknownKind = $this->decide(['tipo' => 'explodir', 'passagem_id' => 1], [1], 'x');
        $noEntry = $this->decide(['tipo' => 'anular'], [1], 'x');
        $notWatched = $this->decide(['tipo' => 'anular', 'passagem_id' => 7], [1], 'x');

        $this->assertSame(400, $unknownKind['status']);
        $this->assertSame(400, $noEntry['status']);
        $this->assertSame(400, $notWatched['status']);
        $this->assertLessThan(1.0, microtime(true) - $startedAt, 'none of the three waited for the lock');
        $lockHeldByAnUpload = null;
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: car A's passes at 1:00 and 2:40 are fine. At 3:10, only 30
     * seconds after the previous one, a pass typed by ALL THREE counters
     * appears: the car was pushed backwards over the line. That raises
     * "volta curta". The pass did not happen.
     *
     * WHAT WENT WRONG: the reviewer had to void three entries with three
     * clicks. After the first click the pass still counted (two counters
     * left); after the second it became a lone entry with a new flag. For
     * as long as that took, the published count was wrong in a new way.
     *
     * EXPECTED: "descartar esta passagem" stores a void for each of the
     * three entries in ONE answer; the car goes from 2 laps to 1 and no
     * new flag about those entries appears. Each void is an ordinary
     * decision in the history and can be undone on its own.
     */
    public function testDiscardingAPassVoidsEveryEntryOfItInOneStep(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee($car, 60);
        $this->allCountersSee($car, 160);
        $ghost = $this->allCountersSee($car, 190);
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $state = $this->freshScreen();
        $this->assertSame(2, $this->carRow($state, $car)['voltas']);

        $answer = $this->decide(['tipo' => 'descartar_passagem', 'passagem_id' => $ghost[0]], $ghost, $this->currentSignatureOf($ghost));

        $this->assertSame(200, $answer['status']);
        $this->assertCount(3, $answer['body']['decisao_ids']);
        $this->assertSame(1, $this->carRow($answer['body']['estado'], $car)['voltas']);
        $this->assertSame(3, $this->countRows('contagem_decisao', 'evento_id = ? AND prova_id = ? AND tipo = ?', [self::EVENTO_ID, self::PROVA_ID, 'anular']));
        foreach ($answer['body']['estado']['alertas_abertos'] as $flag) {
            $this->assertSame([], array_intersect($flag['passagem_ids'], $ghost), 'no flag is left about the discarded pass');
        }
        $this->assertEquals(1, $this->publishedData($car)['VOLTAS']);
    }

    /**
     * SITUATION: two reviewers have the same short-lap flag open. The
     * first discards the pass. The second, whose screen still shows the
     * three entries as counting, presses the same button.
     *
     * EXPECTED: refused with 409 and nothing more is stored: the entries
     * are no longer as the second reviewer's screen showed them.
     */
    public function testDiscardingAPassThatSomebodyElseAlreadyDiscardedIsRefused(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee($car, 60);
        $ghost = $this->allCountersSee($car, 90);
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $signatureOnBothScreens = $this->currentSignatureOf($ghost);
        $request = ['tipo' => 'descartar_passagem', 'passagem_id' => $ghost[0]];

        $first = $this->decide($request, $ghost, $signatureOnBothScreens, 'zzt_maria');
        $second = $this->decide($request, $ghost, $signatureOnBothScreens, 'zzt_joao');

        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $second['status']);
        $this->assertSame(3, $this->countDecisions());
    }

    /**
     * SITUATION: counter 2's phone died ten minutes into the race, and the
     * reviewer excluded Contagem 2 "até segunda ordem" from 0:10. Counters
     * 1 and 3 kept counting: car A passed at 0:01, 0:12 and 0:14 (counter 2
     * has the first only). At 0:16 counter 2 is back with another phone and
     * records the pass at 0:18 with the others.
     *
     * WHAT WENT WRONG: the only way to let Contagem 2 back in was to UNDO
     * the exclusion, which makes it count for the whole race again. Every
     * pass in the gap then looked like a miss of counter 2.
     *
     * EXPECTED: "Encerrar exclusão em 0:16" replaces the open exclusion by
     * one from 0:10 to 0:16, in one step: the open one is undone and a
     * bounded one with the same source and start is stored. The source is
     * no longer listed as excluded "até segunda ordem", and the count of
     * car A is what it was.
     */
    public function testAnOpenEndedExclusionCanBeGivenAnEnd(): void
    {
        $car = self::CAR_A;
        $counter2 = $this->fontes[1];
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee($car, 60);
        foreach ([720, 840] as $seconds) {
            $this->addEntry($this->fontes[0], $car, $this->at($seconds));
            $this->addEntry($this->fontes[2], $car, $this->at($seconds) + 1000);
        }
        $this->allCountersSee($car, 1080);
        $this->syncAll($this->at(1190));
        Clock::freezeAt($this->at(1200));
        $open = $this->decide(['tipo' => 'excluir_fonte', 'fonte_id' => $counter2->getFonteId(), 'ts_ms' => $this->at(600), 'ts_fim_ms' => null]);
        $this->assertSame(200, $open['status']);
        $openId = $open['body']['decisao_id'];

        $closed = $this->decide(['tipo' => 'encerrar_exclusao', 'desfaz_decisao_id' => $openId, 'ts_fim_ms' => $this->at(960)]);

        $this->assertSame(200, $closed['status']);
        $inForce = array_values(array_filter(
            $closed['body']['estado']['historico_em_vigor'],
            fn(array $decision) => $decision['tipo'] === 'excluir_fonte'
        ));
        $this->assertCount(1, $inForce, 'one exclusion stands: the bounded one');
        $this->assertSame($this->at(600), $inForce[0]['ts_ms']);
        $this->assertSame($this->at(960), $inForce[0]['ts_fim_ms']);
        $this->assertSame((int) $counter2->getFonteId(), $inForce[0]['fonte_id']);
        $this->assertSame(3, $this->carRow($closed['body']['estado'], $car)['voltas']);

        // The open one is gone, so closing it a second time is a conflict, not a second row.
        $again = $this->decide(['tipo' => 'encerrar_exclusao', 'desfaz_decisao_id' => $openId, 'ts_fim_ms' => $this->at(900)]);
        $this->assertSame(409, $again['status']);
    }

    /**
     * EXPECTED: an end before the start of the exclusion, or one that has
     * not come yet, is refused with a sentence and nothing is stored. So
     * is "closing" an exclusion that already has an end.
     */
    public function testClosingAnExclusionNeedsAPossibleEnd(): void
    {
        $counter2 = $this->fontes[1];
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(1200));
        $open = $this->decide(['tipo' => 'excluir_fonte', 'fonte_id' => $counter2->getFonteId(), 'ts_ms' => $this->at(600), 'ts_fim_ms' => null]);
        $bounded = $this->decide(['tipo' => 'excluir_fonte', 'fonte_id' => $this->fontes[2]->getFonteId(), 'ts_ms' => $this->at(100), 'ts_fim_ms' => $this->at(200)]);
        $openId = $open['body']['decisao_id'];

        $tooEarly = $this->decide(['tipo' => 'encerrar_exclusao', 'desfaz_decisao_id' => $openId, 'ts_fim_ms' => $this->at(500)]);
        $inTheFuture = $this->decide(['tipo' => 'encerrar_exclusao', 'desfaz_decisao_id' => $openId, 'ts_fim_ms' => $this->at(5000)]);
        $alreadyBounded = $this->decide(['tipo' => 'encerrar_exclusao', 'desfaz_decisao_id' => $bounded['body']['decisao_id'], 'ts_fim_ms' => $this->at(150)]);

        $this->assertSame(400, $tooEarly['status']);
        $this->assertStringContainsString('depois do início', $tooEarly['body']['mensagem']);
        $this->assertSame(400, $inTheFuture['status']);
        $this->assertStringContainsString('futuro', $inTheFuture['body']['mensagem']);
        $this->assertSame(400, $alreadyBounded['status']);
        $this->assertSame(2, $this->countDecisions());
    }

    /**
     * SITUATION: a decision about one entry of car A, in a race in which
     * car B and car C have entries too.
     *
     * WHY THIS TEST EXISTS: a decision is now checked against a recount of
     * the cars the reviewer was looking at only (it used to run the whole
     * engine for the check and then again to publish). That is only right
     * if the fingerprint of an entry comes out the same from the quick
     * recount as from the full calculation the review screen got it from.
     *
     * EXPECTED: a fingerprint taken from the full calculation, and one
     * taken from the car's timeline, are both accepted; a stale one is
     * still refused.
     */
    public function testADecisionIsCheckedAgainstTheCarsConcernedAndStillCatchesAChange(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entriesOfA = $this->allCountersSee(self::CAR_A, 60);
        $this->allCountersSee(self::CAR_B, 70);
        $this->allCountersSee(self::CAR_C, 80);
        $lone = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(200));
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $this->recompute();

        $fromFullRun = $this->currentSignatureOf([$lone]);
        $fromTimeline = $this->signatureOnTimeline(self::CAR_A, $lone);
        $this->assertSame($fromFullRun, $fromTimeline);

        // Counter 2's entry for the same crossing arrives late: the lone entry is no longer alone.
        $this->addEntry($this->fontes[1], self::CAR_A, $this->at(201));
        $stale = $this->decide(['tipo' => 'anular', 'passagem_id' => $lone], [$lone], $fromFullRun);
        $this->assertSame(409, $stale['status']);
        $this->assertSame(DecisionValidator::MOTIVO_DADOS_MUDARAM, $stale['body']['motivo']);

        $fresh = $this->decide(['tipo' => 'anular', 'passagem_id' => $entriesOfA[0]], [$entriesOfA[0]], $this->signatureOnTimeline(self::CAR_A, $entriesOfA[0]));
        $this->assertSame(200, $fresh['status']);
    }

    private function signatureOnTimeline(int $car, int $entryId): string
    {
        $this->newRequest();
        foreach (TimelineWindow::forCar($this->service, $car)['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                if ($entry['id'] === $entryId) {
                    return $entry['assinatura'];
                }
            }
        }
        $this->fail('entry ' . $entryId . ' is not on the timeline of car ' . $car);
    }
}
