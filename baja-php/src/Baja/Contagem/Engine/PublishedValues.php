<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\CarState;

/**
 * The three numbers the scoring formulas read from `Input.dados` for a laps
 * prova. Kept here, next to the engine, so that what gets published is
 * decided by tested, database-free code.
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
     *   LAST    whole seconds from the start of the race to the last counted pass
     *   BEST    best eligible lap in whole seconds, 0 when there is none
     */
    public static function forCar(CarState $car, RaceTimeline $timeline): array
    {
        $lastPassSeconds = 0;
        if ($car->ultimaPassagemMs !== null && $timeline->largadaMs !== null) {
            $lastPassSeconds = max(0, intdiv($car->ultimaPassagemMs - $timeline->largadaMs, 1000));
        }

        return [
            self::VOLTAS => $car->voltas,
            self::LAST => $lastPassSeconds,
            self::BEST => $car->melhorVoltaMs !== null ? (int) round($car->melhorVoltaMs / 1000) : 0,
        ];
    }
}
