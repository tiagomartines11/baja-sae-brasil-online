<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Lap times from hand-typed entries.
 *
 * Counters are trained to type the number as the car approaches and press
 * Enter as it crosses the line, so the time of an entry is the Enter press.
 * Three people pressing Enter give three slightly different times. The
 * engine combines ("fuses") them into one pass time, and a lap time is the
 * difference between two consecutive pass times of the same car. The car's
 * first crossing is its pass zero; lap 1 runs from there to the second.
 *
 * Hand timing is only good to about a second, so only laps whose two ends
 * are well agreed on may be announced as a "best lap".
 */
final class LapTimeTest extends EngineTestCase
{
    /**
     * SITUATION: car 7 crosses the line every three minutes and everybody
     * presses Enter on time, except once: at the third crossing counter 3
     * was distracted and pressed Enter three seconds late. (Three seconds
     * is still "the same crossing"; beyond `dispersao_alerta_ms`, 4 s, the
     * entry would be set apart from the other two: see MatchingTest.)
     *
     * EXPECTED: the pass time of that crossing is still 09:00 sharp. The
     * fused time is the middle value (median) of the three, so one late
     * press cannot drag it. The spread between counters (3 s) is remembered,
     * and it makes the two laps touching that crossing (lap 2, which ends
     * there, and lap 3, which starts there) ineligible for best lap.
     */
    public function testOneLateTypistDoesNotMoveTheFusedPassTime(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '03:00');
        $race->pass(7, '06:00');
        $race->pass(7, '09:00', [1, 2]);
        $race->entry(3, 7, '09:03');
        $race->pass(7, '12:00');

        $result = $race->run('13:00');

        $lapEndingThere = $result->laps[2];
        $this->assertSame(3, $lapEndingThere->fontes, 'All three entries are one pass.');
        $this->assertSame(RaceScenario::ms('09:00'), $lapEndingThere->tsMs);
        $this->assertSame(180000, $lapEndingThere->tempoVoltaMs);
        $this->assertSame(3000, $lapEndingThere->dispersaoMs);
        $this->assertFalse($lapEndingThere->elegivelMelhorVolta, 'The lap ending at the disputed crossing is not eligible.');
        $this->assertFalse($result->laps[3]->elegivelMelhorVolta, 'Nor is the lap that starts from it.');
        $this->assertTrue($result->laps[1]->elegivelMelhorVolta, 'A lap with both ends agreed is eligible.');
    }

    /**
     * SITUATION: counter 3 has a habit: always three seconds late, on every
     * single car. Counters 1 and 2 are on time. Car 7 crosses six times.
     *
     * WHY IT MATTERS: taken at face value, the three counters never agree
     * within the 2 seconds required for a best lap, so no lap would ever be
     * eligible.
     *
     * EXPECTED: the engine measures each counter's habit (the median of
     * "their time minus the pass time" over their recent entries; here
     * +3000 ms for counter 3) and removes it before judging agreement.
     *
     * The habit is learnt as the race goes: at the very first crossing
     * nothing is known about counter 3 yet, so that crossing still shows a
     * spread of 3 seconds and the lap starting from it is not eligible.
     * From the second crossing on the three agree exactly.
     */
    public function testACountersHabitualOffsetIsMeasuredAndRemoved(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        for ($crossing = 1; $crossing <= 6; $crossing++) {
            $seconds = $crossing * 180;
            $race->pass(7, RaceScenario::clock($seconds), [1, 2]);
            $race->entry(3, 7, RaceScenario::clock($seconds + 3));
        }

        $result = $race->run('20:00');

        $this->assertSame(3000, $result->sourceStats[3]->desvioMedianoMs);
        $this->assertSame(0, $result->sourceStats[1]->desvioMedianoMs);
        $spreads = array_map(fn(Lap $lap) => $lap->dispersaoMs, $result->laps);
        $this->assertSame([3000, 0, 0, 0, 0, 0], $spreads, 'Only the first crossing, before the habit was known, shows the 3 s.');
        $this->assertSame(RaceScenario::ms('06:00'), $result->laps[1]->tsMs);
        $this->assertFalse($result->laps[1]->elegivelMelhorVolta, 'Lap 1 starts at the crossing whose spread is 3 s.');
        $this->assertTrue($result->laps[2]->elegivelMelhorVolta);
    }

    /**
     * SITUATION: the same late counter 3. Car 7 crosses every three minutes.
     * At the sixth crossing (18:00) counter 1 misses the car, so that
     * crossing rests on counter 2 (on time) and counter 3 (three seconds
     * late).
     *
     * WHY IT MATTERS: the plain middle of those two times is 18:01.5. Taken
     * as it is, the lap ending there would last 181.5 s and the next one
     * 178.5 s, only because of WHO happened to record the crossing.
     *
     * EXPECTED: two different times are kept for the crossing.
     * - The pass time (what is published as "last pass") is the plain
     *   middle value, 18:01.5. It depends on nothing but these two entries.
     * - Lap durations use the times with each counter's habit removed, so
     *   both laps last exactly 180 s.
     */
    public function testLapDurationsDoNotDependOnWhichCountersRecordedEachEnd(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        for ($crossing = 1; $crossing <= 7; $crossing++) {
            $seconds = $crossing * 180;
            $race->pass(7, RaceScenario::clock($seconds), $crossing === 6 ? [2] : [1, 2]);
            $race->entry(3, 7, RaceScenario::clock($seconds + 3));
        }

        $result = $race->run('22:00');

        $sixthCrossing = $result->laps[5];
        $this->assertSame(RaceScenario::ms('18:01.5'), $sixthCrossing->tsMs, 'The pass time is the middle of the two times as typed.');
        $this->assertSame(RaceScenario::ms('18:00'), $sixthCrossing->grupo->tsAjustadoMs);
        $this->assertSame(180000, $sixthCrossing->tempoVoltaMs, 'The lap ending at that crossing.');
        $this->assertSame(180000, $result->laps[6]->tempoVoltaMs, 'The lap starting from it.');
        $this->assertSame(0, $sixthCrossing->dispersaoMs);
    }

    /**
     * SITUATION: counter 3 is always three seconds late, as above. Car 7
     * crosses every three minutes from 03:00. At the fourth crossing
     * (12:00) counters 1 and 2 both miss it: only counter 3 has it, typed
     * at 12:03 as is its habit, and a reviewer confirms the pass. Between
     * that crossing and the next, counter 3 also types forty stray numbers
     * that nobody else has (forty lone entries). Car 7 crosses again at
     * 15:00, recorded by everybody.
     *
     * EXPECTED, for a pass that rests on a SINGLE entry:
     * - its pass time is the entry as typed (12:03), there being nothing
     *   else to go by;
     * - for lap durations the counter's habit is removed all the same:
     *   12:00, so both laps around it last exactly 180 s, not 183 and 177;
     * - a single entry has no spread;
     * - and a single entry teaches nothing about its counter: there is
     *   nothing to compare it with. If the forty lone entries were taken as
     *   "counter 3 was exactly on time forty times", its habit would drop
     *   to zero and the crossing at 15:00 would show a 3-second
     *   disagreement. It shows none, and the habit is still 3000 ms.
     */
    public function testAPassWithASingleEntryHasItsCountersHabitRemovedAndTeachesNothing(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        foreach ([180, 360, 540] as $seconds) {
            $race->pass(7, RaceScenario::clock($seconds), [1, 2]);
            $race->entry(3, 7, RaceScenario::clock($seconds + 3));
        }
        $onlyCounter3 = $race->entry(3, 7, '12:03');
        $race->confirm($onlyCounter3);
        for ($stray = 0; $stray < 40; $stray++) {
            $race->entry(3, 100 + $stray, RaceScenario::clock(730 + $stray));
        }
        $race->pass(7, '15:00', [1, 2]);
        $race->entry(3, 7, '15:03');

        $result = $race->run('16:00');

        $lapsOfCar7 = array_values(array_filter($result->laps, fn(Lap $lap) => $lap->carro === 7));
        $this->assertCount(5, $lapsOfCar7);
        $single = $lapsOfCar7[3];
        $this->assertSame(1, $single->fontes);
        $this->assertSame(RaceScenario::ms('12:03'), $single->tsMs, 'The pass time is the entry as typed.');
        $this->assertSame(RaceScenario::ms('12:00'), $single->grupo->tsAjustadoMs, 'For durations, the habit is removed.');
        $this->assertSame(0, $single->dispersaoMs);
        $this->assertSame(180000, $single->tempoVoltaMs, 'The lap ending at the single entry.');
        $this->assertSame(180000, $lapsOfCar7[4]->tempoVoltaMs, 'The lap starting from it.');
        $this->assertSame(0, $lapsOfCar7[4]->dispersaoMs, 'At 15:00 the three counters still agree once the habit is removed.');
        $this->assertSame(3000, $result->sourceStats[3]->desvioMedianoMs, 'Forty lone entries did not dilute the habit.');
    }

    /**
     * SITUATION: counter 3 is three seconds late by habit (learnt over five
     * crossings of car 7). Then car 8 crosses at 20:00: counter 1 types it
     * at 20:00, counter 2 at 20:01 and counter 3, for once quicker than its
     * habit, at 20:02.
     *
     * EXPECTED: as typed, the order is 1, 2, 3 and the middle time is
     * 20:01, which is the pass time. With the habit removed, counter 3's
     * entry becomes 19:59 and is now the EARLIEST of the three: the order
     * changed, so the corrected times must be put in order again before
     * the middle one is taken. Corrected time 20:00; spread 2 s (19:59 to
     * 20:01), not 1 s and not negative.
     */
    public function testRemovingAHabitCanChangeTheOrderOfTheThreeTimes(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        for ($crossing = 1; $crossing <= 5; $crossing++) {
            $race->pass(7, RaceScenario::clock($crossing * 180), [1, 2]);
            $race->entry(3, 7, RaceScenario::clock($crossing * 180 + 3));
        }
        $race->entry(1, 8, '20:00');
        $race->entry(2, 8, '20:01');
        $lastTyped = $race->entry(3, 8, '20:02');

        $group = $race->run('21:00')->groupOfEntry($lastTyped);

        $this->assertSame(RaceScenario::ms('20:01'), $group->tsMs);
        $this->assertSame(RaceScenario::ms('20:00'), $group->tsAjustadoMs);
        $this->assertSame(2000, $group->dispersaoMs);
    }

    /**
     * SITUATION: a race with FOUR counters. Car 7 crosses and they type it
     * at 03:00, 03:01, 03:02 and 03:04. (Nothing is known about anybody's
     * habit yet, so the corrected times are the times as typed.)
     *
     * EXPECTED: with an even number of times there is no middle one; the
     * median is halfway between the two in the middle: 03:01.5, for the
     * pass time and for the corrected time alike. The spread is the full
     * four seconds.
     */
    public function testWithFourCountersTheMedianIsHalfwayBetweenTheMiddleTwo(): void
    {
        $race = RaceScenario::withCounters(4);
        $race->start('00:00');
        $first = $race->entry(1, 7, '03:00');
        $race->entry(2, 7, '03:01');
        $race->entry(3, 7, '03:02');
        $race->entry(4, 7, '03:04');

        $group = $race->run('04:00')->groupOfEntry($first);

        $this->assertCount(4, $group->entries);
        $this->assertSame(RaceScenario::ms('03:01.5'), $group->tsMs);
        $this->assertSame(RaceScenario::ms('03:01.5'), $group->tsAjustadoMs);
        $this->assertSame(4000, $group->dispersaoMs);
    }

    /**
     * SITUATION: an automated timing system (a "precisa" source, id 4)
     * times car 7 exactly, and counter 1 habitually types three seconds
     * after it (learnt over five crossings). At 20:00 the system records
     * the car and counter 1 types it at 20:01, quicker than usual. Counter
     * 1 then types the car AGAIN at 20:02 and a reviewer keeps that second
     * entry ("keep both"), so it is a pass of its own.
     *
     * WHY IT MATTERS: for durations, counter 1's habit is removed: its
     * lone second entry is placed at 19:59, one second BEFORE the pass at
     * 20:00 that it follows. Taken literally the lap between the two would
     * last minus one second.
     *
     * EXPECTED: a lap time is never negative. That lap lasts 0.
     */
    public function testALapTimeIsNeverNegative(): void
    {
        $race = RaceScenario::withCounters(1);
        $race->addSource(4, 'Cronometragem', \Baja\Contagem\Engine\Data\Source::TIPO_AUTOMATICA, \Baja\Contagem\Engine\Data\Source::TEMPO_PRECISA);
        $race->start('00:00');
        for ($crossing = 1; $crossing <= 5; $crossing++) {
            $race->entry(4, 7, RaceScenario::clock($crossing * 180));
            $race->entry(1, 7, RaceScenario::clock($crossing * 180 + 3));
        }
        $race->entry(4, 7, '20:00');
        $race->entry(1, 7, '20:01');
        $again = $race->entry(1, 7, '20:02');
        $race->confirm($again);

        $result = $race->run('21:00');

        $group = $result->groupOfEntry($again);
        $this->assertSame(3000, $result->sourceStats[1]->desvioMedianoMs);
        $this->assertSame(RaceScenario::ms('19:59'), $group->tsAjustadoMs);
        $lapEndingThere = array_values(array_filter($result->laps, fn(Lap $lap) => $lap->grupo === $group))[0];
        $this->assertSame(0, $lapEndingThere->tempoVoltaMs);
    }

    /**
     * SITUATION: an automated timing system (a "precisa" source, id 4)
     * records car 7 at 03:00.000 with nobody else, and the three human
     * counters are switched off.
     *
     * EXPECTED: a precise source's single entry is taken exactly as it is,
     * for the pass time and for lap durations alike; the group is marked as
     * precisely timed.
     */
    public function testASingleEntryFromAPreciseSourceIsTakenAsItIs(): void
    {
        $race = RaceScenario::withCounters(1);
        $race->addSource(4, 'Cronometragem', \Baja\Contagem\Engine\Data\Source::TIPO_AUTOMATICA, \Baja\Contagem\Engine\Data\Source::TEMPO_PRECISA);
        $race->deactivateSource(1, null);
        $race->start('00:00');
        $first = $race->entry(4, 7, '00:20');
        $race->entry(4, 7, '03:00');

        $result = $race->run('04:00');

        $this->assertLapCounts([7 => 1], $result);
        $this->assertTrue($result->groupOfEntry($first)->tempoPreciso);
        $this->assertSame(RaceScenario::ms('03:00'), $result->laps[1]->grupo->tsMs);
        $this->assertSame(RaceScenario::ms('03:00'), $result->laps[1]->grupo->tsAjustadoMs);
        $this->assertSame(160000, $result->laps[1]->tempoVoltaMs);
    }

    /**
     * SITUATION: car 7 crosses the line four times:
     *
     *   pass 0  02:00            first crossing, completes no lap
     *   lap 1   02:00 -> 05:00   180 s, everybody agrees on both ends
     *   lap 2   05:00 -> 07:50   170 s, but the crossing at 07:50 was seen
     *                            by one counter only and confirmed by a
     *                            reviewer
     *   lap 3   07:50 -> 10:50   180 s, starting from that same crossing
     *
     * EXPECTED:
     * - Lap 1 IS eligible for best lap. It runs from one crossing of the
     *   counting line to the next, so it is a full lap like any other.
     *   (Nothing is measured from the start time.)
     * - Laps 2 and 3 are not: one of their ends rests on a single counter's
     *   Enter press, which is not precise enough to announce a record.
     * - So the car's best lap is lap 1 (180 s), although lap 2 looks faster.
     */
    public function testLapOneIsAFullLapAndLapsWithAWeaklyAgreedEndAreNotEligibleForBestLap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '02:00');
        $race->pass(7, '05:00');
        $loneCrossing = $race->entry(1, 7, '07:50');
        $race->confirm($loneCrossing);
        $race->pass(7, '10:50');

        $result = $race->run('12:00');

        $this->assertLapCounts([7 => 3], $result);
        $this->assertNull($result->laps[0]->tempoVoltaMs, 'Pass zero has no lap time.');
        $this->assertFalse($result->laps[0]->elegivelMelhorVolta);
        $this->assertSame(180000, $result->laps[1]->tempoVoltaMs);
        $this->assertTrue($result->laps[1]->elegivelMelhorVolta);
        $this->assertSame(170000, $result->laps[2]->tempoVoltaMs);
        $this->assertFalse($result->laps[2]->elegivelMelhorVolta);
        $this->assertFalse($result->laps[3]->elegivelMelhorVolta);
        $this->assertSame(180000, $result->cars[7]->melhorVoltaMs);
        $this->assertSame(180000, $result->cars[7]->ultimaVoltaMs);
    }

    /**
     * SITUATION: two counters only. They agree to the second on car 7's
     * first two crossings. At the third, counter 2 pressed Enter 2.0 s after
     * counter 1; at the fourth, 2.001 s after.
     *
     * EXPECTED: sources must agree within `dispersao_max_ms` (2000 ms) for a
     * crossing to be "well timed". Exactly 2000 ms still qualifies, so lap 2
     * (ending at the third crossing) is eligible. 2001 ms does not, so lap 3
     * is not eligible.
     */
    public function testAgreementWithinTwoSecondsInclusiveIsGoodEnoughForABestLap(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->pass(7, '03:00');
        $race->pass(7, '06:00');
        $race->entry(1, 7, '09:00');
        $race->entry(2, 7, '09:02');
        $race->pass(7, '12:00');
        $race->entry(1, 7, '15:00');
        $race->entry(2, 7, '15:02.001');

        $result = $race->run('16:00');

        $this->assertSame(2000, $result->laps[2]->dispersaoMs);
        $this->assertTrue($result->laps[2]->elegivelMelhorVolta);
        $this->assertSame(2001, $result->laps[4]->dispersaoMs);
        $this->assertFalse($result->laps[4]->elegivelMelhorVolta);
    }

    /**
     * SITUATION: next to the three humans there is an automated system
     * (a source with timing grade "precisa"). At each crossing of car 7 the
     * humans are 0.8, 1.2 and 4.5 seconds after the machine.
     *
     * EXPECTED: the machine's time is used as it is, and the humans' times
     * are ignored for timing (they still count as agreement that the car
     * passed). Laps are eligible for best lap even though the humans are
     * spread over almost four seconds, because a precise source vouches
     * for both ends.
     */
    public function testAPreciseSourceOverridesTheHumanTimes(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->addSource(4, 'Sistema automático', Source::TIPO_AUTOMATICA, Source::TEMPO_PRECISA);
        $race->start('00:00');
        foreach ([180, 357, 540] as $machineSeconds) {
            $race->entry(4, 7, RaceScenario::clock($machineSeconds));
            $race->entry(1, 7, RaceScenario::clock($machineSeconds) . '.8');
            $race->entry(2, 7, RaceScenario::clock($machineSeconds + 1) . '.2');
            $race->entry(3, 7, RaceScenario::clock($machineSeconds + 4) . '.5');
        }

        $result = $race->run('10:00');

        $this->assertSame(RaceScenario::ms('03:00'), $result->laps[0]->tsMs, 'Pass zero, at the machine\'s time.');
        $this->assertSame(177000, $result->laps[1]->tempoVoltaMs, 'Exactly the machine\'s 05:57 minus 03:00.');
        $this->assertSame(183000, $result->laps[2]->tempoVoltaMs);
        $this->assertTrue($result->laps[1]->elegivelMelhorVolta);
        $this->assertSame(177000, $result->cars[7]->melhorVoltaMs);
    }

    /**
     * SITUATION: car 7's first crossing is at 03:00, the next at 05:57.6
     * (a lap of 177.6 s) and the last at 09:00.9, which is 540.9 seconds
     * after the start. Car 8 has crossed once.
     *
     * EXPECTED: what goes to the scoring formulas and the results page is in
     * whole seconds, rounded to the NEAREST second (not cut off): BEST = 178
     * and LAST = 541. Hand timing does not justify decimals. Car 8 has no
     * lap yet: VOLTAS = 0 and BEST = 0, but its LAST is already known.
     */
    public function testPublishedTimesAreRoundedToWholeSeconds(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '03:00');
        $race->pass(7, '05:57.6');
        $race->pass(7, '09:00.9');
        $race->pass(8, '04:00.4');

        $result = $race->run('10:00');

        $this->assertSame(
            ['VOLTAS' => 2, 'LAST' => 541, 'BEST' => 178],
            PublishedValues::forCar($result->cars[7], $result->timeline)
        );
        $this->assertSame(
            ['VOLTAS' => 0, 'LAST' => 240, 'BEST' => 0],
            PublishedValues::forCar($result->cars[8], $result->timeline)
        );
    }

    /**
     * SITUATION: a car that never crossed the line, and a race that has not
     * been started.
     *
     * EXPECTED: all three published numbers are 0. There is nothing to
     * measure a "last pass" from.
     */
    public function testACarWithNoPassPublishesZeros(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([7]);
        $race->start('00:00');
        $started = $race->run('10:00');
        $this->assertSame(['VOLTAS' => 0, 'LAST' => 0, 'BEST' => 0], PublishedValues::forCar($started->cars[7], $started->timeline));

        $notStarted = RaceScenario::withCounters(3);
        $notStarted->teamsPresent([7]);
        $notStarted->pass(7, '03:00');
        $waiting = $notStarted->run('10:00');
        $this->assertSame(['VOLTAS' => 0, 'LAST' => 0, 'BEST' => 0], PublishedValues::forCar($waiting->cars[7], $waiting->timeline));
    }
}
