<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\Source;
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
 *   3. A race that was finished is neither stopped nor restarted.
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
        return self::afterFinishProblem($inForce) ?? self::pairingProblem($inForce);
    }

    /**
     * A red flag or a restart later than the finish. The usual cause is the
     * wrong button after the race ("Bandeira vermelha" for "Encerrar"), and
     * stored it would reopen a stoppage in a race that is over: the car
     * statuses and the "after the finish" rule would both follow it.
     *
     * @param Ocorrencia[] $inForce
     */
    private static function afterFinishProblem(array $inForce): ?string
    {
        $finishMs = null;
        foreach ($inForce as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::ENCERRAMENTO) {
                // Of several finishes in force the most recently stored one counts.
                $finishMs = $ocorrencia->tsMs;
            }
        }
        if ($finishMs === null) {
            return null;
        }
        foreach ($inForce as $ocorrencia) {
            $stopsOrRestarts = $ocorrencia->tipo === Ocorrencia::BANDEIRA_VERMELHA || $ocorrencia->tipo === Ocorrencia::RELARGADA;
            if ($stopsOrRestarts && $ocorrencia->tsMs > $finishMs) {
                return 'A corrida foi encerrada em ' . Pagina::formatClock($finishMs) . ': não cabe '
                    . mb_strtolower(RaceEvents::LABELS[$ocorrencia->tipo]) . ' depois disso (' . Pagina::formatClock($ocorrencia->tsMs) . '). '
                    . 'Se a corrida não acabou, cancele o encerramento primeiro; se o horário está errado, corrija-o.';
            }
        }
        return null;
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
     * Entries that would be BEFORE a start at $newStartMs and are not
     * before the start in force today: the start pack, typically, when a
     * start is moved a few seconds later. With no start in force today
     * (the first start, or a start recorded again after the only one was
     * cancelled) that is every entry typed before the new start.
     *
     * A counter's "??" is one of them: a crossing somebody saw, which the
     * new start leaves out like any other (it counts in `registros`, and
     * has no car for `carros`). A timing sensor's marks are not: a mark
     * never counts by itself, so a start moved past it loses nothing.
     *
     * Asked of the write connection and meant to be called while holding
     * the count lock: the numbers are then the ones the action will really
     * leave behind, not the ones of a page drawn a minute ago.
     *
     * @param int|null $currentStartMs the start in force, or null when the race has none
     * @return array{registros: int, carros: int}
     */
    public static function entriesLeftBeforeStart(Prova $prova, ?int $currentStartMs, int $newStartMs): array
    {
        if ($currentStartMs !== null && $newStartMs <= $currentStartMs) {
            return ['registros' => 0, 'carros' => 0];
        }
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT COUNT(*) AS registros, COUNT(DISTINCT p.equipe_numero) AS carros
               FROM contagem_passagem p
               JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
              WHERE f.evento_id = ? AND f.prova_id = ? AND p.ts_ms >= ? AND p.ts_ms < ?
                AND p.qualidade_tempo <> ? AND f.tipo <> ?'
        );
        // An entry of unknown time carries its phone's own guess: where it
        // falls says nothing, and it never counts by itself anyway.
        $statement->execute([$prova->getEventoId(), $prova->getProvaId(), $currentStartMs ?? 0, $newStartMs, 'desconhecido', Source::TIPO_SENSOR]);
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
