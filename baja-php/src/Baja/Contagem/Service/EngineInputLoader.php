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
 * Reads everything the engine needs for one prova and turns the rows into
 * the engine's plain data objects.
 *
 * Plain prepared SQL instead of Propel objects, on purpose: this runs on
 * every upload, and a long race has tens of thousands of entries. Building
 * a Propel object per row costs several times more than the engine itself.
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
        $input = new EngineInput($settings);
        $input->sources = $this->loadSources();
        $input->entries = $this->loadEntries();
        $input->decisions = $this->loadDecisions();
        $input->ocorrencias = $this->loadOcorrencias();
        $input->totals = $this->loadTotals();
        $input->validCars = array_keys($this->loadTeams(true));
        $input->nowMs = Clock::nowMs();
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

    /**
     * Sources with the sync state of their devices already summarised.
     *
     * A source can have several devices over a race (a handover, a dead
     * battery). Its "synced through" mark is the most recent one among the
     * devices that are still allowed to upload: a revoked device will never
     * report again, so its old mark says nothing about the source today.
     *
     * @return Source[]
     */
    private function loadSources(): array
    {
        $sql = 'SELECT f.fonte_id, f.nome, f.tipo, f.qualidade_tempo, f.ativa, f.ordem,
                       MAX(CASE WHEN d.revogado_em_ms IS NULL THEN d.sincronizado_ate_ms END) AS sincronizado_ate_ms,
                       MAX(d.visto_em_ms) AS visto_em_ms
                  FROM contagem_fonte f
             LEFT JOIN contagem_dispositivo d ON d.fonte_id = f.fonte_id
                 WHERE f.evento_id = :evento AND f.prova_id = :prova
              GROUP BY f.fonte_id, f.nome, f.tipo, f.qualidade_tempo, f.ativa, f.ordem
              ORDER BY f.ordem, f.fonte_id';

        $sources = [];
        foreach ($this->fetchAll($sql, $this->provaParams()) as $row) {
            $sources[] = new Source(
                (int) $row['fonte_id'],
                (string) $row['nome'],
                (string) $row['tipo'],
                (string) $row['qualidade_tempo'],
                (bool) $row['ativa'],
                (int) $row['ordem'],
                self::nullableInt($row['sincronizado_ate_ms']),
                self::nullableInt($row['visto_em_ms'])
            );
        }
        return $sources;
    }

    /**
     * Every raw entry of the prova with its effective time.
     *
     * An entry typed before its device knew any clock offset is stored with
     * ts_ms NULL. Its time is worked out here, from the first offset the
     * device reported later, so that contagem_passagem never needs an UPDATE.
     *
     * @return Entry[]
     */
    private function loadEntries(): array
    {
        $sql = 'SELECT p.passagem_id, p.fonte_id, p.dispositivo_id, p.equipe_numero,
                       COALESCE(p.ts_ms, p.ts_dispositivo_ms + COALESCE(d.primeiro_desvio_ms, 0)) AS ts_efetivo_ms
                  FROM contagem_passagem p
                  JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                  JOIN contagem_dispositivo d ON d.dispositivo_id = p.dispositivo_id
                 WHERE f.evento_id = :evento AND f.prova_id = :prova';

        $entries = [];
        foreach ($this->fetchAll($sql, $this->provaParams()) as $row) {
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

    /** @return Ocorrencia[] oldest first */
    private function loadOcorrencias(): array
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
     * @param array<string, mixed> $params
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
