<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Engine;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A synthetic race watched while it happens, as the results page shows it:
 * the engine is asked again and again during the race, each time knowing
 * only what has been typed and uploaded by then. Nobody reviews anything.
 */
final class LiveRaceTest extends EngineTestCase
{
    /**
     * Twelve races by default. To watch many more (each takes about a
     * second), set the environment variable CONTAGEM_INVARIANT_SEEDS, e.g.
     * to 300: the first six seeds stay, and seeds 2001, 2002, ... are added.
     *
     * @return array<string, array{0: int}>
     */
    public static function seeds(): array
    {
        $cases = ['seed 1' => [1], 'seed 2' => [2], 'seed 3' => [3], 'seed 7' => [7], 'seed 2025' => [2025], 'seed 31337' => [31337]];
        $howMany = max(count($cases), (int) (getenv('CONTAGEM_INVARIANT_SEEDS') ?: 12));
        for ($seed = 2001; count($cases) < $howMany; $seed++) {
            $cases["seed $seed"] = [$seed];
        }
        return $cases;
    }

    /**
     * SITUATION: counter 2's phone is offline from minute 40 to minute 55,
     * then uploads everything. We look at the server's view every two and a
     * half minutes and compare it with what had REALLY happened by that
     * moment (not with the final result).
     *
     * EXPECTED, at every look:
     * - THE INVARIANT OF THE SAFE MODE: no car has more official laps than
     *   it had really completed by then. No exceptions. (There used to be
     *   one: a counter repeated the car and another typed it very late, so
     *   two counters "agreed" on a crossing that never happened and the car
     *   was a lap ahead until somebody reviewed it. Such a pass is now
     *   held.) This is checked for the moment of the look, and for the
     *   moment of every official pass the server knows of at that look.
     * - A car none of whose crossings were touched by a mistake has EXACTLY
     *   the laps it had really completed (give or take the second or two a
     *   counter needs to press Enter).
     * - No car has fewer laps than at the previous look. A count that goes
     *   up and then down again is the worst thing a results page can do.
     * - While the phone is offline, no typo or lone-entry flag is raised for
     *   moments inside the offline period, since those depend on entries
     *   the phone has not uploaded yet.
     */
    #[DataProvider('seeds')]
    public function testWatchedLiveTheCountFollowsTheTruthAndNeverGoesDown(int $seed): void
    {
        $config = SyntheticRaceConfig::withSeed($seed);
        $config->offlinePeriods = [['source' => 2, 'fromSeconds' => 40 * 60, 'toSeconds' => 55 * 60]];
        $race = SyntheticRace::generate($config);
        $offlineFromMs = SyntheticRace::START_MS + 40 * 60000;
        $offlineToMs = SyntheticRace::START_MS + 55 * 60000;

        $lapsAtPreviousLook = array_fill_keys($race->carNumbers(), 0);
        $looksWithLaps = 0;

        for ($secondsIntoRace = 150; $secondsIntoRace <= $config->raceMinutes * 60; $secondsIntoRace += 150) {
            $nowMs = SyntheticRace::START_MS + $secondsIntoRace * 1000;
            $result = Engine::run($race->input($nowMs));
            $where = "at second $secondsIntoRace";
            $isOffline = $nowMs >= $offlineFromMs && $nowMs < $offlineToMs;

            foreach ($race->carNumbers() as $car) {
                $laps = $result->lapCount($car);
                $mostItCanHave = $race->truthLapsAt($car, $nowMs - SyntheticRace::EARLIEST_TYPING_MS);
                $this->assertLessThanOrEqual($mostItCanHave, $laps, "Car $car is ahead of the truth $where.");

                if (!isset($race->mistakes->carsTouched[$car])) {
                    $leastItMustHave = $race->truthLapsAt($car, $nowMs - SyntheticRace::LATEST_TYPING_MS);
                    $this->assertGreaterThanOrEqual($leastItMustHave, $laps, "Car $car, which nobody got wrong, is behind the truth $where.");
                }

                $this->assertGreaterThanOrEqual($lapsAtPreviousLook[$car], $laps, "The count of car $car went down $where.");
                $lapsAtPreviousLook[$car] = $laps;
            }
            $looksWithLaps += array_sum($lapsAtPreviousLook) > 0 ? 1 : 0;
            $this->assertNoCarIsEverAheadOfTheTruth($race, $result, "Seed $seed, $where.");

            if ($isOffline) {
                foreach ($result->openFlags() as $flag) {
                    $dependsOnAbsence = in_array($flag->tipo, [Flag::TROCA_PROVAVEL, Flag::SOLITARIA], true);
                    $this->assertFalse(
                        $dependsOnAbsence && $flag->tsMs >= $offlineFromMs,
                        "Flag {$flag->id} $where was raised while counter 2 was offline."
                    );
                }
            }
        }

        $this->assertGreaterThan(40, $looksWithLaps, 'The race must have been under way at most of the looks.');
    }
}
