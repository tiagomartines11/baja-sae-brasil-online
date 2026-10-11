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
 * The engine's Decision objects deliberately carry no username (the engine
 * does not care who decided), so this reads the people-facing columns
 * straight from contagem_decisao.
 */
final class DecisionHistory
{
    /** The panel shows the recent past; the full list is in the CSV export. */
    private const MAX_ROWS = 200;

    /**
     * @param Decision[] $decisions every decision of the prova (from the engine input)
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Prova $prova, array $decisions): array
    {
        $active = ActiveDecisions::filter($decisions);

        $sql = 'SELECT decisao_id, tipo, passagem_id, fonte_id, equipe_numero, ts_ms, ts_fim_ms,
                       desfaz_decisao_id, origem, usuario, motivo, criado_em_ms
                  FROM contagem_decisao
                 WHERE evento_id = :evento AND prova_id = :prova
                 ORDER BY decisao_id DESC
                 LIMIT ' . self::MAX_ROWS;
        $statement = Propel::getConnection('resultados')->prepare($sql);
        $statement->execute([':evento' => $prova->getEventoId(), ':prova' => $prova->getProvaId()]);

        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) $row['decisao_id'];
            $isActive = isset($active[$id]);
            $rows[] = [
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
        return $rows;
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
        $usuario = $statement->fetchColumn();
        return is_string($usuario) && $usuario !== '' ? $usuario : null;
    }

    /** @param mixed $value */
    private static function intOrNull($value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
