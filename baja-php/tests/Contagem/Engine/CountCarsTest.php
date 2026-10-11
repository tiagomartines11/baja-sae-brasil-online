<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;
use Baja\Tests\Contagem\Engine\Support\SyntheticReviewer;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The engine's two entry points must never disagree.
 *
 * - `Engine::run()` recomputes the whole race: laps, lap times, flags,
 *   statuses. It is what the review screen shows.
 * - `Engine::countCars()` recounts only a few cars, from only those cars'
 *   entries. It is what an upload uses to publish a new lap at once.
 *
 * This works because of one property of the counting rules: a car's
 * official passes depend ONLY on that car's own entries (after decisions),
 * on the decisions, and on which counters were switched on or excluded.
 * Not on the other cars, not on whether a phone has synced, not on the pace
 * of the race. These tests hold the engine to that.
 */
final class CountCarsTest extends EngineTestCase
{
    /**
     * SITUATION: cars 7 and 8 are racing. An upload brings a new entry for
     * car 7, and the server recounts car 7 alone, loading only its entries.
     *
     * EXPECTED: the same laps, numbers and pass times as the full run gives
     * for car 7. A car that was asked for and has no entries is in the
     * answer with no laps; a car that was not asked for is not there.
     */
    public function testRecountingOneCarGivesWhatTheFullRunGives(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 8]);
        $race->pass(7, '03:00');
        $race->pass(8, '03:05');
        $race->pass(7, '06:00', [1, 3]);
        $race->entry(2, 7, '09:00');

        $full = $race->run('10:00');
        $input = $race->input('10:00');
        $input->entries = array_values(array_filter($input->entries, fn($entry) => $entry->carro === 7));
        $onlyCar7 = Engine::countCars($input, [7, 55]);

        $this->assertSame(2, $onlyCar7->lapCount(7));
        $this->assertSame(RaceScenario::ms('06:00'), $onlyCar7->lastPassMs(7));
        $this->assertSame($this->officialPasses($full->laps, 7), $this->officialPasses($onlyCar7->lapsByCar[7], 7));
        $this->assertSame(Lap::PENDENTE, $onlyCar7->lapsByCar[7][3]->estado, 'The lone entry at 09:00 is listed as waiting.');
        $this->assertSame([], $onlyCar7->lapsByCar[55]);
        $this->assertSame(0, $onlyCar7->lapCount(55));
        $this->assertNull($onlyCar7->lastPassMs(55));
        $this->assertArrayNotHasKey(8, $onlyCar7->lapsByCar);
        $this->assertSame($full->timeline->largadaMs, $onlyCar7->timeline->largadaMs);
    }

    /**
     * SITUATION: counter 1 typed 14 when car 41 passed, and a reviewer
     * reassigned that entry to car 41. Counter 3 missed the car, so the
     * pass counts only thanks to the reassigned entry.
     *
     * EXPECTED: "car 41's entries" means the entries that count for car 41
     * NOW. Recounting car 41 from its own entry plus the reassigned one
     * gives the lap; recounting car 14 gives nothing.
     */
    public function testAnEntryReassignedToACarBelongsToThatCar(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([41]);
        $race->entry(2, 41, '03:00');
        $typo = $race->entry(1, 14, '03:00');
        $race->reassign($typo, 41);

        $input = $race->input('04:00');
        $count = Engine::countCars($input, [41, 14]);

        $this->assertSame(1, $count->lapCount(41));
        $this->assertSame([], $count->lapsByCar[14]);
        $this->assertSame(1, $race->run('04:00')->lapCount(41));
    }

    /** @return array<string, array{0: int, 1: string, 2: string}> seed, publish mode, pass-zero rule */
    public static function racesAndModes(): array
    {
        $cases = [];
        foreach ([1, 2, 3, 7, 2025, 31337] as $index => $seed) {
            $mode = Settings::MODOS_PUBLICACAO[$index % count(Settings::MODOS_PUBLICACAO)];
            $passagemInicial = Settings::PASSAGENS_INICIAIS[$index % 2];
            $cases["seed $seed, $mode, $passagemInicial"] = [$seed, $mode, $passagemInicial];
        }
        return $cases;
    }

    /**
     * SITUATION: whole synthetic races with every kind of mistake, a
     * handover and a finish, in every publish mode and under both pass-zero
     * rules. Each race is looked at twice: untouched, and after a reviewer
     * accepted every suggestion (which reassigns entries from one car to
     * another and voids others).
     *
     * For every car, the server then does what an upload does: it loads
     * only the entries that count for that car, in whatever order the
     * database returns them, and asks `countCars`. To make the point, the
     * sources are handed over as if no phone had ever synced.
     *
     * EXPECTED: for every car, exactly the official passes of the full run:
     * the same number of laps, the same number on each pass, the same pass
     * times, to the millisecond.
     */
    #[DataProvider('racesAndModes')]
    public function testForEveryCarOfAWholeRaceTheTwoEntryPointsAgree(int $seed, string $mode, string $passagemInicial): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed)->withFinish());
        $input = $race->input();
        $input->settings->modoPublicacao = $mode;
        $input->settings->fontePrincipalId = 2;
        $input->settings->passagemInicial = $passagemInicial;

        $this->assertEveryCarCountsTheSameOnItsOwn($input, Engine::run($input), $seed);

        $reviewed = SyntheticReviewer::acceptEverySuggestion($input);
        $reassignments = array_filter($input->decisions, fn($decision) => $decision->tipo === Decision::REATRIBUIR);
        $this->assertNotEmpty($reassignments, 'The review must have moved entries between cars.');
        $this->assertEveryCarCountsTheSameOnItsOwn($input, $reviewed, $seed);
    }

    private function assertEveryCarCountsTheSameOnItsOwn(EngineInput $input, EngineResult $full, int $seed): void
    {
        $entriesByEffectiveCar = [];
        foreach ($input->entries as $entry) {
            $entriesByEffectiveCar[$this->carAfterDecisions($input, $entry->id, $entry->carro)][] = $entry;
        }
        $shuffler = new Randomizer(new Mt19937($seed));
        $totalLaps = 0;

        foreach ($input->validCars as $car) {
            $carInput = clone $input;
            $carInput->entries = $shuffler->shuffleArray($entriesByEffectiveCar[$car] ?? []);
            $carInput->sources = [];
            foreach ($input->sources as $source) {
                $neverSynced = clone $source;
                $neverSynced->sincronizadoAteMs = null;
                $carInput->sources[] = $neverSynced;
            }

            $alone = Engine::countCars($carInput, [$car]);

            $this->assertSame($this->officialPasses($full->laps, $car), $this->officialPasses($alone->lapsByCar[$car], $car), "Car $car.");
            $this->assertSame($full->lapCount($car), $alone->lapCount($car), "Laps of car $car.");
            $this->assertSame($full->cars[$car]->ultimaPassagemMs, $alone->lastPassMs($car), "Last pass of car $car.");
            $totalLaps += $alone->lapCount($car);
        }
        $this->assertGreaterThan(900, $totalLaps, 'The race must have been counted at all.');
    }

    /** The car an entry counts for once the "reatribuir" decisions are applied (the latest one wins). */
    private function carAfterDecisions(EngineInput $input, int $entryId, int $typedCar): int
    {
        $car = $typedCar;
        foreach ($input->decisions as $decision) {
            if ($decision->tipo === Decision::REATRIBUIR && $decision->passagemId === $entryId) {
                $car = (int) $decision->carro;
            }
        }
        return $car;
    }

    /**
     * @param Lap[] $laps
     * @return string[] one line per official pass of the car: "number@time"
     */
    private function officialPasses(array $laps, int $car): array
    {
        $passes = [];
        foreach ($laps as $lap) {
            if ($lap->carro === $car && $lap->isOfficialPass()) {
                $passes[] = $lap->numeroVolta . '@' . $lap->tsMs;
            }
        }
        return $passes;
    }
}
