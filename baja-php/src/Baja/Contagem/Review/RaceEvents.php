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
 * Either way the row pointed at stops counting. The engine applies the same
 * rule (RaceTimeline::fromOcorrencias); this class exists so that the race
 * control page can show it and check an action before storing it.
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

        $raceEventIds = [];
        $kept = [];
        foreach ($rows as $row) {
            $isCancellation = $row->getTipo() === Ocorrencia::CANCELAMENTO;
            // The pit log may cancel its own events the same way; those
            // cancellations are not race control's business.
            if ($isCancellation && !isset($raceEventIds[(int) $row->getSubstituiId()])) {
                continue;
            }
            if (!$isCancellation) {
                $raceEventIds[(int) $row->getOcorrenciaId()] = true;
            }
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
     * What became of each row: still in force, corrected, cancelled,
     * overtaken by a later start or finish, or a cancellation itself.
     *
     * @param Ocorrencia[] $ocorrencias
     * @return array<int, string> ocorrência id => one of the constants above
     */
    public static function situations(array $ocorrencias): array
    {
        // What a row was replaced by decides whether it reads "corrigida"
        // or "cancelada". When both happened, the cancellation is the news.
        $replacedBy = [];
        foreach ($ocorrencias as $ocorrencia) {
            if ($ocorrencia->substituiId === null) {
                continue;
            }
            $isCancellation = $ocorrencia->tipo === Ocorrencia::CANCELAMENTO;
            if ($isCancellation || !isset($replacedBy[$ocorrencia->substituiId])) {
                $replacedBy[$ocorrencia->substituiId] = $isCancellation ? self::CANCELADA : self::CORRIGIDA;
            }
        }

        $situations = [];
        foreach ($ocorrencias as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::CANCELAMENTO) {
                $situations[$ocorrencia->id] = self::CANCELAMENTO;
            } else {
                $situations[$ocorrencia->id] = $replacedBy[$ocorrencia->id] ?? self::EM_VIGOR;
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
     * @return Ocorrencia[] the events that count
     */
    public static function inForce(array $ocorrencias): array
    {
        $situations = self::situations($ocorrencias);
        return array_values(array_filter(
            $ocorrencias,
            fn(Ocorrencia $ocorrencia) => $situations[$ocorrencia->id] === self::EM_VIGOR
        ));
    }

    /**
     * Would the race still make sense with these events? Null when it
     * would; otherwise the reason, in Portuguese.
     *
     * The one rule: nothing can happen in a race before its start. So while
     * a red flag, a restart or a finish stands, there must be a start, and
     * it must not be later than any of them.
     *
     * @param Ocorrencia[] $ocorrencias every event, including the one about to be stored
     */
    public static function inconsistency(array $ocorrencias): ?string
    {
        $startMs = RaceTimeline::fromOcorrencias($ocorrencias)->largadaMs;

        foreach (self::inForce($ocorrencias) as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::LARGADA) {
                continue;
            }
            $label = mb_strtolower(self::LABELS[$ocorrencia->tipo] ?? $ocorrencia->tipo);
            $when = Pagina::formatClock($ocorrencia->tsMs);
            if ($startMs === null) {
                return 'Existe ' . $label . ' registrada (' . $when . ') e a corrida ficaria sem largada. Cancele essa ocorrência primeiro.';
            }
            if ($ocorrencia->tsMs < $startMs) {
                return 'A largada ficaria depois de ' . $label . ' já registrada (' . $when . '). Corrija ou cancele essa ocorrência primeiro.';
            }
        }
        return null;
    }
}
