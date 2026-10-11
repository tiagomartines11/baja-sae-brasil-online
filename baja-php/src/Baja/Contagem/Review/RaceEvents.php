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
 * Either way the row pointed at stops counting, FOR AS LONG AS the row that
 * points at it counts itself. A correction that is cancelled gives the
 * original back; a cancellation that is cancelled ("restaurar") gives back
 * what it had removed. That is the rule of undoing a decision, and it is
 * applied the same way: newest row first (see resolve()).
 *
 * The engine applies the same rule (RaceTimeline::fromOcorrencias); this
 * class exists so that the race control page can show what became of each
 * row and check an action before storing it. The checks themselves are in
 * RaceEventRules.
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
     * For every row that does not count: the row that took it out.
     *
     * Rows are visited from the newest to the oldest. By the time a row is
     * reached, everything that could have taken IT out has been seen, so a
     * row that is out takes nothing out itself. That one rule gives:
     * cancelling a correction brings the original back, and cancelling a
     * cancellation restores the event.
     *
     * @param Ocorrencia[] $ocorrencias
     * @return array<int, Ocorrencia> id of a row that is out => the row that took it out
     */
    public static function resolve(array $ocorrencias): array
    {
        $newestFirst = $ocorrencias;
        usort($newestFirst, fn(Ocorrencia $a, Ocorrencia $b) => $b->id <=> $a->id);

        $takenOutBy = [];
        foreach ($newestFirst as $ocorrencia) {
            if (isset($takenOutBy[$ocorrencia->id]) || $ocorrencia->substituiId === null) {
                continue;
            }
            // The newest row pointing at a target is the one that decides.
            $takenOutBy[$ocorrencia->substituiId] ??= $ocorrencia;
        }
        return $takenOutBy;
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
        $takenOutBy = self::resolve($ocorrencias);

        $situations = [];
        foreach ($ocorrencias as $ocorrencia) {
            $killer = $takenOutBy[$ocorrencia->id] ?? null;
            if ($ocorrencia->tipo === Ocorrencia::CANCELAMENTO) {
                $situations[$ocorrencia->id] = $killer === null ? self::CANCELAMENTO : self::CANCELAMENTO_DESFEITO;
            } elseif ($killer === null) {
                $situations[$ocorrencia->id] = self::EM_VIGOR;
            } else {
                $situations[$ocorrencia->id] = $killer->tipo === Ocorrencia::CANCELAMENTO ? self::CANCELADA : self::CORRIGIDA;
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
     * @param Ocorrencia[] $ocorrencias
     * @return Ocorrencia[] the events that count, oldest row first
     */
    public static function inForce(array $ocorrencias): array
    {
        $situations = self::situations($ocorrencias);
        $inForce = array_values(array_filter(
            $ocorrencias,
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
        $killer = self::resolve($ocorrencias)[$ocorrenciaId] ?? null;
        return $killer !== null && $killer->tipo === Ocorrencia::CANCELAMENTO ? $killer : null;
    }

    /** Kept for callers of the old name; see RaceEventRules::inconsistency(). */
    public static function inconsistency(array $ocorrencias): ?string
    {
        return RaceEventRules::inconsistency($ocorrencias);
    }
}
