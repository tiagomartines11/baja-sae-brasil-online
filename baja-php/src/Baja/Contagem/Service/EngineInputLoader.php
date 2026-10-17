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
    private Prova $prova;
    private string $eventoId;
    private string $provaId;

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

        $entries = [];
        foreach ($this->fetchAll($sql, $params) as $row) {
            $entry = new Entry(
                (int) $row['passagem_id'],
                (int) $row['fonte_id'],
                (int) $row['dispositivo_id'],
                (int) $row['equipe_numero'],
                (int) $row['ts_ms']
            );
            $entry->qualidadeTempo = (string) $row['qualidade_tempo'];
            $entries[] = $entry;
        }
        return $entries;
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
