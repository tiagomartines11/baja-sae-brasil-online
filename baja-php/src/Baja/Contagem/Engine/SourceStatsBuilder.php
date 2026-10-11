<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
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
     * @param Entry[] $keptEntries entries that took part in matching
     * @param PassGroup[] $groups
     * @return array<int, SourceStats>
     */
    public static function build(
        array $sourcesById,
        array $keptEntries,
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

        foreach ($keptEntries as $entry) {
            if (isset($statsBySource[$entry->fonteId])) {
                $statsBySource[$entry->fonteId]->entradas++;
            }
        }

        foreach ($applied->reassignedEntryIds as $fonteId) {
            if (isset($statsBySource[$fonteId])) {
                $statsBySource[$fonteId]->trocas++;
            }
        }

        // A miss: a pass that counts thanks to the other sources, at a moment
        // when this source was expected and has nothing.
        foreach ($groups as $group) {
            if (!$group->contado || count($group->realSourceIds()) < 2) {
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
     * A counting source is offline when the race is on and none of its
     * devices has been heard from for `fonte_offline_s`.
     */
    private static function isOffline(Source $source, RaceTimeline $timeline, Settings $settings, int $nowMs): bool
    {
        if (!$source->countsPasses()) {
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
