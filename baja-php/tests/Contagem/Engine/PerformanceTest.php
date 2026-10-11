<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Engine;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;
use Baja\Tests\Contagem\Engine\Support\SyntheticReviewer;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * How long the engine takes.
 *
 * Every upload from a counter's phone triggers a recount, and the results
 * page must show the new lap at once. So there are two speeds to keep:
 *
 * - recounting the ONE car an upload is about (`Engine::countCars`), which
 *   happens inside the upload request, several times a second;
 * - recomputing the WHOLE race with flags, pace and statuses
 *   (`Engine::run`), which happens every few seconds.
 *
 * What is measured is the engine call only (not loading from the database),
 * as the best of five runs so that a hiccup of the machine running the
 * test does not fail it. Entries are handed over SHUFFLED, in the order
 * phones happened to upload them, never conveniently sorted by time.
 *
 * If one of these tests ever fails, the answer is NOT to raise the budget.
 *
 * To see the measured times (they are not printed when the tests pass), run
 * with the environment variable CONTAGEM_PERF_LOG=1: each test then writes
 * one line with its best time to the error output.
 */
final class PerformanceTest extends EngineTestCase
{
    private const BUDGET_ONE_CAR_MS = 10;
    private const BUDGET_REAL_RACE_MS = 100;
    private const BUDGET_STRESS_RACE_MS = 300;

    /** Each measurement is repeated this many times and the fastest one is kept. */
    private const RUNS = 5;

    /** A national endurance race: 64 cars, about 70 laps each, three counters. */
    private function realSizeRace(): SyntheticRace
    {
        return SyntheticRace::generate(SyntheticRaceConfig::forTiming(42, 64, 3, 225, 150));
    }

    /**
     * SITUATION: a real-size race near its end: 64 cars, about 70 laps
     * each, three counters, with the usual mistakes and some fifty reviewer
     * decisions. One more entry arrives for car 17. The server loads only
     * car 17's entries (plus all sources, decisions and race-control
     * events, as it does in reality) and recounts that car.
     *
     * EXPECTED: under 10 milliseconds, and the same count the full
     * recompute gives for that car.
     */
    public function testRecountingOneCarAfterAnUploadTakesUnderTenMilliseconds(): void
    {
        $race = $this->realSizeRace();
        $input = $this->shuffled($race->input());
        $full = SyntheticReviewer::acceptEverySuggestion($input);
        $this->assertGreaterThan(20, count($input->decisions), 'The race must carry a realistic number of decisions.');
        $this->assertGreaterThan(100, count($input->ocorrencias), 'And a realistic pit log.');

        $car = 17;
        $onlyThatCar = clone $input;
        // "Car 17's entries" are the ones that count for car 17 after decisions.
        $carAfterDecisions = fn($entry) => isset($full->effectiveEntries[$entry->id]) ? $full->effectiveEntries[$entry->id]->carro : $entry->carro;
        $onlyThatCar->entries = array_values(array_filter($input->entries, fn($entry) => $carAfterDecisions($entry) === $car));
        $this->assertGreaterThan(190, count($onlyThatCar->entries));

        [$bestMs, $count] = $this->bestOfFive(fn() => Engine::countCars($onlyThatCar, [$car]));

        $this->assertSame($full->lapCount($car), $count->lapCount($car), 'And the answer is the one the full recompute gives.');
        $this->assertSame($full->cars[$car]->ultimaPassagemMs, $count->lastPassMs($car));
        $this->assertGreaterThan(60, $count->lapCount($car));
        $this->assertLessThan(self::BUDGET_ONE_CAR_MS, $bestMs, sprintf('Recounting one car took %.2f ms.', $bestMs));
    }

    /**
     * SITUATION: the same real-size race (about 14,000 typed entries),
     * recomputed from scratch: laps, lap times, pace, flags, statuses.
     *
     * EXPECTED: under 100 milliseconds.
     */
    public function testAFullRecomputeOfARealSizeRaceTakesUnderAHundredMilliseconds(): void
    {
        $race = $this->realSizeRace();
        $input = $this->shuffled($race->input());
        $this->assertGreaterThanOrEqual(13000, count($input->entries), 'The race must be as big as promised.');
        $this->assertGreaterThanOrEqual(68, array_sum($race->truthLapsByCar) / count($race->truthLapsByCar));

        [$bestMs, $result] = $this->bestOfFive(fn() => Engine::run($input));

        $this->assertRoughlyTheTruth($race, array_sum(array_map(fn($car) => $car->voltas, $result->cars)));
        $this->assertLessThan(self::BUDGET_REAL_RACE_MS, $bestMs, sprintf('A full recompute took %.0f ms.', $bestMs));
    }

    /**
     * SITUATION: a race far bigger than any real one: 100 cars, about 100
     * laps each, four counters. Roughly 39,000 typed entries.
     *
     * EXPECTED: a full recompute still takes less than 300 milliseconds.
     */
    public function testAFullRecomputeOfAHundredCarsTimesAHundredLapsTakesUnderThreeHundredMilliseconds(): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::forTiming(42, 100, 4, 310, 150));
        $input = $this->shuffled($race->input());
        $this->assertGreaterThanOrEqual(38000, count($input->entries), 'The race must be as big as promised.');
        $this->assertGreaterThanOrEqual(95, array_sum($race->truthLapsByCar) / count($race->truthLapsByCar));

        [$bestMs, $result] = $this->bestOfFive(fn() => Engine::run($input));

        $this->assertRoughlyTheTruth($race, array_sum(array_map(fn($car) => $car->voltas, $result->cars)));
        $this->assertLessThan(self::BUDGET_STRESS_RACE_MS, $bestMs, sprintf('A full recompute took %.0f ms.', $bestMs));
    }

    /** The same input with its entries in a random (but repeatable) order. */
    private function shuffled(EngineInput $input): EngineInput
    {
        $input->entries = (new Randomizer(new Mt19937(7)))->shuffleArray($input->entries);
        return $input;
    }

    /**
     * @template T
     * @param callable(): T $engineCall
     * @return array{0: float, 1: T} the fastest of five runs in milliseconds, and the last result
     */
    private function bestOfFive(callable $engineCall): array
    {
        $bestMs = PHP_FLOAT_MAX;
        $result = null;
        for ($run = 0; $run < self::RUNS; $run++) {
            // The previous run's result is thrown away BEFORE the clock
            // starts, so that freeing it is not charged to the next run.
            $result = null;
            $startedAt = hrtime(true);
            $result = $engineCall();
            $bestMs = min($bestMs, (hrtime(true) - $startedAt) / 1e6);
        }
        if (getenv('CONTAGEM_PERF_LOG')) {
            fwrite(STDERR, sprintf("PERF %s: %.2f ms\n", $this->name(), $bestMs));
        }
        return [$bestMs, $result];
    }

    /**
     * A fast answer must still be the right one. Unreviewed, a handful of
     * cars are a lap off (see FullRaceTest), so the total is compared with
     * a small tolerance.
     */
    private function assertRoughlyTheTruth(SyntheticRace $race, int $officialLaps): void
    {
        $trueLaps = array_sum($race->truthLapsByCar);
        $this->assertEqualsWithDelta($trueLaps, $officialLaps, $trueLaps * 0.005, 'And the answer is still right (within half a percent before review).');
    }
}
