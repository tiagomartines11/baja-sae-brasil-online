<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Turns official passes into laps: numbers them, measures each lap, and
 * later decides which laps may compete for "best lap".
 *
 * João's rule, the default: laps = official passes - 1. A car's first
 * official pass is its pass zero (number 0, state "inicial"); the second
 * completes lap 1, and so on. Every lap is then measured from one crossing
 * of the counting line to the next, so every lap is a full lap.
 *
 * With `passagem_inicial = largada` the start itself is pass zero: the first
 * official pass completes lap 1, measured from the start time. That lap is
 * not a full lap of the track, so it is never a best lap and does not feed
 * the pace model.
 */
final class LapBuilder
{
    /**
     * The official laps of each car, as the publish mode counts them.
     * Also writes each group's `numeroVolta`.
     *
     * @param array<int, PassGroup[]> $groupsByCar each car's groups in time order
     * @return array<int, Lap[]> carro => pass zero (if any), counted laps in order, then pending rows
     */
    public static function build(array $groupsByCar, RaceTimeline $timeline, Settings $settings): array
    {
        $lapsByCar = [];
        foreach ($groupsByCar as $carro => $groups) {
            $lapsByCar[$carro] = self::buildOneCar($carro, $groups, $timeline, $settings, false);
        }
        return $lapsByCar;
    }

    /**
     * The laps each car would have under the default rule ("two sources
     * agree"), whatever the publish mode. Flags and the pace model are
     * computed from these, so they do not change with the mode.
     *
     * @param array<int, PassGroup[]> $groupsByCar
     * @return array<int, Lap[]>
     */
    public static function buildReference(array $groupsByCar, RaceTimeline $timeline, Settings $settings): array
    {
        $lapsByCar = [];
        foreach ($groupsByCar as $carro => $groups) {
            $lapsByCar[$carro] = self::buildOneCar($carro, $groups, $timeline, $settings, true);
        }
        return $lapsByCar;
    }

    /**
     * @param PassGroup[] $groups
     * @return Lap[]
     */
    private static function buildOneCar(int $carro, array $groups, RaceTimeline $timeline, Settings $settings, bool $reference): array
    {
        $official = [];
        $pendingGroups = [];
        $previousGroup = null;
        $passZeroTaken = !$settings->firstPassIsLapZero();
        $lapNumber = 0;

        foreach ($groups as $group) {
            $isPass = $reference ? $group->referencia : $group->contado;

            if (!$isPass) {
                // The first crossing typed inside the start window is shown
                // as the car's pass zero; it is the same thing by another road.
                if (!$reference && $group->dentroDaJanelaDeLargada && $official === []) {
                    $official[] = self::passZero($carro, $group);
                    $group->numeroVolta = 0;
                } elseif (!$group->dentroDaJanelaDeLargada && !$group->ignoradoAposEncerramento) {
                    $pendingGroups[] = $group;
                }
                continue;
            }

            if (!$passZeroTaken) {
                $passZeroTaken = true;
                $official[] = self::passZero($carro, $group);
                if (!$reference) {
                    $group->numeroVolta = 0;
                }
                $previousGroup = $group;
                continue;
            }

            $lapNumber++;
            if (!$reference) {
                $group->numeroVolta = $lapNumber;
            }
            $lap = new Lap($carro, $lapNumber, $group->tsMs, $group->dispersaoMs, $group->sourceCount());
            $lap->grupo = $group;
            $lap->grupoAnterior = $previousGroup;

            if ($previousGroup !== null) {
                // Durations use the habit-corrected times; overlaps with red
                // flags and pit stays use the plain pass times.
                $lap->tempoVoltaMs = max(0, $group->tsAjustadoMs - $previousGroup->tsAjustadoMs);
                $lap->interrompida = self::isInterrupted($carro, $previousGroup->tsMs, $group->tsMs, $timeline);
            } elseif ($timeline->largadaMs !== null) {
                $lap->tempoVoltaMs = max(0, $group->tsMs - $timeline->largadaMs);
                $lap->interrompida = self::isInterrupted($carro, $timeline->largadaMs, $group->tsMs, $timeline);
            }

            $official[] = $lap;
            $previousGroup = $group;
        }

        if ($reference) {
            return $official;
        }

        // Pending rows continue the numbering only so the primary key of
        // contagem_volta stays unique. They are not laps yet.
        foreach ($pendingGroups as $group) {
            $lapNumber++;
            $lap = new Lap($carro, $lapNumber, $group->tsMs, $group->dispersaoMs, $group->sourceCount());
            $lap->estado = Lap::PENDENTE;
            $lap->grupo = $group;
            $official[] = $lap;
        }
        return $official;
    }

    private static function passZero(int $carro, PassGroup $group): Lap
    {
        $lap = new Lap($carro, 0, $group->tsMs, $group->dispersaoMs, $group->sourceCount());
        $lap->estado = Lap::INICIAL;
        $lap->grupo = $group;
        return $lap;
    }

    private static function isInterrupted(int $carro, int $fromMs, int $toMs, RaceTimeline $timeline): bool
    {
        return $timeline->overlapsRedFlag($fromMs, $toMs) || $timeline->overlapsPitStay($carro, $fromMs, $toMs);
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
        // A lap measured from the start time is not a full lap of the track.
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

        // Even a lap a reviewer confirmed (so it has no open flag) cannot be
        // announced as the best lap of the race when it is too short to be
        // a lap of the track.
        $expectedMs = $pace->expectedLapMs($lap->carro, $lap->grupoAnterior->tsMs);
        if ($settings->isImpossiblyShortLap($lap->tempoVoltaMs, $expectedMs)) {
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
        if ($group->dispersaoMs > $settings->dispersaoMaxMs || count($group->entries) < 2) {
            return false;
        }
        return count($group->realSourceIds()) >= 2;
    }
}
