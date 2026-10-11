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
     * @param iterable<PassGroup> $groups
     * @param array<int, Source> $sourcesById
     */
    public static function setPassTimes(iterable $groups, array $sourcesById): void
    {
        $preciseSourceIds = self::preciseSourceIds($sourcesById);

        foreach ($groups as $group) {
            $times = [];
            $preciseTime = null;
            foreach ($group->entries as $entry) {
                $times[] = $entry->tsMs;
                if (isset($preciseSourceIds[$entry->fonteId])) {
                    $preciseTime = $entry->tsMs;
                }
            }
            $group->tempoPreciso = $preciseTime !== null;
            $group->tsMs = $preciseTime ?? (int) round(Stats::median($times));
            // Until habits are measured (the slower, whole-race step) the
            // corrected time is simply the pass time.
            $group->tsAjustadoMs = $group->tsMs;
        }
    }

    /**
     * Measures each source's habit as the race goes and sets the
     * habit-corrected time and the spread of every group.
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

            if (count($entries) === 1) {
                // A single entry has no spread, and nothing to compare with,
                // so it teaches nothing about its source either.
                $entry = $entries[0];
                $group->dispersaoMs = 0;
                $group->tsAjustadoMs = $group->tempoPreciso ? $group->tsMs : $entry->tsMs - ($habit[$entry->fonteId] ?? 0);
                continue;
            }

            $correctedTimes = [];
            foreach ($entries as $entry) {
                $fonteId = $entry->fonteId;
                $correctedTimes[] = isset($preciseSourceIds[$fonteId]) ? $entry->tsMs : $entry->tsMs - ($habit[$fonteId] ?? 0);
            }
            $group->dispersaoMs = max($correctedTimes) - min($correctedTimes);
            $group->tsAjustadoMs = $group->tempoPreciso ? $group->tsMs : (int) round(Stats::median($correctedTimes));

            // Only now does this group teach us something, for the groups
            // that come after it.
            foreach ($entries as $entry) {
                $fonteId = $entry->fonteId;
                if ($fonteId === Source::REVISOR_ID) {
                    continue;
                }
                $differences = $recentDifferences[$fonteId] ?? ($recentDifferences[$fonteId] = new RecentMedian(self::HABIT_SAMPLE_SIZE));
                $differences->add($entry->tsMs - $group->tsMs);
                $habit[$fonteId] = (int) round($differences->median());
            }
        }

        $fuser->offsetBySource = $habit;
        return $fuser;
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
