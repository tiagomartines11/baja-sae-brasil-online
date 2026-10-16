<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\RaceTimeline;

/**
 * Checks on a decision that need the race as it is NOW, under the lock:
 * is the moment possible, and does the same decision already stand?
 *
 * The second question matters because decisions pile up instead of
 * replacing each other. Two voids on one entry look like one on screen, but
 * undoing "the" void then restores nothing: the other is still there. The
 * same goes for a pass added twice (a retry after a slow answer, or two
 * reviewers adding the pass they both saw missing): the car gains two laps.
 */
final class DecisionRules
{
    /**
     * @return string|null why the decision makes no sense, in Portuguese; null when it does
     */
    public static function invalidReason(Decision $decision, RaceTimeline $timeline, int $nowMs): ?string
    {
        if ($decision->tipo !== Decision::ADICIONAR) {
            return null;
        }
        if ($timeline->largadaMs === null) {
            return 'A corrida ainda não tem largada: não é possível adicionar uma passagem.';
        }
        if ($decision->tsMs < $timeline->largadaMs) {
            return 'O horário informado é anterior à largada.';
        }
        if ($decision->tsMs > $nowMs) {
            // The usual cause: the time of day typed where time of race was asked.
            return 'O horário informado está no futuro. Informe o tempo de prova (h:mm:ss desde a largada), não a hora do relógio.';
        }
        return null;
    }

    /**
     * The decision still in force that already says what this one says.
     *
     * @return int|null its id, or null when this decision is new
     */
    public static function sameDecisionInForce(Decision $decision, EngineInput $input): ?int
    {
        $active = ActiveDecisions::filter($input->decisions);
        ksort($active);

        switch ($decision->tipo) {
            case Decision::ANULAR:
            case Decision::CONFIRMAR:
                foreach ($active as $other) {
                    if ($other->tipo === $decision->tipo && $other->passagemId === $decision->passagemId) {
                        return $other->id;
                    }
                }
                return null;
            case Decision::REATRIBUIR:
                // Of several reassignments of one entry the newest is the
                // one that counts, so that is the one to compare with.
                $newest = null;
                foreach ($active as $other) {
                    if ($other->tipo === Decision::REATRIBUIR && $other->passagemId === $decision->passagemId) {
                        $newest = $other;
                    }
                }
                return $newest !== null && $newest->carro === $decision->carro ? $newest->id : null;
            case Decision::ADICIONAR:
                // Two passes of one car inside the matching window are one
                // crossing; a second added pass there can only be a repeat.
                foreach ($active as $other) {
                    if ($other->tipo === Decision::ADICIONAR
                        && $other->carro === $decision->carro
                        && $other->tsMs !== null
                        && abs($other->tsMs - (int) $decision->tsMs) <= $input->settings->janelaMs) {
                        return $other->id;
                    }
                }
                return null;
            case Decision::EXCLUIR_FONTE:
                foreach ($active as $other) {
                    if ($other->tipo === Decision::EXCLUIR_FONTE
                        && $other->fonteId === $decision->fonteId
                        && $other->tsMs === $decision->tsMs
                        && $other->tsFimMs === $decision->tsFimMs) {
                        return $other->id;
                    }
                }
                return null;
            default:
                return null;
        }
    }
}
