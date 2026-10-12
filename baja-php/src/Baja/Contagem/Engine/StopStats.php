<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Ocorrencia;

/**
 * Numbers about pit lane and fueling station stops, for the public feed and
 * the CSV export: is the car inside right now, how often it stopped, for
 * how long.
 *
 * Pure arithmetic on what RaceTimeline already paired. The two areas are
 * counted separately and never mixed.
 *
 * What counts as a stay: an entry followed by an exit. An entry with no exit
 * yet is the stay the car is in right now. An unpaired event (an exit with
 * no entry, a second entry with no exit) is not a stay and adds no time; it
 * becomes one only if its missing half is recorded.
 */
final class StopStats
{
    public const BOX = RaceTimeline::AREA_BOX;
    public const ABASTECIMENTO = RaceTimeline::AREA_ABASTECIMENTO;

    public const AREAS = [self::BOX, self::ABASTECIMENTO];

    /**
     * One car in one area, as things stand at $nowMs. Anything logged with
     * a time after $nowMs has not happened yet.
     *
     * @param string $area self::BOX or self::ABASTECIMENTO
     * @return array{dentro: bool, desde_ms: ?int, paradas: int, total_ms: int, ultima_ms: ?int}
     *   dentro     the car is inside the area now
     *   desde_ms   since when, null when it is not inside
     *   paradas    completed stays (the one under way is not counted yet)
     *   total_ms   time spent inside: completed stays plus the one under way, up to now
     *   ultima_ms  duration of the most recent completed stay, null when there is none
     */
    public static function forCar(RaceTimeline $timeline, string $area, int $carro, int $nowMs): array
    {
        $stats = ['dentro' => false, 'desde_ms' => null, 'paradas' => 0, 'total_ms' => 0, 'ultima_ms' => null];
        $lastExitMs = null;

        foreach ($timeline->stayIntervals($area)[$carro] ?? [] as [$inMs, $outMs]) {
            if ($inMs > $nowMs) {
                continue;
            }
            if ($outMs === null || $outMs > $nowMs) {
                $stats['dentro'] = true;
                $stats['desde_ms'] = $inMs;
                $stats['total_ms'] += $nowMs - $inMs;
                continue;
            }
            $stats['paradas']++;
            $stats['total_ms'] += $outMs - $inMs;
            if ($lastExitMs === null || $outMs > $lastExitMs) {
                $lastExitMs = $outMs;
                $stats['ultima_ms'] = $outMs - $inMs;
            }
        }
        return $stats;
    }

    /**
     * The latest entries and exits of both areas across the field, newest
     * first, so an overlay can announce them. Unpaired events are included:
     * they did happen. Two events at the same millisecond are listed with
     * the one recorded later first.
     *
     * Each event says how it is known:
     * - `inferida`: an exit a reviewer recorded ("marcar saída"), not the
     *   person at the gate;
     * - `presumida`: an exit nobody recorded at all. The engine assumed it
     *   while counting, because the car was seen lapping (HoldRule). It is
     *   stored nowhere and is listed at its assumed time. Every other
     *   event has `presumida` false, so a consumer never has to ask
     *   whether the key is there.
     *
     * An assumed exit is listed only while the log has NO exit for that
     * stay. When the gate does log one (late), that event is the record
     * and the list shows it alone: two exits for one stay would have an
     * overlay announce the car leaving twice.
     *
     * @return array<int, array{area: string, tipo: string, carro: int, ts_ms: int, inferida: bool, presumida: bool}>
     */
    public static function recentEvents(RaceTimeline $timeline, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $events = [];
        foreach ($timeline->stopEvents as $event) {
            $events[] = [
                'area' => $event['area'],
                'tipo' => $event['tipo'],
                'carro' => $event['carro'],
                'ts_ms' => $event['ts_ms'],
                'inferida' => $event['inferida'],
                'presumida' => false,
            ];
        }
        $hasAssumedExits = false;
        foreach ($timeline->assumedExits as $exit) {
            if ($exit['saida_registrada_ms'] !== null) {
                continue;
            }
            $hasAssumedExits = true;
            $events[] = [
                'area' => $exit['area'],
                'tipo' => $exit['area'] === RaceTimeline::AREA_BOX ? Ocorrencia::BOX_SAIDA : Ocorrencia::ABASTECIMENTO_SAIDA,
                'carro' => $exit['carro'],
                'ts_ms' => $exit['ts_ms'],
                'inferida' => false,
                'presumida' => true,
            ];
        }
        if ($hasAssumedExits) {
            // The logged events are in time order already; the assumed
            // exits were appended and have to find their place. (usort is
            // stable: events at the same millisecond keep their order.)
            usort($events, fn(array $a, array $b) => $a['ts_ms'] <=> $b['ts_ms']);
        }
        return array_reverse(array_slice($events, -$limit));
    }

}
