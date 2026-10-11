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
     * was distracted and pressed Enter six seconds late.
     *
     * EXPECTED: the pass time of that crossing is still 09:00 sharp. The
     * fused time is the middle value (median) of the three, so one late
     * press cannot drag it. The spread between counters (6 s) is remembered,
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
        $race->entry(3, 7, '09:06');
        $race->pass(7, '12:00');

        $result = $race->run('13:00');

        $lapEndingThere = $result->laps[2];
        $this->assertSame(RaceScenario::ms('09:00'), $lapEndingThere->tsMs);
        $this->assertSame(180000, $lapEndingThere->tempoVoltaMs);
        $this->assertSame(6000, $lapEndingThere->dispersaoMs);
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
