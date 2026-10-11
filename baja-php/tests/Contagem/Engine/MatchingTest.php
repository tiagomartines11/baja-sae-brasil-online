<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
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
 *
 * ONE THING TO KNOW BEFORE READING ANY TEST: the counting line is after the
 * start line, so the first time a car crosses it the car has not completed
 * a lap yet. That first official pass is the car's "pass zero". Laps are
 * counted from there: laps = official passes - 1. Most tests therefore
 * begin with `firstCrossing(...)`, which is every car's pass zero.
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
     * EXPECTED: every car gets exactly one lap (each had crossed once
     * before, its pass zero) and the review queue is empty.
     */
    public function testAPackTypedInDifferentOrdersByEachCounterCountsEveryCarOnce(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 12, 41, 3]);

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
     * SITUATION: car 41 crosses the line (for the second time; everybody
     * recorded its first crossing). Counters 1 and 2 record it. Counter 3
     * was looking at their phone and did not.
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
        $race->firstCrossing([41]);
        $race->pass(41, '03:00', [1, 2]);

        $result = $race->run('04:00');

        $this->assertLapCounts([41 => 1], $result);
        $this->assertNoOpenFlags($result);
        $this->assertSame(0, $result->sourceStats[1]->faltas);
        $this->assertSame(0, $result->sourceStats[2]->faltas);
        $this->assertSame(1, $result->sourceStats[3]->faltas, 'The miss is charged to counter 3.');
    }

    /**
     * SITUATION: car 8 crosses the counting line four times, three minutes
     * apart, and everybody records every crossing.
     *
     * NOTHING WENT WRONG.
     *
     * EXPECTED: four official passes, but THREE laps. The first crossing is
     * pass zero (number 0, state "inicial"): the car came from the start
     * line, which is not a full lap. The next three complete laps 1, 2
     * and 3. Entries more than the matching window (20 seconds) apart are
     * different crossings.
     */
    public function testTheFirstCrossingIsPassZeroAndEachLaterCrossingCompletesALap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(8, '00:40');
        $race->pass(8, '03:40');
        $race->pass(8, '06:40');
        $race->pass(8, '09:40');

        $result = $race->run('10:00');

        $this->assertLapCounts([8 => 3], $result);
        $this->assertSame([0, 1, 2, 3], array_map(fn(Lap $lap) => $lap->numeroVolta, $result->laps));
        $this->assertSame(
            [Lap::INICIAL, Lap::CONTADA, Lap::CONTADA, Lap::CONTADA],
            array_map(fn(Lap $lap) => $lap->estado, $result->laps)
        );
        $this->assertSame([0, 1, 2, 3], array_map(fn($group) => $group->numeroVolta, $result->groups));
        $this->assertSame(RaceScenario::ms('09:40'), $result->cars[8]->ultimaPassagemMs);
    }

    /**
     * SITUATION: car 8 has crossed the line once, 40 seconds after the start.
     *
     * EXPECTED: zero laps, but the car is not invisible: it has an official
     * pass (its pass zero), and that is its "last pass" for now.
     */
    public function testACarThatCrossedOnlyOnceHasZeroLapsButAKnownLastPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(8, '00:40');

        $result = $race->run('02:00');

        $this->assertLapCounts([8 => 0], $result);
        $this->assertSame(RaceScenario::ms('00:40'), $result->cars[8]->ultimaPassagemMs);
        $this->assertNull($result->cars[8]->posicao, 'A car with no laps has no position in the standings.');
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the counters start typing during the formation lap, before
     * race control has started the race. Car 5 is recorded by everybody
     * thirty seconds before the start, then twenty seconds after it, then
     * three minutes after it.
     *
     * EXPECTED: what was typed before the start does not exist for the
     * count. It is not even the car's pass zero: pass zero is the crossing
     * at 00:20, and the crossing at 03:00 completes lap 1.
     */
    public function testEntriesTypedBeforeTheStartAreNeitherLapsNorPassZero(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $beforeTheStart = $race->pass(5, '-00:30');
        $race->pass(5, '00:20');
        $race->pass(5, '03:00');

        $result = $race->run('04:00');

        $this->assertLapCounts([5 => 1], $result);
        $this->assertSame(RaceScenario::ms('00:20'), $result->laps[0]->tsMs, 'Pass zero is the first crossing AFTER the start.');
        $this->assertNull($result->groupOfEntry($beforeTheStart[1]));
    }

    /**
     * SITUATION: the counting line is right at the start line and car 5 is
     * typed by everybody at the very millisecond of the start; a second
     * car, 6, one millisecond before it.
     *
     * EXPECTED: "after the start" includes the start instant itself. Car
     * 5's entry is its pass zero; car 6's does not exist for the count.
     */
    public function testAnEntryAtTheExactInstantOfTheStartBelongsToTheRace(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('01:00');
        $atTheStart = $race->pass(5, '01:00');
        $justBefore = $race->pass(6, '00:59.999');

        $result = $race->run('02:00');

        $this->assertSame(0, $result->groupOfEntry($atTheStart[1])->numeroVolta);
        $this->assertNull($result->groupOfEntry($justBefore[1]));
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
     * SITUATION: car 9 made its first crossing normally. At minute 3 only
     * counter 2 records it again. Counters 1 and 3 are online and recorded
     * nothing for it.
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
        $race->firstCrossing([9]);
        $race->entry(2, 9, '03:00');

        $result = $race->run('04:00');

        $this->assertSame(0, $result->lapCount(9));
        $this->assertCount(2, $result->laps, 'Pass zero, and the pass that is waiting.');
        $this->assertSame(Lap::PENDENTE, $result->laps[1]->estado);
        $this->assertSame(RaceScenario::ms('00:20'), $result->cars[9]->ultimaPassagemMs, 'A waiting pass is not the car\'s last pass.');
        $this->assertCount(1, $result->openFlags());
    }

    /**
     * SITUATION: car 9 crosses and counter 1 presses Enter at 03:00.
     * Counter 2 is slow. In the first race counter 2 presses Enter exactly
     * 20 seconds later; in the second race, one millisecond after that.
     *
     * WHY 20 SECONDS: that is the "matching window" (`janela_ms`). Entries
     * for the same car inside it describe the same crossing.
     *
     * EXPECTED: at exactly 20.000 s the two entries are one crossing and the
     * lap counts. At 20.001 s they are two unrelated lone entries, nothing
     * counts, and both are shown to the reviewer.
     */
    public function testTheMatchingWindowIsTwentySecondsInclusive(): void
    {
        $insideTheWindow = RaceScenario::withCounters(2);
        $insideTheWindow->start('00:00');
        $insideTheWindow->firstCrossing([9]);
        $insideTheWindow->entry(1, 9, '03:00');
        $insideTheWindow->entry(2, 9, '03:20');
        $inside = $insideTheWindow->run('05:00');
        $this->assertLapCounts([9 => 1], $inside);
        $this->assertCount(2, $inside->groups);

        $justOutside = RaceScenario::withCounters(2);
        $justOutside->start('00:00');
        $justOutside->firstCrossing([9]);
        $justOutside->entry(1, 9, '03:00');
        $justOutside->entry(2, 9, '03:20.001');
        $outside = $justOutside->run('05:00');
        $this->assertLapCounts([9 => 0], $outside);
        $this->assertCount(3, $outside->groups);
        $this->assertCount(2, $this->openFlags($outside, Flag::SOLITARIA));
    }

    /**
     * SITUATION: the organisers set a narrower matching window of 5 seconds
     * (`janela_ms` = 5000). Counter 1 records car 9 at 03:00 and counter 2
     * at 03:06.
     *
     * EXPECTED: with the default 20 seconds these are one crossing; with
     * 5 seconds they are not, and the lap does not count. The window is the
     * setting, not a number buried in the code.
     */
    public function testTheMatchingWindowFollowsTheSetting(): void
    {
        foreach ([20000 => 1, 5000 => 0] as $janelaMs => $expectedLaps) {
            $race = RaceScenario::withCounters(2);
            $race->settings->janelaMs = $janelaMs;
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->entry(1, 9, '03:00');
            $race->entry(2, 9, '03:06');

            $this->assertLapCounts([9 => $expectedLaps], $race->run('05:00'), "With a window of $janelaMs ms.");
        }
    }
}
