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
 * least two OTHER sources read the crossing AND their readings agree by
 * themselves, so that the pass counts with or without the "??": the "??"
 * must join an agreed pass group of those readings and change no other
 * (joinsAnAgreedPass()). Once applied, the entry counts for that car like
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
 * A TIMING SENSOR'S MARK never votes, so it can wait for the matching
 * (matchMarks(), after PassMatcher, in the per-car recount and the whole
 * race alike). It is put against the crossings the counters read around it
 * (SensorTrains: each car's readings a spread apart, at their earliest),
 * TRAIN BY TRAIN: the marks and crossings whose tolerance windows chain.
 * A matched mark is attached to the pass group of its crossing as a
 * timing-only witness (PassGroup::$marcaSensor) and is that pass's time
 * (PassTimeFuser::useSensorMarks()): LAST, the lap rows, lap durations.
 * It never votes and never counts. What differs from a "??":
 *
 * - the counters press Enter a little after the car crosses and the sensor
 *   does not. In a train of two marks or more, each counter's habit within
 *   the train is taken out of the crossings' times
 *   (SensorTrains::withoutHabits()), and the crossings are then moved by
 *   the lag that pairs them best with the marks (bestShift()), so that two
 *   cars side by side are told apart by their order, not by that delay;
 * - the tolerance is narrower (sensorToleranceMs()), as is the margin
 *   (MARGEM_MINIMA_SENSOR_MS, and MARGEM_MINIMA_SENSOR_TREM_MS in a train
 *   with as many marks as crossings): a wrong pairing times a pass by the
 *   car next to it, a second away, and changes no count (but for the hold
 *   rule's few hundred milliseconds);
 * - where the times leave a train's marks unclear, the counters' order may
 *   settle them (inCountersOrder()): as many marks as crossings, every
 *   counter that read two of the cars typed them in the same order, the
 *   marks at least INTERVALO_MINIMO_NA_ORDEM_MS apart. Otherwise those
 *   passes stay untimed, which loses nothing but a precise time.
 *
 * A per-car recount loads the marks and readings each of its cars' trains
 * needs (SensorTrains::rangesToLoad()), and an upload recounts the cars of
 * the trains near what it changed (SensorTrains::carsAround()), so the
 * pass times it publishes are the whole race's.
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

    /**
     * The same for a sensor's mark in a train with as many marks as
     * crossings: no false trigger and no missed car is likely to be in it,
     * and the counters' lag is taken out of all of them at once.
     */
    public const MARGEM_MINIMA_SENSOR_TREM_MS = 500;

    /**
     * In the counters' order (inCountersOrder()), two marks of a train must
     * be at least this far apart: closer, a counter's hand puts two cars the
     * wrong way round too often for every counter's order to prove anything.
     */
    public const INTERVALO_MINIMO_NA_ORDEM_MS = 500;

    /**
     * ... and each counter's gap between two cars must be the gap between
     * their marks give or take this: its habit is the same on both cars,
     * so only how steady its hand is lies in between.
     */
    public const FOLGA_DA_ORDEM_MS = 500;

    /** A train with at least this many marks and crossings tells by how much the counters lag behind the sensor (bestShift()). */
    private const MARCAS_PARA_ATRASO = 2;

    /** The lags tried between the counters and a sensor (see bestShift()) are this far apart. */
    private const PASSO_DO_ATRASO_MS = 50;

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
     * Matches the timing sensors' marks with the crossings the counters
     * read, train by train (see SensorTrains), and attaches each matched
     * mark to the pass group of its crossing, as the witness that times it
     * (PassGroup::$marcaSensor). Call after PassMatcher and
     * PassTimeFuser::setPassTimes(), and before PublishRule: the mark is
     * the pass time (PassTimeFuser::useSensorMarks()).
     *
     * Only the readings near a mark decide it. A per-car recount passes
     * the groups of its cars only, and the readings of every car around
     * them (EngineInputLoader loads them, see SensorTrains::rangesToLoad()):
     * a mark matched to a car that is not recounted is attached to nothing,
     * and the marks of the cars that are come out as in the whole race.
     *
     * @param Entry[] $marks AppliedDecisions::$sensorMarks
     * @param Entry[] $readings AppliedDecisions::$entries, after assign()
     * @param array<int, PassGroup[]> $groupsByCar
     * @param array<int, Source> $sourcesById
     * @return PassGroup[] the groups that got a mark
     */
    public function matchMarks(array $marks, array $readings, array $groupsByCar, array $sourcesById): array
    {
        $bySource = $this->usable($marks);
        if ($bySource === []) {
            return [];
        }
        $groupOfEntry = [];
        foreach ($groupsByCar as $carGroups) {
            foreach ($carGroups as $group) {
                foreach ($group->entries as $entry) {
                    $groupOfEntry[$entry->id] = $group;
                }
            }
        }
        $crossings = SensorTrains::crossings($readings, $sourcesById, $this->knownCars, $this->settings->agreementSpreadMs());
        $toleranceMs = self::sensorToleranceMs($this->settings);
        $marked = [];
        foreach ($bySource as $fonteId => $entries) {
            // A crossing that already holds one of this sensor's marks (a
            // reviewer said which car it was) is timed by that one.
            $theirs = array_values(array_filter($crossings, fn(array $crossing): bool => !isset($crossing['sensores'][$fonteId])));
            $proposed = [];
            foreach (SensorTrains::trains($entries, $theirs, $toleranceMs) as $train) {
                if ($train['passagens'] === []) {
                    continue;
                }
                if (count($train['marcas']) === 1 && count($train['passagens']) === 1) {
                    // Nearly every train: one mark, one crossing, nothing else
                    // near either. No other answer exists.
                    $proposed[] = [$train['marcas'][0], $this->applyAlone($train['marcas'][0], $train['passagens'][0])];
                    continue;
                }
                $applied = $this->solve($train['marcas'], SensorTrains::withoutHabits($train['passagens']), $toleranceMs, true);
                $inOrder = self::inCountersOrder($train, $applied, $toleranceMs);
                if ($inOrder !== null) {
                    $applied = $this->applyInOrder($inOrder);
                }
                foreach ($train['marcas'] as $mark) {
                    if (isset($applied[$mark->id])) {
                        $proposed[] = [$mark, $applied[$mark->id]];
                    }
                }
            }
            $marked = array_merge($marked, $this->attach($proposed, $groupOfEntry, $groupsByCar));
            foreach ($entries as $entry) {
                if (!isset($this->assignments[$entry->id])) {
                    $this->leftUnread[] = $entry;
                }
            }
            $this->describeCandidatesByTheirPasses($entries, $groupOfEntry);
        }
        return $marked;
    }

    /**
     * The marks of a train put in the counters' order, where the times
     * alone left some of them without a pass (cars a second apart): as many
     * marks as crossings, the counters agreeing on the order of the cars
     * (SensorTrains::countersOrder()), each mark within the tolerance of
     * the crossing it gets, and no pairing the times made clear by
     * themselves contradicted. Null when any of that fails: the train then
     * keeps what the times made clear, and the rest stays untimed.
     *
     * @param array{marcas: Entry[], passagens: array<int, array<string, mixed>>} $train
     * @param array<int, array<string, mixed>> $applied what solve() applied in the train, mark id => crossing
     * @return array<int, array{0: Entry, 1: array<string, mixed>}>|null
     */
    private static function inCountersOrder(array $train, array $applied, int $toleranceMs): ?array
    {
        $marks = $train['marcas'];
        $crossings = $train['passagens'];
        if (count($marks) < 2 || count($marks) !== count($crossings) || count($applied) === count($marks)) {
            return null;
        }
        $order = SensorTrains::countersOrder($crossings);
        if ($order === null) {
            return null;
        }
        $pairs = [];
        foreach ($marks as $position => $mark) {
            $crossing = $crossings[$order[$position]];
            if (abs($mark->tsMs - $crossing['ts_ms']) > $toleranceMs) {
                return null;
            }
            if ($position > 0) {
                $markGap = $mark->tsMs - $marks[$position - 1]->tsMs;
                if ($markGap < self::INTERVALO_MINIMO_NA_ORDEM_MS) {
                    return null;
                }
                $previous = $crossings[$order[$position - 1]];
                foreach (SensorTrains::readingGaps($previous, $crossing) as $readingGap) {
                    if (abs($readingGap - $markGap) > self::FOLGA_DA_ORDEM_MS) {
                        return null;
                    }
                }
            }
            if (isset($applied[$mark->id]) && $applied[$mark->id]['carro'] !== $crossing['carro']) {
                return null;
            }
            $pairs[] = [$mark, $crossing];
        }
        return $pairs;
    }

    /** How much better than the next the best answer must be for it to be applied by itself (see solve()). */
    private static function minimumMarginMs(bool $isSensor, int $entries, int $crossings): int
    {
        if (!$isSensor) {
            return self::MARGEM_MINIMA_MS;
        }
        return $entries === $crossings ? self::MARGEM_MINIMA_SENSOR_TREM_MS : self::MARGEM_MINIMA_SENSOR_MS;
    }

    /**
     * Records a train of one mark and one crossing as applied, as solve()
     * would (there is no other answer, so no margin).
     *
     * @param array<string, mixed> $crossing
     * @return array<string, mixed> the crossing
     */
    private function applyAlone(Entry $mark, array $crossing): array
    {
        $this->assignments[$mark->id] = [
            'carro' => $crossing['carro'],
            'ts_ms' => $crossing['ts_ms'],
            'desvio_ms' => $mark->tsMs - $crossing['ts_ms'],
            'margem_ms' => null,
            'passagem_ids' => $crossing['passagem_ids'],
            'fonte_ids' => $crossing['fonte_ids'],
        ];
        return $crossing;
    }

    /**
     * Records the pairs of inCountersOrder() as applied.
     *
     * @param array<int, array{0: Entry, 1: array<string, mixed>}> $pairs
     * @return array<int, array<string, mixed>> mark id => crossing
     */
    private function applyInOrder(array $pairs): array
    {
        $applied = [];
        foreach ($pairs as [$mark, $crossing]) {
            unset($this->candidates[$mark->id]);
            $this->assignments[$mark->id] = [
                'carro' => $crossing['carro'],
                'ts_ms' => $crossing['ts_ms'],
                'desvio_ms' => $mark->tsMs - $crossing['ts_ms'],
                // No margin: the times did not tell it, the counters' order did.
                'margem_ms' => null,
                'passagem_ids' => $crossing['passagem_ids'],
                'fonte_ids' => $crossing['fonte_ids'],
                'pela_ordem' => true,
            ];
            $applied[$mark->id] = $crossing;
        }
        return $applied;
    }

    /**
     * Gives each matched mark to the pass group its crossing's readings are
     * in. A group two marks would time gets neither (the readings of one
     * pass made two crossings, one of them a counter far behind); so does a
     * crossing whose readings are in no group (all dropped as repeats). A
     * crossing of a car that is not being counted (a neighbour, in a
     * per-car recount) keeps its mark, attached to nothing.
     *
     * @param array<int, array{0: Entry, 1: array<string, mixed>}> $proposed
     * @param array<int, PassGroup> $groupOfEntry
     * @param array<int, PassGroup[]> $groupsByCar
     * @return PassGroup[] the groups that got a mark
     */
    private function attach(array $proposed, array $groupOfEntry, array $groupsByCar): array
    {
        $byGroup = [];
        foreach ($proposed as [$mark, $crossing]) {
            if (!isset($groupsByCar[$crossing['carro']])) {
                continue;
            }
            $group = null;
            foreach ($crossing['entradas'] as $reading) {
                if (isset($groupOfEntry[$reading->id])) {
                    $group = $groupOfEntry[$reading->id];
                    break;
                }
            }
            if ($group === null) {
                $this->unassign($mark->id);
                continue;
            }
            $byGroup[spl_object_id($group)][] = [$mark, $group];
        }
        $marked = [];
        foreach ($byGroup as $pairs) {
            if (count($pairs) > 1) {
                foreach ($pairs as [$mark]) {
                    $this->unassign($mark->id);
                }
                continue;
            }
            [[$mark, $group]] = $pairs;
            $group->marcaSensor = self::withCar($mark, $group->carro);
            $this->matchedMarks[] = $group->marcaSensor;
            // Described by the pass as the counters timed it (the mark is
            // about to become its time).
            $assignment = $this->assignments[$mark->id];
            $assignment['ts_ms'] = $group->tsMs;
            $assignment['desvio_ms'] = $mark->tsMs - $group->tsMs;
            $assignment['passagem_ids'] = $group->entryIds();
            $assignment['fonte_ids'] = $group->realSourceIds();
            $this->assignments[$mark->id] = $assignment;
            $marked[] = $group;
        }
        return $marked;
    }

    /**
     * Takes a mark's pass back: it stays near that crossing, as a candidate
     * (a mark near a pass the counters read is no flag, and in the sensor's
     * statistics it is one not safely paired).
     */
    private function unassign(int $markId): void
    {
        $assignment = $this->assignments[$markId];
        unset($this->assignments[$markId], $assignment['margem_ms'], $assignment['pela_ordem']);
        $this->candidates[$markId][] = $assignment;
    }

    /**
     * The candidates of a mark left without a pass, described by the pass
     * groups their crossings are in (the time the counters gave the pass,
     * all its entries), as the flag shows them.
     *
     * @param Entry[] $marks
     * @param array<int, PassGroup> $groupOfEntry
     */
    private function describeCandidatesByTheirPasses(array $marks, array $groupOfEntry): void
    {
        foreach ($marks as $mark) {
            foreach ($this->candidates[$mark->id] ?? [] as $index => $candidate) {
                $group = null;
                foreach ($candidate['passagem_ids'] as $entryId) {
                    if (isset($groupOfEntry[$entryId])) {
                        $group = $groupOfEntry[$entryId];
                        break;
                    }
                }
                if ($group !== null) {
                    $this->candidates[$mark->id][$index] = [
                        'carro' => $candidate['carro'],
                        'ts_ms' => $group->tsMs,
                        'desvio_ms' => $mark->tsMs - $group->tsMs,
                        'passagem_ids' => $group->entryIds(),
                        'fonte_ids' => $group->realSourceIds(),
                    ];
                }
            }
        }
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
            $crossings[] = ['carro' => $stretch['carro'], 'ts_ms' => $tsMs, 'passagem_ids' => $ids, 'fonte_ids' => $sourceIds, 'entradas' => $stretch['entradas']];
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
        $otherShiftScores = [];
        $rawTimes = [];
        if ($isSensor && count($cluster) >= self::MARCAS_PARA_ATRASO && count($crossings) >= self::MARCAS_PARA_ATRASO) {
            $rawTimes = $crossingTimes;
            [$bestScore, $pairs, $fromStart, $crossingTimes, $otherShiftScores] = self::bestShift($entryTimes, $crossingTimes, $toleranceMs);
        } else {
            [$bestScore, $pairs, $fromStart] = self::bestMatching($entryTimes, $crossingTimes, $toleranceMs);
        }
        $toEnd = $pairs === [] ? [] : self::bestToTheEnd($entryTimes, $crossingTimes, $toleranceMs, $rawTimes);

        $applied = [];
        foreach ($cluster as $position => $entry) {
            $crossingIndex = $pairs[$position] ?? null;
            if ($crossingIndex !== null) {
                $crossing = $crossings[$crossingIndex];
                $marginMs = self::marginOf($position, $crossingIndex, $pairs, $bestScore, $fromStart, $toEnd, $entryTimes, $crossingTimes, $toleranceMs, $rawTimes);
                if (isset($otherShiftScores[$position])) {
                    $marginMs = $marginMs === null ? $bestScore - $otherShiftScores[$position] : min($marginMs, $bestScore - $otherShiftScores[$position]);
                }
                $minimumMs = max(self::minimumMarginMs($isSensor, count($cluster), count($crossings)), abs($entry->tsMs - $crossingTimes[$crossingIndex]));
                $isClear = $marginMs === null || $marginMs >= $minimumMs;
                // A counter's "??" is a vote once it has a car. It is applied
                // by itself only where the pass counts without it: a lone
                // reading may be a wrong number, and "??" says that a car
                // crossed, not which one.
                $decidesNoCount = $isSensor || (count($crossing['fonte_ids']) >= 2 && $this->joinsAnAgreedPass($entry, $crossing['entradas']));
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
     * Whether the "??", given the crossing's car, only joins a pass that
     * counts without it. PassMatcher groups the crossing's readings twice,
     * with and without the "??": the group the "??" lands in must, without
     * it, be the same readings, agreed (within the agreement spread) and
     * from two sources or more; and the "??" must leave it agreed, and the
     * rest as it was.
     *
     * Two readings of the car two sources made are not enough by
     * themselves: they may be a typo for a car next to it and the real one
     * six seconds later, a dispersed group that waits for a person. A "??"
     * between them would pair with one of them into an agreed group, and
     * so count a lap the car never drove.
     *
     * @param Entry[] $readings the crossing's read entries, in time order
     */
    private function joinsAnAgreedPass(Entry $unread, array $readings): bool
    {
        $janelaMs = $this->settings->janelaMs;
        $spreadMs = $this->settings->agreementSpreadMs();
        $carro = $readings[0]->carro;
        $without = PassMatcher::match($readings, $janelaMs, [], $spreadMs)[$carro] ?? [];
        $withIt = $readings;
        $withIt[] = self::withCar($unread, $carro);
        usort($withIt, fn(Entry $a, Entry $b): int => [$a->tsMs, $a->id] <=> [$b->tsMs, $b->id]);
        $with = PassMatcher::match($withIt, $janelaMs, [], $spreadMs)[$carro] ?? [];
        if (count($with) !== count($without)) {
            return false;
        }
        $agreedWithout = [];
        foreach ($without as $group) {
            if (!$group->dispersa && $group->sourceCount() >= 2) {
                $ids = $group->entryIds();
                sort($ids);
                $agreedWithout[implode(',', $ids)] = true;
            }
        }
        foreach ($with as $group) {
            $ids = $group->entryIds();
            if (!in_array($unread->id, $ids, true)) {
                continue;
            }
            if ($group->dispersa) {
                return false;
            }
            $others = array_values(array_diff($ids, [$unread->id]));
            sort($others);
            return isset($agreedWithout[implode(',', $others)]);
        }
        return false;
    }

    /**
     * The counters press Enter a little after a car crosses, all of them,
     * and the sensor does not: its marks come a fairly constant fraction of
     * a second before the crossings. So the crossings are moved by the lag
     * that pairs them best with the marks, tried among the offsets of every
     * mark from every crossing near it: the true lag lines all of them up,
     * and two cars side by side are then told apart by their order, not by
     * that delay. A lag that pairs a mark otherwise and scores nearly as
     * well is an answer too: how much worse it is, per mark, comes back
     * with the best, for the margin.
     *
     * @param int[] $entryTimes
     * @param int[] $crossingTimes
     * @return array{0: int, 1: array<int, int>, 2: array<int, array<int, int>>, 3: int[], 4: array<int, int>} bestMatching()'s
     *         answer for the best lag, the crossing times moved by it, and per mark position the best score of a lag that pairs it otherwise
     */
    private static function bestShift(array $entryTimes, array $crossingTimes, int $toleranceMs): array
    {
        // Lags a few tens of milliseconds apart pair alike: one per step is
        // tried, so that a long train costs a hundred tries at most.
        $lags = [];
        foreach ($entryTimes as $entryMs) {
            foreach ($crossingTimes as $crossingMs) {
                $lagMs = (int) round(($entryMs - $crossingMs) / self::PASSO_DO_ATRASO_MS) * self::PASSO_DO_ATRASO_MS;
                if (abs($lagMs) <= $toleranceMs) {
                    $lags[$lagMs] = abs($lagMs);
                }
            }
        }
        // The smallest first: of two lags as good, the smaller wins.
        asort($lags);
        $tries = [];
        $best = null;
        foreach (array_keys($lags) as $lagMs) {
            $shifted = array_map(fn(int $tsMs): int => $tsMs + $lagMs, $crossingTimes);
            [$score, $pairs, $table] = self::bestMatching($entryTimes, $shifted, $toleranceMs, $crossingTimes);
            $tries[] = [$score, $pairs];
            if ($best === null || $score > $best[0]) {
                $best = [$score, $pairs, $table, $shifted];
            }
        }
        $otherScores = [];
        foreach ($best[1] as $position => $crossingIndex) {
            foreach ($tries as [$score, $pairs]) {
                if (($pairs[$position] ?? null) !== $crossingIndex && (!isset($otherScores[$position]) || $score > $otherScores[$position])) {
                    $otherScores[$position] = $score;
                }
            }
        }
        $best[] = $otherScores;
        return $best;
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
        int $toleranceMs,
        array $rawCrossingTimes = []
    ): ?int {
        $pairValue = $toleranceMs + 1;
        $value = function (int $entry, int $crossing) use ($entryTimes, $crossingTimes, $rawCrossingTimes, $toleranceMs, $pairValue): ?int {
            $distanceMs = abs($entryTimes[$entry] - $crossingTimes[$crossing]);
            return $distanceMs <= $toleranceMs && self::withinRaw($entryTimes[$entry], $rawCrossingTimes, $crossing, $toleranceMs) ? $pairValue - $distanceMs : null;
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
            if ($mineThereMs > $toleranceMs || $theirsHereMs > $toleranceMs
                || !self::withinRaw($entryTimes[$position], $rawCrossingTimes, $otherIndex, $toleranceMs)
                || !self::withinRaw($entryTimes[$otherPosition], $rawCrossingTimes, $crossingIndex, $toleranceMs)) {
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
     * @param int[] $rawCrossingTimes when the crossings were moved (bestShift()): where they were; a pair must be within the tolerance there too
     * @return array{0: int, 1: array<int, int>, 2: array<int, array<int, int>>} the score, entry position =>
     *         crossing index, and the table: [i][j] the best score of the first i entries with the first j crossings
     */
    private static function bestMatching(array $entryTimes, array $crossingTimes, int $toleranceMs, array $rawCrossingTimes = []): array
    {
        $pairValue = $toleranceMs + 1;
        $entryCount = count($entryTimes);
        $crossingCount = count($crossingTimes);
        $checkRaw = $rawCrossingTimes !== [];
        // Row by row, each in a local array: a run decides hundreds of
        // trains, each with a few dozen of these tables.
        $score = [array_fill(0, $crossingCount + 1, 0)];
        for ($i = 1; $i <= $entryCount; $i++) {
            $previous = $score[$i - 1];
            $row = [0];
            $entryMs = $entryTimes[$i - 1];
            for ($j = 1; $j <= $crossingCount; $j++) {
                $best = $previous[$j] > $row[$j - 1] ? $previous[$j] : $row[$j - 1];
                $distanceMs = abs($entryMs - $crossingTimes[$j - 1]);
                if ($distanceMs <= $toleranceMs && (!$checkRaw || abs($entryMs - $rawCrossingTimes[$j - 1]) <= $toleranceMs)) {
                    $paired = $previous[$j - 1] + $pairValue - $distanceMs;
                    if ($paired > $best) {
                        $best = $paired;
                    }
                }
                $row[$j] = $best;
            }
            $score[$i] = $row;
        }

        $pairs = [];
        $i = $entryCount;
        $j = $crossingCount;
        while ($i > 0 && $j > 0) {
            $distanceMs = abs($entryTimes[$i - 1] - $crossingTimes[$j - 1]);
            if ($distanceMs <= $toleranceMs && self::withinRaw($entryTimes[$i - 1], $rawCrossingTimes, $j - 1, $toleranceMs)
                && $score[$i][$j] === $score[$i - 1][$j - 1] + $pairValue - $distanceMs) {
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
     * @param int[] $rawCrossingTimes as for bestMatching()
     * @return array<int, array<int, int>>
     */
    private static function bestToTheEnd(array $entryTimes, array $crossingTimes, int $toleranceMs, array $rawCrossingTimes = []): array
    {
        $pairValue = $toleranceMs + 1;
        $entryCount = count($entryTimes);
        $crossingCount = count($crossingTimes);
        $score = array_fill(0, $entryCount + 1, array_fill(0, $crossingCount + 1, 0));
        for ($i = $entryCount - 1; $i >= 0; $i--) {
            for ($j = $crossingCount - 1; $j >= 0; $j--) {
                $best = $score[$i + 1][$j] > $score[$i][$j + 1] ? $score[$i + 1][$j] : $score[$i][$j + 1];
                $distanceMs = abs($entryTimes[$i] - $crossingTimes[$j]);
                if ($distanceMs <= $toleranceMs && ($rawCrossingTimes === [] || abs($entryTimes[$i] - $rawCrossingTimes[$j]) <= $toleranceMs)) {
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

    /** True when no crossings were moved, or the entry is within the tolerance of where the crossing was. */
    private static function withinRaw(int $entryMs, array $rawCrossingTimes, int $crossingIndex, int $toleranceMs): bool
    {
        return $rawCrossingTimes === [] || abs($entryMs - $rawCrossingTimes[$crossingIndex]) <= $toleranceMs;
    }
}
