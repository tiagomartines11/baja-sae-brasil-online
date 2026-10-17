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
 * The raw entries are never modified. An entry a reviewer reassigned is
 * copied and the copy carries the new car; every other entry is passed on
 * as it is, and no later step writes to an entry.
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
                case Decision::USAR_TEMPO:
                    // A confirmation that also says which entry times the
                    // pass. Visited oldest first: a later choice wins.
                    if ($decision->passagemId !== null) {
                        $applied->confirmedEntryIds[$decision->passagemId] = true;
                        $applied->timeChoices[$decision->passagemId] = $decision->id;
                        if ($decision->passagemAlvoId !== null) {
                            $applied->witnesses[$decision->passagemAlvoId] = $decision->passagemId;
                        }
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
                    // No end time means "until further notice": the way out
                    // when a counter's device is gone for good.
                    if ($decision->fonteId !== null) {
                        $applied->exclusions[$decision->fonteId][] = [$decision->tsMs, $decision->tsFimMs];
                    }
                    break;
            }
        }

        $alwaysCounting = self::sourcesThatCountedAllAlong($sourcesById, $applied);
        // This loop visits every entry of the race, so what it reads and
        // writes on each turn is kept in local variables.
        $voidedEntryIds = $applied->voidedEntryIds;
        $survivors = [];

        foreach ($entries as $rawEntry) {
            if (isset($voidedEntryIds[$rawEntry->id])) {
                continue;
            }
            if (!isset($alwaysCounting[$rawEntry->fonteId])) {
                // A source that was switched off keeps what it recorded while
                // it was on; only entries from after that moment are dropped.
                $source = $sourcesById[$rawEntry->fonteId] ?? null;
                if ($source === null || !$source->wasCountingAt($rawEntry->tsMs)) {
                    continue;
                }
                if ($applied->isSourceExcludedAt($rawEntry->fonteId, $rawEntry->tsMs)) {
                    continue;
                }
            }

            $newCar = $newCarByEntryId[$rawEntry->id] ?? null;
            if ($newCar === null || $newCar === $rawEntry->carro) {
                // Untouched by any decision: the raw entry itself is used,
                // read-only. Copying tens of thousands of them on every run
                // would be the slowest step of the engine.
                $survivors[] = $rawEntry;
                continue;
            }
            $entry = clone $rawEntry;
            $entry->carro = $newCar;
            $applied->reassignedEntryIds[$entry->id] = $entry->fonteId;
            $survivors[] = $entry;
        }

        foreach ($addedPasses as $addedPass) {
            $survivors[] = $addedPass;
        }
        $applied->entries = $survivors;

        return $applied;
    }

    /**
     * The usual case, checked once per source instead of once per entry:
     * sources that are switched on and were never excluded by a reviewer.
     * Every entry of such a source takes part.
     *
     * @param array<int, Source> $sourcesById
     * @return array<int, true> fonteId => true
     */
    private static function sourcesThatCountedAllAlong(array $sourcesById, AppliedDecisions $applied): array
    {
        $alwaysCounting = [];
        foreach ($sourcesById as $source) {
            if ($source->countsPasses() && !isset($applied->exclusions[$source->id])) {
                $alwaysCounting[$source->id] = true;
            }
        }
        return $alwaysCounting;
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
