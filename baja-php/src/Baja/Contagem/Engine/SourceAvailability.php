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
 *
 * Which sources were SUPPOSED to count (active, not excluded) decides what
 * is official. Whether they have SYNCED only decides what is shown to a
 * reviewer and when. The official count never depends on sync marks.
 */
final class SourceAvailability
{
    /** @var Source[] sources of the kinds that record passes (manual or automatic) */
    private array $passSources = [];

    /**
     * The usual case, worked out once: sources that are switched on and
     * were never excluded were supposed to count at every moment. Only the
     * others need their periods looked at, question by question.
     *
     * @var array<int, true> fonteId => true
     */
    private array $alwaysSupposedToCount = [];

    /** @var Source[] pass sources that were switched off, an observer, or excluded for some period */
    private array $sometimesSupposedToCount = [];

    private AppliedDecisions $applied;
    private int $janelaMs;

    /**
     * @param array<int, Source> $sourcesById
     */
    public function __construct(array $sourcesById, AppliedDecisions $applied, int $janelaMs)
    {
        foreach ($sourcesById as $source) {
            // An observer is never supposed to count while it observes, so
            // it is never "expected": it can neither miss a pass nor hold a
            // flag back while its phone is offline. One that never voted is
            // left out altogether.
            if (!$source->mayHaveCounted()) {
                continue;
            }
            // A timing sensor never votes: nobody waits for its marks, and
            // a pass without one is no miss of a witness.
            if ($source->isTimingSensor()) {
                continue;
            }
            $this->passSources[] = $source;
            if ($source->countedAllAlong() && !isset($applied->exclusions[$source->id])) {
                $this->alwaysSupposedToCount[$source->id] = true;
            } else {
                $this->sometimesSupposedToCount[] = $source;
            }
        }
        $this->applied = $applied;
        $this->janelaMs = $janelaMs;
    }

    /** True for a source that is taking part in the count right now. */
    public function isCountingSource(int $fonteId): bool
    {
        foreach ($this->passSources as $source) {
            if ($source->id === $fonteId) {
                return $source->countsPasses();
            }
        }
        return false;
    }

    /**
     * Sources that were supposed to be counting at $tsMs: switched on at
     * that moment and not excluded by a reviewer. Says nothing about sync.
     *
     * @return int[]
     */
    public function activeSourceIdsAt(int $tsMs): array
    {
        $ids = [];
        foreach ($this->passSources as $source) {
            if ($this->wasSupposedToCount($source, $tsMs)) {
                $ids[] = $source->id;
            }
        }
        return $ids;
    }

    /** How many sources were supposed to be counting at $tsMs. */
    public function activeSourceCountAt(int $tsMs): int
    {
        $count = count($this->alwaysSupposedToCount);
        foreach ($this->sometimesSupposedToCount as $source) {
            if ($this->wasSupposedToCount($source, $tsMs)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Was this one source supposed to be counting at $tsMs? False for a
     * source that does not exist or does not record passes.
     */
    public function wasSupposedToCountAt(int $fonteId, int $tsMs): bool
    {
        if (isset($this->alwaysSupposedToCount[$fonteId])) {
            return true;
        }
        foreach ($this->sometimesSupposedToCount as $source) {
            if ($source->id === $fonteId) {
                return $this->wasSupposedToCount($source, $tsMs);
            }
        }
        return false;
    }

    /** True for a source that was supposed to count at every moment of the race: switched on, never excluded. */
    public function wasAlwaysSupposedToCount(int $fonteId): bool
    {
        return isset($this->alwaysSupposedToCount[$fonteId]);
    }

    /** True once the source has synced past $tsMs plus the matching window. */
    public function isKnownAt(Source $source, int $tsMs): bool
    {
        return $source->sincronizadoAteMs !== null
            && $source->sincronizadoAteMs > $tsMs + $this->janelaMs;
    }

    /**
     * "Expected" sources at $tsMs: supposed to count, and known. If one of
     * these has no entry for a pass, it really missed it.
     *
     * @return int[]
     */
    public function expectedSourceIdsAt(int $tsMs): array
    {
        $ids = [];
        foreach ($this->passSources as $source) {
            if ($this->wasSupposedToCount($source, $tsMs) && $this->isKnownAt($source, $tsMs)) {
                $ids[] = $source->id;
            }
        }
        return $ids;
    }

    /**
     * True when some source that was supposed to count has not synced past
     * $tsMs yet, other than the ones listed (sources already present in the
     * group need no sync mark to prove they saw the car).
     *
     * @param int[] $exceptSourceIds
     */
    public function hasUnknownSourceAt(int $tsMs, array $exceptSourceIds = []): bool
    {
        foreach ($this->passSources as $source) {
            if (in_array($source->id, $exceptSourceIds, true)) {
                continue;
            }
            if ($this->wasSupposedToCount($source, $tsMs) && !$this->isKnownAt($source, $tsMs)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when every source supposed to count has synced up to $tsMs itself
     * (no window added). Used by car status: a car is only called late when
     * nobody can still be holding its pass.
     */
    public function allSyncedThrough(int $tsMs): bool
    {
        foreach ($this->passSources as $source) {
            if (!$this->wasSupposedToCount($source, $tsMs)) {
                continue;
            }
            if ($source->sincronizadoAteMs === null || $source->sincronizadoAteMs < $tsMs) {
                return false;
            }
        }
        return true;
    }

    private function wasSupposedToCount(Source $source, int $tsMs): bool
    {
        return $source->wasCountingAt($tsMs) && !$this->applied->isSourceExcludedAt($source->id, $tsMs);
    }
}
