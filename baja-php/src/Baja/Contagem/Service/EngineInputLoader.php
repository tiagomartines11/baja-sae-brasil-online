<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\DecisionApplier;
use Baja\Contagem\Engine\SensorTrains;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Engine\Stats;
use Baja\Contagem\Engine\UnreadAssigner;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Reads what the engine needs for one prova and turns the rows into the
 * engine's plain data objects.
 *
 * Two ways in, matching the engine's two entry points:
 *
 * - load(): the whole race, for the full recalculation.
 * - loadForCars(): only the entries of a few cars, for the recount that
 *   runs inside an upload. A long race has tens of thousands of entries and
 *   one new entry concerns a couple of hundred of them. Plus, where a
 *   counter pressed "??" near those cars, the entries around it: which car
 *   it was is worked out from them (UnreadAssigner). And, with a timing
 *   sensor, the marks and readings a few seconds around each of their
 *   crossings: which mark times which pass is worked out from them
 *   (SensorTrains).
 *
 * Plain prepared SQL instead of Propel objects, on purpose: building a
 * Propel object per row costs several times more than the engine itself.
 */
final class EngineInputLoader
{
    private Prova $prova;
    private string $eventoId;
    private string $provaId;

    /** Whether the prova has any entry stored with no car; asked once. */
    private ?bool $hasUnread = null;

    /** Whether the prova has a timing sensor source; asked with $hasUnread (a sensor's marks are entries with no car). */
    private bool $hasSensor = false;

    /** @var Entry[]|null the counters' and cameras' entries stored with no car; loaded once (see counterUnreadEntries()) */
    private ?array $counterUnread = null;

    public function __construct(Prova $prova)
    {
        $this->prova = $prova;
        $this->eventoId = (string) $prova->getEventoId();
        $this->provaId = (string) $prova->getProvaId();
    }

    public function load(Settings $settings): EngineInput
    {
        $input = $this->everythingButEntries($settings);
        $input->entries = $this->loadEntries(null, []);
        $input->totals = $this->loadTotals();
        return $input;
    }

    /**
     * The input for Engine::countCars(): every entry that counts for the
     * given cars. That is the entries typed with one of those numbers, plus
     * the entries a reviewer reassigned to one of those cars (they were
     * typed with another number). Where a "??" lies near one of them, the
     * entries around it too, of whatever car.
     *
     * @param int[] $cars
     * @param array{0: int, 1: int}|null $window also every "??" between these two moments (a timeline shows them)
     * @param int[] $alsoEntryIds also these entries, whatever car they count for (a "??" a reviewer is deciding about)
     */
    public function loadForCars(Settings $settings, array $cars, ?array $window = null, array $alsoEntryIds = []): EngineInput
    {
        $input = $this->everythingButEntries($settings);

        $reassignedEntryIds = [];
        foreach ($input->decisions as $decision) {
            if ($decision->tipo === Decision::REATRIBUIR && $decision->passagemId !== null && in_array($decision->carro, $cars, true)) {
                $reassignedEntryIds[] = $decision->passagemId;
            }
        }
        $input->entries = $this->loadEntries($cars, array_merge($reassignedEntryIds, $alsoEntryIds));
        $this->addEntriesAroundUnread($input, $window);
        $this->addSensorTrains($input, $cars);
        return $input;
    }

    /**
     * The cars whose pass times a change at these moments may have moved
     * through a timing sensor's marks: which mark times which pass is
     * decided train by train, and a train holds the cars a second or two
     * around a mark (SensorTrains::carsAround()). Empty, with no query of
     * its own, while the prova has no sensor (nor any entry with no car).
     *
     * @param int[] $momentsMs moments of the entries an upload stored, voided or fixed
     * @param int[] $changedCars the cars those entries count (or counted) for, and the cars a "??" near them may be or was
     * @param bool $onlyMarks the entries are a timing sensor's marks (they change no "??")
     * @param int[]|null $newEntryIds when the change only added entries, their ids: then only the cars
     *        whose marks they moved (SensorTrains::carsWhoseMarksMoved()), and the race without them is
     *        loaded as completely as the race with them
     * @return int[]
     */
    public function carsAroundSensorMarks(Settings $settings, array $momentsMs, array $changedCars, bool $onlyMarks = false, ?array $newEntryIds = null): array
    {
        if ($momentsMs === [] || !$this->hasUnreadEntries() || !$this->hasSensor) {
            return [];
        }
        $input = $this->everythingButEntries($settings);
        if (!SensorTrains::anySensorCounts($input->sources)) {
            return [];
        }
        // A "??" near the moments may now count for another car: the
        // crossings around it changed too. (Not after a mark: a mark is no
        // reading of any car.)
        $raw = $onlyMarks ? [] : $this->counterUnreadEntries();
        $items = $raw === [] ? [] : $this->unreadItems($input, $raw);
        $moments = $momentsMs;
        if ($items !== []) {
            $ranges = UnreadAssigner::rangesToLoad($items, $momentsMs, $settings);
            $starts = array_column($ranges, 0);
            foreach ($items as $item) {
                $index = Stats::countAtMost($starts, $item->tsMs) - 1;
                if ($index >= 0 && $item->tsMs <= $ranges[$index][1]) {
                    $moments[] = $item->tsMs;
                }
            }
        }
        $this->addSensorTrains($input, [], $moments, $changedCars, $newEntryIds ?? []);
        return $newEntryIds === null
            ? SensorTrains::carsAround($input, $moments, $changedCars)
            : SensorTrains::carsWhoseMarksMoved($input, $newEntryIds);
    }

    /**
     * Adds what SensorTrains needs to match the marks around the given cars
     * (or the given moments) exactly as the whole-race run does: it asks
     * for the stretches of time it is missing, round after round, until it
     * misses none (usually two rounds, one query each). Nothing, and no
     * query, while no timing sensor takes part.
     *
     * @param int[] $cars
     * @param int[] $momentsMs
     * @param int[] $changedCars
     * @param int[] $withoutEntryIds also complete the trains of the race without these entries
     */
    private function addSensorTrains(EngineInput $input, array $cars, array $momentsMs = [], array $changedCars = [], array $withoutEntryIds = []): void
    {
        if (!$this->hasUnreadEntries() || !$this->hasSensor || !SensorTrains::anySensorCounts($input->sources)) {
            return;
        }
        $loaded = [SensorTrains::MARCAS => [], SensorTrains::TUDO => []];
        $resolvedUnread = [];
        while (true) {
            $missing = SensorTrains::rangesToLoad($input, $cars, $loaded, $momentsMs, $changedCars);
            if ($withoutEntryIds !== []) {
                $before = SensorTrains::rangesToLoad(SensorTrains::without($input, $withoutEntryIds), $cars, $loaded, $momentsMs, $changedCars);
                foreach ($before as $kind => $more) {
                    $missing[$kind] = $kind === SensorTrains::ILEGIVEIS ? array_merge($missing[$kind], $more) : UnreadAssigner::merged(array_merge($missing[$kind], $more));
                }
            }
            // A counter's "??" that may be a reading of a train's crossing:
            // which car it is needs the entries around it.
            $unread = [];
            $toResolve = array_diff_key(array_fill_keys($missing[SensorTrains::ILEGIVEIS], true), $resolvedUnread);
            if ($toResolve !== []) {
                foreach ($input->entries as $entry) {
                    if (isset($toResolve[$entry->id])) {
                        $unread[] = $entry;
                    }
                }
                $resolvedUnread += $toResolve;
            }
            if ($missing[SensorTrains::MARCAS] === [] && $missing[SensorTrains::TUDO] === [] && $unread === []) {
                return;
            }
            if ($missing[SensorTrains::TUDO] !== []) {
                $input->entries = self::union($input->entries, $this->loadEntriesBetween($input->sources, $missing[SensorTrains::TUDO]));
            }
            if ($missing[SensorTrains::MARCAS] !== []) {
                $input->entries = self::union($input->entries, $this->loadEntriesBetween($input->sources, $missing[SensorTrains::MARCAS], true));
            }
            foreach ([SensorTrains::MARCAS, SensorTrains::TUDO] as $kind) {
                $loaded[$kind] = UnreadAssigner::merged(array_merge($loaded[$kind], $missing[$kind]));
            }
            if ($unread !== []) {
                $this->addEntriesAroundUnread($input, null, $unread);
            }
        }
    }

    /**
     * The cars whose count a change at these moments may have moved through
     * a counter's "??" (see UnreadAssigner): the cars that "??" near those
     * moments may be, or was, given, and the cars read right at the moments
     * themselves. Empty, after one cheap query, while the prova has no
     * "??"; and when none is near the moments. A timing sensor's marks are
     * no "??" here: with a sensor and no "??" it is two cheap queries.
     *
     * When the change only added entries ($newEntryIds), the race before
     * it is the same race without them: then only the cars whose "??"
     * assignment the new entries really changed (the car a "??" counted
     * for before, and the one it counts for now) are returned, as
     * carsAroundSensorMarks() does for marks. Usually none: the cars of
     * the upload itself are recounted anyway.
     *
     * @param int[] $momentsMs moments of the entries an upload stored, voided or fixed
     * @param int[]|null $newEntryIds when the change only added entries, their ids
     * @return int[]
     */
    public function carsNearUnread(Settings $settings, array $momentsMs, ?array $newEntryIds = null): array
    {
        if ($momentsMs === [] || !$this->hasUnreadEntries()) {
            return [];
        }
        // A timing sensor's marks move no count by lying near (they only
        // time passes), so with a sensor and no "??" this stops here.
        $raw = $this->counterUnreadEntries();
        if ($raw === []) {
            return [];
        }
        $input = $this->everythingButEntries($settings);
        $reachMs = UnreadAssigner::reachMs($settings);
        // Only the moments with a "??" within reach (decided or voided ones
        // included: what it counted for before may change too).
        $rawTimes = array_map(fn(Entry $entry): int => $entry->tsMs, $raw);
        sort($rawTimes);
        $near = [];
        foreach ($momentsMs as $momentMs) {
            $index = Stats::countAtMost($rawTimes, $momentMs - $reachMs - 1);
            if ($index < count($rawTimes) && $rawTimes[$index] <= $momentMs + $reachMs) {
                $near[] = $momentMs;
            }
        }
        if ($near === []) {
            return [];
        }

        $items = $this->unreadItems($input, $raw);
        $ranges = UnreadAssigner::rangesToLoad($items, $near, $settings);
        foreach ($near as $momentMs) {
            $ranges[] = [$momentMs - $reachMs, $momentMs + $reachMs];
        }
        $input->entries = $this->loadEntriesBetween($input->sources, UnreadAssigner::merged($ranges));

        $sourcesById = [];
        foreach ($input->sources as $source) {
            $sourcesById[$source->id] = $source;
        }
        $applied = DecisionApplier::apply($input->entries, $input->decisions, $sourcesById);
        $knownCars = $input->validCars === [] ? [] : array_fill_keys(array_merge($input->validCars, $input->unclassifiedCars), true);
        $assigner = UnreadAssigner::assign($applied, $settings, $sourcesById, $knownCars);

        if ($newEntryIds !== null) {
            $isNew = array_fill_keys($newEntryIds, true);
            $before = array_values(array_filter($input->entries, fn(Entry $entry): bool => !isset($isNew[$entry->id])));
            $assignerBefore = UnreadAssigner::assign(DecisionApplier::apply($before, $input->decisions, $sourcesById), $settings, $sourcesById, $knownCars);
            return self::carsWhoseAssignmentChanged($assignerBefore->assignments, $assigner->assignments);
        }

        $cars = [];
        foreach ($assigner->assignments as $assignment) {
            $cars[$assignment['carro']] = true;
        }
        foreach ($assigner->candidates as $candidates) {
            foreach ($candidates as $candidate) {
                $cars[$candidate['carro']] = true;
            }
        }
        // What a "??" at one of the moments counted for before it was
        // voided or fixed: a car read right there.
        $aroundMs = $settings->agreementSpreadMs() + $settings->agreementSpreadMs();
        foreach ($applied->entries as $entry) {
            if ($entry->carro === null || $entry->fonteId === Source::REVISOR_ID) {
                continue;
            }
            foreach ($near as $momentMs) {
                if (abs($entry->tsMs - $momentMs) <= $aroundMs) {
                    $cars[$entry->carro] = true;
                    break;
                }
            }
        }
        return array_keys($cars);
    }

    /**
     * The cars a "??" counted for in one list of assignments and not, or at
     * another crossing, in the other.
     *
     * @param array<int, array{carro: int, ts_ms: int}> $before entry id => assignment (UnreadAssigner::$assignments)
     * @param array<int, array{carro: int, ts_ms: int}> $after
     * @return int[]
     */
    private static function carsWhoseAssignmentChanged(array $before, array $after): array
    {
        $cars = [];
        foreach ($before + $after as $entryId => $any) {
            $was = $before[$entryId] ?? null;
            $is = $after[$entryId] ?? null;
            if ($was !== null && $is !== null && $was['carro'] === $is['carro'] && $was['ts_ms'] === $is['ts_ms']) {
                continue;
            }
            foreach ([$was, $is] as $assignment) {
                if ($assignment !== null) {
                    $cars[$assignment['carro']] = true;
                }
            }
        }
        return array_keys($cars);
    }

    /**
     * Adds to a per-car input the entries UnreadAssigner needs to decide the
     * "??" near those cars exactly as the whole-race run does (see
     * UnreadAssigner::rangesToLoad()). Nothing, after one cheap query, while
     * the prova has no "??".
     *
     * @param array{0: int, 1: int}|null $window also every "??" in this stretch of time, as a timeline shows it
     * @param Entry[]|null $around only around these entries (the ones just loaded); null for every entry of the input
     */
    private function addEntriesAroundUnread(EngineInput $input, ?array $window, ?array $around = null): void
    {
        if (!$this->hasUnreadEntries()) {
            return;
        }
        $raw = $this->counterUnreadEntries();
        $items = $raw === [] ? [] : $this->unreadItems($input, $raw);
        if ($items === []) {
            if ($window !== null) {
                $input->entries = self::union($input->entries, $this->loadUnreadEntriesBetween($window));
            }
            return;
        }
        $moments = array_map(fn(Entry $entry): int => $entry->tsMs, $around ?? $input->entries);
        if ($window !== null) {
            $stepMs = max(1000, UnreadAssigner::reachMs($input->settings));
            for ($momentMs = $window[0]; $momentMs < $window[1] + $stepMs; $momentMs += $stepMs) {
                $moments[] = min($momentMs, $window[1]);
            }
        }
        $ranges = UnreadAssigner::rangesToLoad($items, $moments, $input->settings);
        if ($window !== null) {
            $ranges = UnreadAssigner::merged(array_merge($ranges, [$window]));
        }
        if ($ranges !== []) {
            $input->entries = self::union($input->entries, $this->loadEntriesBetween($input->sources, $ranges));
        }
    }

    /**
     * True when the prova has at least one entry stored with no car. One
     * indexed look-up, which also tells whether the prova has a timing
     * sensor ($hasSensor): its marks are such entries.
     */
    private function hasUnreadEntries(): bool
    {
        if ($this->hasUnread === null) {
            $rows = $this->fetchAll(
                'SELECT EXISTS (SELECT 1 FROM contagem_passagem p JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                                 WHERE f.evento_id = ? AND f.prova_id = ? AND p.equipe_numero IS NULL) AS sem_carro,
                        EXISTS (SELECT 1 FROM contagem_fonte f
                                 WHERE f.evento_id = ? AND f.prova_id = ? AND f.tipo = ?) AS sensor',
                [$this->eventoId, $this->provaId, $this->eventoId, $this->provaId, Source::TIPO_SENSOR]
            );
            $this->hasUnread = (int) ($rows[0]['sem_carro'] ?? 0) === 1;
            $this->hasSensor = $this->hasUnread && (int) ($rows[0]['sensor'] ?? 0) === 1;
        }
        return $this->hasUnread;
    }

    /**
     * Every entry of the prova stored with no car except a timing sensor's
     * marks: a sensor sends one per crossing, thousands in a long race, and
     * none of them is a "??" whose car a recount must work out. Loaded once.
     *
     * @return Entry[]
     */
    private function counterUnreadEntries(): array
    {
        if ($this->counterUnread === null) {
            $this->counterUnread = $this->entriesFrom(
                'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero, p.ts_ms, p.qualidade_tempo
                   FROM contagem_passagem p
                   JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                  WHERE f.evento_id = ? AND f.prova_id = ? AND f.tipo <> ? AND p.equipe_numero IS NULL',
                [$this->eventoId, $this->provaId, Source::TIPO_SENSOR]
            );
        }
        return $this->counterUnread;
    }

    /**
     * Every entry stored with no car (a sensor's marks included) between two
     * moments, as a timeline shows them.
     *
     * @param array{0: int, 1: int} $window
     * @return Entry[]
     */
    private function loadUnreadEntriesBetween(array $window): array
    {
        return $this->entriesFrom(
            'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero, p.ts_ms, p.qualidade_tempo
               FROM contagem_passagem p
               JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              WHERE f.evento_id = ? AND f.prova_id = ? AND p.equipe_numero IS NULL AND p.ts_ms BETWEEN ? AND ?',
            [$this->eventoId, $this->provaId, $window[0], $window[1]]
        );
    }

    /**
     * The "??" UnreadAssigner will look at: the counters' and cameras' unread
     * entries no decision took out (a timing sensor's marks need nothing
     * around them in a per-car recount).
     *
     * @param Entry[] $raw
     * @return Entry[]
     */
    private function unreadItems(EngineInput $input, array $raw): array
    {
        $sourcesById = [];
        foreach ($input->sources as $source) {
            $sourcesById[$source->id] = $source;
        }
        return DecisionApplier::apply($raw, $input->decisions, $sourcesById)->unreadEntries;
    }

    /**
     * Every entry of the prova between the moments of each range (only the
     * timing sensors' marks, with $onlySensors).
     *
     * The sources are named in the query, not joined: then the database
     * reads each source's entries by time from its (fonte_id, ts_ms) index,
     * range by range, instead of every entry of every source (measured: a
     * fifth of the time for a long race).
     *
     * @param Source[] $sources the prova's sources (EngineInput::$sources)
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return Entry[]
     */
    private function loadEntriesBetween(array $sources, array $ranges, bool $onlySensors = false): array
    {
        $sourceIds = [];
        foreach ($sources as $source) {
            if ($source->recordsPasses() && (!$onlySensors || $source->isTimingSensor())) {
                $sourceIds[] = $source->id;
            }
        }
        if ($sourceIds === [] || $ranges === []) {
            return [];
        }
        $entries = [];
        foreach (array_chunk($ranges, 100) as $chunk) {
            $sql = 'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero, p.ts_ms, p.qualidade_tempo
                      FROM contagem_passagem p
                     WHERE p.fonte_id IN (' . implode(', ', array_fill(0, count($sourceIds), '?')) . ') AND ('
                . implode(' OR ', array_fill(0, count($chunk), 'p.ts_ms BETWEEN ? AND ?')) . ')';
            $params = $sourceIds;
            foreach ($chunk as [$fromMs, $untilMs]) {
                $params[] = $fromMs;
                $params[] = $untilMs;
            }
            foreach ($this->entriesFrom($sql, $params) as $entry) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * @param Entry[] $entries
     * @param Entry[] $more
     * @return Entry[] the first list plus the entries of the second it does not hold yet
     */
    private static function union(array $entries, array $more): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            $ids[$entry->id] = true;
        }
        foreach ($more as $entry) {
            if (!isset($ids[$entry->id])) {
                $entries[] = $entry;
                $ids[$entry->id] = true;
            }
        }
        return $entries;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return Entry[]
     */
    private function entriesFrom(string $sql, array $params): array
    {
        $entries = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $entry = new Entry(
                (int) $row['passagem_id'],
                (int) $row['fonte_id'],
                (int) $row['dispositivo_id'],
                self::nullableInt($row['equipe_numero']),
                (int) $row['ts_ms']
            );
            $entry->qualidadeTempo = (string) $row['qualidade_tempo'];
            $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Teams of the event, keyed by car number.
     *
     * @param bool $onlyPresent true for the cars a counter may legitimately
     *                          type; false for every car that can hold a score
     * @return array<int, array{numero: int, nome: string, escola: string}>
     */
    public function loadTeams(bool $onlyPresent): array
    {
        $sql = 'SELECT equipe_id, equipe, escola FROM equipe WHERE evento_id = :evento';
        if ($onlyPresent) {
            $sql .= ' AND presente = 1';
        }
        $sql .= ' ORDER BY equipe_id';

        $teams = [];
        foreach ($this->fetchAll($sql, [':evento' => $this->eventoId]) as $row) {
            $numero = (int) $row['equipe_id'];
            $teams[$numero] = [
                'numero' => $numero,
                'nome' => (string) $row['equipe'],
                'escola' => (string) $row['escola'],
            ];
        }
        return $teams;
    }

    /** `contagem_ocorrencia.origem` of an exit recorded by a reviewer (see ContagemService::recordInferredExit()). */
    public const ORIGEM_REVISOR = 'revisor';

    /** @return Ocorrencia[] oldest first */
    public function loadOcorrencias(): array
    {
        $sql = 'SELECT ocorrencia_id, tipo, ts_ms, equipe_numero, substitui_id, qualidade_tempo, origem
                  FROM contagem_ocorrencia
                 WHERE evento_id = :evento AND prova_id = :prova
              ORDER BY ocorrencia_id';

        $ocorrencias = [];
        foreach ($this->fetchAll($sql, $this->provaParams()) as $row) {
            $ocorrencia = new Ocorrencia(
                (int) $row['ocorrencia_id'],
                (string) $row['tipo'],
                (int) $row['ts_ms'],
                self::nullableInt($row['equipe_numero']),
                self::nullableInt($row['substitui_id'])
            );
            // A stop event of unknown time is kept out of the pairing of
            // entries and exits; an exit a reviewer inferred from a pass at
            // the line is shown as such.
            $ocorrencia->qualidadeTempo = (string) $row['qualidade_tempo'];
            $ocorrencia->inferida = $row['origem'] === self::ORIGEM_REVISOR;
            $ocorrencias[] = $ocorrencia;
        }
        return $ocorrencias;
    }

    private function everythingButEntries(Settings $settings): EngineInput
    {
        $input = new EngineInput($settings);
        $input->nowMs = Clock::nowMs();
        $input->sources = (new SourceLoader($this->eventoId, $this->provaId))->load($settings, $input->nowMs);
        $input->decisions = $this->loadDecisions();
        $input->ocorrencias = $this->loadOcorrencias();

        $roster = CarRoster::forProva($this->prova);
        $input->validCars = array_keys($roster->classified());
        $input->unclassifiedCars = array_keys($roster->unclassified());
        return $input;
    }

    /**
     * Raw entries, each with the time it was stored with. That time is
     * final: it was worked out by the page that recorded the entry and is
     * never adjusted here or anywhere else.
     *
     * An entry stored with no car ("??", a timing sensor's mark) has a null
     * car here.
     *
     * @param int[]|null $cars only entries typed with these numbers; null for all
     * @param int[] $alsoEntryIds entries to include whatever number they were typed with
     * @return Entry[]
     */
    private function loadEntries(?array $cars, array $alsoEntryIds): array
    {
        $sql = 'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero, p.ts_ms, p.qualidade_tempo
                  FROM contagem_passagem p
                  JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                 WHERE f.evento_id = ? AND f.prova_id = ?';
        $params = [$this->eventoId, $this->provaId];

        if ($cars !== null) {
            $conditions = [];
            if ($cars !== []) {
                $conditions[] = 'p.equipe_numero IN (' . implode(', ', array_fill(0, count($cars), '?')) . ')';
                $params = array_merge($params, array_map('intval', $cars));
            }
            if ($alsoEntryIds !== []) {
                $conditions[] = 'p.passagem_id IN (' . implode(', ', array_fill(0, count($alsoEntryIds), '?')) . ')';
                $params = array_merge($params, array_map('intval', $alsoEntryIds));
            }
            if ($conditions === []) {
                return [];
            }
            $sql .= ' AND (' . implode(' OR ', $conditions) . ')';
        }

        return $this->entriesFrom($sql, $params);
    }

    /** @return Decision[] oldest first */
    private function loadDecisions(): array
    {
        $sql = 'SELECT decisao_id, tipo, passagem_id, passagem_alvo_id, fonte_id, equipe_numero, ts_ms, ts_fim_ms,
                       desfaz_decisao_id, origem
                  FROM contagem_decisao
                 WHERE evento_id = :evento AND prova_id = :prova
              ORDER BY decisao_id';

        $decisions = [];
        foreach ($this->fetchAll($sql, $this->provaParams()) as $row) {
            $decision = new Decision((int) $row['decisao_id'], (string) $row['tipo']);
            $decision->passagemId = self::nullableInt($row['passagem_id']);
            $decision->passagemAlvoId = self::nullableInt($row['passagem_alvo_id']);
            $decision->fonteId = self::nullableInt($row['fonte_id']);
            $decision->carro = self::nullableInt($row['equipe_numero']);
            $decision->tsMs = self::nullableInt($row['ts_ms']);
            $decision->tsFimMs = self::nullableInt($row['ts_fim_ms']);
            $decision->desfazDecisaoId = self::nullableInt($row['desfaz_decisao_id']);
            $decision->origem = (string) $row['origem'];
            $decisions[] = $decision;
        }
        return $decisions;
    }

    /** @return array<int, array<int, int>> fonteId => [carro => voltas] */
    private function loadTotals(): array
    {
        $sql = 'SELECT t.fonte_id, t.equipe_numero, t.voltas
                  FROM contagem_total t
                  JOIN contagem_fonte f ON f.fonte_id = t.fonte_id
                 WHERE f.evento_id = :evento AND f.prova_id = :prova';

        $totals = [];
        foreach ($this->fetchAll($sql, $this->provaParams()) as $row) {
            $totals[(int) $row['fonte_id']][(int) $row['equipe_numero']] = (int) $row['voltas'];
        }
        return $totals;
    }

    /** @return array<string, string> */
    private function provaParams(): array
    {
        return [':evento' => $this->eventoId, ':prova' => $this->provaId];
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $statement = Propel::getReadConnection('resultados')->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @param mixed $value */
    private static function nullableInt($value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
