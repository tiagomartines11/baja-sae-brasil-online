<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Entry;

/**
 * What is left of the raw entries once people's decisions were applied.
 * Produced by DecisionApplier.
 */
final class AppliedDecisions
{
    /** @var Entry[] surviving entries (reassigned ones carry their new car), plus reviewer-added passes */
    public array $entries = [];

    /**
     * Entries of observer sources (Source::PAPEL_OBSERVADORA) from moments
     * they were observing, after voids and reassignments. They are in no
     * pass group and count for nothing; ObserverComparator measures them
     * against the official passes. Empty when no source is an observer.
     *
     * @var Entry[]
     */
    public array $observedEntries = [];

    /**
     * Unread entries ("??") of the sources that took part, after voids,
     * that no decision gave a car. They are in no pass group; UnreadAssigner
     * works out which car each one most likely was. Empty for nearly every
     * race.
     *
     * @var Entry[]
     */
    public array $unreadEntries = [];

    /**
     * Marks of timing sensors (Source::TIPO_SENSOR) from moments the
     * sensor took part, that no reviewer gave a car. They never vote; the
     * ones UnreadAssigner can match time a pass. Empty without a sensor.
     *
     * @var Entry[]
     */
    public array $sensorMarks = [];

    /** True when some unread entry was given a car by a decision (it is in `entries` like any other). */
    public bool $hasDecidedUnread = false;

    /** @var array<int, true> ids of entries a reviewer confirmed */
    public array $confirmedEntryIds = [];

    /**
     * "usar_tempo": entries whose own time is the time of their pass.
     *
     * @var array<int, int> entry id => id of the decision (the newest wins when two touch one pass)
     */
    public array $timeChoices = [];

    /**
     * "usar_tempo" on a crossing typed late: the late entry joins the pass
     * of the chosen one as a witness that does not time it.
     *
     * @var array<int, int> witness entry id => id of the entry whose pass it joins
     */
    public array $witnesses = [];

    /** @var array<int, int> voided entry id => id of the decision that voided it */
    public array $voidedEntryIds = [];

    /** @var array<int, int> reassigned entry id => fonteId of that entry */
    public array $reassignedEntryIds = [];

    /**
     * Periods during which a source is ignored. A null start means "since
     * always" and a null end means "until further notice".
     *
     * @var array<int, array<int, array{0: ?int, 1: ?int}>> fonteId => [[from, to], ...]
     */
    public array $exclusions = [];

    /** @var Decision[] decisions still in force (not undone), oldest first */
    public array $activeDecisions = [];

    public function isSourceExcludedAt(int $fonteId, int $tsMs): bool
    {
        foreach ($this->exclusions[$fonteId] ?? [] as [$from, $to]) {
            if (($from === null || $tsMs >= $from) && ($to === null || $tsMs <= $to)) {
                return true;
            }
        }
        return false;
    }
}
