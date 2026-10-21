<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Model\ContagemOcorrencia;
use Baja\Model\ContagemOcorrenciaQuery;
use Baja\Model\Prova;

/**
 * The race-control events of a prova (start, red flag, restart, finish) as
 * stored, and which of them are still in force.
 *
 * Events are append-only. A wrong one is never edited or deleted; a new row
 * points at it through `substitui_id`:
 *
 *   - a row of the same type CORRECTS it (a new time for the same event);
 *   - a row of type "cancelamento" CANCELS it (the button was pressed by
 *     mistake, there was no such event).
 *
 * Either way the row pointed at stops counting, for as long as the row that
 * points at it was not cancelled itself. A correction that is cancelled
 * gives the original back; a cancellation that is cancelled ("restaurar")
 * gives back what it had removed.
 *
 * WHICH rows are in force is decided in one place, the engine
 * (RaceTimeline::rowsInForce); this class asks it. What it adds is what the
 * race control page needs on top: what became of each row that is NOT in
 * force, so the page can label it and offer the right button. The checks
 * made before an action is stored are in RaceEventRules.
 */
final class RaceEvents
{
    public const EM_VIGOR = 'em_vigor';
    public const CORRIGIDA = 'corrigida';
    public const CANCELADA = 'cancelada';
    /**
     * An earlier start (or finish) while a later one stands. The latest one
     * is THE start; this one would be the start again if the latest were
     * cancelled, which is what makes "Largar agora" pressed twice undoable.
     */
    public const SUPERADA = 'superada';
    /** The row is itself a cancellation: it is a record of who cancelled what. */
    public const CANCELAMENTO = 'cancelamento';
    /** A cancellation that was cancelled in turn: what it removed is back. */
    public const CANCELAMENTO_DESFEITO = 'cancelamento_desfeito';

    /** Race events, with the label shown for each. Pit events are not race control's. */
    public const LABELS = [
        Ocorrencia::LARGADA => 'Largada',
        Ocorrencia::BANDEIRA_VERMELHA => 'Bandeira vermelha',
        Ocorrencia::RELARGADA => 'Relargada',
        Ocorrencia::ENCERRAMENTO => 'Encerramento',
    ];

    /**
     * @return ContagemOcorrencia[] race-control events and their cancellations, oldest first
     */
    public static function load(Prova $prova): array
    {
        $rows = ContagemOcorrenciaQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->filterByTipo(array_merge(array_keys(self::LABELS), [Ocorrencia::CANCELAMENTO]))
            ->orderByOcorrenciaId()
            ->find();

        // Ids of race-control rows seen so far, cancellations included: a
        // cancellation may point at an event or at another cancellation.
        $raceControlIds = [];
        $kept = [];
        foreach ($rows as $row) {
            $isCancellation = $row->getTipo() === Ocorrencia::CANCELAMENTO;
            // The pit log may cancel its own events the same way; those
            // cancellations are not race control's business.
            if ($isCancellation && !isset($raceControlIds[(int) $row->getSubstituiId()])) {
                continue;
            }
            $raceControlIds[(int) $row->getOcorrenciaId()] = true;
            $kept[] = $row;
        }
        return $kept;
    }

    /**
     * @param ContagemOcorrencia[] $rows
     * @return Ocorrencia[] the same events as the engine's plain objects
     */
    public static function toOcorrencias(array $rows): array
    {
        $ocorrencias = [];
        foreach ($rows as $row) {
            $substitui = $row->getSubstituiId();
            $ocorrencias[] = new Ocorrencia(
                (int) $row->getOcorrenciaId(),
                (string) $row->getTipo(),
                (int) $row->getTsMs(),
                null,
                $substitui === null ? null : (int) $substitui
            );
        }
        return $ocorrencias;
    }

    /**
     * @param ContagemOcorrencia[] $rows
     */
    public static function timelineOf(array $rows): RaceTimeline
    {
        return RaceTimeline::fromOcorrencias(self::toOcorrencias($rows));
    }

    /**
     * The standing cancellation of each cancelled row.
     *
     * Rows are visited from the newest to the oldest. By the time a
     * cancellation is reached, every row that could have cancelled IT has
     * been seen, so a cancellation that was cancelled itself removes
     * nothing: that is "restaurar".
     *
     * This is only used to say WHY a row is out (cancelled or corrected)
     * and to find the cancellation "restaurar" must cancel. WHICH rows are
     * in force is asked of the engine alone, see inForce().
     *
     * @param Ocorrencia[] $ocorrencias
     * @return array<int, Ocorrencia> id of a cancelled row => the cancellation that stands against it
     */
    private static function standingCancellations(array $ocorrencias): array
    {
        $newestFirst = $ocorrencias;
        usort($newestFirst, fn(Ocorrencia $a, Ocorrencia $b) => $b->id <=> $a->id);

        $cancelledBy = [];
        foreach ($newestFirst as $ocorrencia) {
            $isStandingCancellation = $ocorrencia->tipo === Ocorrencia::CANCELAMENTO
                && $ocorrencia->substituiId !== null && !isset($cancelledBy[$ocorrencia->id]);
            if ($isStandingCancellation) {
                // The newest cancellation pointing at a target is the one that decides.
                $cancelledBy[$ocorrencia->substituiId] ??= $ocorrencia;
            }
        }
        return $cancelledBy;
    }

    /**
     * What became of each row: still in force, corrected, cancelled,
     * overtaken by a later start or finish, or a cancellation itself.
     *
     * @param Ocorrencia[] $ocorrencias
     * @return array<int, string> ocorrência id => one of the constants above
     */
    public static function situations(array $ocorrencias): array
    {
        $cancelledBy = self::standingCancellations($ocorrencias);
        $inForceIds = [];
        foreach (RaceTimeline::rowsInForce($ocorrencias) as $ocorrencia) {
            $inForceIds[$ocorrencia->id] = true;
        }
        // A row is corrected when a row of its own kind points at it and
        // was not cancelled. That row may have been corrected in turn: the
        // chain still leads to one time in force, the newest.
        $corrected = [];
        foreach ($ocorrencias as $ocorrencia) {
            $isCorrection = $ocorrencia->tipo !== Ocorrencia::CANCELAMENTO && $ocorrencia->substituiId !== null;
            if ($isCorrection && !isset($cancelledBy[$ocorrencia->id])) {
                $corrected[$ocorrencia->substituiId] = true;
            }
        }

        $situations = [];
        foreach ($ocorrencias as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::CANCELAMENTO) {
                $situations[$ocorrencia->id] = isset($cancelledBy[$ocorrencia->id]) ? self::CANCELAMENTO_DESFEITO : self::CANCELAMENTO;
            } elseif (isset($inForceIds[$ocorrencia->id])) {
                $situations[$ocorrencia->id] = self::EM_VIGOR;
            } elseif (isset($cancelledBy[$ocorrencia->id])) {
                $situations[$ocorrencia->id] = self::CANCELADA;
            } elseif (isset($corrected[$ocorrencia->id])) {
                $situations[$ocorrencia->id] = self::CORRIGIDA;
            } else {
                // Not taken out by anything, and still not what the engine
                // counts: an earlier start or finish under a later one.
                $situations[$ocorrencia->id] = self::SUPERADA;
            }
        }

        // A race has one start and one finish: of several that were never
        // corrected or cancelled, the most recently stored one is it.
        $newestIdByType = [];
        foreach ($ocorrencias as $ocorrencia) {
            $isOneOfAKind = $ocorrencia->tipo === Ocorrencia::LARGADA || $ocorrencia->tipo === Ocorrencia::ENCERRAMENTO;
            if ($isOneOfAKind && $situations[$ocorrencia->id] === self::EM_VIGOR) {
                $newestIdByType[$ocorrencia->tipo] = max($newestIdByType[$ocorrencia->tipo] ?? 0, $ocorrencia->id);
            }
        }
        foreach ($ocorrencias as $ocorrencia) {
            $newestId = $newestIdByType[$ocorrencia->tipo] ?? null;
            if ($newestId !== null && $situations[$ocorrencia->id] === self::EM_VIGOR && $ocorrencia->id !== $newestId) {
                $situations[$ocorrencia->id] = self::SUPERADA;
            }
        }
        return $situations;
    }

    /**
     * The events that count, oldest row first.
     *
     * This is the engine's own answer (RaceTimeline::rowsInForce), with one
     * start and one finish at most. The page used to work it out by itself, with a rule
     * that differed in one case: a row corrected TWICE. The page then
     * believed the first time was back in force beside the third, and
     * refused the second correction of a red flag as "two red flags in a
     * row". One implementation cannot disagree with itself.
     *
     * @param Ocorrencia[] $ocorrencias
     * @return Ocorrencia[]
     */
    public static function inForce(array $ocorrencias): array
    {
        // The engine's rows, minus an earlier start or finish under a later
        // one: the engine keeps those too and simply reads the newest.
        $situations = self::situations($ocorrencias);
        $inForce = array_values(array_filter(
            RaceTimeline::rowsInForce($ocorrencias),
            fn(Ocorrencia $ocorrencia) => $situations[$ocorrencia->id] === self::EM_VIGOR
        ));
        usort($inForce, fn(Ocorrencia $a, Ocorrencia $b) => $a->id <=> $b->id);
        return $inForce;
    }

    /**
     * The cancellation that currently keeps a row out, or null when the row
     * is not cancelled. "Restaurar" cancels that cancellation.
     *
     * @param Ocorrencia[] $ocorrencias
     */
    public static function cancellationOf(array $ocorrencias, int $ocorrenciaId): ?Ocorrencia
    {
        return self::standingCancellations($ocorrencias)[$ocorrenciaId] ?? null;
    }

    /** Kept for callers of the old name; see RaceEventRules::inconsistency(). */
    public static function inconsistency(array $ocorrencias): ?string
    {
        return RaceEventRules::inconsistency($ocorrencias);
    }
}
