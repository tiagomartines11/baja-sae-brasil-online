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
            if (!$lap->isCounted()) {
                continue;
            }
            $state->voltas = $lap->numeroVolta;
            $state->ultimaPassagemMs = $lap->tsMs;
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
        $raceState = $this->timeline->stateAt($nowMs);
        if ($raceState === RaceTimeline::AGUARDANDO || $raceState === RaceTimeline::ENCERRADA) {
            $state->status = CarState::FORA;
            return;
        }

        $state->voltaEsperadaMs = $this->pace->expectedLapMs($state->carro, $nowMs);

        $pitEntryMs = $this->timeline->pitEntryBefore($state->carro, $nowMs);
        if ($pitEntryMs !== null) {
            $state->status = CarState::NO_BOX;
            $state->statusDesdeMs = $pitEntryMs;
            return;
        }

        $state->status = CarState::CORRENDO;
        if ($state->voltaEsperadaMs === null) {
            // Too early in the race to know what "late" means.
            return;
        }

        // The stopwatch for "how long since this car last passed" does not
        // run during a red flag: nobody is passing then.
        $lastSeenMs = $state->ultimaPassagemMs ?? (int) $this->timeline->largadaMs;
        $racingSinceLastPassMs = $this->timeline->racingMsBetween($lastSeenMs, $nowMs);

        $thresholds = [
            CarState::PARADO => $this->settings->fatorParado,
            CarState::ATRASADO => $this->settings->fatorAtrasado,
        ];
        foreach ($thresholds as $status => $factor) {
            $allowedMs = (int) round($factor * $state->voltaEsperadaMs);
            if ($racingSinceLastPassMs <= $allowedMs) {
                continue;
            }
            $thresholdMoment = $this->timeline->momentAfterRacing($lastSeenMs, $allowedMs);
            if ($thresholdMoment === null) {
                continue;
            }
            // If a counter has not uploaded up to that moment, the pass that
            // would prove the car is fine may be sitting in a phone.
            if (!$this->availability->allSyncedThrough($thresholdMoment)) {
                continue;
            }
            $state->status = $status;
            $state->statusDesdeMs = $thresholdMoment;
            return;
        }
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
