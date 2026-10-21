<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Source;

/**
 * What a timing sensor's marks are matched against, and how far around
 * them a per-car recount must look to match them as the whole race does.
 *
 * THE CROSSINGS (crossings()). Per car, the counters' readings (after
 * decisions, a "??" the engine gave the car included) chained where two
 * are no more than the agreement spread apart (`dispersao_alerta_ms`, 4 s):
 * the readings of one crossing of the line. Each is put on the time line at
 * its EARLIEST reading: a counter presses Enter as the car is on the line
 * or after, seldom before, so that is the reading nearest the moment the
 * sensor saw. They are worked out from the readings alone, not from the
 * pass groups, so that what decides a mark lies within a few seconds of it
 * and a per-car recount can load exactly that (rangesToLoad()).
 *
 * A TRAIN (trains()) is a sensor's marks and the crossings whose tolerance
 * windows chain: a mark and a crossing within the tolerance of each other
 * belong to the same train, and so does everything within the tolerance of
 * either, and so on. Marks of different trains can never be paired with
 * the same crossing, so each train is decided on its own. Most trains are
 * one mark and one crossing; cars a second apart make trains of two, three
 * or more.
 *
 * THE COUNTERS' ORDER (countersOrder()). In a train of cars a second apart
 * the times cannot tell which mark is which car, but the counters can:
 * each typed the cars in the order they crossed. Where every counter that
 * read two or more of the train's cars has them in the same relative order,
 * and together they put every car before or after every other, that is the
 * order the marks were made in.
 *
 * THE PER-CAR RECOUNT of an upload must time its cars' passes as the whole
 * race does. It loads what their trains need (rangesToLoad(), round after
 * round: the marks near each crossing, the readings a few seconds around
 * those marks, the entries around a "??" that may be a reading of one of
 * them), and recounts the other cars whose marks the upload moved: for an
 * upload that only added entries, the cars whose mark differs with and
 * without them (carsWhoseMarksMoved()); otherwise every car of the trains
 * near what it changed (carsAround()).
 */
final class SensorTrains
{
    /** A longer train is never put in the counters' order: comparing every pair would cost more than it is worth. */
    private const MAX_CROSSINGS_IN_ORDER = 12;

    /**
     * True when a timing sensor takes part in the count (or did, for a
     * while): only then are marks matched at all, and only then does a
     * per-car recount look around its cars for them.
     *
     * @param Source[] $sources
     */
    public static function anySensorCounts(array $sources): bool
    {
        foreach ($sources as $source) {
            if ($source->isTimingSensor() && $source->mayHaveCounted()) {
                return true;
            }
        }
        return false;
    }

    /** rangesToLoad(): stretches of time where only the sensors' marks are needed. */
    public const MARCAS = 'marcas';

    /** rangesToLoad(): stretches of time where every entry is needed (the readings of every car, and the marks). */
    public const TUDO = 'tudo';

    /**
     * rangesToLoad(): the counters' "??" that may be readings of a train's
     * crossing (within a spread of one of its readings, or the tolerance of
     * one of its marks), by id: which car each is needs the entries around
     * it (UnreadAssigner::rangesToLoad()).
     */
    public const ILEGIVEIS = 'ilegiveis';

    /**
     * What a per-car recount must still load to match the marks around its
     * cars exactly as the whole race does: the stretches of time where the
     * sensors' marks are missing (`MARCAS`), and those where every entry is
     * (`TUDO`). Call it again after loading what it asked for, until it
     * asks for nothing: each round looks at the trains it can see and asks
     * for what lies around their edges, so a train is complete when nothing
     * is missing.
     *
     * What a train needs: for each of its crossings, the marks within the
     * tolerance of it and, unless all the car's entries are loaded anyway,
     * the readings a spread before and after it (to know where it begins
     * and ends); for each of its marks, every reading within the tolerance
     * of it (and the spread before that, where a crossing may begin). So a
     * crossing alone with its mark costs the marks around it and a few
     * seconds of readings around that mark; a crossing no mark is near
     * costs only the marks. The trains looked at are those of every
     * crossing of `$cars` (all their entries are loaded by the caller), and
     * those a change at `$momentsMs` to the entries of `$changedCars` may
     * have touched (see carsAround()).
     *
     * @param int[] $cars
     * @param array{marcas: array<int, array{0: int, 1: int}>, tudo: array<int, array{0: int, 1: int}>} $loaded what was loaded so far, each merged
     * @param int[] $momentsMs
     * @param int[] $changedCars
     * @return array{marcas: array<int, array{0: int, 1: int}>, tudo: array<int, array{0: int, 1: int}>, ilegiveis: int[]} merged;
     *         the ranges both empty when nothing is missing
     */
    public static function rangesToLoad(EngineInput $input, array $cars, array $loaded, array $momentsMs = [], array $changedCars = []): array
    {
        $missing = [self::MARCAS => [], self::TUDO => [], self::ILEGIVEIS => []];
        if (($cars === [] && $momentsMs === []) || !self::anySensorCounts($input->sources)) {
            return $missing;
        }
        $picture = self::picture($input);
        $spreadMs = $input->settings->agreementSpreadMs();
        $toleranceMs = $picture['tolerancia'];
        [$seedCrossings, $seedMarks] = self::seeds($picture, $cars, $momentsMs, $changedCars, self::markMoments($input, $momentsMs), $spreadMs, $toleranceMs);

        $fullyLoaded = array_fill_keys($cars, true);
        $needs = [self::MARCAS => [], self::TUDO => []];
        $crossingNeeds = function (array $crossing, bool $marksNearAnyReading) use (&$needs, $fullyLoaded, $spreadMs, $toleranceMs): void {
            $needs[self::MARCAS][] = $marksNearAnyReading
                ? [$crossing['de_ms'] - $toleranceMs, $crossing['ate_ms'] + $toleranceMs]
                : [$crossing['ts_ms'] - $toleranceMs, $crossing['ts_ms'] + $toleranceMs];
            if (!isset($fullyLoaded[$crossing['carro']])) {
                $needs[self::TUDO][] = [$crossing['de_ms'] - $spreadMs, $crossing['ate_ms'] + $spreadMs];
            }
        };
        foreach ($momentsMs as $momentMs) {
            // The changed cars' crossings around the moment, and the marks there.
            $needs[self::TUDO][] = [$momentMs - $spreadMs, $momentMs + $spreadMs];
        }
        $firstRound = $loaded[self::MARCAS] === [] && $loaded[self::TUDO] === [];
        foreach (array_keys($seedCrossings) as $index) {
            $crossing = $picture['crossings'][$index];
            // A changed crossing: the marks near any of its readings are seeds.
            $crossingNeeds($crossing, !isset($fullyLoaded[$crossing['carro']]));
            if ($firstRound && isset($fullyLoaded[$crossing['carro']])) {
                // What a mark next to it will need, asked for at once: most
                // crossings have one, and a round costs more than the rows.
                $needs[self::TUDO][] = [$crossing['ts_ms'] - 2 * $toleranceMs - $spreadMs, $crossing['ts_ms'] + 2 * $toleranceMs];
            }
        }
        $marksToSee = [];
        foreach ($picture['marcas'] as $marks) {
            foreach ($marks as $mark) {
                if (isset($seedMarks[$mark->id])) {
                    $marksToSee[] = $mark;
                }
            }
        }
        $trainMarkTimes = [];
        $trainReadingTimes = [];
        foreach (self::trainsOf($picture, $seedCrossings, $seedMarks) as [$marks, $crossings]) {
            foreach ($crossings as $crossing) {
                $crossingNeeds($crossing, false);
                foreach ($crossing['entradas'] as $reading) {
                    $trainReadingTimes[] = $reading->tsMs;
                }
            }
            foreach ($marks as $mark) {
                $marksToSee[] = $mark;
                $trainMarkTimes[] = $mark->tsMs;
            }
        }
        foreach ($marksToSee as $mark) {
            $needs[self::TUDO][] = [$mark->tsMs - $toleranceMs - $spreadMs, $mark->tsMs + $toleranceMs];
        }
        sort($trainMarkTimes);
        sort($trainReadingTimes);
        $isNear = function (array $sortedTimes, int $tsMs, int $withinMs): bool {
            $first = Stats::countAtMost($sortedTimes, $tsMs - $withinMs - 1);
            return $first < count($sortedTimes) && $sortedTimes[$first] <= $tsMs + $withinMs;
        };
        foreach ($picture['ilegiveis'] as $unread) {
            if ($isNear($trainMarkTimes, $unread->tsMs, $toleranceMs) || $isNear($trainReadingTimes, $unread->tsMs, $spreadMs)) {
                $missing[self::ILEGIVEIS][] = $unread->id;
            }
        }

        // What is missing is asked for with a little room around it: a
        // crossing alone with its mark is then settled in one round, and
        // little more than a train needs is read. Marks are few, so they
        // are asked for with more room.
        $everything = UnreadAssigner::merged($loaded[self::TUDO]);
        $covered = [
            self::MARCAS => UnreadAssigner::merged(array_merge($loaded[self::MARCAS], $loaded[self::TUDO])),
            self::TUDO => $everything,
        ];
        $padMs = [self::MARCAS => $toleranceMs + $spreadMs, self::TUDO => $toleranceMs];
        foreach ($needs as $kind => $ranges) {
            $starts = array_column($covered[$kind], 0);
            foreach ($ranges as [$fromMs, $untilMs]) {
                $index = Stats::countAtMost($starts, $fromMs) - 1;
                if ($index < 0 || $covered[$kind][$index][1] < $untilMs) {
                    $missing[$kind][] = [$fromMs - $padMs[$kind], $untilMs + $padMs[$kind]];
                }
            }
            $missing[$kind] = UnreadAssigner::merged($missing[$kind]);
        }
        return $missing;
    }

    /**
     * The cars whose passes a change may make the marks time otherwise. The
     * change: entries stored, voided or given a car at `$momentsMs`, that
     * count (or counted) for `$changedCars`, or marks of a sensor stored or
     * voided there. The cars: every car of a train with a mark within the
     * tolerance of a moment, or of a reading of a crossing of a changed car
     * read within a spread of a moment; and, where the moment is a
     * sensor's own mark, the cars whose crossings lie within the tolerance
     * of it. The input must hold what rangesToLoad() asks for, with these
     * moments and changed cars.
     *
     * Why that is enough: a crossing the change made, unmade or moved is of
     * a changed car and has a reading within a spread of the moment (or had,
     * the one at the moment). Any train it is in, or was in, holds a mark
     * within the tolerance of one of those readings, and every other part
     * of such a train is still joined to one of those marks. A mark that
     * came or went was joined to the crossings within the tolerance of it:
     * every part of its train, before or after, holds one of those (they
     * are looked at too, with their trains).
     *
     * @param int[] $momentsMs
     * @param int[] $changedCars
     * @return int[]
     */
    public static function carsAround(EngineInput $input, array $momentsMs, array $changedCars): array
    {
        if ($momentsMs === [] || !self::anySensorCounts($input->sources)) {
            return [];
        }
        $picture = self::picture($input);
        $spreadMs = $input->settings->agreementSpreadMs();
        $toleranceMs = $picture['tolerancia'];
        $markMoments = self::markMoments($input, $momentsMs);
        [$seedCrossings, $seedMarks] = self::seeds($picture, [], $momentsMs, $changedCars, $markMoments, $spreadMs, $toleranceMs);
        $cars = [];
        foreach (self::trainsOf($picture, $seedCrossings, $seedMarks) as [, $crossings]) {
            foreach ($crossings as $crossing) {
                $cars[$crossing['carro']] = true;
            }
        }
        foreach ($picture['crossings'] as $crossing) {
            foreach ($markMoments as $momentMs) {
                if (abs($crossing['ts_ms'] - $momentMs) <= $toleranceMs) {
                    $cars[$crossing['carro']] = true;
                }
            }
        }
        $cars = array_keys($cars);
        sort($cars);
        return $cars;
    }

    /**
     * The cars whose passes the marks time otherwise because these entries
     * were added (and nothing else changed): the marks are matched with and
     * without them, and every car a mark goes to, or went to, otherwise is
     * named. A new reading of a car rarely changes what its neighbours in
     * a train get; when it does, they are recounted. The input must hold
     * what rangesToLoad() asks for, around the entries' moments, for the
     * race with them and without them.
     *
     * @param int[] $newEntryIds
     * @return int[]
     */
    public static function carsWhoseMarksMoved(EngineInput $input, array $newEntryIds): array
    {
        if (!self::anySensorCounts($input->sources)) {
            return [];
        }
        $before = self::crossingOfEachMark(self::without($input, $newEntryIds));
        $after = self::crossingOfEachMark($input);
        $cars = [];
        foreach (array_keys($before + $after) as $markId) {
            $was = $before[$markId] ?? null;
            $is = $after[$markId] ?? null;
            if ($was === $is) {
                continue;
            }
            foreach ([$was, $is] as $crossing) {
                if ($crossing !== null) {
                    $cars[$crossing[0]] = true;
                }
            }
        }
        $cars = array_keys($cars);
        sort($cars);
        return $cars;
    }

    /** The same input without these entries. */
    public static function without(EngineInput $input, array $entryIds): EngineInput
    {
        $leftOut = array_fill_keys($entryIds, true);
        $before = clone $input;
        $before->entries = array_values(array_filter($input->entries, fn(Entry $entry): bool => !isset($leftOut[$entry->id])));
        return $before;
    }

    /**
     * Each matched mark of the input: the car and the first reading of the
     * crossing it times (as UnreadAssigner::matchMarks() pairs them, before
     * they are given to pass groups).
     *
     * @return array<int, array{0: int, 1: int}> mark id => [car, id of the crossing's first reading]
     */
    private static function crossingOfEachMark(EngineInput $input): array
    {
        $unread = LapCounter::count($input, [])->unread;
        if ($unread === null) {
            return [];
        }
        $sensorIds = [];
        foreach ($input->sources as $source) {
            if ($source->isTimingSensor()) {
                $sensorIds[$source->id] = true;
            }
        }
        $crossings = [];
        foreach ($unread->assignments as $entryId => $assignment) {
            if (isset($sensorIds[$unread->sourceOfEntry($entryId)])) {
                $crossings[$entryId] = [$assignment['carro'], min($assignment['passagem_ids'])];
            }
        }
        return $crossings;
    }

    /**
     * The crossings and the marks of an input as LapCounter and
     * UnreadAssigner::matchMarks() see them: after decisions, with the
     * "??" the engine gives a car among the readings.
     *
     * @return array{crossings: array<int, array<string, mixed>>, marcas: array<int, Entry[]>, ilegiveis: Entry[], tolerancia: int}
     */
    private static function picture(EngineInput $input): array
    {
        $sourcesById = [];
        foreach ($input->sources as $source) {
            $sourcesById[$source->id] = $source;
        }
        $applied = DecisionApplier::apply($input->entries, $input->decisions, $sourcesById);
        $knownCars = $input->validCars === [] ? [] : array_fill_keys(array_merge($input->validCars, $input->unclassifiedCars), true);
        if ($applied->unreadEntries !== []) {
            UnreadAssigner::assign($applied, $input->settings, $sourcesById, $knownCars);
        }
        $marksBySource = [];
        foreach ($applied->sensorMarks as $mark) {
            if ($mark->qualidadeTempo !== Entry::TEMPO_DESCONHECIDO) {
                $marksBySource[$mark->fonteId][] = $mark;
            }
        }
        ksort($marksBySource);
        foreach ($marksBySource as $fonteId => $marks) {
            usort($marks, fn(Entry $a, Entry $b): int => [$a->tsMs, $a->id] <=> [$b->tsMs, $b->id]);
            $marksBySource[$fonteId] = $marks;
        }
        return [
            'crossings' => self::crossings($applied->entries, $sourcesById, $knownCars, $input->settings->agreementSpreadMs()),
            'marcas' => $marksBySource,
            // The counters' "??" nobody gave a car, of known time.
            'ilegiveis' => array_values(array_filter($applied->unreadEntries, fn(Entry $entry): bool => $entry->qualidadeTempo !== Entry::TEMPO_DESCONHECIDO)),
            'tolerancia' => UnreadAssigner::sensorToleranceMs($input->settings),
        ];
    }

    /**
     * The moments, of these, at which a timing sensor has an entry (a mark,
     * stored or voided): what changed there is a mark.
     *
     * @param int[] $momentsMs
     * @return int[]
     */
    private static function markMoments(EngineInput $input, array $momentsMs): array
    {
        if ($momentsMs === []) {
            return [];
        }
        $sensorIds = [];
        foreach ($input->sources as $source) {
            if ($source->isTimingSensor()) {
                $sensorIds[$source->id] = true;
            }
        }
        $moments = array_fill_keys($momentsMs, true);
        $found = [];
        foreach ($input->entries as $entry) {
            if (isset($sensorIds[$entry->fonteId], $moments[$entry->tsMs])) {
                $found[$entry->tsMs] = true;
            }
        }
        return array_keys($found);
    }

    /**
     * Where to start looking: the crossings of the cars, the crossings of
     * the changed cars read within a spread of a moment, the crossings
     * within the tolerance of a mark that came or went, and the marks
     * within the tolerance of a moment or of any reading of the changed
     * crossings.
     *
     * @param array<string, mixed> $picture
     * @param int[] $cars
     * @param int[] $momentsMs
     * @param int[] $changedCars
     * @param int[] $markMomentsMs
     * @return array{0: array<int, true>, 1: array<int, true>} positions in the picture's crossings, and ids of marks
     */
    private static function seeds(array $picture, array $cars, array $momentsMs, array $changedCars, array $markMomentsMs, int $spreadMs, int $toleranceMs): array
    {
        $wantedCars = array_fill_keys($cars, true);
        $changed = array_fill_keys($changedCars, true);
        $moments = $momentsMs;
        sort($moments);
        $markMoments = $markMomentsMs;
        sort($markMoments);
        $seedCrossings = [];
        $near = $moments;
        foreach ($picture['crossings'] as $index => $crossing) {
            if (isset($wantedCars[$crossing['carro']])) {
                $seedCrossings[$index] = true;
                continue;
            }
            $first = Stats::countAtMost($markMoments, $crossing['ts_ms'] - $toleranceMs - 1);
            if ($first < count($markMoments) && $markMoments[$first] <= $crossing['ts_ms'] + $toleranceMs) {
                $seedCrossings[$index] = true;
            }
            if ($moments === [] || !isset($changed[$crossing['carro']])) {
                continue;
            }
            foreach ($crossing['entradas'] as $reading) {
                $first = Stats::countAtMost($moments, $reading->tsMs - $spreadMs - 1);
                if ($first < count($moments) && $moments[$first] <= $reading->tsMs + $spreadMs) {
                    $seedCrossings[$index] = true;
                    foreach ($crossing['entradas'] as $any) {
                        $near[] = $any->tsMs;
                    }
                    break;
                }
            }
        }
        $seedMarks = [];
        if ($moments === []) {
            return [$seedCrossings, $seedMarks];
        }
        sort($near);
        foreach ($picture['marcas'] as $marks) {
            foreach ($marks as $mark) {
                $first = Stats::countAtMost($near, $mark->tsMs - $toleranceMs - 1);
                if ($first < count($near) && $near[$first] <= $mark->tsMs + $toleranceMs) {
                    $seedMarks[$mark->id] = true;
                }
            }
        }
        return [$seedCrossings, $seedMarks];
    }

    /**
     * The trains, of every sensor, that hold one of the given crossings or
     * marks.
     *
     * @param array<string, mixed> $picture
     * @param array<int, true> $seedCrossings positions in the picture's crossings
     * @param array<int, true> $seedMarks mark ids
     * @return array<int, array{0: Entry[], 1: array<int, array<string, mixed>>}> each train's marks and crossings
     */
    private static function trainsOf(array $picture, array $seedCrossings, array $seedMarks): array
    {
        $found = [];
        foreach ($picture['marcas'] as $fonteId => $marks) {
            $theirs = [];
            foreach ($picture['crossings'] as $index => $crossing) {
                if (!isset($crossing['sensores'][$fonteId])) {
                    $theirs[] = $crossing + ['posicao' => $index];
                }
            }
            foreach (self::trains($marks, $theirs, $picture['tolerancia']) as $train) {
                $isWanted = false;
                foreach ($train['marcas'] as $mark) {
                    $isWanted = $isWanted || isset($seedMarks[$mark->id]);
                }
                foreach ($train['passagens'] as $crossing) {
                    $isWanted = $isWanted || isset($seedCrossings[$crossing['posicao']]);
                }
                if ($isWanted && $train['passagens'] !== []) {
                    $found[] = [$train['marcas'], $train['passagens']];
                }
            }
        }
        return $found;
    }

    /**
     * The crossings the counters read, earliest first. A pass a reviewer
     * added, an entry of unknown time and a number that is no team are no
     * reading. A sensor's mark a reviewer gave a car is no reading either,
     * but it chains with the readings of its car: that crossing is already
     * timed by that mark (`sensores`), and no other mark of that sensor may
     * be paired with it.
     *
     * @param Entry[] $entries entries after decisions (AppliedDecisions::$entries)
     * @param array<int, Source> $sourcesById
     * @param array<int, true> $knownCars car numbers of the teams; empty when not known
     * @return array<int, array{carro: int, ts_ms: int, ate_ms: int, entradas: Entry[], sensores: array<int, true>, passagem_ids: int[], fonte_ids: int[]}>
     */
    public static function crossings(array $entries, array $sourcesById, array $knownCars, int $spreadMs): array
    {
        $wanted = [];
        $times = [];
        foreach ($entries as $entry) {
            if ($entry->fonteId === Source::REVISOR_ID || $entry->carro === null
                || $entry->qualidadeTempo === Entry::TEMPO_DESCONHECIDO
                || ($knownCars !== [] && !isset($knownCars[$entry->carro]))) {
                continue;
            }
            $wanted[] = $entry;
            $times[] = $entry->tsMs;
        }
        // Sorting the plain list of times and following the order is much
        // faster than sorting the objects with a comparison callback.
        // Entries sharing a millisecond are put in id order as they go by.
        asort($times);
        $byCar = [];
        foreach (array_keys($times) as $index) {
            $entry = $wanted[$index];
            $carEntries = &$byCar[$entry->carro];
            $position = $carEntries === null ? 0 : count($carEntries);
            while ($position > 0 && $carEntries[$position - 1]->tsMs === $entry->tsMs && $carEntries[$position - 1]->id > $entry->id) {
                $carEntries[$position] = $carEntries[$position - 1];
                $position--;
            }
            $carEntries[$position] = $entry;
            unset($carEntries);
        }
        $crossings = [];
        foreach ($byCar as $carro => $carEntries) {
            $chain = [];
            foreach ($carEntries as $entry) {
                if ($chain !== [] && $entry->tsMs - $chain[count($chain) - 1]->tsMs > $spreadMs) {
                    self::addCrossing($crossings, (int) $carro, $chain, $sourcesById);
                    $chain = [];
                }
                $chain[] = $entry;
            }
            self::addCrossing($crossings, (int) $carro, $chain, $sourcesById);
        }
        usort($crossings, fn(array $a, array $b): int => [$a['ts_ms'], $a['carro']] <=> [$b['ts_ms'], $b['carro']]);
        return $crossings;
    }

    /**
     * @param array<int, array<string, mixed>> $crossings
     * @param Entry[] $chain one car's entries of one crossing, in time order
     * @param array<int, Source> $sourcesById
     */
    private static function addCrossing(array &$crossings, int $carro, array $chain, array $sourcesById): void
    {
        $readings = [];
        $sensors = [];
        $sources = [];
        foreach ($chain as $entry) {
            $source = $sourcesById[$entry->fonteId] ?? null;
            if ($source !== null && $source->isTimingSensor()) {
                $sensors[$entry->fonteId] = true;
                continue;
            }
            $readings[] = $entry;
            $sources[$entry->fonteId] = true;
        }
        if ($readings === []) {
            return;
        }
        $sourceIds = array_keys($sources);
        sort($sourceIds);
        $crossings[] = [
            'carro' => $carro,
            'ts_ms' => $readings[0]->tsMs,
            'ate_ms' => $chain[count($chain) - 1]->tsMs,
            'de_ms' => $chain[0]->tsMs,
            'entradas' => $readings,
            'sensores' => $sensors,
            'passagem_ids' => array_map(fn(Entry $entry): int => $entry->id, $readings),
            'fonte_ids' => $sourceIds,
        ];
    }

    /**
     * One sensor's marks cut into trains, earliest first. A mark no
     * crossing is near is a train of its own, with no crossing.
     *
     * @param Entry[] $marks one sensor's marks, in time order
     * @param array<int, array<string, mixed>> $crossings by ts_ms (crossings()), the ones this sensor may time
     * @return array<int, array{marcas: Entry[], passagens: array<int, array<string, mixed>>}>
     */
    public static function trains(array $marks, array $crossings, int $toleranceMs): array
    {
        $markCount = count($marks);
        $parent = range(0, $markCount + count($crossings) - 1);
        $find = function (int $item) use (&$parent): int {
            while ($parent[$item] !== $item) {
                $parent[$item] = $parent[$parent[$item]];
                $item = $parent[$item];
            }
            return $item;
        };
        $crossingTimes = array_column($crossings, 'ts_ms');
        foreach ($marks as $position => $mark) {
            $until = $mark->tsMs + $toleranceMs;
            for ($index = Stats::countAtMost($crossingTimes, $mark->tsMs - $toleranceMs - 1); $index < count($crossings) && $crossingTimes[$index] <= $until; $index++) {
                $a = $find($position);
                $b = $find($markCount + $index);
                if ($a !== $b) {
                    $parent[max($a, $b)] = min($a, $b);
                }
            }
        }
        // Every train has a mark, and the smallest item of a set is a mark
        // whenever the set has one: so each train is named by its first mark.
        $trains = [];
        foreach ($marks as $position => $mark) {
            $trains[$find($position)]['marcas'][] = $mark;
        }
        foreach ($crossings as $index => $crossing) {
            $root = $find($markCount + $index);
            if (isset($trains[$root])) {
                $trains[$root]['passagens'][] = $crossing;
            }
        }
        $ordered = [];
        foreach ($trains as $train) {
            $ordered[] = ['marcas' => $train['marcas'], 'passagens' => $train['passagens'] ?? []];
        }
        return $ordered;
    }

    /**
     * The crossings of a train timed with each counter's habit within the
     * train taken out: a counter that presses a second late does so on
     * every car of the train, so the gaps between the crossings come out as
     * the gaps between the cars, whoever of the counters missed one. The
     * habit is measured against the middle of the counters' readings of
     * each crossing, twice over; one crossing alone keeps its time.
     *
     * @param array<int, array<string, mixed>> $crossings
     * @return array<int, array<string, mixed>> the same crossings, `ts_ms` replaced
     */
    public static function withoutHabits(array $crossings): array
    {
        if (count($crossings) < 2) {
            return $crossings;
        }
        $readings = [];
        foreach ($crossings as $index => $crossing) {
            foreach ($crossing['entradas'] as $entry) {
                $earliest = $readings[$index][$entry->fonteId] ?? null;
                if ($earliest === null || $entry->tsMs < $earliest) {
                    $readings[$index][$entry->fonteId] = $entry->tsMs;
                }
            }
        }
        $times = [];
        foreach ($readings as $index => $bySource) {
            $times[$index] = (float) Stats::median($bySource);
        }
        for ($round = 0; $round < 2; $round++) {
            $offsets = [];
            foreach ($readings as $index => $bySource) {
                foreach ($bySource as $fonteId => $tsMs) {
                    $offsets[$fonteId][] = $tsMs - $times[$index];
                }
            }
            $habits = array_map(fn(array $values): float => (float) Stats::median($values), $offsets);
            foreach ($readings as $index => $bySource) {
                $corrected = [];
                foreach ($bySource as $fonteId => $tsMs) {
                    $corrected[] = $tsMs - $habits[$fonteId];
                }
                $times[$index] = (float) Stats::median($corrected);
            }
        }
        foreach ($crossings as $index => $crossing) {
            $crossings[$index]['ts_ms'] = (int) round($times[$index]);
        }
        return $crossings;
    }

    /**
     * How long after each counter's reading of one crossing it read the
     * other, for every counter that read both (its earliest reading of
     * each). A counter's habit is the same on both, so this is the gap
     * between the two cars, give or take how steady the counter's hand is.
     *
     * @param array<string, mixed> $first
     * @param array<string, mixed> $second
     * @return int[]
     */
    public static function readingGaps(array $first, array $second): array
    {
        $earliest = function (array $crossing): array {
            $times = [];
            foreach ($crossing['entradas'] as $entry) {
                if (!isset($times[$entry->fonteId]) || $entry->tsMs < $times[$entry->fonteId]) {
                    $times[$entry->fonteId] = $entry->tsMs;
                }
            }
            return $times;
        };
        $from = $earliest($first);
        $gaps = [];
        foreach ($earliest($second) as $fonteId => $tsMs) {
            if (isset($from[$fonteId])) {
                $gaps[] = $tsMs - $from[$fonteId];
            }
        }
        return $gaps;
    }

    /**
     * The order the counters typed a train's crossings in, as positions in
     * `$crossings`; null when they do not tell it: two counters disagree on
     * two cars, or nobody puts two of them in an order (no counter read
     * both, and no third car links them), or two crossings are of one car.
     * Each counter's earliest reading of each crossing is what counts; two
     * readings in the same millisecond say nothing.
     *
     * @param array<int, array<string, mixed>> $crossings
     * @return int[]|null
     */
    public static function countersOrder(array $crossings): ?array
    {
        $count = count($crossings);
        if ($count < 2 || $count > self::MAX_CROSSINGS_IN_ORDER) {
            return null;
        }
        $timesBySource = [];
        $cars = [];
        foreach ($crossings as $index => $crossing) {
            $cars[$crossing['carro']] = true;
            foreach ($crossing['entradas'] as $entry) {
                $earliest = $timesBySource[$entry->fonteId][$index] ?? null;
                if ($earliest === null || $entry->tsMs < $earliest) {
                    $timesBySource[$entry->fonteId][$index] = $entry->tsMs;
                }
            }
        }
        if (count($cars) !== $count) {
            return null;
        }
        $before = array_fill(0, $count, array_fill(0, $count, false));
        foreach ($timesBySource as $times) {
            foreach ($times as $a => $aMs) {
                foreach ($times as $b => $bMs) {
                    if ($aMs < $bMs) {
                        $before[$a][$b] = true;
                    }
                }
            }
        }
        // What follows from it: A before B and B before C puts A before C.
        for ($k = 0; $k < $count; $k++) {
            for ($i = 0; $i < $count; $i++) {
                if (!$before[$i][$k]) {
                    continue;
                }
                for ($j = 0; $j < $count; $j++) {
                    if ($before[$k][$j]) {
                        $before[$i][$j] = true;
                    }
                }
            }
        }
        $earlierCount = array_fill(0, $count, 0);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($before[$i][$j] === $before[$j][$i]) {
                    // Both: the counters disagree. Neither: nobody says.
                    return null;
                }
                $earlierCount[$before[$i][$j] ? $j : $i]++;
            }
        }
        // In a total order the number of crossings before each one is its place.
        asort($earlierCount);
        return array_keys($earlierCount);
    }
}
