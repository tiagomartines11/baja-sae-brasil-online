<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * The counting half of the engine: from raw entries to official passes.
 *
 * This is everything the official count needs and nothing else:
 *
 *   1. DecisionApplier   apply what people decided
 *   2. DuplicateFilter   drop double taps and handover duplicates
 *   3. PassMatcher       group entries of the same crossing
 *      PassTimeFuser     the pass time of each group
 *   4. PublishRule       which groups are official passes
 *      PassRoles         leave out the start crossing and late finishers
 *
 * A car's count depends only on that car's own entries, on the decisions,
 * and on which sources were switched on or excluded. It does not depend on
 * the other cars, on whether a phone has synced, or on the pace of the race.
 * That is what lets an upload recount just the cars it touched (pass
 * `$onlyCars`) and get exactly what a full recount would give; a test
 * checks that this stays true.
 */
final class LapCounter
{
    public RaceTimeline $timeline;
    public AppliedDecisions $applied;
    public DuplicateFilter $duplicates;
    public SourceAvailability $availability;

    /** @var array<int, Source> */
    public array $sourcesById = [];

    /** @var array<int, PassGroup[]> carro => its pass groups in time order */
    public array $groupsByCar = [];

    /** The counting rule actually applied: the publish mode, or what it fell back to. One of Settings::MODO_*. */
    public string $ruleApplied = Settings::MODO_CONFIRMADAS;

    private Settings $settings;

    /**
     * @param int[]|null $onlyCars recount just these cars; null for the whole race
     */
    public static function count(EngineInput $input, ?array $onlyCars = null): self
    {
        $counter = new self();
        $counter->settings = $input->settings;
        $counter->timeline = RaceTimeline::fromOcorrencias($input->ocorrencias);
        foreach ($input->sources as $source) {
            $counter->sourcesById[$source->id] = $source;
        }

        $counter->applied = DecisionApplier::apply($input->entries, $input->decisions, $counter->sourcesById);
        $counter->availability = new SourceAvailability($counter->sourcesById, $counter->applied, $input->settings->janelaMs);

        $entries = self::inTimeOrder($counter->entriesAfterStart($onlyCars));
        $counter->duplicates = DuplicateFilter::filter($entries, $input->settings, $counter->applied->confirmedEntryIds, $counter->sourcesById);
        $counter->groupsByCar = PassMatcher::match($counter->duplicates->keptEntries, $input->settings->janelaMs, $counter->applied->confirmedEntryIds);

        foreach ($counter->groupsByCar as $groups) {
            PassTimeFuser::setPassTimes($groups, $counter->sourcesById);
        }
        $counter->ruleApplied = PublishRule::decide($counter->groupsByCar, $input->settings, $counter->availability);
        PassRoles::assign($counter->groupsByCar, $counter->timeline, $input->settings);

        return $counter;
    }

    /**
     * Numbers the official passes of each car (pass zero, lap 1, lap 2, ...)
     * and lists the pending ones after them.
     *
     * @return array<int, Lap[]>
     */
    public function buildLaps(): array
    {
        return LapBuilder::build($this->groupsByCar, $this->timeline, $this->settings);
    }

    /**
     * Entries arrive in the order phones uploaded them. Every later step
     * wants them in time order (earlier id first when two share a
     * millisecond), so they are sorted here, once, for all of them.
     *
     * Sorting a plain list of times and following the order is several
     * times faster than sorting the objects with a comparison callback, and
     * this is the largest sort of a run. Entries sharing a millisecond are
     * rare; each such handful is put in id order afterwards.
     *
     * @param \Baja\Contagem\Engine\Data\Entry[] $entries
     * @return \Baja\Contagem\Engine\Data\Entry[]
     */
    private static function inTimeOrder(array $entries): array
    {
        $times = [];
        foreach ($entries as $entry) {
            $times[] = $entry->tsMs;
        }
        asort($times);

        $ordered = [];
        $sameMillisecond = [];
        $previousTime = null;
        foreach ($times as $index => $time) {
            if ($time !== $previousTime) {
                self::appendInIdOrder($ordered, $sameMillisecond);
                $sameMillisecond = [];
                $previousTime = $time;
            }
            $sameMillisecond[] = $entries[$index];
        }
        self::appendInIdOrder($ordered, $sameMillisecond);
        return $ordered;
    }

    /**
     * @param \Baja\Contagem\Engine\Data\Entry[] $ordered
     * @param \Baja\Contagem\Engine\Data\Entry[] $sameMillisecond
     */
    private static function appendInIdOrder(array &$ordered, array $sameMillisecond): void
    {
        if (count($sameMillisecond) > 1) {
            usort($sameMillisecond, fn($a, $b) => $a->id <=> $b->id);
        }
        foreach ($sameMillisecond as $entry) {
            $ordered[] = $entry;
        }
    }

    /**
     * Entries that can be part of the race: after decisions, at or after
     * the start (a pass can only follow the start), and for the requested
     * cars. The car is the one the entry counts for now, so an entry a
     * reviewer moved to car 41 belongs to car 41 here.
     *
     * @param int[]|null $onlyCars
     * @return \Baja\Contagem\Engine\Data\Entry[]
     */
    private function entriesAfterStart(?array $onlyCars): array
    {
        if ($this->timeline->largadaMs === null) {
            return [];
        }
        $wanted = $onlyCars === null ? null : array_fill_keys($onlyCars, true);

        $entries = [];
        foreach ($this->applied->entries as $entry) {
            if ($entry->tsMs < $this->timeline->largadaMs) {
                continue;
            }
            if ($wanted !== null && !isset($wanted[$entry->carro])) {
                continue;
            }
            $entries[] = $entry;
        }
        return $entries;
    }
}
