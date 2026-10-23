<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\CarState;

/**
 * The three numbers the scoring formulas read from `Input.dados` for a laps
 * prova. Kept here, next to the engine, so that what gets published is
 * decided by tested, database-free code.
 *
 * Times are published in whole seconds, rounded to the nearest: hand timing
 * does not justify decimals. Both times are rounded the same way.
 */
final class PublishedValues
{
    public const VOLTAS = 'VOLTAS';
    public const LAST = 'LAST';
    public const BEST = 'BEST';

    public const KEYS = [self::VOLTAS, self::LAST, self::BEST];

    /**
     * @return array{VOLTAS: int, LAST: int, BEST: int}
     *   VOLTAS  counted laps
     *   LAST    whole seconds from the start of the race to the last official pass
     *   BEST    best eligible lap in whole seconds, 0 when there is none
     */
    public static function forCar(CarState $car, RaceTimeline $timeline): array
    {
        return [
            self::VOLTAS => $car->voltas,
            self::LAST => self::lastPassSeconds($car->ultimaPassagemMs, $timeline),
            self::BEST => self::bestLapSeconds($car->melhorVoltaMs),
        ];
    }

    /** LAST: whole seconds from the start to the given pass; 0 when there is no pass or no start. */
    public static function lastPassSeconds(?int $lastPassMs, RaceTimeline $timeline): int
    {
        if ($lastPassMs === null || $timeline->largadaMs === null) {
            return 0;
        }
        return max(0, (int) round(($lastPassMs - $timeline->largadaMs) / 1000));
    }

    /** BEST: the best eligible lap in whole seconds; 0 when there is none. */
    public static function bestLapSeconds(?int $bestLapMs): int
    {
        return $bestLapMs !== null ? (int) round($bestLapMs / 1000) : 0;
    }
}
