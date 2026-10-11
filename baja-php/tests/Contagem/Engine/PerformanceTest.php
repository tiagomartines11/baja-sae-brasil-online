<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Engine;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;

/**
 * The engine recomputes the WHOLE race from the raw entries every time a
 * counter uploads something. That is what keeps it simple and always
 * consistent, and it is only acceptable if it is fast.
 */
final class PerformanceTest extends EngineTestCase
{
    /** The budget for one full recompute of the largest race we plan for. */
    private const BUDGET_MS = 300;

    /**
     * SITUATION: a race far bigger than any real one: 100 cars, about 100
     * laps each, four counters. Roughly 40,000 typed entries.
     *
     * EXPECTED: a full recompute takes less than 300 milliseconds. The best
     * of three runs is used, so that a hiccup of the machine running the
     * test does not fail it.
     *
     * If this test ever fails, the answer is NOT to raise the budget: add a
     * per-car incremental recompute, and a test proving it gives exactly
     * the same result as the full rebuild.
     */
    public function testAFullRecomputeOfAHundredCarsTimesAHundredLapsFitsTheBudget(): void
    {
        $config = SyntheticRaceConfig::withSeed(42);
        $config->cars = 100;
        $config->counters = 4;
        $config->baseLapSeconds = 150;
        // 100 laps of an average car on this track take about five hours.
        $config->raceMinutes = 310;
        $race = SyntheticRace::generate($config);
        $input = $race->input();

        $this->assertGreaterThanOrEqual(38000, count($input->entries), 'The race must be as big as promised.');
        $averageLaps = array_sum($race->truthLapsByCar) / count($race->truthLapsByCar);
        $this->assertGreaterThanOrEqual(95, $averageLaps);

        $bestMs = PHP_FLOAT_MAX;
        for ($run = 0; $run < 3; $run++) {
            $startedAt = hrtime(true);
            $result = Engine::run($input);
            $bestMs = min($bestMs, (hrtime(true) - $startedAt) / 1e6);
        }

        $this->assertSame(array_sum($race->truthLapsByCar), array_sum(array_map(fn($car) => $car->voltas, $result->cars)), 'And the answer is still right.');
        $this->assertLessThan(self::BUDGET_MS, $bestMs, sprintf('A full recompute took %.0f ms.', $bestMs));
    }
}
