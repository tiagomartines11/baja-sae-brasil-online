<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Random\Randomizer;

/**
 * Part 1 of a synthetic race: what REALLY happened on the track (the
 * "ground truth"). Every car, every crossing of the counting line, with a
 * field that gets slower as the track deteriorates, breakdowns, pit stops
 * and fueling stops. Optionally: a start that race control recorded a
 * moment late, and cars that are not classified going out anyway. Nobody
 * has typed anything yet.
 */
final class SyntheticTrack
{
    /**
     * Every real crossing, in time order. `official` is false only for a
     * second crossing after the finish, which the rules of the race leave
     * out (see the setting `apos_encerramento`).
     *
     * @var array<int, array{car: int, tsMs: int, official: bool}>
     */
    public array $passes = [];

    /** @var Ocorrencia[] the start, the pit log, and the finish when there is one */
    public array $ocorrencias = [];

    /** @var array<int, int[]> car => times of its crossings that are official passes, ascending */
    public array $officialPassTimesByCar = [];

    /**
     * When race control recorded the start. Normally the true start; with
     * `startRecordedLate` it is a little after the first cars crossed the
     * counting line.
     */
    public int $recordedStartMs = 0;

    /** @var int[] cars whose first crossing is before the recorded start */
    public array $carsBeforeTheRecordedStart = [];

    /** @var int[] numbers of the cars that are present but not classified */
    public array $unclassifiedCars = [];

    /**
     * Crossings of the unclassified cars, in time order. They are real
     * crossings, but of cars that do not take part: never official.
     *
     * @var array<int, array{car: int, tsMs: int}>
     */
    public array $unclassifiedPasses = [];

    /** How many fueling stops the field made. */
    public int $fuelStops = 0;

    public static function invent(SyntheticRaceConfig $config, Randomizer $random, int $startMs, int $endMs): self
    {
        $track = new self();
        $track->recordedStartMs = $startMs;
        $nextOcorrenciaId = 1;
        $start = new Ocorrencia($nextOcorrenciaId++, Ocorrencia::LARGADA, $startMs);
        $track->ocorrencias[] = $start;
        if ($config->finishAfterRace) {
            $track->ocorrencias[] = new Ocorrencia($nextOcorrenciaId++, Ocorrencia::ENCERRAMENTO, $endMs);
        }

        // The counting line is somewhere along the lap, the same place for
        // everybody, so every car's first crossing comes after the same
        // part of a lap.
        $lineAtFractionOfLap = $random->getFloat(0.3, 1.0);
        $raceLengthMs = $endMs - $startMs;

        for ($car = 1; $car <= $config->cars; $car++) {
            // Some cars are simply faster than others, for the whole race.
            $carFactor = $random->getFloat(0.85, 1.3);
            $passMs = $startMs + (int) ($lineAtFractionOfLap * $config->baseLapSeconds * 1000 * $carFactor);
            $track->officialPassTimesByCar[$car] = [];
            $crossingsAfterFinish = 0;

            while (true) {
                // A car on the line at the very instant the flag falls is a
                // call for the race director, not for a lap counter: typed
                // half a second early or late it lands on either side. The
                // generator keeps crossings three seconds clear of the finish.
                if ($config->finishAfterRace && abs($passMs - $endMs) < 3000) {
                    $passMs = $endMs + 3000;
                }
                $isAfterFinish = $passMs > $endMs;
                if ($isAfterFinish && !$config->finishAfterRace) {
                    break;
                }
                if ($isAfterFinish) {
                    $crossingsAfterFinish++;
                }
                // After the finish only the first crossing still counts.
                $isOfficial = $crossingsAfterFinish <= 1;
                $track->passes[] = ['car' => $car, 'tsMs' => $passMs, 'official' => $isOfficial];
                if ($isOfficial) {
                    $track->officialPassTimesByCar[$car][] = $passMs;
                }
                if ($isAfterFinish) {
                    // About one car in three comes round once more before leaving the track.
                    $comesRoundAgain = $crossingsAfterFinish === 1 && $random->getFloat(0.0, 1.0) < 0.3;
                    if (!$comesRoundAgain) {
                        break;
                    }
                }

                // The track gets slower through the race, for everybody.
                $trackFactor = 1.0 + 0.25 * (($passMs - $startMs) / $raceLengthMs);
                $lapMs = (int) ($config->baseLapSeconds * 1000 * $carFactor * $trackFactor * $random->getFloat(0.94, 1.06));

                if (!$isAfterFinish && $config->breakdownRate > 0 && $random->getFloat(0.0, 1.0) < $config->breakdownRate) {
                    $lapMs += $random->getInt(180, 600) * 1000;
                } elseif (!$isAfterFinish && $config->pitStopRate > 0 && $random->getFloat(0.0, 1.0) < $config->pitStopRate) {
                    $stayMs = $random->getInt(120, 300) * 1000;
                    $pitInMs = $passMs + $random->getInt(20, 40) * 1000;
                    $track->ocorrencias[] = new Ocorrencia($nextOcorrenciaId++, Ocorrencia::BOX_ENTRADA, $pitInMs, $car);
                    $track->ocorrencias[] = new Ocorrencia($nextOcorrenciaId++, Ocorrencia::BOX_SAIDA, $pitInMs + $stayMs, $car);
                    $lapMs += $stayMs;
                } elseif (!$isAfterFinish && $config->fuelStopRate > 0 && $random->getFloat(0.0, 1.0) < $config->fuelStopRate) {
                    $stayMs = $random->getInt(60, 180) * 1000;
                    $fuelInMs = $passMs + $random->getInt(20, 40) * 1000;
                    $track->ocorrencias[] = new Ocorrencia($nextOcorrenciaId++, Ocorrencia::ABASTECIMENTO_ENTRADA, $fuelInMs, $car);
                    $track->ocorrencias[] = new Ocorrencia($nextOcorrenciaId++, Ocorrencia::ABASTECIMENTO_SAIDA, $fuelInMs + $stayMs, $car);
                    $track->fuelStops++;
                    $lapMs += $stayMs;
                }

                $passMs += $lapMs;
            }
        }

        usort($track->passes, fn(array $a, array $b) => [$a['tsMs'], $a['car']] <=> [$b['tsMs'], $b['car']]);
        if ($config->startRecordedLate) {
            $track->recordTheStartLate($start);
        }
        $track->sendUnclassifiedCarsOut($config, $random, $startMs, $raceLengthMs);
        return $track;
    }

    /**
     * Moves the recorded start to two seconds after the crossing of the
     * third car to reach the line (or of fewer cars, when the third is more
     * than ten seconds behind the first: the pack must stay "shortly"
     * before the start).
     */
    private function recordTheStartLate(Ocorrencia $start): void
    {
        $firstCrossingMs = $this->passes[0]['tsMs'];
        $lastBeforeMs = $firstCrossingMs;
        $seen = [];
        foreach ($this->passes as $pass) {
            if (count($seen) === 3 || $pass['tsMs'] - $firstCrossingMs > 10000 || isset($seen[$pass['car']])) {
                break;
            }
            $seen[$pass['car']] = true;
            $lastBeforeMs = $pass['tsMs'];
        }
        $this->recordedStartMs = $lastBeforeMs + 2000;
        $start->tsMs = $this->recordedStartMs;
        $this->carsBeforeTheRecordedStart = array_keys($seen);

        // A car on the line at the very instant the start is recorded would
        // be typed on either side of it, and the truth could not be stated.
        // Any other car that would cross within a second of the recorded
        // start is held back until one second after it (its whole race
        // shifts by that much).
        $clearFromMs = $this->recordedStartMs + 1000;
        foreach ($this->officialPassTimesByCar as $car => $passTimes) {
            if (!isset($seen[$car]) && $passTimes !== [] && $passTimes[0] < $clearFromMs) {
                $this->delayCar($car, $clearFromMs - $passTimes[0]);
            }
        }
        usort($this->passes, fn(array $a, array $b) => [$a['tsMs'], $a['car']] <=> [$b['tsMs'], $b['car']]);
    }

    private function delayCar(int $car, int $byMs): void
    {
        foreach ($this->passes as $index => $pass) {
            if ($pass['car'] === $car) {
                $this->passes[$index]['tsMs'] += $byMs;
            }
        }
        foreach ($this->officialPassTimesByCar[$car] as $index => $passMs) {
            $this->officialPassTimesByCar[$car][$index] = $passMs + $byMs;
        }
        foreach ($this->ocorrencias as $ocorrencia) {
            if ($ocorrencia->carro === $car) {
                $ocorrencia->tsMs += $byMs;
            }
        }
    }

    /**
     * Each unclassified car joins the track somewhere in the first half of
     * the race and crosses the counting line three times.
     */
    private function sendUnclassifiedCarsOut(SyntheticRaceConfig $config, Randomizer $random, int $startMs, int $raceLengthMs): void
    {
        for ($index = 1; $index <= $config->unclassifiedCarsOnTrack; $index++) {
            $car = $config->cars + $index;
            $this->unclassifiedCars[] = $car;
            $passMs = $startMs + (int) ($random->getFloat(0.1, 0.5) * $raceLengthMs);
            for ($crossing = 0; $crossing < 3; $crossing++) {
                $this->unclassifiedPasses[] = ['car' => $car, 'tsMs' => $passMs];
                $passMs += (int) ($config->baseLapSeconds * 1000 * $random->getFloat(1.0, 1.3));
            }
        }
        usort($this->unclassifiedPasses, fn(array $a, array $b) => [$a['tsMs'], $a['car']] <=> [$b['tsMs'], $b['car']]);
    }

    /** How many official passes the car had really made by $momentMs. */
    public function officialPassesBy(int $car, int $momentMs): int
    {
        $count = 0;
        foreach ($this->officialPassTimesByCar[$car] ?? [] as $passMs) {
            if ($passMs <= $momentMs) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array<int, true> cars with a crossing within $marginMs of the given crossing (itself excluded) */
    public function carsPassingAround(int $passIndex, int $marginMs): array
    {
        $centerMs = $this->passes[$passIndex]['tsMs'];
        $cars = [];
        for ($i = $passIndex - 1; $i >= 0 && $centerMs - $this->passes[$i]['tsMs'] <= $marginMs; $i--) {
            $cars[$this->passes[$i]['car']] = true;
        }
        $count = count($this->passes);
        for ($i = $passIndex + 1; $i < $count && $this->passes[$i]['tsMs'] - $centerMs <= $marginMs; $i++) {
            $cars[$this->passes[$i]['car']] = true;
        }
        return $cars;
    }
}
