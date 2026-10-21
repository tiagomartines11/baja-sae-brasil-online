<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;

/**
 * The flag "primeira_passagem_tardia": a car whose pass zero was probably
 * missed.
 *
 * With laps = passes - 1, the pack crossing the line seconds after the
 * start is the hardest moment of the race to count, and a car whose first
 * crossing nobody caught silently loses a lap: its second crossing becomes
 * its pass zero. Nothing looks wrong about that car afterwards, except that
 * its "first" pass comes about one lap later than everybody else's.
 *
 * So: a car is flagged when its first pass is later than the field's median
 * first pass plus half of the field's median first lap. There is no
 * automatic suggestion. If the car really started late (from the pit lane,
 * say), the reviewer confirms it; otherwise the reviewer adds the missing
 * pass.
 */
final class LateFirstPass
{
    /** With fewer cars than this there is no "field" to compare a car with. */
    private const MIN_CARS = 3;

    /**
     * @param array<int, Lap[]> $referenceLapsByCar each car's pass zero followed by its laps
     * @param array<int, true> $validCars the cars that take part; empty = every car. A number that
     *   is no team, or a car that is not classified, is neither part of "the field" nor flagged here
     * @return array<int, array{0: Flag, 1: PassGroup}> each flag with the pass group it is about
     */
    public static function find(array $referenceLapsByCar, array $validCars = []): array
    {
        $firstPassByCar = [];
        $firstPassTimes = [];
        $firstLapDurations = [];
        foreach ($referenceLapsByCar as $carro => $laps) {
            if ($validCars !== [] && !isset($validCars[$carro])) {
                continue;
            }
            $passZero = $laps[0] ?? null;
            if ($passZero === null || $passZero->estado !== Lap::INICIAL) {
                continue;
            }
            $firstPassByCar[$carro] = $passZero;
            $firstPassTimes[] = $passZero->tsMs;
            if (isset($laps[1]) && $laps[1]->tempoVoltaMs !== null) {
                $firstLapDurations[] = $laps[1]->tempoVoltaMs;
            }
        }
        if (count($firstPassTimes) < self::MIN_CARS || count($firstLapDurations) < self::MIN_CARS) {
            return [];
        }

        $medianFirstPassMs = Stats::median($firstPassTimes);
        $medianFirstLapMs = Stats::median($firstLapDurations);
        $latestPlausibleMs = $medianFirstPassMs + $medianFirstLapMs / 2;

        $found = [];
        foreach ($firstPassByCar as $carro => $passZero) {
            $group = $passZero->grupo;
            if ($group === null || $passZero->tsMs <= $latestPlausibleMs) {
                continue;
            }
            if ($group->confirmadoPorRevisor || $group->alertasAbertos !== []) {
                continue;
            }
            $flag = new Flag(Flag::PRIMEIRA_PASSAGEM_TARDIA, [$carro], $group->entryIds(), (string) $group->anchorEntryId());
            $flag->tsMs = $group->tsMs;
            $flag->motivo = sprintf(
                'A primeira passagem do carro %d veio %d s depois da primeira passagem da maioria; a passagem inicial dele pode não ter sido registrada.',
                $carro,
                (int) round(($passZero->tsMs - $medianFirstPassMs) / 1000)
            );
            $found[] = [$flag, $group];
        }
        return $found;
    }
}
