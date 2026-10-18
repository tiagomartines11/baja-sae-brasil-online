<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Data\SourceStats;

/**
 * The per-source statistics block of the review screen: how many entries,
 * how many misses, how many typos, and how early or late the source types.
 */
final class SourceStatsBuilder
{
    /**
     * @param array<int, Source> $sourcesById
     * @param PassGroup[] $groups every pass group of the race
     * @return array<int, SourceStats>
     */
    public static function build(
        array $sourcesById,
        array $groups,
        AppliedDecisions $applied,
        DuplicateFilter $duplicates,
        PassTimeFuser $fuser,
        SourceAvailability $availability,
        RaceTimeline $timeline,
        Settings $settings,
        int $nowMs,
        ?UnreadAssigner $unread = null
    ): array {
        $statsBySource = [];
        foreach ($sourcesById as $source) {
            if ($source->tipo === Source::TIPO_BOX) {
                continue;
            }
            $stats = new SourceStats($source->id);
            $stats->sincronizadoAteMs = $source->sincronizadoAteMs;
            $stats->vistoEmMs = $source->vistoEmMs;
            $stats->desvioMedianoMs = $fuser->offsetBySource[$source->id] ?? 0;
            $stats->repetidasAnuladas = $duplicates->autoVoidedCountBySource[$source->id] ?? 0;
            $stats->offline = self::isOffline($source, $timeline, $settings, $nowMs);
            $statsBySource[$source->id] = $stats;
        }

        // Entries in use: those in a pass group of the race. What was typed
        // before the start and not accepted as an initial pass is warm-up.
        $entriesBySource = [];
        foreach ($groups as $group) {
            if ($group->antesDaLargada && !$group->contado) {
                continue;
            }
            foreach ($group->entries as $entry) {
                $entriesBySource[$entry->fonteId] = ($entriesBySource[$entry->fonteId] ?? 0) + 1;
            }
        }
        foreach ($entriesBySource as $fonteId => $entryCount) {
            if (isset($statsBySource[$fonteId])) {
                $statsBySource[$fonteId]->entradas = $entryCount;
            }
        }

        foreach ($applied->reassignedEntryIds as $fonteId) {
            if (isset($statsBySource[$fonteId])) {
                $statsBySource[$fonteId]->trocas++;
            }
        }

        // A miss: a pass that counts thanks to the other sources, at a moment
        // when this source was expected and has nothing.
        $countingSources = 0;
        foreach ($sourcesById as $source) {
            // An observer that never voted is in no pass group, and a
            // timing sensor's marks are in none either.
            $countingSources += $source->mayHaveCounted() && !$source->isTimingSensor() ? 1 : 0;
        }
        foreach ($groups as $group) {
            $sourcesInGroup = count($group->entries);
            // A pass every source recorded (nearly all of them) has no miss
            // to look for.
            if (!$group->contado || $sourcesInGroup < 2 || $sourcesInGroup >= $countingSources) {
                continue;
            }
            if (count($group->realSourceIds()) < 2) {
                continue;
            }
            foreach ($availability->expectedSourceIdsAt($group->primeiroTsMs) as $expectedSourceId) {
                if (!$group->hasSource($expectedSourceId) && isset($statsBySource[$expectedSourceId])) {
                    $statsBySource[$expectedSourceId]->faltas++;
                }
            }
        }

        if ($unread !== null || $applied->hasDecidedUnread) {
            self::countUnread($statsBySource, $sourcesById, $groups, $applied, $availability, $timeline, $unread);
        }

        return $statsBySource;
    }

    /**
     * The unread passes of each source, and the timing sensors' own block.
     * Only reached in a race that has unread entries.
     *
     * @param array<int, SourceStats> $statsBySource
     * @param array<int, Source> $sourcesById
     * @param PassGroup[] $groups
     */
    private static function countUnread(
        array $statsBySource,
        array $sourcesById,
        array $groups,
        AppliedDecisions $applied,
        SourceAvailability $availability,
        RaceTimeline $timeline,
        ?UnreadAssigner $unread
    ): void {
        $startMs = $timeline->largadaMs ?? PHP_INT_MAX;
        $counted = [];
        $unreadIds = [];
        foreach (array_merge($applied->unreadEntries, $applied->sensorMarks, $applied->entries) as $entry) {
            if (!$entry->isUnread() || $entry->tsMs < $startMs || isset($counted[$entry->id])) {
                continue;
            }
            $counted[$entry->id] = true;
            $unreadIds[$entry->fonteId][] = $entry->id;
        }
        $sensorOffsets = [];
        foreach ($unread === null ? [] : $unread->assignments as $entryId => $assignment) {
            $fonteId = $unread->sourceOfEntry($entryId);
            if ($fonteId === null || !isset($counted[$entryId])) {
                continue;
            }
            if (isset($sourcesById[$fonteId]) && $sourcesById[$fonteId]->isTimingSensor()) {
                $sensorOffsets[$fonteId][] = $assignment['desvio_ms'];
            } elseif (isset($statsBySource[$fonteId])) {
                $statsBySource[$fonteId]->ilegiveisAtribuidas++;
            }
        }

        foreach ($sourcesById as $source) {
            $stats = $statsBySource[$source->id] ?? null;
            if ($stats === null) {
                continue;
            }
            $ids = $unreadIds[$source->id] ?? [];
            if (!$source->isTimingSensor()) {
                $stats->ilegiveis = count($ids);
                continue;
            }
            if (!$source->mayHaveCounted()) {
                // An observer: compared with the official passes instead (ObserverComparator).
                continue;
            }
            $ambiguous = 0;
            $noPass = 0;
            foreach ($unread === null ? [] : $unread->leftUnread as $entry) {
                if ($entry->fonteId !== $source->id || !isset($counted[$entry->id])) {
                    continue;
                }
                if (isset($unread->candidates[$entry->id])) {
                    $ambiguous++;
                } else {
                    $noPass++;
                }
            }
            $missed = 0;
            foreach ($groups as $group) {
                if (!$group->contado || $group->marcaSensor !== null || $group->hasSource($source->id) || $group->tsMs < $startMs) {
                    continue;
                }
                if ($source->wasCountingAt($group->tsMs) && !$applied->isSourceExcludedAt($source->id, $group->tsMs) && $availability->isKnownAt($source, $group->tsMs)) {
                    $missed++;
                }
            }
            $offsets = $sensorOffsets[$source->id] ?? [];
            $absolute = array_map('abs', $offsets);
            sort($absolute);
            $stats->sensor = [
                'marcas' => count($ids),
                'atribuidas' => count($offsets),
                'ambiguas' => $ambiguous,
                'sem_passagem' => $noPass,
                'passagens_sem_marca' => $missed,
                'desvio_mediano_ms' => $offsets === [] ? null : (int) round((float) Stats::median($offsets)),
                'desvio_p90_ms' => $absolute === [] ? null : $absolute[(int) ceil(0.9 * count($absolute)) - 1],
            ];
        }
    }

    /**
     * A pass source that is switched on (counting, or observing) is offline
     * when the race is on and none of its devices has been heard from for
     * `fonte_offline_s`. An observer's silence changes no count, but whoever
     * runs the camera wants to know it stopped sending.
     */
    private static function isOffline(Source $source, RaceTimeline $timeline, Settings $settings, int $nowMs): bool
    {
        if (!$source->ativa || !$source->recordsPasses()) {
            return false;
        }
        $raceState = $timeline->stateAt($nowMs);
        if ($raceState !== RaceTimeline::CORRENDO && $raceState !== RaceTimeline::BANDEIRA_VERMELHA) {
            return false;
        }
        if ($source->vistoEmMs === null) {
            return true;
        }
        return $nowMs - $source->vistoEmMs > $settings->fonteOfflineS * 1000;
    }
}
