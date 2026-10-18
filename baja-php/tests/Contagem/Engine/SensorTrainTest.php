<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\DecisionApplier;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\SensorTrains;
use Baja\Contagem\Engine\UnreadAssigner;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;
use Baja\Tests\Contagem\Engine\Support\SyntheticRace;
use Baja\Tests\Contagem\Engine\Support\SyntheticRaceConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A timing sensor's marks in TRAINS (cars a second apart), and the mark as
 * the pass time, in the whole race and in the per-car recount of an upload.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The sensor marks the moment a car crosses, to the millisecond, and never
 * which car. The counters say which car, a second or so late. Where cars
 * come a second apart, the marks and the counters' readings of several
 * cars lie within the tolerance of each other: a train. Which mark is
 * which car is then decided by the times, or else by the order in which
 * the counters typed the cars; otherwise those passes stay untimed.
 *
 * A matched mark is the pass time (LAST, the lap row's time). The per-car
 * recount of an upload must give exactly what the whole race gives, so it
 * loads the marks and readings of every car a few seconds around its cars'
 * crossings (SensorTrains::rangesToLoad()), and an upload recounts the cars
 * of the trains near what it changed (SensorTrains::carsAround()).
 */
final class SensorTrainTest extends EngineTestCase
{
    /**
     * SITUATION: cars 7 and 8 cross at 04:00.0 and 04:00.6, the sensor
     * marks both. The three counters type 7 and then 8, each with its own
     * delay and only 0.2 s between the two: the times alone cannot tell the
     * marks apart well enough (the counters put the cars 0.2 s apart, the
     * marks 0.6 s).
     *
     * EXPECTED: every counter typed 7 before 8, as many marks as cars, the
     * marks more than half a second apart: the first mark is car 7's pass
     * time, the second car 8's.
     */
    public function testCarsASecondApartAreTimedInTheOrderTheCountersTypedThem(): void
    {
        $race = $this->twoCarsBehindEachOther('04:00.60', [[7, 8], [7, 8], [7, 8]]);

        $result = $race->run('10:00');

        $this->assertSame(RaceScenario::ms('04:00.00'), $this->groupOf($result, 7)->tsMs);
        $this->assertSame(RaceScenario::ms('04:00.60'), $this->groupOf($result, 8)->tsMs);
        $this->assertTrue($this->groupOf($result, 8)->tempoDoSensor);
        $this->assertLapCounts([7 => 1, 8 => 1], $result);
    }

    /**
     * SITUATION: the same, but the third counter typed 8 before 7.
     *
     * EXPECTED: the counters do not agree on the order, so neither pass is
     * timed by the sensor: both keep the counters' time, and the marks are
     * left over (no flag: a pass the counters recorded is near them).
     */
    public function testWhenTheCountersDisagreeOnTheOrderNeitherPassIsTimed(): void
    {
        $race = $this->twoCarsBehindEachOther('04:00.60', [[7, 8], [7, 8], [8, 7]]);

        $result = $race->run('10:00');

        $this->assertNull($this->groupOf($result, 7)->marcaSensor);
        $this->assertNull($this->groupOf($result, 8)->marcaSensor);
        $this->assertFalse($this->groupOf($result, 7)->tempoPreciso);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the same agreeing counters, but the two marks are 0.4 s
     * apart.
     *
     * EXPECTED: untimed: that close, a counter's hand puts two cars the
     * wrong way round too often for the counters' order to prove anything.
     */
    public function testMarksCloserThanHalfASecondAreNotPutInTheCountersOrder(): void
    {
        $race = $this->twoCarsBehindEachOther('04:00.40', [[7, 8], [7, 8], [7, 8]]);

        $result = $race->run('10:00');

        $this->assertNull($this->groupOf($result, 7)->marcaSensor);
        $this->assertNull($this->groupOf($result, 8)->marcaSensor);
    }

    /**
     * SITUATION: the train of the first test, counted the way an upload
     * counts: car 8 alone, from what the recount loads (car 8's entries,
     * and every entry a few seconds around them).
     *
     * EXPECTED: car 8's pass is timed by the second mark there too: the
     * same lap rows, LAST included, as the whole race. With car 8's entries
     * alone (car 7's readings left out), it would have taken the first.
     */
    public function testThePerCarRecountTimesAPassInATrainAsTheWholeRaceDoes(): void
    {
        $race = $this->twoCarsBehindEachOther('04:00.60', [[7, 8], [7, 8], [7, 8]]);
        $input = $race->input('10:00');
        $full = Engine::run($input);

        $loaded = self::whatTheRecountLoads($input, [8], []);
        $this->assertContains(7, array_map(fn(Entry $entry) => $entry->carro, $loaded->entries), 'the readings of the car in front are loaded');
        $alone = Engine::countCars($loaded, [8]);

        $this->assertSame(self::rows($full->laps, 8), self::rows($alone->lapsByCar[8], 8));
        $this->assertSame(RaceScenario::ms('04:00.60'), $alone->lastPassMs(8));
    }

    /**
     * SITUATION: a race with no timing sensor (with "??").
     *
     * EXPECTED: the recount loads nothing more for marks, and an upload
     * recounts no other car because of them.
     */
    public function testWithoutASensorNothingMoreIsLoadedOrRecounted(): void
    {
        $config = SyntheticRaceConfig::withSeed(3);
        $config->unreadRate = 0.05;
        $input = SyntheticRace::generate($config)->input();

        $this->assertSame([SensorTrains::MARCAS => [], SensorTrains::TUDO => []], SensorTrains::rangesToLoad($input, [5], [SensorTrains::MARCAS => [], SensorTrains::TUDO => []]));
        $this->assertSame([], SensorTrains::carsAround($input, [$input->entries[100]->tsMs], [$input->entries[100]->carro]));
    }

    /** @return array<string, array{0: int, 1: int}> seed, counters */
    public static function races(): array
    {
        return ['seed 1, three counters' => [1, 3], 'seed 2, two counters' => [2, 2], 'seed 3, three counters' => [3, 3], 'seed 4, two counters' => [4, 2]];
    }

    /**
     * SITUATION: whole generated races with a sensor (missing 2 % of the
     * crossings, a false trigger every minute), counters pressing "??" on
     * 2 % of the crossings, and forty cars on 150 s laps, so that trains
     * are everywhere.
     *
     * For every car, the server does what an upload does: it loads that
     * car's entries, the entries around the "??" near them, and the entries
     * the sensor's trains around its crossings need, round after round,
     * and recounts the car alone.
     *
     * EXPECTED: exactly the rows of the whole race, pass times to the
     * millisecond (many of them a sensor's marks).
     */
    #[DataProvider('races')]
    public function testEveryCarRecountedOnWhatItLoadsCountsAsInTheWholeRace(int $seed, int $counters): void
    {
        $race = SyntheticRace::generate($this->denseRace($seed, $counters, 0.02));
        $input = $race->input();
        $full = Engine::run($input);

        $timed = 0;
        foreach ($race->carNumbers() as $car) {
            $alone = Engine::countCars(self::whatTheRecountLoads($input, [$car], []), [$car]);
            $this->assertSame(self::rows($full->laps, $car), self::rows($alone->lapsByCar[$car], $car), "Seed $seed, car $car.");
        }
        foreach ($full->groups as $group) {
            $timed += $group->tempoDoSensor ? 1 : 0;
        }
        $this->assertGreaterThan(200, $timed, 'the sensor timed passes');
    }

    /**
     * SITUATION: the same races. One entry at a time (a counter's reading,
     * or a sensor's mark) is uploaded, or voided by its phone.
     *
     * EXPECTED: every car whose rows the change moves is a car of the
     * upload (the car typed) or one SensorTrains::carsAround() names from
     * what the recount loads around the moment: the cars of the trains
     * there. A recount of those cars is all the change needs.
     */
    #[DataProvider('races')]
    public function testAnUploadMovesNoPassTimeOutsideTheCarsItRecounts(int $seed, int $counters): void
    {
        $race = SyntheticRace::generate($this->denseRace($seed, $counters, 0.0));
        $input = $race->input();
        $dice = new Randomizer(new Mt19937($seed));
        $sensorId = $race->sensorSourceId();
        $moved = 0;
        for ($try = 0; $try < 15; $try++) {
            $entry = $input->entries[$dice->getInt(0, count($input->entries) - 1)];
            $without = clone $input;
            $without->entries = array_values(array_filter($input->entries, fn(Entry $other) => $other->id !== $entry->id));
            $voided = clone $input;
            $voided->decisions[] = Decision::anular(1, $entry->id, Decision::ORIGEM_CONTADOR);

            foreach ([[$without, $input, 'stored'], [$input, $voided, 'voided']] as [$before, $after, $what]) {
                $changed = self::carsWhoseRowsDiffer(Engine::run($before), Engine::run($after));
                $changedCars = $entry->carro === null ? [] : [$entry->carro];
                $recounted = array_merge($changedCars, SensorTrains::carsAround(self::whatTheRecountLoads($after, [], [$entry->tsMs], $changedCars), [$entry->tsMs], $changedCars));
                $this->assertSame([], array_values(array_diff($changed, $recounted)), sprintf(
                    'Seed %d: %s entry %d (%s at %d) changed cars it does not recount.',
                    $seed, $what, $entry->id, $entry->fonteId === $sensorId ? 'a mark' : 'car ' . $entry->carro, $entry->tsMs
                ));
                $moved += count(array_diff($changed, $entry->carro === null ? [] : [$entry->carro]));
            }
        }
        $this->assertGreaterThan(0, $moved, 'some changes did move another car (through the marks)');
    }

    // ----- helpers -----

    /**
     * Car 7 crosses at 04:00.00 and car 8 at $secondMarkAt, the sensor marks
     * both; the counters type them in the given orders, 0.2 s apart, each
     * with its own delay. Both cars made their pass zero at 00:20.
     *
     * @param array<int, array{0: int, 1: int}> $orders per counter, the car it typed first and second
     */
    private function twoCarsBehindEachOther(string $secondMarkAt, array $orders): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->addSensor(4);
        $race->start('00:00');
        $race->firstCrossing([7, 8]);
        $delays = [1 => 0.2, 2 => 1.4, 3 => 0.9];
        foreach ($orders as $index => [$first, $second]) {
            $counter = $index + 1;
            $race->entry($counter, $first, sprintf('04:%05.2f', $delays[$counter]));
            $race->entry($counter, $second, sprintf('04:%05.2f', $delays[$counter] + 0.2));
        }
        $race->mark(4, '04:00.00');
        $race->mark(4, $secondMarkAt);
        return $race;
    }

    private function groupOf(EngineResult $result, int $car): PassGroup
    {
        foreach ($result->groups as $group) {
            if ($group->carro === $car && $group->primeiroTsMs > RaceScenario::ms('03:00')) {
                return $group;
            }
        }
        $this->fail('No pass of car ' . $car . ' at 04:00');
    }

    private function denseRace(int $seed, int $counters, float $unreadRate): SyntheticRaceConfig
    {
        $config = SyntheticRaceConfig::withSeed($seed)->withFinish();
        $config->cars = 40;
        $config->raceMinutes = 30;
        $config->baseLapSeconds = 150;
        $config->counters = $counters;
        $config->handovers = [];
        $config->unreadRate = $unreadRate;
        $config->timingSensor = true;
        $config->sensorMissRate = 0.02;
        $config->sensorFalseTriggersPerMinute = 1.0;
        return $config;
    }

    /**
     * What EngineInputLoader::loadForCars() loads for these cars (with no
     * cars, around these moments, as carsAroundSensorMarks() does): their
     * entries, the entries around the counters' "??" near them, and what
     * SensorTrains asks for, round after round.
     *
     * @param int[] $cars
     * @param int[] $momentsMs
     * @param int[] $changedCars
     */
    private static function whatTheRecountLoads(EngineInput $full, array $cars, array $momentsMs, array $changedCars = []): EngineInput
    {
        $wanted = array_fill_keys($cars, true);
        $loaded = clone $full;
        $loaded->entries = array_values(array_filter($full->entries, fn(Entry $entry) => $entry->carro !== null && isset($wanted[$entry->carro])));
        self::addAroundUnread($full, $loaded);
        $sensorIds = [];
        foreach ($full->sources as $source) {
            if ($source->isTimingSensor()) {
                $sensorIds[$source->id] = true;
            }
        }
        $marks = array_values(array_filter($full->entries, fn(Entry $entry) => isset($sensorIds[$entry->fonteId])));
        $ranges = [SensorTrains::MARCAS => [], SensorTrains::TUDO => []];
        while (true) {
            $missing = SensorTrains::rangesToLoad($loaded, $cars, $ranges, $momentsMs, $changedCars);
            if ($missing[SensorTrains::MARCAS] === [] && $missing[SensorTrains::TUDO] === []) {
                return $loaded;
            }
            $loaded->entries = self::union($loaded->entries, self::entriesIn($full->entries, $missing[SensorTrains::TUDO]));
            $loaded->entries = self::union($loaded->entries, self::entriesIn($marks, $missing[SensorTrains::MARCAS]));
            foreach ($missing as $kind => $missingRanges) {
                $ranges[$kind] = UnreadAssigner::merged(array_merge($ranges[$kind], $missingRanges));
            }
            self::addAroundUnread($full, $loaded);
        }
    }

    private static function addAroundUnread(EngineInput $full, EngineInput $loaded): void
    {
        $sourcesById = [];
        foreach ($full->sources as $source) {
            $sourcesById[$source->id] = $source;
        }
        $counterUnread = array_filter($full->entries, fn(Entry $entry) => $entry->carro === null && !$sourcesById[$entry->fonteId]->isTimingSensor());
        $items = DecisionApplier::apply($counterUnread, $full->decisions, $sourcesById)->unreadEntries;
        $moments = array_map(fn(Entry $entry) => $entry->tsMs, $loaded->entries);
        $ranges = UnreadAssigner::rangesToLoad($items, $moments, $full->settings);
        $loaded->entries = self::union($loaded->entries, self::entriesIn($full->entries, $ranges));
    }

    /**
     * @param Entry[] $entries
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return Entry[]
     */
    private static function entriesIn(array $entries, array $ranges): array
    {
        return array_values(array_filter($entries, function (Entry $entry) use ($ranges): bool {
            foreach ($ranges as [$fromMs, $untilMs]) {
                if ($entry->tsMs >= $fromMs && $entry->tsMs <= $untilMs) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * @param Entry[] $entries
     * @param Entry[] $more
     * @return Entry[]
     */
    private static function union(array $entries, array $more): array
    {
        $ids = array_fill_keys(array_map(fn(Entry $entry) => $entry->id, $entries), true);
        foreach ($more as $entry) {
            if (!isset($ids[$entry->id])) {
                $entries[] = $entry;
                $ids[$entry->id] = true;
            }
        }
        return $entries;
    }

    /** @return int[] */
    private static function carsWhoseRowsDiffer(EngineResult $before, EngineResult $after): array
    {
        $cars = [];
        foreach ([$before->laps, $after->laps] as $laps) {
            foreach ($laps as $lap) {
                $cars[$lap->carro] = true;
            }
        }
        $changed = [];
        foreach (array_keys($cars) as $car) {
            if (self::rows($before->laps, $car) !== self::rows($after->laps, $car)) {
                $changed[] = $car;
            }
        }
        sort($changed);
        return $changed;
    }

    /**
     * @param Lap[] $laps
     * @return string[] one line per row of the car: "number@time state sources"
     */
    private static function rows(array $laps, int $car): array
    {
        $rows = [];
        foreach ($laps as $lap) {
            if ($lap->carro === $car) {
                $rows[] = $lap->numeroVolta . '@' . $lap->tsMs . ' ' . $lap->estado . ' ' . $lap->fontes;
            }
        }
        return $rows;
    }
}
