<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Turns pass groups into laps: numbers the counted ones, measures each lap,
 * and later decides which laps may compete for "best lap".
 */
final class LapBuilder
{
    /**
     * @param array<int, PassGroup[]> $groupsByCar each car's groups in time order
     * @return array<int, Lap[]> carro => counted laps first (in order), then pending rows
     */
    public static function build(array $groupsByCar, RaceTimeline $timeline): array
    {
        $lapsByCar = [];
        foreach ($groupsByCar as $carro => $groups) {
            $lapsByCar[$carro] = self::buildOneCar($carro, $groups, $timeline);
        }
        return $lapsByCar;
    }

    /**
     * @param PassGroup[] $groups
     * @return Lap[]
     */
    private static function buildOneCar(int $carro, array $groups, RaceTimeline $timeline): array
    {
        $counted = [];
        $pendingGroups = [];
        $previousGroup = null;
        $lapNumber = 0;

        foreach ($groups as $group) {
            if (!$group->contado) {
                $pendingGroups[] = $group;
                continue;
            }

            $lapNumber++;
            $group->numeroVolta = $lapNumber;
            $lap = new Lap($carro, $lapNumber, $group->tsMs, $group->dispersaoMs, $group->sourceCount());
            $lap->grupo = $group;
            $lap->grupoAnterior = $previousGroup;

            // Lap 1 is measured from the start of the race; every other lap
            // from the previous counted pass.
            $lapStartMs = $previousGroup !== null ? $previousGroup->tsMs : $timeline->largadaMs;
            if ($lapStartMs !== null) {
                $lap->tempoVoltaMs = max(0, $group->tsMs - $lapStartMs);
                $lap->interrompida = $timeline->overlapsRedFlag($lapStartMs, $group->tsMs)
                    || $timeline->overlapsPitStay($carro, $lapStartMs, $group->tsMs);
            }

            $counted[] = $lap;
            $previousGroup = $group;
        }

        // Pending rows continue the numbering only so the primary key of
        // contagem_volta stays unique. They are not laps yet.
        $pending = [];
        foreach ($pendingGroups as $group) {
            $lapNumber++;
            $lap = new Lap($carro, $lapNumber, $group->tsMs, $group->dispersaoMs, $group->sourceCount());
            $lap->estado = Lap::PENDENTE;
            $lap->grupo = $group;
            $pending[] = $lap;
        }

        return array_merge($counted, $pending);
    }

    /**
     * Decides which counted laps are trustworthy enough to be a "best lap".
     * Runs after flag detection because an open flag disqualifies a lap.
     *
     * @param array<int, Lap[]> $lapsByCar
     */
    public static function markBestLapEligibility(array $lapsByCar, Settings $settings, PaceModel $pace): void
    {
        foreach ($lapsByCar as $laps) {
            foreach ($laps as $lap) {
                $lap->elegivelMelhorVolta = self::isEligibleForBestLap($lap, $settings, $pace);
            }
        }
    }

    private static function isEligibleForBestLap(Lap $lap, Settings $settings, PaceModel $pace): bool
    {
        if (!$lap->isCounted() || $lap->tempoVoltaMs === null) {
            return false;
        }
        // Lap 1 never qualifies: the counting line is not always the start
        // line, so its length is not a full lap.
        if ($lap->grupoAnterior === null || $lap->grupo === null) {
            return false;
        }
        if ($lap->interrompida) {
            return false;
        }
        if (!self::isWellTimed($lap->grupoAnterior, $settings) || !self::isWellTimed($lap->grupo, $settings)) {
            return false;
        }
        if ($lap->grupoAnterior->alertasAbertos !== [] || $lap->grupo->alertasAbertos !== []) {
            return false;
        }

        $expectedMs = $pace->expectedLapMs($lap->carro, $lap->grupoAnterior->tsMs);
        if ($expectedMs !== null && $lap->tempoVoltaMs < $settings->fracaoVoltaMinima * $expectedMs) {
            return false;
        }

        return true;
    }

    /** A pass time is reliable when two sources agree closely, or a precise source gave it. */
    private static function isWellTimed(PassGroup $group, Settings $settings): bool
    {
        if ($group->tempoPreciso) {
            return true;
        }
        return count($group->realSourceIds()) >= 2 && $group->dispersaoMs <= $settings->dispersaoMaxMs;
    }
}
