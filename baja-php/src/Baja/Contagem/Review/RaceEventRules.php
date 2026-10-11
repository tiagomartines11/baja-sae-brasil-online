<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Would the race still make sense after a race-control action? Asked before
 * the action is stored, with the row about to be stored already in the list.
 *
 * Kept apart from RaceEvents (what the rows mean) and RaceControl (what the
 * buttons do) so that the rules can be read in one place:
 *
 *   1. Nothing happens in a race before its start.
 *   2. Red flags and restarts come in pairs, in that order.
 *
 * And one question that is not a refusal but needs a person's "yes":
 * moving the start later leaves entries before it, which then stop counting.
 */
final class RaceEventRules
{
    /**
     * The start in force: the most recently stored start that nothing took out.
     *
     * @param Ocorrencia[] $ocorrencias
     */
    public static function startMs(array $ocorrencias): ?int
    {
        $startMs = null;
        foreach (RaceEvents::inForce($ocorrencias) as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::LARGADA) {
                $startMs = $ocorrencia->tsMs;
            }
        }
        return $startMs;
    }

    /**
     * Would cancelling this row leave the race with no start at all? True
     * for the only start; false for a corrected start (the time it replaced
     * comes back) and for a second start (the earlier one counts again).
     *
     * @param Ocorrencia[] $ocorrencias every stored event
     */
    public static function cancellingLeavesNoStart(array $ocorrencias, Ocorrencia $target): bool
    {
        if ($target->tipo !== Ocorrencia::LARGADA) {
            return false;
        }
        $ocorrencias[] = new Ocorrencia(PHP_INT_MAX, Ocorrencia::CANCELAMENTO, $target->tsMs, null, $target->id);
        return self::startMs($ocorrencias) === null;
    }

    /**
     * Null when the race makes sense with these events; otherwise the
     * reason, in Portuguese.
     *
     * @param Ocorrencia[] $ocorrencias every event, including the one about to be stored
     */
    public static function inconsistency(array $ocorrencias): ?string
    {
        $inForce = RaceEvents::inForce($ocorrencias);
        $startMs = self::startMs($ocorrencias);

        foreach ($inForce as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::LARGADA) {
                continue;
            }
            $label = mb_strtolower(RaceEvents::LABELS[$ocorrencia->tipo] ?? $ocorrencia->tipo);
            $when = Pagina::formatClock($ocorrencia->tsMs);
            if ($startMs === null) {
                return 'Existe ' . $label . ' registrada (' . $when . ') e a corrida ficaria sem largada. Cancele essa ocorrência primeiro.';
            }
            if ($ocorrencia->tsMs < $startMs) {
                return 'A largada ficaria depois de ' . $label . ' já registrada (' . $when . '). Corrija ou cancele essa ocorrência primeiro.';
            }
        }
        return self::pairingProblem($inForce);
    }

    /**
     * Red flag, restart, red flag, restart: walked in the order they
     * happened. A second red flag while the race is stopped, or a restart
     * while it is running, means one of the two buttons was pressed by
     * mistake or carries the wrong time. Stored as it is, the engine would
     * have to guess which stoppage the lap times should skip.
     *
     * @param Ocorrencia[] $inForce
     */
    private static function pairingProblem(array $inForce): ?string
    {
        usort($inForce, fn(Ocorrencia $a, Ocorrencia $b) => [$a->tsMs, $a->id] <=> [$b->tsMs, $b->id]);

        $stoppedSince = null;
        foreach ($inForce as $ocorrencia) {
            $when = Pagina::formatClock($ocorrencia->tsMs);
            if ($ocorrencia->tipo === Ocorrencia::BANDEIRA_VERMELHA) {
                if ($stoppedSince !== null) {
                    return 'Haveria duas bandeiras vermelhas seguidas (' . Pagina::formatClock($stoppedSince) . ' e ' . $when
                        . ') sem relargada entre elas. Registre a relargada, ou corrija ou cancele uma das duas.';
                }
                $stoppedSince = $ocorrencia->tsMs;
            } elseif ($ocorrencia->tipo === Ocorrencia::RELARGADA) {
                if ($stoppedSince === null) {
                    return 'A relargada de ' . $when . ' ficaria sem bandeira vermelha antes dela. '
                        . 'Registre, corrija ou restaure a bandeira vermelha primeiro, ou cancele essa relargada.';
                }
                $stoppedSince = null;
            }
        }
        return null;
    }

    /**
     * Entries that count as part of the race today and would be BEFORE a
     * start moved to $newStartMs: the start pack, typically, when a start is
     * corrected a few seconds later.
     *
     * @return array{registros: int, carros: int}
     */
    public static function entriesLeftBeforeStart(Prova $prova, int $currentStartMs, int $newStartMs): array
    {
        if ($newStartMs <= $currentStartMs) {
            return ['registros' => 0, 'carros' => 0];
        }
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT COUNT(*) AS registros, COUNT(DISTINCT p.equipe_numero) AS carros
               FROM contagem_passagem p
               JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              WHERE f.evento_id = ? AND f.prova_id = ? AND p.ts_ms >= ? AND p.ts_ms < ?'
        );
        $statement->execute([$prova->getEventoId(), $prova->getProvaId(), $currentStartMs, $newStartMs]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['registros' => (int) ($row['registros'] ?? 0), 'carros' => (int) ($row['carros'] ?? 0)];
    }

    /** "3 registros de 2 carros", for the question put to the controller. */
    public static function describeEntriesLeftOut(array $affected): string
    {
        return $affected['registros'] . ($affected['registros'] === 1 ? ' registro' : ' registros')
            . ' de ' . $affected['carros'] . ($affected['carros'] === 1 ? ' carro' : ' carros');
    }
}
