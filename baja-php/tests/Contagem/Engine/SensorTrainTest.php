<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Flag;
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
     * SITUATION (race simulator, seed 5, two counters, "??" and sensor):
     * car 13 crosses at 04:05.67 and car 24 at about 04:08.4; the sensor
     * marks both and falsely triggers at 04:10.01. Counter 1 types 13 at
     * 04:06.42 and presses "??" for 24 at 04:08.23; counter 2 types 13 at
     * 04:07.09 and 24 at 04:08.08. One train: three marks, two crossings.
     *
     * EXPECTED: car 13's pass is not timed by 04:08.47 (car 24's mark) or
     * by the false trigger: pairing them so means marks two seconds after
     * every counter's Enter, which no counter's hand explains, and it once
     * beat the true pairing by more than the margin. The pass is timed by
     * its own mark or not at all, and the per-car recount says the same.
     */
    public function testMarksSecondsAfterEveryCounterDoNotTimeTheCarInFront(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->addSensor(3);
        $race->start('00:00');
        $race->firstCrossing([13, 24]);
        $race->entry(1, 13, '04:06.417');
        $race->entry(2, 13, '04:07.086');
        $race->entry(2, 24, '04:08.082');
        $race->unread(1, '04:08.228');
        foreach (['04:05.670', '04:08.471', '04:10.013'] as $at) {
            $race->mark(3, $at);
        }
        $input = $race->input('10:00');

        $result = Engine::run($input);

        $pass = $this->groupOf($result, 13);
        $this->assertTrue($pass->contado);
        $this->assertContains($pass->marcaSensor?->tsMs, [null, RaceScenario::ms('04:05.670')]);
        $this->assertLessThan(RaceScenario::ms('04:08.000'), $pass->tsMs);
        $alone = Engine::countCars(self::whatTheRecountLoads($input, [13], []), [13]);
        $this->assertSame(self::rows($result->laps, 13), self::rows($alone->lapsByCar[13], 13));
    }

    /**
     * SITUATION: a sensor whose marks come late (its beam a few metres past
     * the counters' line, or its clock slow). Cars 13 and 24 cross 1.6 s
     * apart at about 04:00; both counters type each car 0.3 s and 0.35 s
     * after it crosses, and the sensor marks each 0.8 s, or 1.3 s, after
     * the counters' Enter.
     *
     * WHAT WENT WRONG (review of the bound on the lag in a train): a lag
     * that late was not tried at all, so it did not count against the
     * lag one car gap away either; that one paired car 24 with car 13's
     * mark, with a clear margin.
     *
     * EXPECTED: each pass is timed by its own mark or by none, and the
     * per-car recount says the same.
     */
    public function testALateSensorDoesNotTimeACarByTheMarkOfTheCarInFront(): void
    {
        foreach ([0.8, 1.3] as $lateS) {
            $race = RaceScenario::withCounters(2);
            $race->addSensor(3);
            $race->start('00:00');
            $race->firstCrossing([13, 24]);
            $marks = [];
            foreach ([13 => 0.0, 24 => 1.6] as $car => $crossingS) {
                $race->entry(1, $car, sprintf('04:%06.3f', $crossingS + 0.3));
                $race->entry(2, $car, sprintf('04:%06.3f', $crossingS + 0.35));
                $marks[$car] = sprintf('04:%06.3f', $crossingS + 0.325 + $lateS);
                $race->mark(3, $marks[$car]);
            }
            $input = $race->input('10:00');

            $result = Engine::run($input);

            foreach ($marks as $car => $mark) {
                $this->assertContains($this->groupOf($result, $car)->marcaSensor?->tsMs, [null, RaceScenario::ms($mark)], "car $car, sensor {$lateS} s late");
                $alone = Engine::countCars(self::whatTheRecountLoads($input, [$car], []), [$car]);
                $this->assertSame(self::rows($result->laps, $car), self::rows($alone->lapsByCar[$car], $car));
            }
        }
    }

    /**
     * SITUATION: race control records the start at 01:00, a moment after
     * the pack really left. Car 7 crosses at 00:59.80 and the sensor marks
     * it there; the three counters press Enter after the start instant
     * (01:00.40, 01:00.60, 01:00.90), or the first of them just before it
     * (00:59.95). Car 7 then does three laps, each marked by the sensor.
     *
     * EXPECTED: the pass time decides, and it is the mark, before the start
     * in both cases: that crossing is before the start (kept and marked,
     * not counted), so car 7 has 2 laps either way. Without the mark the
     * first case is an ordinary pass at 01:00.60. When the counters typed
     * it does not change which side of the start the car crossed on.
     */
    public function testAMarkBeforeTheStartPutsThePassBeforeTheStartWhenEveryCounterTypedItAfter(): void
    {
        foreach (['01:00.40', '00:59.95'] as $firstCounterAt) {
            $race = RaceScenario::withCounters(3);
            $race->addSensor(4);
            $race->start('01:00');
            $race->entry(1, 7, $firstCounterAt);
            $race->entry(2, 7, '01:00.60');
            $race->entry(3, 7, '01:00.90');
            $race->mark(4, '00:59.80');
            foreach (['04:00', '07:00', '10:00'] as $at) {
                $race->pass(7, $at . '.50');
                $race->mark(4, $at . '.00');
            }

            $result = $race->run('12:00');

            $first = $result->groups[0];
            $this->assertSame(RaceScenario::ms('00:59.80'), $first->tsMs, $firstCounterAt);
            $this->assertTrue($first->antesDaLargada, $firstCounterAt);
            $this->assertFalse($first->contado, $firstCounterAt);
            $this->assertLapCounts([7 => 2], $result);
        }
    }

    /**
     * SITUATION: race control ends the race at 20:00. Car 7 crosses at
     * 19:59.80, the sensor marks it there, and all three counters press
     * Enter after the finish instant.
     *
     * EXPECTED: as with a precise source among the entries, the pass lies
     * on both sides of the finish (the mark before, the counters after),
     * and which side it counts on decides a lap: flag "no_encerramento".
     * It counts before the finish, by the mark.
     */
    public function testAMarkBeforeTheFinishWithEveryCounterAfterItIsAPassAcrossTheFinish(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->addSensor(4);
        $race->start('00:00');
        $race->firstCrossing([7]);
        $race->pass(7, '03:20');
        $race->finish('20:00');
        $race->entry(1, 7, '20:00.30');
        $race->entry(2, 7, '20:00.50');
        $race->entry(3, 7, '20:00.80');
        $race->mark(4, '19:59.80');

        $result = $race->run('22:00');

        $types = array_map(fn($flag) => $flag->tipo, $result->openFlags());
        $this->assertContains(Flag::NO_ENCERRAMENTO, $types);
        $last = $result->groups[count($result->groups) - 1];
        $this->assertSame(RaceScenario::ms('19:59.80'), $last->tsMs);
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

        $this->assertSame([SensorTrains::MARCAS => [], SensorTrains::TUDO => [], SensorTrains::ILEGIVEIS => []], SensorTrains::rangesToLoad($input, [5], [SensorTrains::MARCAS => [], SensorTrains::TUDO => []]));
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
     * there. A recount of those cars is all the change needs. For an
     * upload that only added the entry, SensorTrains::carsWhoseMarksMoved()
     * names fewer cars, and still every car the entry moved.
     */
    #[DataProvider('races')]
    public function testAnUploadMovesNoPassTimeOutsideTheCarsItRecounts(int $seed, int $counters): void
    {
        $race = SyntheticRace::generate($this->denseRace($seed, $counters, 0.0));
        $input = $race->input();
        $dice = new Randomizer(new Mt19937($seed));
        $sensorId = $race->sensorSourceId();
        $moved = 0;
        $spared = 0;
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
                if ($what === 'stored') {
                    $loaded = self::whatTheRecountLoads($after, [], [$entry->tsMs], $changedCars, [$entry->id]);
                    $fewer = array_merge($changedCars, SensorTrains::carsWhoseMarksMoved($loaded, [$entry->id]));
                    $this->assertSame([], array_values(array_diff($changed, $fewer)), sprintf('Seed %d: the new entry %d changed cars the precise rule does not recount.', $seed, $entry->id));
                    $spared += count(array_diff($recounted, $fewer));
                }
                $moved += count(array_diff($changed, $entry->carro === null ? [] : [$entry->carro]));
            }
        }
        $this->assertGreaterThan(0, $moved, 'some changes did move another car (through the marks)');
        $this->assertGreaterThan(0, $spared, 'the precise rule spared recounts');
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
     * @param int[] $withoutEntryIds also complete what the race without these entries needs
     */
    private static function whatTheRecountLoads(EngineInput $full, array $cars, array $momentsMs, array $changedCars = [], array $withoutEntryIds = []): EngineInput
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
        $resolved = [];
        while (true) {
            $missing = SensorTrains::rangesToLoad($loaded, $cars, $ranges, $momentsMs, $changedCars);
            if ($withoutEntryIds !== []) {
                $before = SensorTrains::rangesToLoad(SensorTrains::without($loaded, $withoutEntryIds), $cars, $ranges, $momentsMs, $changedCars);
                foreach ($before as $kind => $more) {
                    $missing[$kind] = $kind === SensorTrains::ILEGIVEIS ? array_merge($missing[$kind], $more) : UnreadAssigner::merged(array_merge($missing[$kind], $more));
                }
            }
            $unread = array_values(array_filter($loaded->entries, fn(Entry $entry) => in_array($entry->id, $missing[SensorTrains::ILEGIVEIS], true) && !isset($resolved[$entry->id])));
            foreach ($unread as $entry) {
                $resolved[$entry->id] = true;
            }
            if ($missing[SensorTrains::MARCAS] === [] && $missing[SensorTrains::TUDO] === [] && $unread === []) {
                return $loaded;
            }
            $loaded->entries = self::union($loaded->entries, self::entriesIn($full->entries, $missing[SensorTrains::TUDO]));
            $loaded->entries = self::union($loaded->entries, self::entriesIn($marks, $missing[SensorTrains::MARCAS]));
            foreach ([SensorTrains::MARCAS, SensorTrains::TUDO] as $kind) {
                $ranges[$kind] = UnreadAssigner::merged(array_merge($ranges[$kind], $missing[$kind]));
            }
            if ($unread !== []) {
                self::addAroundUnread($full, $loaded, $unread);
            }
        }
    }

    /**
     * @param Entry[]|null $around only around these (the ones just loaded); null: around every loaded entry
     */
    private static function addAroundUnread(EngineInput $full, EngineInput $loaded, ?array $around = null): void
    {
        $sourcesById = [];
        foreach ($full->sources as $source) {
            $sourcesById[$source->id] = $source;
        }
        $counterUnread = array_filter($full->entries, fn(Entry $entry) => $entry->carro === null && !$sourcesById[$entry->fonteId]->isTimingSensor());
        $items = DecisionApplier::apply($counterUnread, $full->decisions, $sourcesById)->unreadEntries;
        $moments = array_map(fn(Entry $entry) => $entry->tsMs, $around ?? $loaded->entries);
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
