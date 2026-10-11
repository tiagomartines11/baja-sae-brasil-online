<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

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
 * difference between two consecutive pass times of the same car.
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
     * and it makes the laps touching that crossing ineligible for best lap.
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

        $third = $result->laps[2];
        $this->assertSame(RaceScenario::ms('09:00'), $third->tsMs);
        $this->assertSame(180000, $third->tempoVoltaMs);
        $this->assertSame(6000, $third->dispersaoMs);
        $this->assertFalse($third->elegivelMelhorVolta, 'The lap ending at the disputed crossing is not eligible.');
        $this->assertFalse($result->laps[3]->elegivelMelhorVolta, 'Nor is the lap that starts from it.');
        $this->assertTrue($result->laps[1]->elegivelMelhorVolta, 'A lap with both ends agreed is eligible.');
    }

    /**
     * SITUATION: counter 3 has a habit: always three seconds late, on every
     * single car. Counters 1 and 2 are on time.
     *
     * WHY IT MATTERS: taken at face value, the three counters never agree
     * within the 2 seconds required for a best lap, so no lap would ever be
     * eligible.
     *
     * EXPECTED: the engine measures each counter's habitual offset (the
     * median of "their time minus the group's time" over their recent
     * entries; here +3000 ms for counter 3) and removes it. After that the
     * three agree exactly, the pass times are those of the punctual
     * counters, and the laps are eligible.
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
        foreach ($result->laps as $lap) {
            $this->assertSame(0, $lap->dispersaoMs, 'With the habit removed the counters agree.');
        }
        $this->assertSame(RaceScenario::ms('06:00'), $result->laps[1]->tsMs);
        $this->assertTrue($result->laps[1]->elegivelMelhorVolta);
    }

    /**
     * SITUATION: car 7 does four laps:
     *
     *   lap 1  00:00 -> 02:00   from the start of the race
     *   lap 2  02:00 -> 05:00   180 s, everybody agrees
     *   lap 3  05:00 -> 07:50   170 s, but the crossing at 07:50 was seen
     *                           by one counter only and confirmed by a
     *                           reviewer
     *   lap 4  07:50 -> 10:50   180 s, starting from that same crossing
     *
     * EXPECTED:
     * - Lap 1 is never eligible: the counting line is not always the start
     *   line, so "start to first crossing" is not a full lap.
     * - Lap 2 is eligible.
     * - Laps 3 and 4 are not: one of their ends rests on a single counter's
     *   Enter press, which is not precise enough to announce a record.
     * - So the car's best lap is lap 2 (180 s), although lap 3 looks faster.
     */
    public function testLapOneAndLapsWithAWeaklyAgreedEndAreNotEligibleForBestLap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '02:00');
        $race->pass(7, '05:00');
        $loneCrossing = $race->entry(1, 7, '07:50');
        $race->confirm($loneCrossing);
        $race->pass(7, '10:50');

        $result = $race->run('12:00');

        $this->assertLapCounts([7 => 4], $result);
        $this->assertSame(120000, $result->laps[0]->tempoVoltaMs, 'Lap 1 is measured from the start.');
        $this->assertFalse($result->laps[0]->elegivelMelhorVolta);
        $this->assertTrue($result->laps[1]->elegivelMelhorVolta);
        $this->assertSame(170000, $result->laps[2]->tempoVoltaMs);
        $this->assertFalse($result->laps[2]->elegivelMelhorVolta);
        $this->assertFalse($result->laps[3]->elegivelMelhorVolta);
        $this->assertSame(180000, $result->cars[7]->melhorVoltaMs);
        $this->assertSame(180000, $result->cars[7]->ultimaVoltaMs);
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

        $this->assertSame(RaceScenario::ms('03:00'), $result->laps[0]->tsMs);
        $this->assertSame(177000, $result->laps[1]->tempoVoltaMs, 'Exactly the machine\'s 05:57 minus 03:00.');
        $this->assertSame(183000, $result->laps[2]->tempoVoltaMs);
        $this->assertTrue($result->laps[1]->elegivelMelhorVolta);
        $this->assertSame(177000, $result->cars[7]->melhorVoltaMs);
    }

    /**
     * SITUATION: car 7's best eligible lap is 177.6 seconds and its last
     * counted pass was 540.9 seconds after the start.
     *
     * EXPECTED: what goes to the scoring formulas and the results page is in
     * whole seconds: BEST = 178, LAST = 540. Hand timing does not justify
     * decimals. A car with no eligible lap publishes BEST = 0.
     */
    public function testPublishedTimesAreWholeSeconds(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '03:00');
        $race->pass(7, '05:57.6');
        $race->pass(7, '09:00.9');
        $race->pass(8, '04:00');

        $result = $race->run('10:00');

        $this->assertSame(
            ['VOLTAS' => 3, 'LAST' => 540, 'BEST' => 178],
            PublishedValues::forCar($result->cars[7], $result->timeline)
        );
        $this->assertSame(
            ['VOLTAS' => 1, 'LAST' => 240, 'BEST' => 0],
            PublishedValues::forCar($result->cars[8], $result->timeline)
        );
    }
}
