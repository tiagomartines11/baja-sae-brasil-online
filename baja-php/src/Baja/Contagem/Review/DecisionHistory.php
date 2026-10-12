<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Model\Prova;
use PDO;
use Propel\Runtime\Propel;

/**
 * The decision history panel: who decided what, most recent first, and
 * whether each decision can still be undone.
 *
 * Two lists:
 *
 * - page(): the history itself, a page at a time. A long race gathers
 *   hundreds of decisions and the screen is redrawn from every answer, so
 *   only the newest page travels with the state; older pages are fetched
 *   when the reviewer asks.
 * - inForce(): every "excluir fonte", "adicionar passagem" and "marcar
 *   saída" still in force, however old. These change the race for as long
 *   as they stand and are not attached to any entry on a timeline, so the
 *   history is the only place to see and undo them. They must never scroll
 *   away.
 *
 * The engine's Decision objects deliberately carry no username (the engine
 * does not care who decided), so this reads the people-facing columns
 * straight from contagem_decisao.
 */
final class DecisionHistory
{
    public const PAGE_SIZE = 50;

    private const COLUMNS = 'decisao_id, tipo, passagem_id, fonte_id, equipe_numero, ts_ms, ts_fim_ms,
                             desfaz_decisao_id, origem, usuario, motivo, criado_em_ms';

    /**
     * One page of the history, newest first.
     *
     * @param array<int, true> $activeDecisionIds ids of the decisions still in force
     * @param int|null $beforeId only decisions older than this id; null for the newest page
     * @return array{linhas: array<int, array<string, mixed>>, ha_mais: bool}
     */
    public static function page(Prova $prova, array $activeDecisionIds, ?int $beforeId, int $pageSize = self::PAGE_SIZE): array
    {
        $pageSize = max(1, min(500, $pageSize));
        // One row more than the page, only to learn whether another page exists.
        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM contagem_decisao
                 WHERE evento_id = :evento AND prova_id = :prova AND decisao_id < :antes
                 ORDER BY decisao_id DESC
                 LIMIT ' . ($pageSize + 1);
        $statement = Propel::getConnection('resultados')->prepare($sql);
        $statement->execute([
            ':evento' => $prova->getEventoId(),
            ':prova' => $prova->getProvaId(),
            ':antes' => $beforeId ?? PHP_INT_MAX,
        ]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = self::describe($row, $activeDecisionIds);
        }
        $hasMore = count($rows) > $pageSize;
        return ['linhas' => array_slice($rows, 0, $pageSize), 'ha_mais' => $hasMore];
    }

    /**
     * Every source exclusion, added pass and marked exit still in force, newest first.
     *
     * @param array<int, true> $activeDecisionIds
     * @return array<int, array<string, mixed>>
     */
    public static function inForce(Prova $prova, array $activeDecisionIds): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
                  FROM contagem_decisao
                 WHERE evento_id = :evento AND prova_id = :prova AND tipo IN (:excluir, :adicionar, :saida)
                 ORDER BY decisao_id DESC';
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
                $rows[] = self::describe($row, $activeDecisionIds);
            }
        }
        return $rows;
    }

    /**
     * Which decisions of the prova are still in force, read from the
     * database. For the "older page" request, which has no engine input at
     * hand and must not load a whole race to get one.
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
     * @param array<string, mixed> $row one row of contagem_decisao
     * @param array<int, true> $activeDecisionIds
     * @return array<string, mixed>
     */
    private static function describe(array $row, array $activeDecisionIds): array
    {
        $id = (int) $row['decisao_id'];
        $isActive = isset($activeDecisionIds[$id]);
        return [
            'id' => $id,
            'tipo' => $row['tipo'],
            'passagem_id' => self::intOrNull($row['passagem_id']),
            'fonte_id' => self::intOrNull($row['fonte_id']),
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
