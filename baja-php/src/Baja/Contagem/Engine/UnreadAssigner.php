<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Which car an unread pass most likely was.
 *
 * An unread pass is an entry with no car. A counter pressed "??" (a car
 * went by and the number could not be read), a camera could not read the
 * plate, or a timing sensor marked a crossing (it never knows the car).
 * The other sources usually did read that car: the "??" of Contagem 2 at
 * 01:02:03.4 is, nearly always, the car 7 that Contagem 1 and 3 typed at
 * 01:02:03.1. This class finds that car, and gives it to the entry only
 * when nothing else is a believable answer.
 *
 * A COUNTER'S "??" (step 1b, between the decisions and the matching). For
 * each source S on its own, the unread entries of S are put against the
 * CROSSINGS the other sources read: per car, the read entries no more than
 * a matching window apart (`janela_ms`, as PassMatcher cuts a car's entries
 * into stretches), timed by the median of their times. A crossing in which
 * S itself has a read entry is no candidate for S: S cannot have typed car
 * 7 AND pressed "??" for the same crossing of car 7. A crossing is a
 * candidate when its time is within the TOLERANCE of the entry's:
 * `dispersao_alerta_ms` (4 s), the spread within which honest entries of
 * one crossing lie.
 *
 * Unread entries of S closer together than twice the tolerance may compete
 * for the same crossings (two "??" in a bunch of three cars), so they are
 * solved together, as one cluster: every entry gets a different crossing,
 * each pair is worth the tolerance minus its offset, and the answer worth
 * most wins (an entry is left without a crossing only where giving it one
 * would push the others further than that). Entries and crossings are
 * points on the time line, and an answer that crosses (the earlier entry
 * with the later crossing) is never better than the one that does not, so
 * a cluster is solved exactly by a dynamic programme over the two sorted
 * lists.
 *
 * WHEN IT IS APPLIED BY ITSELF. An entry's crossing is applied
 * automatically only when the best answer without that pairing is clearly
 * worse: the total offset grows by at least MARGEM_MINIMA_MS (2 s) and by
 * at least the entry's own offset (the next best is twice as far), or
 * there is no other answer at all. And, for a counter's "??", only when at
 * least two OTHER sources read the crossing, so that the pass counts with
 * or without the "??". Once applied, the entry counts for that car like
 * any entry of it: a counter's "??" is that counter's vote. With a single
 * reading the "??" would be the vote that makes the pass count, and that
 * reading may be a wrong number (one counter types car 29 when car 22
 * crosses, another misses it, the third presses "??": applied, it gave car
 * 29 a pass it never made, in one generated race in sixteen). Every entry
 * not applied stays without a car and the full run flags it
 * (`numero_ilegivel`, see UnreadFlags) with the candidates found here. The
 * candidates are always crossings some other source READ: an unread entry
 * is never given a car nobody typed.
 *
 * A TIMING SENSOR'S MARK never votes, so it can wait for the matching: it
 * is put against the PASS GROUPS of every car (matchMarks(), in the
 * whole-race run), each at its counters' earliest entry, in the same way. A
 * matched mark is attached to its group as a timing-only witness
 * (PassGroup::$marcaSensor): a precise sensor's mark times the laps that
 * pass ends and begins (PassTimeFuser::removeHabits()). It changes no
 * count and not the pass time itself, so the per-car recount of an upload,
 * which never looks at marks, still gives what the whole race gives. Two things differ from a "??": the counters
 * press Enter a little after the car crosses and the sensor does not, so in
 * a cluster with two pairs or more the crossings are first moved by the
 * median of that lag; and the tolerance is narrower (sensorToleranceMs()),
 * as is the margin (MARGEM_MINIMA_SENSOR_MS): a wrong pairing there times a
 * pass by the car next to it, a second away, and changes no count.
 *
 * Nothing here is stored: it is worked out again on every run, like the
 * repeats DuplicateFilter voids. A decision on the entry (a reviewer's or
 * its own counter's "reatribuir", or a void) takes it out of here
 * altogether, so a person's word always wins.
 *
 * THE COUNT OF A CAR now depends on the entries of other cars around a
 * counter's "??", so the per-car recount of an upload must be given those
 * too: rangesToLoad() says which (EngineInputLoader loads them). Habits are
 * not removed from the times here: they are only known to the whole-race
 * run, and the per-car recount must give exactly what the whole race does.
 * They are a second or so, well inside the tolerance.
 *
 * A race with no unread entry never gets here.
 */
final class UnreadAssigner
{
    /** The best answer must be at least this much better (total offset, ms) than the next for it to be applied by itself. */
    public const MARGEM_MINIMA_MS = 2000;

    /** The same for a timing sensor's mark, which only times a pass (see the class comment). */
    public const MARGEM_MINIMA_SENSOR_MS = 1000;

    /** A cluster of a sensor's marks with at least this many pairs tells by how much the counters lag behind it. */
    private const MARCAS_PARA_ATRASO = 2;

    /**
     * The unread entries (and sensor marks) given a car by this class,
     * entry id => the crossing: its car and time, the entry's offset from
     * it (entry minus crossing), how much worse the next best answer is
     * (null: there is none) and the read entries and sources that make the
     * crossing.
     *
     * @var array<int, array{carro: int, ts_ms: int, desvio_ms: int, margem_ms: ?int, passagem_ids: int[], fonte_ids: int[]}>
     */
    public array $assignments = [];

    /**
     * The unread entries (and marks) left without a car that some crossing
     * could be, entry id => those crossings, closest first.
     *
     * @var array<int, array<int, array{carro: int, ts_ms: int, desvio_ms: int, passagem_ids: int[], fonte_ids: int[]}>>
     */
    public array $candidates = [];

    /**
     * Unread entries and sensor marks in no pass group: no decision and no
     * assignment gave them a car. Those of unknown time are among them.
     *
     * @var Entry[]
     */
    public array $leftUnread = [];

    /**
     * Copies of the sensor marks that were matched, each carrying the car
     * of the pass it times (and set as that pass's `marcaSensor`).
     *
     * @var Entry[]
     */
    public array $matchedMarks = [];

    /** @var array<int, int> id of an unread entry looked at => its source */
    private array $sourceByEntryId = [];

    private Settings $settings;

    /** @var array<int, true> */
    private array $knownCars;

    private function __construct(Settings $settings, array $knownCars)
    {
        $this->settings = $settings;
        $this->knownCars = $knownCars;
    }

    /**
     * How far a timing sensor's mark may be from the counters' time of a
     * crossing: half the agreement spread (the counters' median is within
     * that of the crossing) plus half a second for their common habit of
     * pressing late. 2.5 s with the default settings. A precise mark
     * further away is more likely a false trigger than that crossing.
     */
    public static function sensorToleranceMs(Settings $settings): int
    {
        return min($settings->agreementSpreadMs(), intdiv($settings->agreementSpreadMs(), 2) + 500);
    }

    /**
     * Gives the counters' (and cameras') unread entries of `$applied` the
     * car they most likely were, where that is clear enough: each such
     * entry is added to `$applied->entries` as a copy carrying the car (and
     * `atribuidoAutomaticamente`). The sensor marks wait for matchMarks().
     *
     * @param array<int, Source> $sourcesById
     * @param array<int, true> $knownCars car numbers of the teams; empty when not known (every number is a car)
     */
    public static function assign(AppliedDecisions $applied, Settings $settings, array $sourcesById, array $knownCars = []): self
    {
        $assigner = new self($settings, $knownCars);
        $bySource = $assigner->usable($applied->unreadEntries);
        if ($bySource === []) {
            return $assigner;
        }

        // Only the entries within reach of an unread one can decide it: the
        // same ones the per-car recount loads (rangesToLoad()), so that both
        // cut the cars' entries into the same crossings.
        $toleranceMs = $settings->agreementSpreadMs();
        $reachMs = self::reachMs($settings);
        $ranges = [];
        foreach ($bySource as $entries) {
            foreach (self::clusters($entries, 2 * $toleranceMs) as $cluster) {
                $ranges[] = [$cluster[0]->tsMs - $reachMs, $cluster[count($cluster) - 1]->tsMs + $reachMs];
            }
        }
        $ranges = self::merged($ranges);
        $rangeStarts = array_column($ranges, 0);
        $nearby = [];
        foreach ($applied->entries as $entry) {
            $index = Stats::countAtMost($rangeStarts, $entry->tsMs) - 1;
            if ($index >= 0 && $entry->tsMs <= $ranges[$index][1]) {
                $nearby[] = $entry;
            }
        }

        [$stretches, $longestMs] = self::readCrossings($nearby, $sourcesById, $settings->janelaMs, $knownCars);
        $firstTimes = array_column($stretches, 'de');
        foreach ($bySource as $fonteId => $entries) {
            foreach (self::clusters($entries, 2 * $toleranceMs) as $cluster) {
                $crossings = self::crossingsNotReadBy(
                    (int) $fonteId,
                    $stretches,
                    $firstTimes,
                    $longestMs,
                    $cluster[0]->tsMs - $toleranceMs,
                    $cluster[count($cluster) - 1]->tsMs + $toleranceMs
                );
                $assigner->solve($cluster, $crossings, $toleranceMs, false);
            }
            foreach ($entries as $entry) {
                $assignment = $assigner->assignments[$entry->id] ?? null;
                if ($assignment === null) {
                    $assigner->leftUnread[] = $entry;
                    continue;
                }
                $applied->entries[] = self::withCar($entry, $assignment['carro']);
            }
        }
        return $assigner;
    }

    /**
     * Matches the timing sensors' marks with the pass groups, as the
     * witnesses that time them (PassGroup::$marcaSensor). Call after
     * PassMatcher and PassTimeFuser::setPassTimes(), with every car's
     * groups, and before PassTimeFuser::removeHabits().
     *
     * @param Entry[] $marks AppliedDecisions::$sensorMarks
     * @param array<int, PassGroup[]> $groupsByCar
     * @return PassGroup[] the groups that got a mark
     */
    public function matchMarks(array $marks, array $groupsByCar): array
    {
        $bySource = $this->usable($marks);
        if ($bySource === []) {
            return [];
        }
        // Each group is put on the time line at its counters' EARLIEST
        // entry: a counter presses Enter as the car is on the line or after,
        // seldom before, so that is the reading nearest the moment the
        // sensor saw. (Their median, the pass time, lags behind by the
        // habit of the slower ones; in a train of cars a second apart that
        // lag paired every mark with the car in front.)
        $groups = [];
        $groupTimes = [];
        foreach ($groupsByCar as $carro => $carGroups) {
            if ($this->knownCars !== [] && !isset($this->knownCars[$carro])) {
                continue;
            }
            foreach ($carGroups as $group) {
                $earliestMs = null;
                foreach ($group->entries as $entry) {
                    if ($entry->fonteId !== Source::REVISOR_ID && ($earliestMs === null || $entry->tsMs < $earliestMs)) {
                        $earliestMs = $entry->tsMs;
                    }
                }
                if ($earliestMs !== null) {
                    $groups[] = $group;
                    $groupTimes[] = $earliestMs;
                }
            }
        }
        array_multisort($groupTimes, $groups);

        $toleranceMs = self::sensorToleranceMs($this->settings);
        $clusterGapMs = 2 * $this->settings->agreementSpreadMs();
        $marked = [];
        foreach ($bySource as $fonteId => $entries) {
            foreach (self::clusters($entries, $clusterGapMs) as $cluster) {
                $crossings = [];
                $untilMs = $cluster[count($cluster) - 1]->tsMs + $toleranceMs;
                for ($index = Stats::countAtMost($groupTimes, $cluster[0]->tsMs - $toleranceMs - 1); $index < count($groups) && $groupTimes[$index] <= $untilMs; $index++) {
                    $group = $groups[$index];
                    // A group that already holds one of this sensor's marks (a
                    // reviewer said which car it was) is timed by that one.
                    if ($group->hasSource((int) $fonteId)) {
                        continue;
                    }
                    $crossings[] = [
                        'carro' => $group->carro,
                        'ts_ms' => $groupTimes[$index],
                        'ts_passagem' => $group->tsMs,
                        'passagem_ids' => $group->entryIds(),
                        'fonte_ids' => $group->realSourceIds(),
                        'grupo' => $group,
                    ];
                }
                foreach ($this->solve($cluster, $crossings, $toleranceMs, true) as $entryId => $crossing) {
                    $mark = null;
                    foreach ($cluster as $entry) {
                        if ($entry->id === $entryId) {
                            $mark = self::withCar($entry, $crossing['carro']);
                        }
                    }
                    $crossing['grupo']->marcaSensor = $mark;
                    $this->matchedMarks[] = $mark;
                    $marked[] = $crossing['grupo'];
                }
            }
            foreach ($entries as $entry) {
                if (!isset($this->assignments[$entry->id])) {
                    $this->leftUnread[] = $entry;
                }
            }
        }
        return $marked;
    }

    /** The source of an unread entry this class looked at; null for any other. */
    public function sourceOfEntry(int $entryId): ?int
    {
        return $this->sourceByEntryId[$entryId] ?? null;
    }

    /**
     * What the per-car recount of an upload must load besides the cars'
     * own entries, for this class to decide their unread neighbours exactly
     * as the whole-race run does: the time ranges around every cluster of
     * unread entries that lies near one of the given moments (the times of
     * the cars' entries). Merged, earliest first; empty when no unread
     * entry is near.
     *
     * @param Entry[] $items unread entries after decisions (AppliedDecisions::$unreadEntries; a sensor's marks need nothing)
     * @param int[] $momentsMs
     * @return array<int, array{0: int, 1: int}>
     */
    public static function rangesToLoad(array $items, array $momentsMs, Settings $settings): array
    {
        if ($items === [] || $momentsMs === []) {
            return [];
        }
        $toleranceMs = $settings->agreementSpreadMs();
        $reachMs = self::reachMs($settings);
        sort($momentsMs);
        $ranges = [];
        foreach ((new self($settings, []))->usable($items) as $entries) {
            foreach (self::clusters($entries, 2 * $toleranceMs) as $cluster) {
                $fromMs = $cluster[0]->tsMs;
                $untilMs = $cluster[count($cluster) - 1]->tsMs;
                // Is any of the moments within reach of the cluster?
                $index = Stats::countAtMost($momentsMs, $fromMs - $reachMs - 1);
                if ($index < count($momentsMs) && $momentsMs[$index] <= $untilMs + $reachMs) {
                    $ranges[] = [$fromMs - $reachMs, $untilMs + $reachMs];
                }
            }
        }
        return self::merged($ranges);
    }

    /**
     * How far from an unread entry the entries that decide it can lie: the
     * tolerance, plus two matching windows for the crossing it may be (a
     * car's stretch of entries, or its pass group) and for the entry of its
     * own source that would rule that crossing out.
     */
    public static function reachMs(Settings $settings): int
    {
        return $settings->agreementSpreadMs() + 2 * $settings->janelaMs;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return array<int, array{0: int, 1: int}>
     */
    public static function merged(array $ranges): array
    {
        sort($ranges);
        $merged = [];
        foreach ($ranges as [$fromMs, $untilMs]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $fromMs <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $untilMs);
                continue;
            }
            $merged[] = [$fromMs, $untilMs];
        }
        return $merged;
    }

    /**
     * The entries that can be judged by their time, per source (in source
     * order), each in time order. Those of unknown time are left without a
     * car: their time means nothing, no crossing can be told from another.
     *
     * @param Entry[] $entries
     * @return array<int, Entry[]>
     */
    private function usable(array $entries): array
    {
        $bySource = [];
        foreach ($entries as $entry) {
            if ($entry->qualidadeTempo === Entry::TEMPO_DESCONHECIDO) {
                $this->leftUnread[] = $entry;
                continue;
            }
            $bySource[$entry->fonteId][] = $entry;
            $this->sourceByEntryId[$entry->id] = $entry->fonteId;
        }
        ksort($bySource);
        foreach ($bySource as $fonteId => $sourceEntries) {
            usort($sourceEntries, fn(Entry $a, Entry $b): int => [$a->tsMs, $a->id] <=> [$b->tsMs, $b->id]);
            $bySource[$fonteId] = $sourceEntries;
        }
        return $bySource;
    }

    private static function withCar(Entry $entry, int $carro): Entry
    {
        $copy = clone $entry;
        $copy->carro = $carro;
        $copy->atribuidoAutomaticamente = true;
        return $copy;
    }

    /**
     * The crossings the sources read: per car, its read entries cut where
     * two are more than a matching window apart. A pass a reviewer added,
     * an entry of unknown time, a timing sensor's entry and a number that
     * is no team are no reading of a crossing.
     *
     * @param Entry[] $entries
     * @param array<int, Source> $sourcesById
     * @param array<int, true> $knownCars
     * @return array{0: array<int, array{carro: int, de: int, ate: int, entradas: Entry[]}>, 1: int} the crossings by first entry, and the longest one's span
     */
    private static function readCrossings(array $entries, array $sourcesById, int $janelaMs, array $knownCars): array
    {
        $sensorIds = [];
        foreach ($sourcesById as $source) {
            if ($source->isTimingSensor()) {
                $sensorIds[$source->id] = true;
            }
        }
        $byCar = [];
        foreach ($entries as $entry) {
            if ($entry->fonteId === Source::REVISOR_ID || $entry->carro === null || isset($sensorIds[$entry->fonteId])
                || $entry->qualidadeTempo === Entry::TEMPO_DESCONHECIDO
                || ($knownCars !== [] && !isset($knownCars[$entry->carro]))) {
                continue;
            }
            $byCar[$entry->carro][] = $entry;
        }
        $stretches = [];
        foreach ($byCar as $carro => $carEntries) {
            usort($carEntries, fn(Entry $a, Entry $b): int => [$a->tsMs, $a->id] <=> [$b->tsMs, $b->id]);
            $current = [];
            foreach ($carEntries as $entry) {
                if ($current !== [] && $entry->tsMs - $current[count($current) - 1]->tsMs > $janelaMs) {
                    $stretches[] = ['carro' => (int) $carro, 'de' => $current[0]->tsMs, 'ate' => $current[count($current) - 1]->tsMs, 'entradas' => $current];
                    $current = [];
                }
                $current[] = $entry;
            }
            $stretches[] = ['carro' => (int) $carro, 'de' => $current[0]->tsMs, 'ate' => $current[count($current) - 1]->tsMs, 'entradas' => $current];
        }
        usort($stretches, fn(array $a, array $b): int => [$a['de'], $a['carro']] <=> [$b['de'], $b['carro']]);
        $longestMs = 0;
        foreach ($stretches as $stretch) {
            $longestMs = max($longestMs, $stretch['ate'] - $stretch['de']);
        }
        return [$stretches, $longestMs];
    }

    /**
     * The read crossings timed between two moments in which the given
     * source has no entry, each timed by the median of its entries.
     *
     * @param array<int, array{carro: int, de: int, ate: int, entradas: Entry[]}> $stretches by first entry
     * @param int[] $firstTimes the stretches' first times, for the search
     * @return array<int, array{carro: int, ts_ms: int, passagem_ids: int[], fonte_ids: int[]}>
     */
    private static function crossingsNotReadBy(int $fonteId, array $stretches, array $firstTimes, int $longestMs, int $fromMs, int $untilMs): array
    {
        $crossings = [];
        for ($index = Stats::countAtMost($firstTimes, $fromMs - $longestMs - 1); $index < count($stretches) && $stretches[$index]['de'] <= $untilMs; $index++) {
            $stretch = $stretches[$index];
            $times = [];
            $sources = [];
            $ids = [];
            foreach ($stretch['entradas'] as $entry) {
                if ($entry->fonteId === $fonteId) {
                    continue 2;
                }
                $times[] = $entry->tsMs;
                $sources[$entry->fonteId] = true;
                $ids[] = $entry->id;
            }
            $tsMs = (int) round((float) Stats::median($times));
            if ($tsMs < $fromMs || $tsMs > $untilMs) {
                continue;
            }
            $sourceIds = array_keys($sources);
            sort($sourceIds);
            $crossings[] = ['carro' => $stretch['carro'], 'ts_ms' => $tsMs, 'passagem_ids' => $ids, 'fonte_ids' => $sourceIds];
        }
        return $crossings;
    }

    /**
     * Cuts one source's unread entries where two are further apart than
     * $gapMs: entries of different clusters can never want the same crossing.
     *
     * @param Entry[] $entries in time order
     * @return array<int, Entry[]>
     */
    private static function clusters(array $entries, int $gapMs): array
    {
        $clusters = [];
        $current = [];
        foreach ($entries as $entry) {
            if ($current !== [] && $entry->tsMs - $current[count($current) - 1]->tsMs > $gapMs) {
                $clusters[] = $current;
                $current = [];
            }
            $current[] = $entry;
        }
        if ($current !== []) {
            $clusters[] = $current;
        }
        return $clusters;
    }

    /**
     * One cluster of one source's unread entries against the crossings near
     * it: records what is applied (`assignments`) and, for the others, what
     * they may be (`candidates`).
     *
     * @param Entry[] $cluster in time order
     * @param array<int, array<string, mixed>> $crossings each with carro, ts_ms (paired by), passagem_ids,
     *        fonte_ids, and ts_passagem when the time to report is another (a pass group's pass time)
     * @return array<int, array<string, mixed>> entry id => the crossing applied to it
     */
    private function solve(array $cluster, array $crossings, int $toleranceMs, bool $isSensor): array
    {
        if ($crossings === []) {
            return [];
        }
        usort($crossings, fn(array $a, array $b): int => [$a['ts_ms'], $a['carro']] <=> [$b['ts_ms'], $b['carro']]);

        $entryTimes = array_map(fn(Entry $entry): int => $entry->tsMs, $cluster);
        $crossingTimes = array_column($crossings, 'ts_ms');
        [$bestScore, $pairs, $fromStart] = self::bestMatching($entryTimes, $crossingTimes, $toleranceMs);
        if ($isSensor && count($pairs) >= self::MARCAS_PARA_ATRASO) {
            // The counters press Enter a little after a car crosses, all of
            // them, and the sensor does not: its marks come a fairly
            // constant fraction of a second before their median. Where the
            // cluster has enough pairs to see by how much, the crossings
            // are moved by that before pairing again, so that two cars side
            // by side are told apart by their order, not by that delay.
            $lagMs = (int) round((float) Stats::median(array_map(fn(int $position, int $index): int => $entryTimes[$position] - $crossingTimes[$index], array_keys($pairs), $pairs)));
            $crossingTimes = array_map(fn(int $tsMs): int => $tsMs + $lagMs, $crossingTimes);
            [$bestScore, $pairs, $fromStart] = self::bestMatching($entryTimes, $crossingTimes, $toleranceMs);
        }
        $toEnd = $pairs === [] ? [] : self::bestToTheEnd($entryTimes, $crossingTimes, $toleranceMs);

        $applied = [];
        foreach ($cluster as $position => $entry) {
            $crossingIndex = $pairs[$position] ?? null;
            if ($crossingIndex !== null) {
                $crossing = $crossings[$crossingIndex];
                $marginMs = self::marginOf($position, $crossingIndex, $pairs, $bestScore, $fromStart, $toEnd, $entryTimes, $crossingTimes, $toleranceMs);
                $minimumMs = max($isSensor ? self::MARGEM_MINIMA_SENSOR_MS : self::MARGEM_MINIMA_MS, abs($entry->tsMs - $crossingTimes[$crossingIndex]));
                $isClear = $marginMs === null || $marginMs >= $minimumMs;
                // A counter's "??" is a vote once it has a car. It is applied
                // by itself only where the pass counts without it: a lone
                // reading may be a wrong number, and "??" says that a car
                // crossed, not which one.
                $decidesNoCount = $isSensor || count($crossing['fonte_ids']) >= 2;
                if ($isClear && $decidesNoCount) {
                    $tsMs = $crossing['ts_passagem'] ?? $crossing['ts_ms'];
                    $this->assignments[$entry->id] = [
                        'carro' => $crossing['carro'],
                        'ts_ms' => $tsMs,
                        'desvio_ms' => $entry->tsMs - $tsMs,
                        'margem_ms' => $marginMs,
                        'passagem_ids' => $crossing['passagem_ids'],
                        'fonte_ids' => $crossing['fonte_ids'],
                    ];
                    $applied[$entry->id] = $crossing;
                    continue;
                }
            }
            $near = [];
            foreach ($crossings as $index => $crossing) {
                $distanceMs = abs($entry->tsMs - $crossing['ts_ms']);
                if ($distanceMs <= $toleranceMs) {
                    $near[] = [$distanceMs, $crossing['ts_ms'], $crossing['carro'], $index];
                }
            }
            sort($near);
            foreach ($near as [, , , $index]) {
                $crossing = $crossings[$index];
                $tsMs = $crossing['ts_passagem'] ?? $crossing['ts_ms'];
                $this->candidates[$entry->id][] = [
                    'carro' => $crossing['carro'],
                    'ts_ms' => $tsMs,
                    'desvio_ms' => $entry->tsMs - $tsMs,
                    'passagem_ids' => $crossing['passagem_ids'],
                    'fonte_ids' => $crossing['fonte_ids'],
                ];
            }
        }
        return $applied;
    }

    /**
     * How much worse (in total offset, ms) the best answer is in which the
     * entry at $position does NOT get crossing $crossingIndex. Null when
     * there is no other answer at all: without this pair the entry simply
     * has no crossing, and every other entry keeps its own.
     *
     * The best answer without the pair comes from the two tables of the
     * programme, from the start and to the end. An answer is a path through
     * the table, and every path enters the corner that the pair leads into
     * exactly once: by the pair itself or by one of the moves along that
     * corner's two edges. The best path without the pair is the best of
     * those other ways in. That only knows answers that do not cross, and
     * the answer that matters most may: two entries swapping their
     * crossings (two cars side by side). Those swaps are tried one by one
     * as well.
     *
     * @param array<int, int> $pairs the best answer, entry position => crossing index
     * @param array<int, array<int, int>> $fromStart bestMatching()'s table
     * @param array<int, array<int, int>> $toEnd bestToTheEnd()'s table
     * @param int[] $entryTimes
     * @param int[] $crossingTimes
     */
    private static function marginOf(
        int $position,
        int $crossingIndex,
        array $pairs,
        int $bestScore,
        array $fromStart,
        array $toEnd,
        array $entryTimes,
        array $crossingTimes,
        int $toleranceMs
    ): ?int {
        $pairValue = $toleranceMs + 1;
        $value = function (int $entry, int $crossing) use ($entryTimes, $crossingTimes, $toleranceMs, $pairValue): ?int {
            $distanceMs = abs($entryTimes[$entry] - $crossingTimes[$crossing]);
            return $distanceMs <= $toleranceMs ? $pairValue - $distanceMs : null;
        };
        // In table coordinates the pair leads from (i - 1, j - 1) into (i, j).
        $i = $position + 1;
        $j = $crossingIndex + 1;
        $entryCount = count($entryTimes);
        $crossingCount = count($crossingTimes);
        $alternativeScore = PHP_INT_MIN;
        for ($a = $i; $a <= $entryCount; $a++) {
            // Passing over crossing j - 1 into column j.
            $alternativeScore = max($alternativeScore, $fromStart[$a][$j - 1] + $toEnd[$a][$j]);
            if ($a > $i && ($pair = $value($a - 1, $j - 1)) !== null) {
                $alternativeScore = max($alternativeScore, $fromStart[$a - 1][$j - 1] + $pair + $toEnd[$a][$j]);
            }
        }
        for ($b = $j; $b <= $crossingCount; $b++) {
            // Leaving entry i - 1 without a crossing, into row i.
            $alternativeScore = max($alternativeScore, $fromStart[$i - 1][$b] + $toEnd[$i][$b]);
            if ($b > $j && ($pair = $value($i - 1, $b - 1)) !== null) {
                $alternativeScore = max($alternativeScore, $fromStart[$i - 1][$b - 1] + $pair + $toEnd[$i][$b]);
            }
        }
        $ownMs = abs($entryTimes[$position] - $crossingTimes[$crossingIndex]);
        // Just dropping the pair is always possible; if nothing does better,
        // there is no other answer for this entry.
        $marginMs = $alternativeScore <= $bestScore - ($pairValue - $ownMs) ? null : $bestScore - $alternativeScore;

        foreach ($pairs as $otherPosition => $otherIndex) {
            if ($otherPosition === $position) {
                continue;
            }
            $mineThereMs = abs($entryTimes[$position] - $crossingTimes[$otherIndex]);
            $theirsHereMs = abs($entryTimes[$otherPosition] - $crossingTimes[$crossingIndex]);
            if ($mineThereMs > $toleranceMs || $theirsHereMs > $toleranceMs) {
                continue;
            }
            $swapLossMs = $mineThereMs + $theirsHereMs - $ownMs - abs($entryTimes[$otherPosition] - $crossingTimes[$otherIndex]);
            $marginMs = $marginMs === null ? $swapLossMs : min($marginMs, $swapLossMs);
        }
        return $marginMs;
    }

    /**
     * The best pairing of entries with crossings, both in time order, each
     * pair no further apart than the tolerance. Every pair is worth the
     * tolerance (and a millisecond) minus its offset: an entry is paired
     * whenever it can be, unless pairing it would push other entries onto
     * worse crossings by more than that (a false trigger of a sensor must
     * not shift every mark after it onto the next car). An optimal pairing
     * never crosses, so the classic dynamic programme over the two lists
     * finds it.
     *
     * @param int[] $entryTimes
     * @param int[] $crossingTimes
     * @return array{0: int, 1: array<int, int>, 2: array<int, array<int, int>>} the score, entry position =>
     *         crossing index, and the table: [i][j] the best score of the first i entries with the first j crossings
     */
    private static function bestMatching(array $entryTimes, array $crossingTimes, int $toleranceMs): array
    {
        $pairValue = $toleranceMs + 1;
        $entryCount = count($entryTimes);
        $crossingCount = count($crossingTimes);
        $score = array_fill(0, $entryCount + 1, array_fill(0, $crossingCount + 1, 0));
        for ($i = 1; $i <= $entryCount; $i++) {
            for ($j = 1; $j <= $crossingCount; $j++) {
                $best = $score[$i - 1][$j] > $score[$i][$j - 1] ? $score[$i - 1][$j] : $score[$i][$j - 1];
                $distanceMs = abs($entryTimes[$i - 1] - $crossingTimes[$j - 1]);
                if ($distanceMs <= $toleranceMs) {
                    $paired = $score[$i - 1][$j - 1] + $pairValue - $distanceMs;
                    if ($paired > $best) {
                        $best = $paired;
                    }
                }
                $score[$i][$j] = $best;
            }
        }

        $pairs = [];
        $i = $entryCount;
        $j = $crossingCount;
        while ($i > 0 && $j > 0) {
            $distanceMs = abs($entryTimes[$i - 1] - $crossingTimes[$j - 1]);
            if ($distanceMs <= $toleranceMs && $score[$i][$j] === $score[$i - 1][$j - 1] + $pairValue - $distanceMs) {
                $pairs[$i - 1] = $j - 1;
                $i--;
                $j--;
            } elseif ($score[$i][$j] === $score[$i - 1][$j]) {
                $i--;
            } else {
                $j--;
            }
        }
        return [$score[$entryCount][$crossingCount], $pairs, $score];
    }

    /**
     * bestMatching()'s table the other way round: [i][j] is the best score
     * of the entries from position i on with the crossings from index j on.
     *
     * @param int[] $entryTimes
     * @param int[] $crossingTimes
     * @return array<int, array<int, int>>
     */
    private static function bestToTheEnd(array $entryTimes, array $crossingTimes, int $toleranceMs): array
    {
        $pairValue = $toleranceMs + 1;
        $entryCount = count($entryTimes);
        $crossingCount = count($crossingTimes);
        $score = array_fill(0, $entryCount + 1, array_fill(0, $crossingCount + 1, 0));
        for ($i = $entryCount - 1; $i >= 0; $i--) {
            for ($j = $crossingCount - 1; $j >= 0; $j--) {
                $best = $score[$i + 1][$j] > $score[$i][$j + 1] ? $score[$i + 1][$j] : $score[$i][$j + 1];
                $distanceMs = abs($entryTimes[$i] - $crossingTimes[$j]);
                if ($distanceMs <= $toleranceMs) {
                    $paired = $score[$i + 1][$j + 1] + $pairValue - $distanceMs;
                    if ($paired > $best) {
                        $best = $paired;
                    }
                }
                $score[$i][$j] = $best;
            }
        }
        return $score;
    }
}
