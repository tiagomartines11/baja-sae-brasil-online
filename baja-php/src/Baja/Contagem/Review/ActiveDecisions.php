<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;

/**
 * Which decisions still count.
 *
 * Decisions are never deleted: undoing one stores a new "desfazer" row that
 * points at it. The review screen needs to know, for every decision, whether
 * it is still in force, both to draw the history ("desfeita") and to detect
 * that two reviewers acted on the same entry.
 */
final class ActiveDecisions
{
    /** Decision types that point at one entry through passagem_id. */
    public const ENTRY_TYPES = [Decision::ANULAR, Decision::REATRIBUIR, Decision::CONFIRMAR];

    /**
     * @param Decision[] $decisions every decision of the prova, in any order
     * @return array<int, Decision> id => decision, only those still in force
     */
    public static function filter(array $decisions): array
    {
        $newestFirst = $decisions;
        usort($newestFirst, fn(Decision $a, Decision $b) => $b->id <=> $a->id);

        // An undo always has a higher id than what it undoes, so walking from
        // the newest row down means that by the time we reach a decision we
        // already know whether something still in force undid it.
        $undoneIds = [];
        $active = [];
        foreach ($newestFirst as $decision) {
            if (isset($undoneIds[$decision->id])) {
                continue;
            }
            $active[$decision->id] = $decision;
            if ($decision->tipo === Decision::DESFAZER && $decision->desfazDecisaoId !== null) {
                $undoneIds[$decision->desfazDecisaoId] = true;
            }
        }
        return $active;
    }

    /**
     * @param array<int, Decision> $active as returned by filter()
     * @return array<int, int[]> passagemId => ids of the decisions in force about that entry, ascending
     */
    public static function idsByEntry(array $active): array
    {
        $byEntry = [];
        foreach ($active as $decision) {
            if ($decision->passagemId !== null && in_array($decision->tipo, self::ENTRY_TYPES, true)) {
                $byEntry[$decision->passagemId][] = $decision->id;
            }
        }
        foreach ($byEntry as &$ids) {
            sort($ids);
        }
        unset($ids);
        return $byEntry;
    }

    /**
     * The entry a decision is about, following an undo to the decision it
     * undid. Null for decisions that are not about one entry.
     *
     * @param array<int, Decision> $allById every decision keyed by id
     */
    public static function entryIdOf(Decision $decision, array $allById): ?int
    {
        if ($decision->tipo === Decision::DESFAZER) {
            $target = $allById[$decision->desfazDecisaoId ?? -1] ?? null;
            if ($target === null || $target->tipo === Decision::DESFAZER) {
                return null;
            }
            return self::entryIdOf($target, $allById);
        }
        if (in_array($decision->tipo, self::ENTRY_TYPES, true)) {
            return $decision->passagemId;
        }
        if ($decision->tipo === Decision::ADICIONAR) {
            // The pass a reviewer added is shown as an entry whose id is
            // minus the id of this decision (see DecisionApplier).
            return -$decision->id;
        }
        return null;
    }

    /**
     * @param Decision[] $decisions
     * @return array<int, Decision>
     */
    public static function indexById(array $decisions): array
    {
        $byId = [];
        foreach ($decisions as $decision) {
            $byId[$decision->id] = $decision;
        }
        return $byId;
    }
}
