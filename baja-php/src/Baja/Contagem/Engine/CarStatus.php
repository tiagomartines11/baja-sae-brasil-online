<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\CarState;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Data\Source;

/**
 * Step 6: where each car stands at "now" — its official numbers and whether
 * it still seems to be running.
 *
 * Status is a statement about the present, so it is computed from "now" on
 * every read and never stored.
 */
final class CarStatus
{
    private Settings $settings;
    private RaceTimeline $timeline;
    private PaceModel $pace;
    private SourceAvailability $availability;

    public function __construct(Settings $settings, RaceTimeline $timeline, PaceModel $pace, SourceAvailability $availability)
    {
        $this->settings = $settings;
        $this->timeline = $timeline;
        $this->pace = $pace;
        $this->availability = $availability;
    }

    /**
     * @param int[] $cars the cars to report on
     * @param array<int, Lap[]> $lapsByCar
     * @param array<int, PassGroup[]> $groupsByCar
     * @return array<int, CarState> carro => state, in standings order
     */
    public function build(array $cars, array $lapsByCar, array $groupsByCar, int $nowMs): array
    {
        $states = [];
        foreach ($cars as $carro) {
            $state = new CarState($carro);
            $this->fillNumbers($state, $lapsByCar[$carro] ?? []);
            $this->fillCountsPerSource($state, $groupsByCar[$carro] ?? []);
            $this->fillStatus($state, $nowMs);
            $states[$carro] = $state;
        }
        return self::inStandingsOrder($states);
    }

    /** @param Lap[] $laps */
    private function fillNumbers(CarState $state, array $laps): void
    {
        foreach ($laps as $lap) {
            if (!$lap->isOfficialPass()) {
                continue;
            }
            // Pass zero is an official pass (it is the car's "last pass"
            // until the next one) but completes no lap.
            $state->ultimaPassagemMs = $lap->tsMs;
            if (!$lap->isCounted()) {
                continue;
            }
            $state->voltas = $lap->numeroVolta;
            $state->ultimaVoltaMs = $lap->tempoVoltaMs;
            if ($lap->elegivelMelhorVolta && ($state->melhorVoltaMs === null || $lap->tempoVoltaMs < $state->melhorVoltaMs)) {
                $state->melhorVoltaMs = $lap->tempoVoltaMs;
            }
        }
    }

    /** @param PassGroup[] $groups */
    private function fillCountsPerSource(CarState $state, array $groups): void
    {
        foreach ($groups as $group) {
            foreach ($group->entries as $entry) {
                if ($entry->fonteId === Source::REVISOR_ID) {
                    continue;
                }
                $state->contagemPorFonte[$entry->fonteId] = ($state->contagemPorFonte[$entry->fonteId] ?? 0) + 1;
            }
        }
    }

    private function fillStatus(CarState $state, int $nowMs): void
    {
        $state->voltaEsperadaMs = $this->timeline->isRunningAt($nowMs)
            ? $this->pace->expectedLapMs($state->carro, $nowMs)
            : null;

        [$state->status, $state->statusDesdeMs] = self::statusAt(
            $nowMs,
            $state->carro,
            $state->ultimaPassagemMs,
            $state->voltaEsperadaMs,
            $this->timeline,
            $this->settings,
            fn(int $momentMs): bool => $this->availability->allSyncedThrough($momentMs)
        );
    }

    /**
     * The status rule itself, as a pure function of a few numbers, so that
     * it exists in exactly one place: the engine uses it here, and whoever
     * shows a stored snapshot later can ask again "and now?" without
     * rebuilding the pace model.
     *
     * @param int|null $ultimaPassagemMs the car's last official pass, null when it has none
     * @param int|null $voltaEsperadaMs what the pace model expects of this car, null when unknown
     * @param callable(int): bool $allSyncedThrough tells whether every counting source has uploaded up to the given moment
     * @return array{0: string, 1: ?int} [status (CarState::*), since when (ms) or null]
     */
    public static function statusAt(
        int $nowMs,
        int $carro,
        ?int $ultimaPassagemMs,
        ?int $voltaEsperadaMs,
        RaceTimeline $timeline,
        Settings $settings,
        callable $allSyncedThrough
    ): array {
        if (!$timeline->isRunningAt($nowMs)) {
            // Before the start and after the finish nobody is late.
            return [CarState::FORA, null];
        }

        $pitEntryMs = $timeline->pitEntryBefore($carro, $nowMs);
        if ($pitEntryMs !== null) {
            return [CarState::NO_BOX, $pitEntryMs];
        }

        if ($voltaEsperadaMs === null) {
            // Too early in the race to know what "late" means.
            return [CarState::CORRENDO, null];
        }

        // The stopwatch for "how long since this car last passed" does not
        // run during a red flag: nobody is passing then.
        // A car that just left the pit lane is known to have been there, so
        // the wait is measured from whichever is later: its last pass or
        // its last pit exit. Otherwise every pit stop would end "atrasado".
        $lastSeenMs = $ultimaPassagemMs ?? (int) $timeline->largadaMs;
        $lastPitExitMs = $timeline->lastPitExitBefore($carro, $nowMs);
        if ($lastPitExitMs !== null && $lastPitExitMs > $lastSeenMs) {
            $lastSeenMs = $lastPitExitMs;
        }
        $racingSinceLastSeenMs = $timeline->racingMsBetween($lastSeenMs, $nowMs);

        $thresholds = [
            CarState::PARADO => $settings->fatorParado,
            CarState::ATRASADO => $settings->fatorAtrasado,
        ];
        foreach ($thresholds as $status => $factor) {
            $allowedMs = (int) round($factor * $voltaEsperadaMs);
            if ($racingSinceLastSeenMs <= $allowedMs) {
                continue;
            }
            $thresholdMoment = $timeline->momentAfterRacing($lastSeenMs, $allowedMs);
            if ($thresholdMoment === null) {
                continue;
            }
            // If a counter has not uploaded up to that moment, the pass that
            // would prove the car is fine may be sitting in a phone.
            if (!$allSyncedThrough($thresholdMoment)) {
                continue;
            }
            return [$status, $thresholdMoment];
        }

        return [CarState::CORRENDO, null];
    }

    /**
     * More laps first; among equals, whoever completed them earlier. Cars
     * with no laps go last, by number, and have no position.
     *
     * @param array<int, CarState> $states
     * @return array<int, CarState>
     */
    private static function inStandingsOrder(array $states): array
    {
        uasort($states, function (CarState $a, CarState $b): int {
            if ($a->voltas !== $b->voltas) {
                return $b->voltas <=> $a->voltas;
            }
            if ($a->voltas > 0 && $a->ultimaPassagemMs !== $b->ultimaPassagemMs) {
                return $a->ultimaPassagemMs <=> $b->ultimaPassagemMs;
            }
            return $a->carro <=> $b->carro;
        });

        $position = 0;
        foreach ($states as $state) {
            if ($state->voltas > 0) {
                $position++;
                $state->posicao = $position;
            }
        }
        return $states;
    }
}
