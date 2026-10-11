<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Turns the several times of a pass group into one pass time.
 *
 * People are consistent in their own way: one counter presses Enter a
 * second early, another a second late, every time. So for each source we
 * measure its habitual offset (the median of "its time minus the group's
 * time" over its last matched entries) and remove it before combining.
 *
 * The combined time is the median of the corrected times, so one counter
 * who typed a single number late does not move it. When a source with
 * precise timing (an automated system) is in the group, its time is used
 * as it is and the humans are ignored.
 */
final class PassTimeFuser
{
    /** How many of a source's most recent matched entries define its offset. */
    private const OFFSET_SAMPLE_SIZE = 30;

    /** @var array<int, int> fonteId => habitual offset in ms (positive = types late) */
    public array $offsetBySource = [];

    /**
     * @param PassGroup[] $groupsInTimeOrder every pass group of the race
     * @param array<int, Source> $sourcesById
     */
    public static function fuse(array $groupsInTimeOrder, array $sourcesById): self
    {
        $fuser = new self();
        $fuser->measureSourceOffsets($groupsInTimeOrder);

        foreach ($groupsInTimeOrder as $group) {
            $fuser->fuseOneGroup($group, $sourcesById);
        }

        return $fuser;
    }

    /** @param PassGroup[] $groupsInTimeOrder */
    private function measureSourceOffsets(array $groupsInTimeOrder): void
    {
        $differencesBySource = [];
        foreach ($groupsInTimeOrder as $group) {
            if (count($group->entries) < 2) {
                // With a single entry there is nothing to compare it with.
                continue;
            }
            $times = [];
            foreach ($group->entries as $entry) {
                $times[] = $entry->tsMs;
            }
            $groupMedian = Stats::median($times);
            foreach ($group->entries as $entry) {
                if ($entry->fonteId !== Source::REVISOR_ID) {
                    $differencesBySource[$entry->fonteId][] = $entry->tsMs - $groupMedian;
                }
            }
        }

        foreach ($differencesBySource as $fonteId => $differences) {
            $recent = array_slice($differences, -self::OFFSET_SAMPLE_SIZE);
            $this->offsetBySource[$fonteId] = (int) round(Stats::median($recent));
        }
    }

    /** @param array<int, Source> $sourcesById */
    private function fuseOneGroup(PassGroup $group, array $sourcesById): void
    {
        $correctedTimes = [];
        $preciseTime = null;
        foreach ($group->entries as $entry) {
            $source = $sourcesById[$entry->fonteId] ?? null;
            if ($source !== null && $source->hasPreciseTime()) {
                $preciseTime = $entry->tsMs;
                $correctedTimes[] = $entry->tsMs;
                continue;
            }
            $correctedTimes[] = $entry->tsMs - ($this->offsetBySource[$entry->fonteId] ?? 0);
        }

        $group->dispersaoMs = max($correctedTimes) - min($correctedTimes);

        if ($preciseTime !== null) {
            $group->tsMs = $preciseTime;
            $group->tempoPreciso = true;
            return;
        }
        $group->tsMs = (int) round(Stats::median($correctedTimes));
    }
}
