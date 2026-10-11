<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Source;

/**
 * Answers, for a moment of the race: which sources were supposed to be
 * counting, and for which of them do we already know everything they
 * recorded around that moment?
 *
 * The distinction matters because a phone with no signal looks exactly like
 * a counter who missed a car, until the phone uploads. A source is only
 * "known" at time T once it has told us its queue was empty at some moment
 * later than T plus the matching window. Until then its silence proves
 * nothing, and flags that depend on that silence are held back.
 */
final class SourceAvailability
{
    /** @var Source[] sources that take part in counting (active, manual or automatic) */
    private array $countingSources = [];

    private AppliedDecisions $applied;
    private int $janelaMs;

    /**
     * @param array<int, Source> $sourcesById
     */
    public function __construct(array $sourcesById, AppliedDecisions $applied, int $janelaMs)
    {
        foreach ($sourcesById as $source) {
            if ($source->countsPasses()) {
                $this->countingSources[] = $source;
            }
        }
        $this->applied = $applied;
        $this->janelaMs = $janelaMs;
    }

    /** True for an active manual or automatic source. */
    public function isCountingSource(int $fonteId): bool
    {
        foreach ($this->countingSources as $source) {
            if ($source->id === $fonteId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sources that were supposed to be counting at $tsMs: active and not
     * excluded by a reviewer for that moment. Says nothing about sync.
     *
     * @return int[]
     */
    public function activeSourceIdsAt(int $tsMs): array
    {
        $ids = [];
        foreach ($this->countingSources as $source) {
            if (!$this->applied->isSourceExcludedAt($source->id, $tsMs)) {
                $ids[] = $source->id;
            }
        }
        return $ids;
    }

    /** True once the source has synced past $tsMs plus the matching window. */
    public function isKnownAt(Source $source, int $tsMs): bool
    {
        return $source->sincronizadoAteMs !== null
            && $source->sincronizadoAteMs > $tsMs + $this->janelaMs;
    }

    /**
     * "Expected" sources at $tsMs: active, not excluded, and known. If one of
     * these has no entry for a pass, it really missed it.
     *
     * @return int[]
     */
    public function expectedSourceIdsAt(int $tsMs): array
    {
        $ids = [];
        foreach ($this->countingSources as $source) {
            if ($this->applied->isSourceExcludedAt($source->id, $tsMs)) {
                continue;
            }
            if ($this->isKnownAt($source, $tsMs)) {
                $ids[] = $source->id;
            }
        }
        return $ids;
    }

    /**
     * True when some active source has not synced past $tsMs yet, other than
     * the ones listed (sources already present in the group need no sync
     * mark to prove they saw the car).
     *
     * @param int[] $exceptSourceIds
     */
    public function hasUnknownSourceAt(int $tsMs, array $exceptSourceIds = []): bool
    {
        foreach ($this->countingSources as $source) {
            if (in_array($source->id, $exceptSourceIds, true)) {
                continue;
            }
            if ($this->applied->isSourceExcludedAt($source->id, $tsMs)) {
                continue;
            }
            if (!$this->isKnownAt($source, $tsMs)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when every active source has synced up to $tsMs itself (no window
     * added). Used by car status: a car is only called late when nobody can
     * still be holding its pass.
     */
    public function allSyncedThrough(int $tsMs): bool
    {
        foreach ($this->countingSources as $source) {
            if ($this->applied->isSourceExcludedAt($source->id, $tsMs)) {
                continue;
            }
            if ($source->sincronizadoAteMs === null || $source->sincronizadoAteMs < $tsMs) {
                return false;
            }
        }
        return true;
    }
}
