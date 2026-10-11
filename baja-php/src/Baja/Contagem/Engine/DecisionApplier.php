<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Source;

/**
 * Step 1 of the engine: apply what people decided before looking at anything
 * else, so every later step works on the corrected picture.
 *
 * The raw entries are never modified. This class works on copies.
 */
final class DecisionApplier
{
    /**
     * @param Entry[] $entries
     * @param Decision[] $decisions
     * @param array<int, Source> $sourcesById
     */
    public static function apply(array $entries, array $decisions, array $sourcesById): AppliedDecisions
    {
        $applied = new AppliedDecisions();
        $applied->activeDecisions = self::dropUndone($decisions);

        $newCarByEntryId = [];
        $addedPasses = [];

        foreach ($applied->activeDecisions as $decision) {
            switch ($decision->tipo) {
                case Decision::ANULAR:
                    if ($decision->passagemId !== null) {
                        $applied->voidedEntryIds[$decision->passagemId] = $decision->id;
                    }
                    break;
                case Decision::REATRIBUIR:
                    // Decisions are visited oldest first, so if an entry was
                    // reassigned twice the most recent choice is the one kept.
                    if ($decision->passagemId !== null && $decision->carro !== null) {
                        $newCarByEntryId[$decision->passagemId] = $decision->carro;
                    }
                    break;
                case Decision::CONFIRMAR:
                    if ($decision->passagemId !== null) {
                        $applied->confirmedEntryIds[$decision->passagemId] = true;
                    }
                    break;
                case Decision::ADICIONAR:
                    if ($decision->carro !== null && $decision->tsMs !== null) {
                        // A negative id can never collide with a stored entry
                        // and still tells which decision created the pass.
                        $addedPasses[] = new Entry(-$decision->id, Source::REVISOR_ID, 0, $decision->carro, $decision->tsMs);
                    }
                    break;
                case Decision::EXCLUIR_FONTE:
                    if ($decision->fonteId !== null && $decision->tsMs !== null && $decision->tsFimMs !== null) {
                        $applied->exclusions[$decision->fonteId][] = [$decision->tsMs, $decision->tsFimMs];
                    }
                    break;
            }
        }

        foreach ($entries as $rawEntry) {
            if (isset($applied->voidedEntryIds[$rawEntry->id])) {
                continue;
            }
            $source = $sourcesById[$rawEntry->fonteId] ?? null;
            if ($source === null || !$source->countsPasses()) {
                continue;
            }
            if ($applied->isSourceExcludedAt($rawEntry->fonteId, $rawEntry->tsMs)) {
                continue;
            }

            $entry = clone $rawEntry;
            if (isset($newCarByEntryId[$entry->id]) && $newCarByEntryId[$entry->id] !== $entry->carro) {
                $entry->carro = $newCarByEntryId[$entry->id];
                $applied->reassignedEntryIds[$entry->id] = $entry->fonteId;
            }
            $applied->entries[] = $entry;
        }

        foreach ($addedPasses as $addedPass) {
            $applied->entries[] = $addedPass;
        }

        return $applied;
    }

    /**
     * Removes decisions that a later "desfazer" cancelled.
     *
     * Walking from the newest to the oldest makes undo-of-undo work without
     * special cases: by the time a decision is reached, every "desfazer"
     * that could cancel it has already been seen, and a "desfazer" that was
     * itself cancelled cancels nothing.
     *
     * @param Decision[] $decisions
     * @return Decision[] oldest first
     */
    private static function dropUndone(array $decisions): array
    {
        $newestFirst = $decisions;
        usort($newestFirst, fn(Decision $a, Decision $b) => $b->id <=> $a->id);

        $cancelledIds = [];
        $active = [];
        foreach ($newestFirst as $decision) {
            if (isset($cancelledIds[$decision->id])) {
                continue;
            }
            if ($decision->tipo === Decision::DESFAZER) {
                if ($decision->desfazDecisaoId !== null) {
                    $cancelledIds[$decision->desfazDecisaoId] = true;
                }
                continue;
            }
            $active[] = $decision;
        }

        return array_reverse($active);
    }
}
