<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * The counting half of the engine: from raw entries to official passes.
 *
 * This is everything the official count needs and nothing else:
 *
 *   1. DecisionApplier   apply what people decided
 *      UnreadAssigner    the likeliest car of an unread pass ("??"), where clear
 *   2. DuplicateFilter   drop double taps and handover duplicates
 *   3. PassMatcher       group entries of the same crossing
 *      PassTimeFuser     the pass time of each group
 *   4. PublishRule       which groups are official passes
 *      PassRoles         leave out the start crossing and late finishers
 *      HoldRule          hold a pass that comes too soon after the previous one
 *
 * A car's count depends only on that car's own entries, on the decisions,
 * on which sources were switched on or excluded, and on whether the car is
 * classified. It does not depend on the other cars, on whether a phone has
 * synced, or on the pace of the race. That is what lets an upload recount
 * just the cars it touched (pass `$onlyCars`) and get exactly what a full
 * recount would give; a test checks that this stays true. The one
 * exception is an unread pass: which car it was is worked out from what
 * the other sources read around it, whatever car that is, so the input of
 * a per-car recount also holds the entries around the unread ones nearby
 * (UnreadAssigner::rangesToLoad()), and the assignment is made before the
 * cars are picked out. A timing sensor's marks change no count and no
 * pass time; they are matched in the whole-race run only (Engine::run()).
 *
 * No entry is dropped because of its time. Entries from before the start
 * are grouped like the others and marked (see PassRoles). The one exception
 * to "grouped like the others" is an entry whose time is UNKNOWN: its time
 * means nothing, so matching it with anything would be a guess. It is set
 * aside in `$unknownTimeEntries` and never counts. Confirming it only stops
 * the warning: the time shown is the phone's own clock, which the reviewer
 * cannot check, so a reviewer who knows the car did pass ADDS a pass at the
 * time they know instead.
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

    /**
     * Entries of unknown time: in no group, never counted, confirmed or not.
     *
     * @var Entry[]
     */
    public array $unknownTimeEntries = [];

    /** What was worked out about the unread passes; null when the race has none (nearly always). */
    public ?UnreadAssigner $unread = null;

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
        if ($counter->applied->unreadEntries !== [] || $counter->applied->sensorMarks !== []) {
            $knownCars = $input->validCars === [] ? [] : array_fill_keys(array_merge($input->validCars, $input->unclassifiedCars), true);
            $counter->unread = UnreadAssigner::assign($counter->applied, $input->settings, $counter->sourcesById, $knownCars);
        }
        $counter->availability = new SourceAvailability($counter->sourcesById, $counter->applied, $input->settings->janelaMs);

        $entries = $counter->entriesInTimeOrder($onlyCars);
        $counter->duplicates = DuplicateFilter::filter($entries, $input->settings, $counter->applied->confirmedEntryIds, $counter->sourcesById, $counter->timeline->largadaMs);
        $counter->groupsByCar = PassMatcher::match(
            $counter->duplicates->keptEntries,
            $input->settings->janelaMs,
            $counter->applied->confirmedEntryIds,
            $input->settings->agreementSpreadMs(),
            $counter->applied->timeChoices,
            $counter->applied->witnesses
        );

        foreach ($counter->groupsByCar as $groups) {
            PassTimeFuser::setPassTimes($groups, $counter->sourcesById);
        }
        $unclassifiedCars = array_fill_keys($input->unclassifiedCars, true);
        $counter->ruleApplied = PublishRule::decide($counter->groupsByCar, $input->settings, $counter->availability, $unclassifiedCars);
        PassRoles::assign($counter->groupsByCar, $counter->timeline, $input->settings, $counter->ruleApplied);

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
     * The entries that take part, in time order: after decisions, for the
     * requested cars, and with a usable time. The car is the one the entry
     * counts for now, so an entry a reviewer moved to car 41 belongs to car
     * 41 here. While the race has no start there is nothing to count.
     *
     * Entries arrive in the order phones uploaded them. Every later step
     * wants them in time order (earlier id first when two share a
     * millisecond), so they are sorted here, once, for all of them.
     * Sorting a plain list of times and following the order is several
     * times faster than sorting the objects with a comparison callback, and
     * this is the largest sort of a run. Entries sharing a millisecond are
     * rare; each such handful is put in id order as it goes by.
     *
     * @param int[]|null $onlyCars
     * @return Entry[]
     */
    private function entriesInTimeOrder(?array $onlyCars): array
    {
        if ($this->timeline->largadaMs === null) {
            return [];
        }
        $wanted = $onlyCars === null ? null : array_fill_keys($onlyCars, true);
        $entries = [];
        $times = [];
        foreach ($this->applied->entries as $entry) {
            if ($wanted !== null && !isset($wanted[$entry->carro])) {
                continue;
            }
            if ($entry->qualidadeTempo === Entry::TEMPO_DESCONHECIDO) {
                $this->unknownTimeEntries[] = $entry;
                continue;
            }
            $entries[] = $entry;
            $times[] = $entry->tsMs;
        }
        asort($times);

        $ordered = [];
        $previousTime = null;
        foreach ($times as $index => $time) {
            $entry = $entries[$index];
            if ($time !== $previousTime) {
                $ordered[] = $entry;
                $previousTime = $time;
                continue;
            }
            // Same millisecond as the entry before: slide back past the
            // ones with a later id.
            $position = count($ordered);
            while ($position > 0 && $ordered[$position - 1]->tsMs === $time && $ordered[$position - 1]->id > $entry->id) {
                $ordered[$position] = $ordered[$position - 1];
                $position--;
            }
            $ordered[$position] = $entry;
        }
        return $ordered;
    }
}
