<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Lap;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * How the engine decides that entries typed by different counters are the
 * same car crossing the line (a "pass group"), and when such a group
 * becomes an official lap.
 *
 * The default rule ("confirmadas"): a pass is official when at least two
 * counters recorded it.
 */
final class MatchingTest extends EngineTestCase
{
    /**
     * SITUATION: four cars cross the line almost together (a "pack"). Each
     * of the three counters types the four numbers, but each in a different
     * order, because nobody can type four numbers at once.
     *
     * NOTHING WENT WRONG here. This is the normal case, and it is the reason
     * entries are matched by car and time rather than by typing order.
     *
     * EXPECTED: every car gets exactly one lap and the review queue is empty.
     */
    public function testAPackTypedInDifferentOrdersByEachCounterCountsEveryCarOnce(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');

        // Counter 1 types them in the order 7, 12, 41, 3.
        $race->entry(1, 7, '03:00');
        $race->entry(1, 12, '03:02');
        $race->entry(1, 41, '03:04');
        $race->entry(1, 3, '03:06');

        // Counter 2 types 12, 7, 3, 41.
        $race->entry(2, 12, '03:00.5');
        $race->entry(2, 7, '03:02.5');
        $race->entry(2, 3, '03:04.5');
        $race->entry(2, 41, '03:06.5');

        // Counter 3 types 41, 3, 7, 12.
        $race->entry(3, 41, '03:01');
        $race->entry(3, 3, '03:03');
        $race->entry(3, 7, '03:05');
        $race->entry(3, 12, '03:07');

        $result = $race->run('04:00');

        $this->assertLapCounts([7 => 1, 12 => 1, 41 => 1, 3 => 1], $result);
        $this->assertNoOpenFlags($result);
        foreach ([1, 2, 3] as $counter) {
            $this->assertSame(0, $result->sourceStats[$counter]->faltas, "Counter $counter missed nothing.");
        }
    }

    /**
     * SITUATION: car 41 crosses the line. Counters 1 and 2 record it.
     * Counter 3 was looking at their phone and did not.
     *
     * WHAT WENT WRONG: one counter missed a car.
     *
     * EXPECTED: the lap counts (two counters agree, that is enough). Nobody
     * is asked to review anything, because there is nothing to decide. The
     * miss is simply added to counter 3's statistics, so that after the race
     * one can see how reliable each counter was.
     */
    public function testALapMissedByOneCounterStillCountsAndIsRecordedAsThatCountersMiss(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(41, '03:00', [1, 2]);

        $result = $race->run('04:00');

        $this->assertLapCounts([41 => 1], $result);
        $this->assertNoOpenFlags($result);
        $this->assertSame(0, $result->sourceStats[1]->faltas);
        $this->assertSame(0, $result->sourceStats[2]->faltas);
        $this->assertSame(1, $result->sourceStats[3]->faltas, 'The miss is charged to counter 3.');
    }

    /**
     * SITUATION: car 8 crosses the line three times, three minutes apart,
     * and everybody records every crossing.
     *
     * EXPECTED: three laps, numbered 1, 2 and 3. Entries more than the
     * matching window (20 seconds) apart are different crossings.
     */
    public function testCrossingsMinutesApartAreSeparateLapsNumberedInOrder(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(8, '03:00');
        $race->pass(8, '06:00');
        $race->pass(8, '09:00');

        $result = $race->run('10:00');

        $this->assertLapCounts([8 => 3], $result);
        $lapNumbers = array_map(fn(Lap $lap) => $lap->numeroVolta, $result->laps);
        $this->assertSame([1, 2, 3], $lapNumbers);
    }

    /**
     * SITUATION: the counters start typing during the formation lap, before
     * race control has started the race. Car 5 is recorded by everybody
     * thirty seconds before the start and again three minutes after it.
     *
     * EXPECTED: only the crossing after the start is a lap. "Lap 1" is the
     * first pass after the start, whatever was typed before.
     */
    public function testEntriesTypedBeforeTheStartAreNotLaps(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(5, '-00:30');
        $race->pass(5, '03:00');

        $result = $race->run('04:00');

        $this->assertLapCounts([5 => 1], $result);
    }

    /**
     * SITUATION: everybody is typing, but race control has not pressed
     * "Largar agora" yet.
     *
     * EXPECTED: nothing is counted and nothing is flagged. Without a start
     * there is no race to count.
     */
    public function testNothingIsCountedWhileTheRaceHasNoStart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->pass(5, '03:00');

        $result = $race->run('04:00');

        $this->assertSame(0, $result->lapCount(5));
        $this->assertSame([], $result->laps);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: only counter 2 records car 9 at minute 3. Counters 1 and 3
     * are online and recorded nothing for it.
     *
     * WHAT WENT WRONG: either counter 2 typed something that did not happen,
     * or two counters missed the same car. The engine cannot know.
     *
     * EXPECTED: the pass does not count yet. It is kept as a "pendente" row
     * (waiting for a person), and a flag asks the reviewer to decide.
     */
    public function testAPassSeenByOnlyOneCounterWaitsForAReviewer(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->entry(2, 9, '03:00');

        $result = $race->run('04:00');

        $this->assertSame(0, $result->lapCount(9));
        $this->assertCount(1, $result->laps);
        $this->assertSame(Lap::PENDENTE, $result->laps[0]->estado);
        $this->assertCount(1, $result->openFlags());
    }
}
