<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
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
 *   one new entry concerns a couple of hundred of them.
 *
 * Plain prepared SQL instead of Propel objects, on purpose: building a
 * Propel object per row costs several times more than the engine itself.
 */
final class EngineInputLoader
{
    private string $eventoId;
    private string $provaId;

    public function __construct(Prova $prova)
    {
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
     * typed with another number).
     *
     * @param int[] $cars
     */
    public function loadForCars(Settings $settings, array $cars): EngineInput
    {
        $input = $this->everythingButEntries($settings);

        $reassignedEntryIds = [];
        foreach ($input->decisions as $decision) {
            if ($decision->tipo === Decision::REATRIBUIR && $decision->passagemId !== null && in_array($decision->carro, $cars, true)) {
                $reassignedEntryIds[] = $decision->passagemId;
            }
        }
        $input->entries = $this->loadEntries($cars, $reassignedEntryIds);
        return $input;
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

    /** @return Ocorrencia[] oldest first */
    public function loadOcorrencias(): array
    {
        $sql = 'SELECT ocorrencia_id, tipo, ts_ms, equipe_numero, substitui_id
                  FROM contagem_ocorrencia
                 WHERE evento_id = :evento AND prova_id = :prova
              ORDER BY ocorrencia_id';

        $ocorrencias = [];
        foreach ($this->fetchAll($sql, $this->provaParams()) as $row) {
            $ocorrencias[] = new Ocorrencia(
                (int) $row['ocorrencia_id'],
                (string) $row['tipo'],
                (int) $row['ts_ms'],
                self::nullableInt($row['equipe_numero']),
                self::nullableInt($row['substitui_id'])
            );
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
        $input->validCars = array_keys($this->loadTeams(true));
        return $input;
    }

    /**
     * Raw entries with their effective time.
     *
     * An entry typed before its device knew any clock offset is stored with
     * ts_ms NULL. Its time is worked out here, from the first offset the
     * device reported later, so that contagem_passagem never needs an UPDATE.
     *
     * @param int[]|null $cars only entries typed with these numbers; null for all
     * @param int[] $alsoEntryIds entries to include whatever number they were typed with
     * @return Entry[]
     */
    private function loadEntries(?array $cars, array $alsoEntryIds): array
    {
        $sql = 'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero,
                       COALESCE(p.ts_ms, p.ts_dispositivo_ms + COALESCE(d.primeiro_desvio_ms, 0)) AS ts_efetivo_ms
                  FROM contagem_passagem p
                  JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                  JOIN contagem_dispositivo d ON d.dispositivo_id = p.dispositivo_id
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

        $entries = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $entries[] = new Entry(
                (int) $row['passagem_id'],
                (int) $row['fonte_id'],
                (int) $row['dispositivo_id'],
                (int) $row['equipe_numero'],
                (int) $row['ts_efetivo_ms']
            );
        }
        return $entries;
    }

    /** @return Decision[] oldest first */
    private function loadDecisions(): array
    {
        $sql = 'SELECT decisao_id, tipo, passagem_id, fonte_id, equipe_numero, ts_ms, ts_fim_ms,
                       desfaz_decisao_id, origem
                  FROM contagem_decisao
                 WHERE evento_id = :evento AND prova_id = :prova
              ORDER BY decisao_id';

        $decisions = [];
        foreach ($this->fetchAll($sql, $this->provaParams()) as $row) {
            $decision = new Decision((int) $row['decisao_id'], (string) $row['tipo']);
            $decision->passagemId = self::nullableInt($row['passagem_id']);
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
