<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Engine;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;

/**
 * Published times must not flicker.
 *
 * The engine recomputes the whole race every time something is uploaded.
 * If the time of a pass at minute 10 depended on what a counter types at
 * minute 50, a lap time shown on the results page would change by a second
 * now and then for no visible reason.
 *
 * So: the time of a pass depends only on that pass's own entries, and a
 * counter's typing habit (see LapTimeTest) is measured only from what that
 * counter typed BEFORE the pass.
 */
final class PassTimeStabilityTest extends EngineTestCase
{
    /**
     * SITUATION: car 7 laps every three minutes. In the first half hour
     * counter 3 presses Enter on time; some crossings are recorded by
     * counters 2 and 3 only. We note every pass time and lap time at 30:00.
     *
     * Then the race goes on for another hour, and counter 3 (a different
     * person now, say) presses Enter four seconds late on every car.
     *
     * WHAT COULD GO WRONG: by the end, counter 3's habit is "+4 s". An
     * engine that applied that habit to the whole race would move the
     * early crossings recorded by counters 2 and 3 two seconds earlier, and
     * change the lap times around them.
     *
     * EXPECTED: at 90:00 every pass time, corrected time, spread and lap
     * time of the first half hour is exactly what it was at 30:00.
     */
    public function testWhatIsTypedLaterNeverMovesAnEarlierPassOrLapTime(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        for ($crossing = 0; $crossing < 10; $crossing++) {
            $at = RaceScenario::clock(60 + $crossing * 180);
            $race->pass(7, $at, $crossing % 3 === 1 ? [2, 3] : [1, 2, 3]);
        }

        $atHalfAnHour = $this->timesOfEveryPass($race->run('30:00'), RaceScenario::ms('30:00'));
        $this->assertCount(10, $atHalfAnHour);

        for ($crossing = 10; $crossing < 30; $crossing++) {
            $seconds = 60 + $crossing * 180;
            $race->pass(7, RaceScenario::clock($seconds), [1, 2]);
            $race->entry(3, 7, RaceScenario::clock($seconds + 4));
        }
        $atTheEnd = $race->run('90:00');

        $this->assertSame(4000, $atTheEnd->sourceStats[3]->desvioMedianoMs, 'By the end counter 3 is known to type four seconds late.');
        $this->assertSame($atHalfAnHour, $this->timesOfEveryPass($atTheEnd, RaceScenario::ms('30:00')));
    }

    /**
     * SITUATION: a whole synthetic race with every kind of mistake, looked
     * at after 60 minutes and again when it is over.
     *
     * EXPECTED: every pass older than 59 minutes has, at the end, exactly
     * the times it had at minute 60. (The last minute is left out because a
     * counter typing late may still add an entry to those crossings, which
     * legitimately changes them.)
     */
    public function testInAWholeRaceTheFirstHourLooksTheSameAtTheEnd(): void
    {
        foreach ([1, 2, 3] as $seed) {
            $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed));
            $afterAnHourMs = SyntheticRace::START_MS + 60 * 60000;
            $settledBeforeMs = $afterAnHourMs - 60000;

            $then = $this->timesOfEveryPass(Engine::run($race->input($afterAnHourMs)), $settledBeforeMs);
            $atTheEnd = $this->timesOfEveryPass(Engine::run($race->input()), $settledBeforeMs);

            $this->assertGreaterThan(300, count($then));
            $this->assertSame($then, $atTheEnd, "Seed $seed.");
        }
    }

    /**
     * @return array<int, string> for every pass group that began before $beforeMs, keyed by its first entry id:
     *   "pass time / habit-corrected time / spread / lap time ending there"
     */
    private function timesOfEveryPass(EngineResult $result, int $beforeMs): array
    {
        $lapTimeByGroup = [];
        foreach ($result->laps as $lap) {
            if ($lap->grupo !== null) {
                $lapTimeByGroup[$lap->grupo->anchorEntryId()] = $lap->tempoVoltaMs;
            }
        }

        $times = [];
        foreach ($result->groups as $group) {
            if ($group->primeiroTsMs >= $beforeMs) {
                continue;
            }
            $anchor = $group->anchorEntryId();
            $times[$anchor] = sprintf(
                '%d / %d / %d / %s',
                $group->tsMs,
                $group->tsAjustadoMs,
                $group->dispersaoMs,
                var_export($lapTimeByGroup[$anchor] ?? null, true)
            );
        }
        ksort($times);
        return $times;
    }

    /**
     * SITUATION: counter 3 presses Enter on time for twenty crossings, and
     * then four seconds late from the twenty-first crossing on.
     *
     * EXPECTED: a counter's habit is the middle value of its last THIRTY
     * matched entries. After ten late entries, twenty of the thirty are
     * still on time: the habit is 0. After sixteen late entries the late
     * ones are the majority of the thirty: the habit is 4 s. A habit is
     * slow to change on purpose; a few odd entries must not move lap times.
     */
    public function testAHabitIsMeasuredOverTheLastThirtyEntries(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        for ($crossing = 0; $crossing < 20; $crossing++) {
            $race->pass(7, RaceScenario::clock(60 + $crossing * 180));
        }
        $addLateCrossings = function (int $from, int $to) use ($race): void {
            for ($crossing = $from; $crossing < $to; $crossing++) {
                $seconds = 60 + $crossing * 180;
                $race->pass(7, RaceScenario::clock($seconds), [1, 2]);
                $race->entry(3, 7, RaceScenario::clock($seconds + 4));
            }
        };

        $addLateCrossings(20, 30);
        $this->assertSame(0, $race->run('120:00')->sourceStats[3]->desvioMedianoMs);

        $addLateCrossings(30, 36);
        $this->assertSame(4000, $race->run('120:00')->sourceStats[3]->desvioMedianoMs);
    }
}
