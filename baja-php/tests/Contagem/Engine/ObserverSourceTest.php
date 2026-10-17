<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\ObserverComparator;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * A machine-vision system on trial: an automatic source in the role of an
 * OBSERVER. It records passes like a counter, but they are only compared
 * with the official count; they never take part in it.
 *
 * (`firstCrossing` is each car's pass zero, seen by every counter.)
 */
final class ObserverSourceTest extends EngineTestCase
{
    /**
     * SITUATION: two counters, and the same race once more with a camera
     * added as an observer with precise timing. The camera sees every pass
     * a little late, invents a car 9, has never synced and has not been in
     * contact for the whole race.
     *
     * EXPECTED: nothing official moves. The counts, the pass times, the
     * habit-corrected times, the flags and the counters' statistics are
     * exactly those of the race without the camera. A precise observer
     * does not set the pass time; a silent one holds nothing back and is
     * charged no miss. It is still shown as offline, so whoever runs it
     * knows it stopped sending.
     */
    public function testAnObserverChangesNoCountNoTimeAndNoFlag(): void
    {
        $without = $this->raceWithLoneEntries(false)->run('15:00');
        $with = $this->raceWithLoneEntries(true)->run('15:00');

        $this->assertSame($this->officialPicture($without), $this->officialPicture($with));
        $this->assertNotEmpty($this->openFlags($with), 'the race has flags to compare');

        $observer = $with->sourceStats[3];
        $this->assertSame(0, $observer->entradas);
        $this->assertSame(0, $observer->faltas);
        $this->assertTrue($observer->offline);
        $this->assertNotNull($observer->observacao);
        $this->assertArrayNotHasKey('observacao', $without->sourceStats[1]->toArray(), 'a race with no observer sends what it always did');
        $this->assertSame([], $without->observerRows);
    }

    /**
     * SITUATION: counters 1 and 2 record cars 5, 6 and 7. The camera
     * (observer) records:
     *   - the three pass zeros at 00:20, 0.3 s late;
     *   - car 5 at 03:00 +0.2 s, 06:00 +0.4 s and 10:00 -0.1 s;
     *   - "8" at 08:00.3, when car 6 passed and car 8 did not: a misread;
     *   - "9" at 11:00, when nobody passed: a false positive;
     *   - nothing when car 7 passed at 09:00: a miss.
     *
     * EXPECTED: 6 hits, 1 misread (8 read for 6), 1 false positive, 1 miss.
     * The median offset is +0.3 s and the 90th percentile 0.4 s. A pass
     * from after the camera's last sync is not a miss: it may still send it.
     */
    public function testTheComparisonCountsHitsMissesFalsePositivesAndMisreads(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->addSource(3, 'Câmera', Source::TIPO_AUTOMATICA, Source::TEMPO_PRECISA);
        $race->makeObserver(3);
        $race->start('00:00');
        $race->firstCrossing([5, 6, 7]);
        foreach ([5, 6, 7] as $car) {
            $race->entry(3, $car, '00:20.3');
        }
        $race->pass(5, '03:00');
        $race->entry(3, 5, '03:00.2');
        $race->pass(5, '06:00');
        $race->entry(3, 5, '06:00.4');
        $race->pass(6, '08:00');
        $race->entry(3, 8, '08:00.3');
        $race->pass(7, '09:00');
        $race->pass(5, '10:00');
        $race->entry(3, 5, '09:59.9');
        $race->entry(3, 9, '11:00');

        $result = $race->run('15:00');

        $this->assertLapCounts([5 => 3, 6 => 1, 7 => 1], $result);
        $stats = $result->sourceStats[3]->observacao;
        $this->assertSame(8, $stats->entradas);
        $this->assertSame(6, $stats->acertos);
        $this->assertSame(1, $stats->leiturasTrocadas);
        $this->assertSame(1, $stats->falsosPositivos);
        $this->assertSame(1, $stats->faltas);
        $this->assertSame(300, $stats->desvioMedianoMs);
        $this->assertSame(400, $stats->desvioP90Ms);
        $this->assertSame([['carro_lido' => 8, 'carro_real' => 6, 'vezes' => 1]], $stats->trocasFrequentes);

        $results = array_count_values(array_column($result->observerRows, 'resultado'));
        $this->assertSame(6, $results[ObserverComparator::ACERTO]);
        $this->assertSame(1, $results[ObserverComparator::FALTA]);

        $race->syncedThrough(3, '08:30');
        $this->assertSame(0, $race->run('15:00')->sourceStats[3]->observacao->faltas, 'the 09:00 pass may still be in the camera');
    }

    /**
     * SITUATION: two quick entries of car 5 from the camera, 1 s apart,
     * around one pass of car 5 seen by both counters.
     *
     * EXPECTED: one pass is one hit. The closer entry takes it; the other
     * is a false positive (a double read), not a second hit.
     */
    public function testOnePassIsPairedWithOneEntryOnlyTheClosest(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->addSource(3, 'Câmera', Source::TIPO_AUTOMATICA, Source::TEMPO_PRECISA);
        $race->makeObserver(3);
        $race->start('00:00');
        $race->pass(5, '03:00');
        $race->entry(3, 5, '02:59.5');
        $race->entry(3, 5, '03:00.2');

        $stats = $race->run('15:00')->sourceStats[3]->observacao;

        $this->assertSame(1, $stats->acertos);
        $this->assertSame(1, $stats->falsosPositivos);
        $this->assertSame(200, $stats->desvioMedianoMs);
    }

    /**
     * SITUATION: two counters and a camera observing since the start. Car
     * 5 passes at 05:00 and at 12:00; both times only counter 1 and the
     * camera record it. At 10:00 the controller promotes the camera to
     * votante.
     *
     * EXPECTED: the promotion does not reach back. At 05:00 the camera was
     * an observer, so counter 1 stands alone and the pass waits. At 12:00
     * the camera is a votante: two sources agree and the pass counts.
     */
    public function testAPromotionCountsFromThatMomentOnNeverBackwards(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->addSource(3, 'Câmera', Source::TIPO_AUTOMATICA, Source::TEMPO_PRECISA);
        $race->makeObserver(3);
        $race->start('00:00');
        $race->firstCrossing([5]);
        $before = $race->entry(3, 5, '05:00');
        $race->entry(1, 5, '05:00');
        $after = $race->entry(3, 5, '12:00');
        $race->entry(1, 5, '12:00');
        $race->promote(3, '10:00');

        $result = $race->run('15:00');

        $this->assertLapCounts([5 => 1], $result);
        $this->assertNull($result->groupOfEntry($before), 'observed, so in no group');
        $this->assertTrue($result->groupOfEntry($after)->contado);
        $this->assertNull($result->sourceStats[3]->observacao, 'a votante is not compared');
    }

    /**
     * SITUATION: two counters. Counter 2 records car 5 at 05:00 and at
     * 08:00. At 06:00 the controller makes it an observer.
     *
     * EXPECTED: like switching a counter off, it keeps the votes it cast
     * before: the 05:00 pass still has both counters. Its 08:00 entry is
     * only compared (a hit); counter 1, the only votante left, stands alone
     * there and the pass counts.
     */
    public function testMakingAVotanteAnObserverKeepsItsPastVotes(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([5]);
        $votes = $race->pass(5, '05:00');
        $race->entry(1, 5, '08:00');
        $observed = $race->entry(2, 5, '08:00');
        $race->makeObserver(2, '06:00');

        $result = $race->run('15:00');

        $this->assertLapCounts([5 => 2], $result);
        $this->assertCount(2, $result->groupOfEntry($votes[2])->entries);
        $this->assertNull($result->groupOfEntry($observed));
        $this->assertSame(1, $result->sourceStats[2]->observacao->acertos);
    }

    /**
     * SITUATION: counter 2 was switched off from 05:00 to 10:00 and then
     * switched on again, without asking for the gap to count. Its phone,
     * in a pocket, sent a "5" at 07:00 that counter 1 also has.
     *
     * EXPECTED: switching it on again does not bring that entry back: the
     * 07:00 pass has counter 1 only. Before and after the gap counter 2
     * counts as usual.
     */
    public function testWhatASourceSentWhileSwitchedOffStaysOutAfterItIsSwitchedOnAgain(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([5]);
        $race->pass(5, '03:00');
        $race->entry(1, 5, '07:00');
        $inTheGap = $race->entry(2, 5, '07:00');
        $race->pass(5, '12:00');
        $race->switchedOffBetween(2, '05:00', '10:00');

        $result = $race->run('15:00');

        $this->assertNull($result->groupOfEntry($inTheGap));
        $this->assertLapCounts([5 => 3], $result, '07:00 stands alone: one counter was supposed to count then');
    }

    private function raceWithLoneEntries(bool $withObserver): RaceScenario
    {
        $race = RaceScenario::withCounters(2);
        if ($withObserver) {
            $race->addSource(3, 'Câmera', Source::TIPO_AUTOMATICA, Source::TEMPO_PRECISA);
            $race->makeObserver(3);
            $race->syncedThrough(3, null);
            $race->lastSeen(3, null);
        }
        $race->start('00:00');
        $race->firstCrossing([5, 6]);
        foreach (['03:00', '06:00', '09:00'] as $at) {
            $race->entry(1, 5, $at);
            $race->entry(2, 5, $at . '.8');
            $race->pass(6, $at . '.5');
        }
        $race->entry(1, 6, '11:00');
        $race->entry(2, 41, '11:30');
        if ($withObserver) {
            foreach (['00:21', '03:01', '03:02', '06:01', '09:01', '11:01'] as $at) {
                $race->entry(3, 5, $at);
                $race->entry(3, 6, $at);
            }
            $race->entry(3, 9, '07:00');
        }
        return $race;
    }

    /** @return array<string, mixed> everything official about a run */
    private function officialPicture(EngineResult $result): array
    {
        $groups = [];
        foreach ($result->groups as $group) {
            $groups[] = [$group->carro, $group->entryIds(), $group->tsMs, $group->tsAjustadoMs, $group->contado, $group->retida];
        }
        $flags = [];
        foreach ($result->flags as $flag) {
            $flags[] = [$flag->id, $flag->autoResolvido];
        }
        $laps = [];
        foreach ($result->cars as $carro => $car) {
            $laps[$carro] = [$car->voltas, $car->status];
        }
        return [
            'groups' => $groups,
            'flags' => $flags,
            'cars' => $laps,
            'counter1' => $result->sourceStats[1]->toArray(),
            'counter2' => $result->sourceStats[2]->toArray(),
        ];
    }
}
