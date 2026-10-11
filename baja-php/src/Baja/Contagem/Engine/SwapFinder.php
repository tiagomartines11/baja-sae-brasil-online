<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Looks for the car a stray entry was probably meant for.
 *
 * The situation: source S recorded car X all on its own, and at the same
 * moment the other sources recorded a car Y that S did not. The likely
 * story is that S saw Y and typed X.
 */
final class SwapFinder
{
    /** @var PassGroup[] every pass group of the race, ordered by first entry time */
    private array $groupsInTimeOrder;

    /** @var int[] first entry time of each group, same order (for binary search) */
    private array $groupTimes = [];

    private int $janelaMs;

    /** @var array<int, true> spl_object_id of groups already paired with a stray entry */
    private array $takenGroups = [];

    /** @param PassGroup[] $groupsInTimeOrder */
    public function __construct(array $groupsInTimeOrder, int $janelaMs)
    {
        $this->groupsInTimeOrder = $groupsInTimeOrder;
        foreach ($groupsInTimeOrder as $group) {
            $this->groupTimes[] = $group->primeiroTsMs;
        }
        $this->janelaMs = $janelaMs;
    }

    /**
     * The pass group the lone entry most probably belongs to, or null.
     *
     * A candidate is a group for another car, inside the matching window,
     * with no entry from the lone entry's source. Among candidates the
     * closest in time wins (compared in whole seconds, since typing delays
     * make finer differences meaningless), then the most similar number.
     */
    public function findPartnerFor(PassGroup $loneGroup): ?PassGroup
    {
        $loneEntry = $loneGroup->entries[0];
        $firstIndex = Stats::countAtMost($this->groupTimes, $loneEntry->tsMs - $this->janelaMs - 1);
        $groupCount = count($this->groupsInTimeOrder);

        $best = null;
        $bestScore = null;
        for ($index = $firstIndex; $index < $groupCount; $index++) {
            $candidate = $this->groupsInTimeOrder[$index];
            if ($candidate->primeiroTsMs > $loneEntry->tsMs + $this->janelaMs) {
                break;
            }
            if (!$this->isCandidate($candidate, $loneGroup)) {
                continue;
            }
            $distanceMs = abs($candidate->tsMs - $loneEntry->tsMs);
            $distanceSeconds = intdiv($distanceMs, 1000);
            if ($bestScore !== null && $distanceSeconds > $bestScore[0]) {
                // Further away in whole seconds: it cannot win, so how
                // alike the two numbers are need not be worked out.
                continue;
            }
            $score = [$distanceSeconds, DigitSimilarity::rank($loneEntry->carro, $candidate->carro), $distanceMs];
            if ($bestScore === null || $score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        if ($best !== null) {
            // One real pass can only explain one stray entry.
            $this->takenGroups[spl_object_id($best)] = true;
        }
        return $best;
    }

    private function isCandidate(PassGroup $candidate, PassGroup $loneGroup): bool
    {
        if ($candidate === $loneGroup || $candidate->carro === $loneGroup->carro) {
            return false;
        }
        if (isset($this->takenGroups[spl_object_id($candidate)])) {
            return false;
        }
        $entryCount = count($candidate->entries);
        if ($entryCount < 2 || ($entryCount === 2 && $candidate->adicionadoPorRevisor)) {
            // The other car must be a real pass, seen by two counters (a
            // pass a reviewer added is not a counter). Two stray entries
            // next to each other explain nothing, and pairing them would
            // turn two mistakes into one counted lap.
            return false;
        }
        return !$candidate->hasSource($loneGroup->entries[0]->fonteId);
    }
}
