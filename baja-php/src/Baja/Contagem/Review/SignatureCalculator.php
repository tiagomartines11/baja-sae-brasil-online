<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Computes the "assinatura" (signature) of a set of entries: a short hash of
 * everything a reviewer was looking at when they made up their mind.
 *
 * If the signature the browser sends back with a decision is no longer the
 * one the server computes, something about those entries changed in between
 * (another reviewer decided, a late upload joined the group) and the decision
 * is refused instead of being applied to a situation the reviewer never saw.
 *
 * Built once per engine result because the review state needs one signature
 * per flag and per timeline entry; indexing the result every time would cost
 * a full scan of the race for each of them.
 */
final class SignatureCalculator
{
    /** @var array<int, Entry> entry id => entry in use (after decisions) */
    private array $entriesInUse = [];

    /** @var array<int, PassGroup> entry id => the group it ended up in */
    private array $groupByEntry = [];

    /** @var array<int, int[]> entry id => ids of the decisions in force about it */
    private array $decisionIdsByEntry;

    /**
     * @param Decision[] $decisions every decision of the prova
     */
    public function __construct(EngineResult $result, array $decisions)
    {
        foreach ($result->effectiveEntries as $entry) {
            $this->entriesInUse[$entry->id] = $entry;
        }
        foreach ($result->groups as $group) {
            foreach ($group->entries as $entry) {
                // Passes added by a reviewer only exist inside their group.
                $this->entriesInUse[$entry->id] = $entry;
                $this->groupByEntry[$entry->id] = $group;
            }
        }
        $this->decisionIdsByEntry = ActiveDecisions::idsByEntry(ActiveDecisions::filter($decisions));
    }

    /**
     * @param int[] $passagemIds
     */
    public function forEntries(array $passagemIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $passagemIds)));
        sort($ids);

        $parts = [];
        foreach ($ids as $id) {
            $entry = $this->entriesInUse[$id] ?? null;
            $groupIds = $this->groupEntryIds($id);
            $parts[] = implode('|', [
                $id,
                $entry !== null ? $entry->carro : '-',
                $entry !== null ? '1' : '0',
                implode(',', $groupIds),
                implode(',', $this->decisionIdsByEntry[$id] ?? []),
            ]);
        }
        return sha1(implode(';', $parts));
    }

    public function groupOf(int $passagemId): ?PassGroup
    {
        return $this->groupByEntry[$passagemId] ?? null;
    }

    public function entryInUse(int $passagemId): ?Entry
    {
        return $this->entriesInUse[$passagemId] ?? null;
    }

    /** @return int[] ids of the decisions in force about this entry */
    public function decisionIdsOf(int $passagemId): array
    {
        return $this->decisionIdsByEntry[$passagemId] ?? [];
    }

    /** @return int[] sorted ids of every entry in the same pass group, itself included */
    private function groupEntryIds(int $passagemId): array
    {
        $group = $this->groupByEntry[$passagemId] ?? null;
        if ($group === null) {
            return [];
        }
        $ids = $group->entryIds();
        sort($ids);
        return $ids;
    }
}
