<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\LapCounter;
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
 * on the decisions, on which counters were switched on or excluded, and on
 * whether the car is classified. Not on the other cars, not on whether a
 * phone has synced, not on the pace of the race. These tests hold the
 * engine to that, first with hand-written cases and then by brute force:
 * many generated races, with random settings and random decisions thrown
 * at them.
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

    /**
     * SITUATION: car 7 has crossed the counting line once (its pass zero)
     * and has not completed a lap yet. The server recounts it after that
     * first upload.
     *
     * EXPECTED: no lap, but the car does have a last official pass: pass
     * zero is official, and LAST is published from it. (A car with no pass
     * at all has none.)
     */
    public function testACarWithOnlyItsPassZeroHasNoLapButHasALastPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');

        $count = $race->countCars('01:00', [7, 8]);

        $this->assertSame(0, $count->lapCount(7));
        $this->assertSame(RaceScenario::ms('00:20'), $count->lastPassMs(7));
        $this->assertCount(1, $count->lapsByCar[7]);
        $this->assertSame(Lap::INICIAL, $count->lapsByCar[7][0]->estado);
        $this->assertNull($count->lastPassMs(8));
        $this->assertSame($race->run('01:00')->cars[7]->ultimaPassagemMs, $count->lastPassMs(7));
    }

    /**
     * SITUATION: car 7 made its first crossing and one lap, recorded by
     * everybody. Counter 2 alone also has it at 06:00, which does not count
     * (a "pendente" row).
     *
     * EXPECTED: a pending row is not an official pass: the lap count stays
     * 1 and the last pass stays 03:00, not 06:00.
     */
    public function testAPendingRowIsNeitherALapNorTheLastPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->pass(7, '03:00');
        $race->entry(2, 7, '06:00');

        $count = $race->countCars('07:00', [7]);

        $this->assertSame(1, $count->lapCount(7));
        $this->assertSame(RaceScenario::ms('03:00'), $count->lastPassMs(7));
        $this->assertSame(Lap::PENDENTE, $count->lapsByCar[7][2]->estado);
    }

    /**
     * SITUATION: the review screen asks for car 7's timeline. It uses the
     * counting half of the engine directly (`LapCounter::count`) and, as it
     * happens, hands it the entries of cars 7 AND 8, among them one entry
     * of unknown time for each car.
     *
     * EXPECTED: "recount only these cars" means only these cars: the pass
     * groups and the entries of unknown time that come back are car 7's
     * and nobody else's.
     */
    public function testTheCounterKeepsToTheCarsItWasAskedFor(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->pass(8, '00:25');
        $unknownOfCar7 = $race->entryWithTimeQuality(1, 7, '03:00', Entry::TEMPO_DESCONHECIDO);
        $race->entryWithTimeQuality(1, 8, '03:05', Entry::TEMPO_DESCONHECIDO);

        $counter = LapCounter::count($race->input('04:00'), [7]);

        $this->assertSame([7], array_keys($counter->groupsByCar));
        $this->assertSame([$unknownOfCar7], array_map(fn(Entry $entry) => $entry->id, $counter->unknownTimeEntries));
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
     * handover, a finish, pit and fueling stops and all the race-day
     * trouble (a start recorded late, unclassified cars on track, entries
     * with estimated and unknown times), in every publish mode and under
     * both pass-zero rules. Each race is looked at twice: untouched, and
     * after a reviewer accepted every suggestion (which reassigns entries
     * from one car to another, voids others, and confirms passes typed
     * before the start).
     *
     * For every car, the server then does what an upload does: it loads
     * only the entries that count for that car, in whatever order the
     * database returns them, and asks `countCars`. To make the point, the
     * sources are handed over as if no phone had ever synced.
     *
     * EXPECTED: for every car (the ones that take part, the unclassified
     * ones, and any stray number somebody typed), exactly the rows of the
     * full run: the same number of laps, the same number on each pass, the
     * same pass times to the millisecond, and the same pending rows.
     */
    #[DataProvider('racesAndModes')]
    public function testForEveryCarOfAWholeRaceTheTwoEntryPointsAgree(int $seed, string $mode, string $passagemInicial): void
    {
        $race = SyntheticRace::generate(SyntheticRaceConfig::withSeed($seed)->withFinish()->withRaceDayTrouble());
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

    /**
     * Forty seeds by default. To try many more (it takes a few seconds per
     * hundred), set the environment variable CONTAGEM_FUZZ_SEEDS, e.g. to 600.
     *
     * @return array<string, array{0: int}>
     */
    public static function fuzzSeeds(): array
    {
        $howMany = max(1, (int) (getenv('CONTAGEM_FUZZ_SEEDS') ?: 40));
        $cases = [];
        for ($seed = 101; $seed <= 100 + $howMany; $seed++) {
            $cases["seed $seed"] = [$seed];
        }
        return $cases;
    }

    /**
     * SITUATION: forty small races (ten cars, half an hour) with mistakes
     * and race-day trouble, each given a random combination of everything
     * that can change a count:
     *
     * - settings: any publish mode, any primary counter, either pass-zero
     *   rule, a start window of 0, 30 or 90 seconds, either rule for
     *   passes after the finish, several matching windows, agreement
     *   spreads and minimum-lap fractions;
     * - the start corrected to a later time (so more entries fall before
     *   it);
     * - a counter switched off mid-race, or one that never took part;
     * - a regular car turned "not classified";
     * - decisions: counters excluded for random intervals (some with no
     *   start or no end), passes added by a reviewer (some before the
     *   start, some for unclassified cars), random entries confirmed,
     *   voided and reassigned, and some of those decisions undone.
     *
     * Most of these decisions make no sense as reviewing. That is the
     * point: whatever is thrown at it, the engine must give the same
     * answer through both entry points.
     *
     * EXPECTED: for every car, recounting it alone gives exactly the rows
     * of the full run, AND exactly its pass groups: the same entries
     * together, held or not for the same reason. Matching and the hold rule
     * depend on the car's own entries and on nothing else. And the full
     * run itself gives the same groups when the entries are handed over in
     * another order: nothing depends on the order of arrival. (A 600-seed
     * run of this passed when the round was written; the suite keeps
     * forty. See fuzzSeeds() to run more.)
     */
    #[DataProvider('fuzzSeeds')]
    public function testWhateverIsThrownAtItEveryCarCountsTheSameOnItsOwn(int $seed): void
    {
        $dice = new Randomizer(new Mt19937($seed));
        $config = SyntheticRaceConfig::withSeed($seed)->withFinish();
        $config->cars = 10;
        $config->raceMinutes = 30;
        $config->baseLapSeconds = 120;
        $config->handovers = [['source' => 1, 'fromSeconds' => 10 * 60, 'toSeconds' => 12 * 60]];
        $config->startRecordedLate = true;
        $config->unclassifiedCarsOnTrack = 1;
        $config->estimatedTimePeriods = [['source' => 3, 'fromSeconds' => 5 * 60, 'toSeconds' => 7 * 60, 'errorMs' => 1200]];
        $config->unknownTimePeriods = [['source' => 2, 'fromSeconds' => 15 * 60, 'toSeconds' => 16 * 60, 'errorMs' => -3 * 24 * 3600 * 1000]];
        // Plenty of what the matcher and the hold rule exist for: stray
        // entries one window away from a real pass, repeats, late
        // counters, and two counters typing the same wrong number.
        $config->windowEdgeTypoRate = 0.02;
        $config->sharedTypoRate = 0.02;
        $config->repeatAndLatecomerRate = 0.02;
        $config->lateRate = 0.04;
        $race = SyntheticRace::generate($config);
        $input = $race->input();

        $this->randomSettings($input, $dice);
        $this->randomRaceControlAndSources($input, $race, $dice);
        $this->randomDecisions($input, $race, $dice);

        $full = Engine::run($input);
        $this->assertEveryCarCountsTheSameOnItsOwn($input, $full, $seed, 0);

        $inAnotherOrder = clone $input;
        $inAnotherOrder->entries = $dice->shuffleArray($input->entries);
        $this->assertSame(
            $this->describeGroups($full->groups),
            $this->describeGroups(Engine::run($inAnotherOrder)->groups),
            'The pass groups changed with the order in which the entries arrived.'
        );
    }

    /**
     * @param PassGroup[] $groups
     * @return string[] one line per group, in a fixed order: its car, its entries, and whether and why it counts or is held
     */
    private function describeGroups(array $groups): array
    {
        $lines = [];
        foreach ($groups as $group) {
            $lines[] = sprintf(
                'car %d: %s%s%s%s %s',
                $group->carro,
                implode('+', $group->entryIds()),
                $group->contado ? ' counted' : '',
                $group->referencia ? ' reference' : '',
                $group->dispersa ? ' dispersed' : '',
                ($group->retida ? 'held ' : '') . ($group->retidaNaReferencia ? 'held-in-reference ' : '') . ($group->motivoRetencao ?? '')
            );
        }
        sort($lines);
        return $lines;
    }

    private function randomSettings(EngineInput $input, Randomizer $dice): void
    {
        $settings = $input->settings;
        $settings->modoPublicacao = Settings::MODOS_PUBLICACAO[$dice->getInt(0, 3)];
        $settings->fontePrincipalId = $dice->getInt(1, 3);
        $settings->passagemInicial = Settings::PASSAGENS_INICIAIS[$dice->getInt(0, 1)];
        $settings->janelaLargadaS = [0, 30, 90][$dice->getInt(0, 2)];
        $settings->aposEncerramento = Settings::APOS_ENCERRAMENTO[$dice->getInt(0, 1)];
        $settings->janelaMs = [20000, 20000, 8000, 30000][$dice->getInt(0, 3)];
        $settings->dispersaoAlertaMs = [4000, 4000, 1500, 9000][$dice->getInt(0, 3)];
        $settings->fracaoVoltaMinima = [0.5, 0.5, 0.2, 0.9][$dice->getInt(0, 3)];
    }

    private function randomRaceControlAndSources(EngineInput $input, SyntheticRace $race, Randomizer $dice): void
    {
        if ($dice->getInt(0, 1) === 1) {
            // The start is corrected to up to a minute later.
            $input->ocorrencias[] = new Ocorrencia(100000, Ocorrencia::LARGADA, $race->recordedStartMs() + $dice->getInt(0, 60000));
        }
        $switchedOff = $dice->getInt(0, 3);
        if ($switchedOff >= 1) {
            // Counter 1, 2 or 3 is switched off: at a random moment, or it never took part.
            $source = $input->sources[$switchedOff - 1];
            $source->ativa = false;
            $source->desativadaEmMs = $dice->getInt(0, 2) === 0 ? null : $this->randomMoment($race, $dice);
        }
        if ($dice->getInt(0, 1) === 1) {
            // One of the regular cars turns out not to be classified.
            $car = $input->validCars[$dice->getInt(0, count($input->validCars) - 1)];
            $input->validCars = array_values(array_diff($input->validCars, [$car]));
            $input->unclassifiedCars[] = $car;
        }
    }

    private function randomDecisions(EngineInput $input, SyntheticRace $race, Randomizer $dice): void
    {
        $allCars = array_merge($input->validCars, $input->unclassifiedCars);
        $randomCar = fn() => $allCars[$dice->getInt(0, count($allCars) - 1)];
        $randomEntryId = fn() => $input->entries[$dice->getInt(0, count($input->entries) - 1)]->id;
        $nextId = 1;

        for ($i = $dice->getInt(0, 2); $i > 0; $i--) {
            $from = $dice->getInt(0, 3) === 0 ? null : $this->randomMoment($race, $dice);
            $to = $dice->getInt(0, 3) === 0 ? null : ($from ?? $this->randomMoment($race, $dice)) + $dice->getInt(0, 600000);
            $input->decisions[] = Decision::excluirFonte($nextId++, $dice->getInt(1, 3), $from, $to);
        }
        for ($i = 0; $i < 4; $i++) {
            // Some of these land before the start, and some on a pass that exists.
            $input->decisions[] = Decision::adicionar($nextId++, $randomCar(), $this->randomMoment($race, $dice) - $dice->getInt(0, 120000));
        }
        for ($i = 0; $i < 12; $i++) {
            $input->decisions[] = Decision::confirmar($nextId++, $randomEntryId());
        }
        // The entries of unknown time and those before the start are few;
        // make sure some of them get confirmed too.
        foreach ($input->entries as $entry) {
            $isSpecial = $entry->qualidadeTempo === Entry::TEMPO_DESCONHECIDO || $entry->tsMs < $race->recordedStartMs();
            if ($isSpecial && $dice->getInt(0, 2) === 0) {
                $input->decisions[] = Decision::confirmar($nextId++, $entry->id);
            }
        }
        for ($i = 0; $i < 6; $i++) {
            $input->decisions[] = Decision::anular($nextId++, $randomEntryId());
        }
        for ($i = 0; $i < 4; $i++) {
            $input->decisions[] = Decision::reatribuir($nextId++, $randomEntryId(), $randomCar());
        }
        for ($i = 0; $i < 4; $i++) {
            $input->decisions[] = Decision::desfazer($nextId, $dice->getInt(1, $nextId - 1));
            $nextId++;
        }
    }

    /** A moment between the true start of the race and its end. */
    private function randomMoment(SyntheticRace $race, Randomizer $dice): int
    {
        return $dice->getInt(SyntheticRace::START_MS, $race->endMs);
    }

    /**
     * Recounts every car on its own, as an upload does, and compares with
     * the full run.
     *
     * @param int $atLeastThisManyLaps the race must have been counted at all
     */
    private function assertEveryCarCountsTheSameOnItsOwn(EngineInput $input, EngineResult $full, int $seed, int $atLeastThisManyLaps = 900): void
    {
        // What the server loads for a car: the entries typed as that car,
        // plus any entry a reviewer ever moved (it may count for this car
        // now). The engine itself keeps only those that do.
        $movedEntryIds = [];
        foreach ($input->decisions as $decision) {
            if ($decision->tipo === Decision::REATRIBUIR) {
                $movedEntryIds[$decision->passagemId] = true;
            }
        }
        $entriesByTypedCar = [];
        $movedEntries = [];
        $cars = array_fill_keys(array_merge($input->validCars, $input->unclassifiedCars), true);
        foreach ($input->entries as $entry) {
            $cars[$entry->carro] = true;
            if (isset($movedEntryIds[$entry->id])) {
                $movedEntries[] = $entry;
            } else {
                $entriesByTypedCar[$entry->carro][] = $entry;
            }
        }
        foreach ($input->decisions as $decision) {
            if ($decision->carro !== null) {
                $cars[$decision->carro] = true;
            }
        }
        $shuffler = new Randomizer(new Mt19937($seed));
        $totalLaps = 0;

        foreach (array_keys($cars) as $car) {
            $carInput = clone $input;
            $carInput->entries = $shuffler->shuffleArray(array_merge($entriesByTypedCar[$car] ?? [], $movedEntries));
            $carInput->sources = [];
            foreach ($input->sources as $source) {
                $neverSynced = clone $source;
                $neverSynced->sincronizadoAteMs = null;
                $carInput->sources[] = $neverSynced;
            }

            $ownGroups = LapCounter::count($carInput, [$car])->groupsByCar[$car] ?? [];
            $firstEntryTimes = array_map(fn(PassGroup $group) => $group->primeiroTsMs, $ownGroups);
            $inTimeOrder = $firstEntryTimes;
            sort($inTimeOrder);
            $this->assertSame($inTimeOrder, $firstEntryTimes, "The pass groups of car $car are ordered by first entry.");
            $alone = Engine::countCars($carInput, [$car]);
            $this->assertSame(
                $this->describeGroups(array_filter($full->groups, fn(PassGroup $group) => $group->carro === $car)),
                $this->describeGroups(LapCounter::count($carInput, [$car])->groupsByCar[$car] ?? []),
                "Pass groups of car $car."
            );

            $this->assertSame($this->officialPasses($full->laps, $car), $this->officialPasses($alone->lapsByCar[$car], $car), "Car $car.");
            $this->assertSame($this->rows($full->laps, $car), $this->rows($alone->lapsByCar[$car], $car), "Rows of car $car, pending ones included.");
            $this->assertSame($full->lapCount($car), $alone->lapCount($car), "Laps of car $car.");
            if (isset($full->cars[$car])) {
                $this->assertSame($full->cars[$car]->ultimaPassagemMs, $alone->lastPassMs($car), "Last pass of car $car.");
            } else {
                $this->assertNull($alone->lastPassMs($car), "Car $car is not in the standings, so it has no official pass.");
            }
            $totalLaps += $alone->lapCount($car);
        }
        $this->assertGreaterThan($atLeastThisManyLaps, $totalLaps, 'The race must have been counted at all.');
    }

    /**
     * @param Lap[] $laps
     * @return string[] one line per row of the car, pending rows included: "number@time state sources"
     */
    private function rows(array $laps, int $car): array
    {
        $rows = [];
        foreach ($laps as $lap) {
            if ($lap->carro === $car) {
                $rows[] = $lap->numeroVolta . '@' . $lap->tsMs . ' ' . $lap->estado . ' ' . $lap->fontes;
            }
        }
        return $rows;
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
