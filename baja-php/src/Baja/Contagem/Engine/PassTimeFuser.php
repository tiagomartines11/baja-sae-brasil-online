<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Turns the several times of a pass group into pass times.
 *
 * Two times are produced, for two purposes:
 *
 * 1. The PASS TIME (`tsMs`): the median of the times as typed, or the time
 *    of a precise source (an automated system) when there is one. It depends
 *    on nothing but the group's own entries, so it is the same whether one
 *    car or the whole race is being recomputed, and it never moves later.
 *    The published "last pass" comes from it.
 *
 * 2. The HABIT-CORRECTED TIME (`tsAjustadoMs`), for lap durations. People
 *    are consistent in their own way: one counter presses Enter a second
 *    early, another a second late, every time. For each source we measure
 *    that habit (the median of "its time minus the group's time") and
 *    remove it before taking the median.
 *
 *    The habit is measured from the source's 30 matched entries BEFORE the
 *    group, never after. Otherwise every new entry would shift the times of
 *    the whole race a little, and published lap times would flicker by a
 *    second between one run and the next.
 *
 * A pass a reviewer timed by one entry ("usar_tempo": the other counter
 * typed late) takes that entry's time for both. The other entries are
 * witnesses: they do not time the pass, and the group teaches nothing
 * about anybody's habit (the late entry would teach a habit of 20 s).
 */
final class PassTimeFuser
{
    /** How many of a source's most recent matched entries define its habit. */
    private const HABIT_SAMPLE_SIZE = 30;

    /** @var array<int, int> fonteId => its habit at the end of the race, in ms (positive = types late) */
    public array $offsetBySource = [];

    /**
     * Sets the pass time of each group. Needs nothing but the group itself.
     *
     * The entries of a group are already in time order (that is how the
     * matcher built it), so the median is simply the entry in the middle,
     * or halfway between the two in the middle. Nothing has to be sorted.
     *
     * @param iterable<PassGroup> $groups
     * @param array<int, Source> $sourcesById
     */
    public static function setPassTimes(iterable $groups, array $sourcesById): void
    {
        $preciseSourceIds = self::preciseSourceIds($sourcesById);

        foreach ($groups as $group) {
            $entries = $group->entries;
            $count = count($entries);
            if ($count === 3) {
                $medianMs = $entries[1]->tsMs;
            } elseif ($count === 1) {
                $medianMs = $entries[0]->tsMs;
            } else {
                $middle = $count >> 1;
                $medianMs = $count % 2 === 1
                    ? $entries[$middle]->tsMs
                    : self::halfwayBetween($entries[$middle - 1]->tsMs, $entries[$middle]->tsMs);
            }

            $preciseTime = null;
            if ($preciseSourceIds !== []) {
                foreach ($entries as $entry) {
                    if (isset($preciseSourceIds[$entry->fonteId])) {
                        $preciseTime = $entry->tsMs;
                    }
                }
            }
            $group->tempoPreciso = $preciseTime !== null;
            $group->tsMs = $preciseTime ?? $medianMs;
            if ($group->tempoEscolhidoId !== null) {
                // The reviewer's word beats both the median and a precise
                // source. The time is precise only when it is a precise
                // source's own (the reviewer picked the transponder's time).
                $chosen = $group->chosenTimeEntry();
                if ($chosen !== null) {
                    $group->tempoPreciso = isset($preciseSourceIds[$chosen->fonteId]);
                    $group->tsMs = $chosen->tsMs;
                }
            }
            // Until habits are measured (the slower, whole-race step) the
            // corrected time is simply the pass time.
            $group->tsAjustadoMs = $group->tsMs;
        }
    }

    /**
     * Measures each source's habit as the race goes and sets the
     * habit-corrected time and the spread of every group.
     *
     * This loop visits every entry of the race and is the most expensive
     * step of a full run, which is why the medians of two and of three
     * numbers (nearly every group) are worked out in place.
     *
     * @param PassGroup[] $groupsInTimeOrder every pass group of the race
     * @param array<int, Source> $sourcesById
     */
    public static function removeHabits(array $groupsInTimeOrder, array $sourcesById): self
    {
        $fuser = new self();
        /** @var array<int, RecentMedian> fonteId => its recent (time - pass time) differences */
        $recentDifferences = [];
        /** @var array<int, int> fonteId => its habit so far, in ms */
        $habit = [];

        $preciseSourceIds = self::preciseSourceIds($sourcesById);

        foreach ($groupsInTimeOrder as $group) {
            $entries = $group->entries;
            $count = count($entries);

            if ($group->tempoEscolhidoId !== null) {
                self::timeByTheChosenEntry($group, $habit, $preciseSourceIds);
                continue;
            }

            if ($count === 1) {
                // A single entry has no spread, and nothing to compare with,
                // so it teaches nothing about its source either.
                $entry = $entries[0];
                $group->dispersaoMs = 0;
                $group->tsAjustadoMs = $group->tempoPreciso ? $group->tsMs : $entry->tsMs - ($habit[$entry->fonteId] ?? 0);
                continue;
            }

            if ($preciseSourceIds === []) {
                $passMs = $group->tsMs;
                if ($count === 3) {
                    [$first, $second, $third] = $entries;
                    $a = $first->tsMs - ($habit[$first->fonteId] ?? 0);
                    $b = $second->tsMs - ($habit[$second->fonteId] ?? 0);
                    $c = $third->tsMs - ($habit[$third->fonteId] ?? 0);
                    // Put the three in order by hand: smallest, middle, largest.
                    if ($a > $b) {
                        [$a, $b] = [$b, $a];
                    }
                    if ($b > $c) {
                        [$b, $c] = [$c, $b];
                        if ($a > $b) {
                            [$a, $b] = [$b, $a];
                        }
                    }
                    $group->dispersaoMs = $c - $a;
                    $group->tsAjustadoMs = $b;
                } elseif ($count === 2) {
                    [$first, $second] = $entries;
                    $a = $first->tsMs - ($habit[$first->fonteId] ?? 0);
                    $b = $second->tsMs - ($habit[$second->fonteId] ?? 0);
                    $group->dispersaoMs = $a > $b ? $a - $b : $b - $a;
                    $group->tsAjustadoMs = self::halfwayBetween($a, $b);
                } else {
                    $correctedTimes = [];
                    foreach ($entries as $entry) {
                        $correctedTimes[] = $entry->tsMs - ($habit[$entry->fonteId] ?? 0);
                    }
                    // Sorted once, the list gives the spread (its two ends)
                    // and the median (its middle) with no further work.
                    sort($correctedTimes);
                    $middle = $count >> 1;
                    $group->dispersaoMs = $correctedTimes[$count - 1] - $correctedTimes[0];
                    $group->tsAjustadoMs = $count % 2 === 1
                        ? $correctedTimes[$middle]
                        : self::halfwayBetween($correctedTimes[$middle - 1], $correctedTimes[$middle]);
                }
            } else {
                $passMs = $group->tsMs;
                $correctedTimes = [];
                foreach ($entries as $entry) {
                    $fonteId = $entry->fonteId;
                    $correctedTimes[] = isset($preciseSourceIds[$fonteId]) ? $entry->tsMs : $entry->tsMs - ($habit[$fonteId] ?? 0);
                }
                $group->dispersaoMs = max($correctedTimes) - min($correctedTimes);
                $group->tsAjustadoMs = $group->tempoPreciso ? $passMs : (int) round(Stats::median($correctedTimes));
            }

            // Only now does this group teach us something, for the groups
            // that come after it.
            foreach ($entries as $entry) {
                $fonteId = $entry->fonteId;
                if ($fonteId === Source::REVISOR_ID) {
                    continue;
                }
                $differences = $recentDifferences[$fonteId] ?? ($recentDifferences[$fonteId] = new RecentMedian(self::HABIT_SAMPLE_SIZE));
                $habit[$fonteId] = $differences->addAndRound($entry->tsMs - $passMs);
            }
        }

        $fuser->offsetBySource = $habit;
        return $fuser;
    }

    /**
     * A pass timed by the entry a reviewer chose: its corrected time is that
     * entry's, and the spread is still the real one (the pass is not "well
     * timed" for best lap just because a person picked a time). Nothing is
     * learnt from it.
     *
     * @param array<int, int> $habit
     * @param array<int, true> $preciseSourceIds
     */
    private static function timeByTheChosenEntry(PassGroup $group, array $habit, array $preciseSourceIds): void
    {
        $chosen = $group->chosenTimeEntry();
        $corrected = [];
        foreach ($group->entries as $entry) {
            $corrected[] = isset($preciseSourceIds[$entry->fonteId]) ? $entry->tsMs : $entry->tsMs - ($habit[$entry->fonteId] ?? 0);
        }
        $group->dispersaoMs = max($corrected) - min($corrected);
        $group->tsAjustadoMs = $chosen === null || isset($preciseSourceIds[$chosen->fonteId])
            ? $group->tsMs
            : $chosen->tsMs - ($habit[$chosen->fonteId] ?? 0);
    }

    /** The whole millisecond halfway between two times; a half rounds up, as round() does. */
    private static function halfwayBetween(int $a, int $b): int
    {
        return (int) round(($a + $b) / 2);
    }

    /**
     * @param array<int, Source> $sourcesById
     * @return array<int, true> ids of the sources whose times are used as they are
     */
    private static function preciseSourceIds(array $sourcesById): array
    {
        $ids = [];
        foreach ($sourcesById as $source) {
            if ($source->hasPreciseTime()) {
                $ids[$source->id] = true;
            }
        }
        return $ids;
    }
}
