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

    /** @var array<int, true> ids of entries a reviewer confirmed */
    public array $confirmedEntryIds = [];

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
