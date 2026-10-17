<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Model\Prova;
use PDO;
use Propel\Runtime\Propel;

/**
 * The decision history panel: who decided what, most recent first, and
 * whether each decision can still be undone.
 *
 * Two lists:
 *
 * - all(): the history itself, the whole of it. The screen filters it by
 *   car, counter, person and type, and a filter over the newest page alone
 *   would quietly miss the rest. A race gathers about a hundred decisions
 *   (81 at 26SE), about 30 KB of JSON in the stored state, which travels
 *   only when the state changes; so the whole list goes. Past MAX_ROWS
 *   the oldest are left out and the answer says so: by then the list would
 *   weigh more than the rest of the screen together.
 * - inForce(): every "excluir fonte", "adicionar passagem" and "marcar
 *   saída" still in force, however old. These change the race for as long
 *   as they stand and are not attached to any entry on a timeline. The
 *   Fontes block and the status bar read the open-ended exclusions from
 *   this list, and the screen merges it into the history, so they are
 *   never lost even past MAX_ROWS.
 *
 * The engine's Decision objects deliberately carry no username (the engine
 * does not care who decided), so this reads the people-facing columns
 * straight from contagem_decisao.
 *
 * A decision about an entry (or the undo of one) also says which entry it
 * was: the car typed, the car the entry counts for now when a reassignment
 * in force moved it, and the counter, with its name. The screen writes them
 * in the line and can filter on them.
 */
final class DecisionHistory
{
    /** Ten times the decisions of a busy race; see the class comment. */
    public const MAX_ROWS = 1000;

    private const COLUMNS = 'd.decisao_id, d.tipo, d.passagem_id, d.passagem_alvo_id, d.fonte_id, d.equipe_numero, d.ts_ms, d.ts_fim_ms,
                             d.desfaz_decisao_id, d.origem, d.usuario, d.motivo, d.criado_em_ms,
                             p.passagem_id AS registro_id, p.equipe_numero AS registro_carro, p.fonte_id AS registro_fonte_id,
                             p.ts_ms AS registro_ts_ms, p.qualidade_tempo AS registro_qualidade,
                             f.nome AS fonte_nome';

    /**
     * The decision, the decision an undo undid (one level: an undo is never
     * undone), the entry either is about, and the source named by the
     * decision or by that entry.
     */
    private const FROM = 'FROM contagem_decisao d
                     LEFT JOIN contagem_decisao desfeita ON desfeita.decisao_id = d.desfaz_decisao_id
                     LEFT JOIN contagem_passagem p ON p.passagem_id = COALESCE(d.passagem_id, desfeita.passagem_id)
                     LEFT JOIN contagem_fonte f ON f.fonte_id = COALESCE(d.fonte_id, p.fonte_id)';

    /**
     * The whole history, newest first; at most $limit decisions, the newest.
     *
     * @param array<int, true> $activeDecisionIds ids of the decisions still in force
     * @return array{linhas: array<int, array<string, mixed>>, completo: bool} completo is false when older ones were left out
     */
    public static function all(Prova $prova, array $activeDecisionIds, int $limit = self::MAX_ROWS): array
    {
        $limit = max(1, $limit);
        // One row more than the limit, only to learn whether any was left out.
        $sql = 'SELECT ' . self::COLUMNS . ' ' . self::FROM . '
                 WHERE d.evento_id = :evento AND d.prova_id = :prova
                 ORDER BY d.decisao_id DESC
                 LIMIT ' . ($limit + 1);
        $statement = Propel::getConnection('resultados')->prepare($sql);
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId()]);

        $rows = [];
        $carNow = self::carsAfterReassignment($prova, $activeDecisionIds);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = self::describe($row, $activeDecisionIds, $carNow);
        }
        return ['linhas' => array_slice($rows, 0, $limit), 'completo' => count($rows) <= $limit];
    }

    /**
     * Every source exclusion, added pass and marked exit still in force, newest first.
     *
     * @param array<int, true> $activeDecisionIds
     * @return array<int, array<string, mixed>>
     */
    public static function inForce(Prova $prova, array $activeDecisionIds): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' ' . self::FROM . '
                 WHERE d.evento_id = :evento AND d.prova_id = :prova AND d.tipo IN (:excluir, :adicionar, :saida)
                 ORDER BY d.decisao_id DESC';
        $statement = Propel::getConnection('resultados')->prepare($sql);
        $statement->execute([
            ':evento' => $prova->getEventoId(),
            ':prova' => $prova->getProvaId(),
            ':excluir' => Decision::EXCLUIR_FONTE,
            ':adicionar' => Decision::ADICIONAR,
            // An exit a reviewer marked is attached to no entry either.
            ':saida' => DecisionRequest::MARCAR_SAIDA,
        ]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($activeDecisionIds[(int) $row['decisao_id']])) {
                // None of these is about an entry: no reassignment to look up.
                $rows[] = self::describe($row, $activeDecisionIds, []);
            }
        }
        return $rows;
    }

    /**
     * Which decisions of the prova are still in force, read from the
     * database. For callers that have no engine input at hand and must not
     * load a whole race to get one.
     *
     * @return array<int, true>
     */
    public static function activeIdsFromDatabase(Prova $prova): array
    {
        $statement = Propel::getConnection('resultados')->prepare(
            'SELECT decisao_id, tipo, desfaz_decisao_id FROM contagem_decisao
              WHERE evento_id = :evento AND prova_id = :prova'
        );
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId()]);

        $decisions = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decision = new Decision((int) $row['decisao_id'], (string) $row['tipo']);
            $decision->desfazDecisaoId = self::intOrNull($row['desfaz_decisao_id']);
            $decisions[] = $decision;
        }
        return array_fill_keys(array_keys(ActiveDecisions::filter($decisions)), true);
    }

    /**
     * Who made each of these decisions, when, and why, for the timeline's
     * chips ("anulada pelo contador (Contador1)").
     *
     * @param int[] $decisaoIds
     * @return array<int, array{usuario: ?string, criado_em_ms: int, motivo: ?string}>
     */
    public static function peopleOf(Prova $prova, array $decisaoIds): array
    {
        if ($decisaoIds === []) {
            return [];
        }
        $statement = Propel::getConnection('resultados')->prepare(
            'SELECT decisao_id, usuario, criado_em_ms, motivo FROM contagem_decisao
              WHERE evento_id = ? AND prova_id = ?
                AND decisao_id IN (' . implode(', ', array_fill(0, count($decisaoIds), '?')) . ')'
        );
        $statement->execute(array_merge([$prova->getEventoId(), $prova->getProvaId()], array_values($decisaoIds)));
        $people = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $people[(int) $row['decisao_id']] = [
                'usuario' => $row['usuario'],
                'criado_em_ms' => (int) $row['criado_em_ms'],
                'motivo' => $row['motivo'],
            ];
        }
        return $people;
    }

    /**
     * The car each reassigned entry counts for now: the newest reassignment
     * in force. One small query over the reassignments of the prova.
     *
     * @param array<int, true> $activeDecisionIds
     * @return array<int, int> passagem_id => car
     */
    private static function carsAfterReassignment(Prova $prova, array $activeDecisionIds): array
    {
        $statement = Propel::getConnection('resultados')->prepare(
            'SELECT decisao_id, passagem_id, equipe_numero FROM contagem_decisao
              WHERE evento_id = :evento AND prova_id = :prova AND tipo = :tipo
              ORDER BY decisao_id'
        );
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId(), ':tipo' => Decision::REATRIBUIR]);
        $cars = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($activeDecisionIds[(int) $row['decisao_id']]) && $row['passagem_id'] !== null && $row['equipe_numero'] !== null) {
                $cars[(int) $row['passagem_id']] = (int) $row['equipe_numero'];
            }
        }
        return $cars;
    }

    /** Username that made a decision, or null when unknown (or the row is gone). */
    public static function usernameOf(Prova $prova, int $decisaoId): ?string
    {
        $sql = 'SELECT usuario FROM contagem_decisao
                 WHERE decisao_id = :id AND evento_id = :evento AND prova_id = :prova';
        $statement = Propel::getConnection('resultados')->prepare($sql);
        $statement->execute([
            ':id' => $decisaoId,
            ':evento' => $prova->getEventoId(),
            ':prova' => $prova->getProvaId(),
        ]);
        $row = $statement->fetch(PDO::FETCH_NUM);
        $usuario = is_array($row) ? $row[0] : null;
        return is_string($usuario) && $usuario !== '' ? $usuario : null;
    }

    /**
     * @param array<string, mixed> $row one row of contagem_decisao, with the entry it is about
     * @param array<int, true> $activeDecisionIds
     * @param array<int, int> $carNow passagem_id => car, for reassigned entries
     * @return array<string, mixed>
     */
    private static function describe(array $row, array $activeDecisionIds, array $carNow): array
    {
        $id = (int) $row['decisao_id'];
        $isActive = isset($activeDecisionIds[$id]);
        $entryId = self::intOrNull($row['registro_id']);
        $typedCar = self::intOrNull($row['registro_carro']);
        return [
            'id' => $id,
            'tipo' => $row['tipo'],
            'passagem_id' => self::intOrNull($row['passagem_id']),
            'passagem_alvo_id' => self::intOrNull($row['passagem_alvo_id']),
            // The decision's own source (excluir_fonte), or the source of
            // the entry it is about (followed through an undo).
            'fonte_id' => self::intOrNull($row['fonte_id']) ?? self::intOrNull($row['registro_fonte_id']),
            'fonte_nome' => $row['fonte_nome'],
            // The entry's car as typed, and the car it counts for now.
            // Null for a decision about no stored entry.
            'carro_digitado' => $typedCar,
            'carro' => $entryId === null ? null : ($carNow[$entryId] ?? $typedCar),
            // When the entry was typed: the screen opens the car's timeline
            // there. Null for an entry of unknown time.
            'passagem_ts_ms' => $entryId === null || $row['registro_qualidade'] === Entry::TEMPO_DESCONHECIDO
                ? null
                : self::intOrNull($row['registro_ts_ms']),
            'equipe_numero' => self::intOrNull($row['equipe_numero']),
            'ts_ms' => self::intOrNull($row['ts_ms']),
            'ts_fim_ms' => self::intOrNull($row['ts_fim_ms']),
            'desfaz_decisao_id' => self::intOrNull($row['desfaz_decisao_id']),
            'origem' => $row['origem'],
            'usuario' => $row['usuario'],
            'motivo' => $row['motivo'],
            'criado_em_ms' => (int) $row['criado_em_ms'],
            'ativa' => $isActive,
            // An undo is not itself undone; see DecisionRequest::buildUndo.
            'pode_desfazer' => $isActive && $row['tipo'] !== Decision::DESFAZER,
        ];
    }

    /** @param mixed $value */
    private static function intOrNull($value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
