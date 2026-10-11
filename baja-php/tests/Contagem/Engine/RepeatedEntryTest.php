<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The same counter recording the same car twice in a row ("repetida").
 *
 * A car cannot cross the line twice within 20 seconds, so the second entry
 * is never a second lap. The only question is whether to bother a reviewer.
 */
final class RepeatedEntryTest extends EngineTestCase
{
    /**
     * SITUATION: car 9 crosses. Counter 1's finger bounces on the Enter key
     * and the number is recorded twice, two seconds apart.
     *
     * WHAT WENT WRONG: a double tap.
     *
     * EXPECTED: the engine ignores the second entry by itself (within
     * `duplicata_ms`, 5 seconds, it is obviously the same crossing). The
     * car has one lap, the review queue stays empty, and the event is only
     * listed among the auto-resolved items, for the record.
     */
    public function testADoubleTapIsVoidedAutomaticallyWithoutBotheringTheReviewer(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(9, '03:00');
        $secondTap = $race->entry(1, 9, '03:02');

        $result = $race->run('04:00');

        $this->assertLapCounts([9 => 1], $result);
        $this->assertNoOpenFlags($result);
        $this->assertSame([$secondTap], $result->autoVoidedEntryIds);
        $this->assertSame(1, $this->sourceCount($result, 9, 1), 'Counter 1 has one pass for car 9, not two.');

        $autoResolved = $this->autoResolvedFlags($result);
        $this->assertCount(1, $autoResolved);
        $this->assertSame(Flag::REPETIDA, $autoResolved[0]->tipo);
        $this->assertStringContainsString('toque duplo', $autoResolved[0]->motivo);
        $this->assertSame(1, $result->sourceStats[1]->repetidasAnuladas);
    }

    /**
     * SITUATION: counter 1 records car 9 at 03:00 and again at 03:12, on the
     * same phone. Twelve seconds is too long for a bouncing finger and too
     * short for a lap.
     *
     * WHAT WENT WRONG: unclear. Perhaps the counter was unsure the first
     * entry had registered; perhaps the second one was meant for another car.
     *
     * EXPECTED: an open "repetida" flag suggesting to void the second entry.
     * The car still has exactly one lap meanwhile. Accepting the suggestion
     * clears the queue.
     */
    public function testARepeatOutsideTheDoubleTapWindowIsShownToTheReviewer(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(9, '03:00');
        $repeat = $race->entry(1, 9, '03:12');

        $result = $race->run('04:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::REPETIDA);
        $this->assertSame(['tipo' => Decision::ANULAR, 'passagem_id' => $repeat], $flag->sugestao);
        $this->assertStringContainsString('12 s', $flag->motivo);
        $this->assertLapCounts([9 => 1], $result);
        $this->assertSame([], $result->autoVoidedEntryIds, 'Nothing was voided automatically.');

        $race->accept($flag);
        $after = $race->run('04:00');
        $this->assertNoOpenFlags($after);
        $this->assertLapCounts([9 => 1], $after);
    }

    /**
     * SITUATION: the other two counters were slower than counter 1's repeat.
     * Counter 1 records car 9 at 03:00 and again at 03:10; counter 2 records
     * it at 03:09 and counter 3 at 03:11.
     *
     * WHY THIS TEST EXISTS: a careless matcher would pair counter 2 with the
     * first entry and counter 3 with the second, see "two counters agree"
     * twice, and give the car two laps for one crossing.
     *
     * EXPECTED: one lap. Entries from the other counters join the earliest
     * group of that crossing.
     */
    public function testAnUnresolvedRepeatNeverSplitsOneCrossingIntoTwoLaps(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->entry(1, 9, '03:00');
        $race->entry(2, 9, '03:09');
        $race->entry(1, 9, '03:10');
        $race->entry(3, 9, '03:11');

        $result = $race->run('04:00');

        $this->assertLapCounts([9 => 1], $result);
    }

    /**
     * SITUATION: a handover on "Contagem 1". The person leaving (phone A)
     * keeps counting for three laps while the person arriving (phone B)
     * starts counting on the same source. For those laps both phones record
     * every car, a few seconds apart. Counters 2 and 3 work normally.
     *
     * NOTHING WENT WRONG: this overlap is exactly how a handover should be
     * done, so that no car is missed while people swap seats.
     *
     * EXPECTED:
     * - every crossing counts once, not twice;
     * - nothing reaches the review queue, even when the two phones are more
     *   than five seconds apart (different devices on one source is the
     *   signature of a handover);
     * - "Contagem 1" ends with the same number of passes per car as the
     *   other counters.
     */
    public function testTwoPeopleOnTheSameSourceDuringAHandoverCountEachPassOnce(): void
    {
        $phoneOfPersonLeaving = 10;
        $phoneOfPersonArriving = 11;

        $race = RaceScenario::withCounters(3);
        $race->start('00:00');

        $cars = [1, 2, 3, 4, 5];
        // The newcomer is still finding the rhythm: between 2 and 8 seconds
        // behind, depending on the car.
        $newcomerDelaySeconds = [2, 4, 5, 7, 8];
        for ($lap = 0; $lap < 3; $lap++) {
            foreach ($cars as $position => $car) {
                $crossingSeconds = 180 + $lap * 180 + $position * 25;
                $race->entry(1, $car, RaceScenario::clock($crossingSeconds), $phoneOfPersonLeaving);
                $race->entry(1, $car, RaceScenario::clock($crossingSeconds + $newcomerDelaySeconds[$position]), $phoneOfPersonArriving);
                $race->pass($car, RaceScenario::clock($crossingSeconds), [2, 3]);
            }
        }

        $result = $race->run('15:00');

        $this->assertLapCounts([1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3], $result);
        $this->assertNoOpenFlags($result, 'A handover must not create work for the reviewer.');
        foreach ($cars as $car) {
            $this->assertSame(3, $this->sourceCount($result, $car, 1), "Contagem 1 has three passes for car $car.");
            $this->assertSame($this->sourceCount($result, $car, 2), $this->sourceCount($result, $car, 1));
        }
        $this->assertSame(0, $result->sourceStats[1]->faltas);
    }
}
