<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\DecisionApplier;
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
 *   it was is worked out from them (UnreadAssigner).
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
        return $input;
    }

    /**
     * The cars whose count a change at these moments may have moved through
     * a counter's "??" (see UnreadAssigner): the cars that "??" near those
     * moments may be, or was, given, and the cars read right at the moments
     * themselves. Empty, after one cheap query, while the prova has no
     * "??"; and when none is near the moments.
     *
     * @param int[] $momentsMs moments of the entries an upload stored, voided or fixed
     * @return int[]
     */
    public function carsNearUnread(Settings $settings, array $momentsMs): array
    {
        if ($momentsMs === [] || !$this->hasUnreadEntries()) {
            return [];
        }
        $input = $this->everythingButEntries($settings);
        $raw = $this->loadUnreadEntries();
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
        $input->entries = $this->loadEntriesBetween(UnreadAssigner::merged($ranges));

        $sourcesById = [];
        foreach ($input->sources as $source) {
            $sourcesById[$source->id] = $source;
        }
        $applied = DecisionApplier::apply($input->entries, $input->decisions, $sourcesById);
        $knownCars = $input->validCars === [] ? [] : array_fill_keys(array_merge($input->validCars, $input->unclassifiedCars), true);
        $assigner = UnreadAssigner::assign($applied, $settings, $sourcesById, $knownCars);

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
     * Adds to a per-car input the entries UnreadAssigner needs to decide the
     * "??" near those cars exactly as the whole-race run does (see
     * UnreadAssigner::rangesToLoad()). Nothing, after one cheap query, while
     * the prova has no "??".
     *
     * @param array{0: int, 1: int}|null $window also every "??" in this stretch of time, as a timeline shows it
     */
    private function addEntriesAroundUnread(EngineInput $input, ?array $window): void
    {
        if (!$this->hasUnreadEntries()) {
            return;
        }
        $raw = $this->loadUnreadEntries();
        $items = $this->unreadItems($input, $raw);
        if ($items === []) {
            if ($window !== null) {
                $input->entries = self::union($input->entries, array_filter($raw, fn(Entry $entry): bool => $entry->tsMs >= $window[0] && $entry->tsMs <= $window[1]));
            }
            return;
        }
        $moments = array_map(fn(Entry $entry): int => $entry->tsMs, $input->entries);
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
            $input->entries = self::union($input->entries, $this->loadEntriesBetween($ranges));
        }
    }

    /** True when the prova has at least one entry stored with no car. One indexed look-up. */
    private function hasUnreadEntries(): bool
    {
        if ($this->hasUnread === null) {
            $rows = $this->fetchAll(
                'SELECT 1 FROM contagem_passagem p JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                  WHERE f.evento_id = :evento AND f.prova_id = :prova AND p.equipe_numero IS NULL LIMIT 1',
                $this->provaParams()
            );
            $this->hasUnread = $rows !== [];
        }
        return $this->hasUnread;
    }

    /** @return Entry[] every entry of the prova stored with no car */
    private function loadUnreadEntries(): array
    {
        return $this->entriesFrom(
            'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero, p.ts_ms, p.qualidade_tempo
               FROM contagem_passagem p
               JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              WHERE f.evento_id = ? AND f.prova_id = ? AND p.equipe_numero IS NULL',
            [$this->eventoId, $this->provaId]
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
     * Every entry of the prova between the moments of each range.
     *
     * @param array<int, array{0: int, 1: int}> $ranges
     * @return Entry[]
     */
    private function loadEntriesBetween(array $ranges): array
    {
        $entries = [];
        foreach (array_chunk($ranges, 100) as $chunk) {
            $sql = 'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero, p.ts_ms, p.qualidade_tempo
                      FROM contagem_passagem p
                      JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                     WHERE f.evento_id = ? AND f.prova_id = ? AND ('
                . implode(' OR ', array_fill(0, count($chunk), 'p.ts_ms BETWEEN ? AND ?')) . ')';
            $params = [$this->eventoId, $this->provaId];
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
