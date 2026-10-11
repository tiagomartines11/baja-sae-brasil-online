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

    /** @var PassGroup[] every pass group after the start, in time order */
    public array $groups = [];

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

    /** The pass group an entry ended up in, or null if it was voided or is before the start. */
    public function groupOfEntry(int $passagemId): ?PassGroup
    {
        foreach ($this->groups as $group) {
            foreach ($group->entries as $entry) {
                if ($entry->id === $passagemId) {
                    return $group;
                }
            }
        }
        return null;
    }
}
