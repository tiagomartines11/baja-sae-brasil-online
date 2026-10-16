<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Step 3: decide which entries from different sources describe the same
 * crossing of the line.
 *
 * Matching is done per car and by time only. It deliberately ignores the
 * order in which each counter typed: when a pack of five cars crosses
 * together, three people type the five numbers in three different orders.
 *
 * THE RULE: THE CLOSEST ENTRIES GO TOGETHER FIRST
 *
 * Counters who saw the same crossing press Enter within a few seconds of
 * each other (`dispersao_alerta_ms`, 4 s). So, for one car:
 *
 *   1. Of all pairs of entries from two different counters, the two closest
 *      in time are put together; then the next closest; and so on. A group
 *      never holds two entries of the same counter, and never spans more
 *      than the matching window (`janela_ms`, 20 s).
 *   2. A group whose entries lie within 4 s is AGREED. Nothing further away
 *      is added to it: a third counter's entry 10 s later stands alone, and
 *      the reviewer is shown it as set apart from the two that agree.
 *   3. Entries that agree with nobody but are within the matching window of
 *      each other are still put together, as a group marked `dispersa`:
 *      "probably the same crossing, but nobody agrees closely". Such a
 *      group does not count until a person has looked (see PublishRule).
 *
 * Why not simply "the first group still open", as this class once did: a
 * counter's stray entry (a typo for this car) 19 s before the car really
 * passed used to collect the first real entry, and the remaining real
 * entries then formed a second group. One crossing, two counted passes.
 *
 * The result depends only on the car's own entries, and not on the order
 * in which they were uploaded: the entries arrive here sorted by time, and
 * ties are settled by time and then by entry id.
 */
final class PassMatcher
{
    /**
     * @param Entry[] $entriesInTimeOrder already sorted by time (LapCounter does it once for every step)
     * @param array<int, true> $confirmedEntryIds
     * @param int $agreementSpreadMs entries of one crossing lie within this (Settings::agreementSpreadMs())
     * @return array<int, PassGroup[]> carro => its pass groups, ordered by first entry
     */
    public static function match(array $entriesInTimeOrder, int $janelaMs, array $confirmedEntryIds, int $agreementSpreadMs = 4000): array
    {
        $entriesByCar = [];
        foreach ($entriesInTimeOrder as $entry) {
            $entriesByCar[$entry->carro][] = $entry;
        }

        $groupsByCar = [];
        foreach ($entriesByCar as $carro => $carEntries) {
            $groupsByCar[$carro] = self::matchOneCar($carEntries, $janelaMs, $agreementSpreadMs, $confirmedEntryIds);
        }

        return $groupsByCar;
    }

    /**
     * Cuts the car's entries into stretches separated by more than one
     * matching window (entries of different stretches can never be the same
     * crossing) and groups each stretch on its own.
     *
     * Nearly every stretch is the ordinary case: two or three counters,
     * one entry each, all within the agreement spread. That is one group,
     * decided here without further ado; this loop runs for every entry of
     * the race.
     *
     * @param Entry[] $carEntries one car's entries in time order
     * @param array<int, true> $confirmedEntryIds
     * @return PassGroup[]
     */
    private static function matchOneCar(array $carEntries, int $janelaMs, int $spreadMs, array $confirmedEntryIds): array
    {
        $groups = [];
        $count = count($carEntries);
        $from = 0;

        while ($from < $count) {
            $first = $carEntries[$from];
            $lastMs = $first->tsMs;
            $to = $from + 1;
            while ($to < $count && $carEntries[$to]->tsMs - $lastMs <= $janelaMs) {
                $lastMs = $carEntries[$to]->tsMs;
                $to++;
            }

            if ($to - $from === 1) {
                $groups[] = self::groupOf([$first], $confirmedEntryIds);
            } elseif ($lastMs - $first->tsMs <= $spreadMs && self::isOneEntryPerCounter($carEntries, $from, $to)) {
                $groups[] = self::groupOf(array_slice($carEntries, $from, $to - $from), $confirmedEntryIds);
            } else {
                foreach (self::closestFirst(array_slice($carEntries, $from, $to - $from), $janelaMs, $spreadMs, $confirmedEntryIds) as $group) {
                    $groups[] = $group;
                }
            }
            $from = $to;
        }

        return $groups;
    }

    /**
     * @param Entry[] $carEntries
     * @return bool true when the entries [$from, $to) come from different real counters
     */
    private static function isOneEntryPerCounter(array $carEntries, int $from, int $to): bool
    {
        $seen = [];
        for ($index = $from; $index < $to; $index++) {
            $fonteId = $carEntries[$index]->fonteId;
            if ($fonteId === Source::REVISOR_ID || isset($seen[$fonteId])) {
                return false;
            }
            $seen[$fonteId] = true;
        }
        return true;
    }

    /**
     * The rule of the class comment, for one stretch of entries that is not
     * the ordinary case.
     *
     * Every entry starts as a group of its own. The pairs of entries from
     * different counters are visited from the closest to the furthest
     * apart, and the two groups of a pair are joined when the rule allows
     * it. A pair that is refused once would be refused forever (groups only
     * grow), so one visit each is enough.
     *
     * A pass a reviewer added is not a counter's entry and takes no part in
     * this: afterwards it joins the group nearest to it (see below).
     *
     * @param Entry[] $stretch in time order
     * @param array<int, true> $confirmedEntryIds
     * @return PassGroup[] ordered by first entry
     */
    private static function closestFirst(array $stretch, int $janelaMs, int $spreadMs, array $confirmedEntryIds): array
    {
        /** @var array<int, int[]> group number => positions (in $stretch) of its entries */
        $members = [];
        /** @var array<int, int> position of a counter's entry => its group number */
        $groupOf = [];
        /** @var array<int, array<int, true>> group number => the counters in it */
        $sources = [];
        /** @var array<int, bool> group number => two or more entries, all within the agreement spread */
        $agreed = [];
        $firstMs = [];
        $lastMs = [];
        $reviewerPositions = [];

        foreach ($stretch as $position => $entry) {
            if ($entry->fonteId === Source::REVISOR_ID) {
                $reviewerPositions[] = $position;
                continue;
            }
            $members[$position] = [$position];
            $groupOf[$position] = $position;
            $sources[$position] = [$entry->fonteId => true];
            $agreed[$position] = false;
            $firstMs[$position] = $entry->tsMs;
            $lastMs[$position] = $entry->tsMs;
        }

        foreach (self::pairsFromClosest($stretch, array_keys($groupOf), $janelaMs) as [, $earlier, $later]) {
            $a = $groupOf[$earlier];
            $b = $groupOf[$later];
            if ($a === $b || array_intersect_key($sources[$a], $sources[$b]) !== []) {
                continue;
            }
            $spanFromMs = min($firstMs[$a], $firstMs[$b]);
            $spanToMs = max($lastMs[$a], $lastMs[$b]);
            $spanMs = $spanToMs - $spanFromMs;
            if ($spanMs > $janelaMs) {
                continue;
            }
            // Nothing further away is added to counters who agree.
            if ($spanMs > $spreadMs && ($agreed[$a] || $agreed[$b])) {
                continue;
            }
            foreach ($members[$b] as $position) {
                $members[$a][] = $position;
                $groupOf[$position] = $a;
            }
            $sources[$a] += $sources[$b];
            $agreed[$a] = $spanMs <= $spreadMs;
            $firstMs[$a] = $spanFromMs;
            $lastMs[$a] = $spanToMs;
            unset($members[$b], $sources[$b], $agreed[$b], $firstMs[$b], $lastMs[$b]);
        }

        $isDispersed = [];
        foreach ($members as $number => $positions) {
            $isDispersed[$number] = count($positions) >= 2 && !$agreed[$number];
        }
        self::placeReviewerPasses($stretch, $reviewerPositions, $groupOf, $members, $janelaMs);

        $groups = [];
        $firstPositions = [];
        foreach ($members as $number => $positions) {
            sort($positions);
            $entries = [];
            foreach ($positions as $position) {
                $entries[] = $stretch[$position];
            }
            $group = self::groupOf($entries, $confirmedEntryIds);
            $group->dispersa = $isDispersed[$number] ?? false;
            $groups[] = $group;
            $firstPositions[] = $positions[0];
        }
        array_multisort($firstPositions, $groups);
        return $groups;
    }

    /**
     * Every pair of entries from two different counters that could be the
     * same crossing (no further apart than the matching window), the
     * closest pair first. Pairs equally close are taken in time order,
     * which is also a fixed order: the stretch is sorted by time and id.
     *
     * @param Entry[] $stretch
     * @param int[] $positions the positions of the counters' entries, ascending
     * @return array<int, array{0: int, 1: int, 2: int}> [gap in ms, earlier position, later position]
     */
    private static function pairsFromClosest(array $stretch, array $positions, int $janelaMs): array
    {
        $pairs = [];
        $count = count($positions);
        for ($i = 0; $i < $count; $i++) {
            $earlier = $stretch[$positions[$i]];
            for ($j = $i + 1; $j < $count; $j++) {
                $later = $stretch[$positions[$j]];
                $gapMs = $later->tsMs - $earlier->tsMs;
                if ($gapMs > $janelaMs) {
                    break;
                }
                if ($later->fonteId !== $earlier->fonteId) {
                    $pairs[] = [$gapMs, $positions[$i], $positions[$j]];
                }
            }
        }
        sort($pairs);
        return $pairs;
    }

    /**
     * A pass a reviewer added joins the group whose nearest entry is
     * closest to it, when that is within the matching window and the group
     * has no added pass yet: the reviewer is vouching for that crossing,
     * not adding another. Otherwise it is a pass of its own.
     *
     * @param Entry[] $stretch
     * @param int[] $reviewerPositions
     * @param array<int, int> $groupOf position of a counter's entry => group number
     * @param array<int, int[]> $members group number => positions, extended here
     */
    private static function placeReviewerPasses(array $stretch, array $reviewerPositions, array $groupOf, array &$members, int $janelaMs): void
    {
        $hasAddedPass = [];
        foreach ($reviewerPositions as $reviewerPosition) {
            $addedMs = $stretch[$reviewerPosition]->tsMs;
            $nearest = null;
            $nearestMs = $janelaMs + 1;
            foreach ($groupOf as $position => $number) {
                $distanceMs = abs($stretch[$position]->tsMs - $addedMs);
                if ($distanceMs < $nearestMs && !isset($hasAddedPass[$number])) {
                    $nearest = $number;
                    $nearestMs = $distanceMs;
                }
            }
            if ($nearest === null) {
                $members[$reviewerPosition] = [$reviewerPosition];
                continue;
            }
            $members[$nearest][] = $reviewerPosition;
            $hasAddedPass[$nearest] = true;
        }
    }

    /**
     * @param Entry[] $entries the entries of one group, in time order
     * @param array<int, true> $confirmedEntryIds
     */
    private static function groupOf(array $entries, array $confirmedEntryIds): PassGroup
    {
        $group = new PassGroup($entries[0]);
        $group->entries = $entries;
        foreach ($entries as $entry) {
            if ($entry->qualidadeTempo !== Entry::TEMPO_EXATO) {
                $group->tempoEstimado = true;
            }
            if ($entry->fonteId === Source::REVISOR_ID) {
                $group->adicionadoPorRevisor = true;
            }
            if ($confirmedEntryIds !== [] && isset($confirmedEntryIds[$entry->id])) {
                $group->confirmadoPorRevisor = true;
            }
        }
        return $group;
    }
}
