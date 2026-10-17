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
        int $nowMs
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
            // An observer that never voted is in no pass group.
            $countingSources += $source->mayHaveCounted() ? 1 : 0;
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

        return $statsBySource;
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
