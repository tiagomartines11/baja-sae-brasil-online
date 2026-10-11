<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

use Baja\Contagem\Engine\RaceTimeline;

/**
 * Everything one engine run produced. Nothing in here is a source of truth:
 * it can all be thrown away and rebuilt from the raw tables.
 */
final class EngineResult
{
    public RaceTimeline $timeline;

    /**
     * Every pass group, in time order. Groups typed before the start are in
     * here too, marked `antesDaLargada`; they are shown and never count
     * unless a reviewer confirms them.
     *
     * @var PassGroup[]
     */
    public array $groups = [];

    /**
     * Entries whose time is unknown (`Entry::TEMPO_DESCONHECIDO`) and that no
     * reviewer confirmed. They are in no pass group and count for nothing;
     * each has a "tempo_desconhecido" flag. Listed so timelines can show them.
     *
     * @var Entry[]
     */
    public array $unknownTimeEntries = [];

    /** @var Lap[] counted and pending laps, ready to be saved to contagem_volta */
    public array $laps = [];

    /** @var Flag[] open flags first (most important first), then auto-resolved ones */
    public array $flags = [];

    /** @var array<int, CarState> carro => state, in standings order */
    public array $cars = [];

    /** @var array<int, SourceStats> fonteId => statistics */
    public array $sourceStats = [];

    /** @var int[] ids of the entries the engine voided on its own (double taps, handovers) */
    public array $autoVoidedEntryIds = [];

    /**
     * Entries after decisions, keyed by id, for timelines. Voided entries are
     * not in here.
     *
     * @var array<int, Entry>
     */
    public array $effectiveEntries = [];

    public function __construct(RaceTimeline $timeline)
    {
        $this->timeline = $timeline;
    }

    /** @return Flag[] the flags a reviewer still has to deal with */
    public function openFlags(): array
    {
        return array_values(array_filter($this->flags, fn(Flag $flag) => !$flag->autoResolvido));
    }

    public function findFlag(string $flagId): ?Flag
    {
        foreach ($this->flags as $flag) {
            if ($flag->id === $flagId) {
                return $flag;
            }
        }
        return null;
    }

    /** Official lap count of a car; 0 when it has none. */
    public function lapCount(int $carro): int
    {
        return isset($this->cars[$carro]) ? $this->cars[$carro]->voltas : 0;
    }

    /**
     * Entry id => the group it is in. Built the first time somebody asks:
     * most runs (every recalculation nobody is looking at) never do, and
     * indexing tens of thousands of entries for nothing is wasted time.
     *
     * @var array<int, PassGroup>|null
     */
    private ?array $groupByEntryId = null;

    /** @param PassGroup[] $groupsInTimeOrder */
    public function setGroups(array $groupsInTimeOrder): void
    {
        $this->groups = $groupsInTimeOrder;
        $this->groupByEntryId = null;
    }

    /**
     * The pass group an entry ended up in. Null if it was voided, if its
     * time is unknown, or if the race has no start yet.
     */
    public function groupOfEntry(int $passagemId): ?PassGroup
    {
        if ($this->groupByEntryId === null) {
            $this->groupByEntryId = [];
            foreach ($this->groups as $group) {
                foreach ($group->entries as $entry) {
                    $this->groupByEntryId[$entry->id] = $group;
                }
            }
        }
        return $this->groupByEntryId[$passagemId] ?? null;
    }
}
