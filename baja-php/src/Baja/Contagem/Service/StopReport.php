<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Engine\StopStats;

/**
 * Pit lane and fueling station stops per car, for the CSV export and the
 * public feed.
 *
 * The numbers come from the engine (StopStats) and the race timeline; this
 * class only lays them out. Times are worked out for a given moment because
 * a car that is inside an area right now has a stay that is still growing.
 */
final class StopReport
{
    /** The areas, with the prefix their columns get in the export. */
    private const AREAS = [StopStats::BOX => 'box', StopStats::ABASTECIMENTO => 'abastecimento'];

    /**
     * One row per present car, for `contagem_exportar.php?tipo=paradas`.
     *
     * @return array<int, array<string, mixed>> each row: numero, equipe, and for each area
     *         {area}_dentro (0/1), {area}_desde_ms, {area}_paradas, {area}_total_ms, {area}_ultima_ms
     */
    public static function rows(ContagemService $service): array
    {
        $nowMs = Clock::nowMs();
        $timeline = RaceContext::timelineOf($service->raceContext());

        $rows = [];
        foreach ($service->loadTeams(true) as $carro => $team) {
            $row = ['numero' => $carro, 'equipe' => $team['nome']];
            foreach (self::AREAS as $area => $prefix) {
                $stats = StopStats::forCar($timeline, $area, (int) $carro, $nowMs);
                $row[$prefix . '_dentro'] = $stats['dentro'] ? 1 : 0;
                $row[$prefix . '_desde_ms'] = $stats['desde_ms'];
                $row[$prefix . '_paradas'] = $stats['paradas'];
                $row[$prefix . '_total_ms'] = $stats['total_ms'];
                $row[$prefix . '_ultima_ms'] = $stats['ultima_ms'];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * What the public feed says about one car's stops.
     *
     * @param bool $fuelingInUse false leaves the fueling part out (null): the prova does not log that area
     * @return array{box: array<string, mixed>, abastecimento: ?array<string, mixed>}
     */
    public static function forFeed(RaceTimeline $timeline, int $carro, bool $fuelingInUse, int $nowMs): array
    {
        return [
            'box' => StopStats::forCar($timeline, StopStats::BOX, $carro, $nowMs),
            'abastecimento' => $fuelingInUse
                ? StopStats::forCar($timeline, StopStats::ABASTECIMENTO, $carro, $nowMs)
                : null,
        ];
    }
}
