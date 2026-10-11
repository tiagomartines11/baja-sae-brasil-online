<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Random\Randomizer;

/**
 * Part 1 of a synthetic race: what REALLY happened on the track (the
 * "ground truth"). Every car, every crossing of the counting line, with a
 * field that gets slower as the track deteriorates, breakdowns and pit
 * stops. Nobody has typed anything yet.
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

    public static function invent(SyntheticRaceConfig $config, Randomizer $random, int $startMs, int $endMs): self
    {
        $track = new self();
        $nextOcorrenciaId = 1;
        $track->ocorrencias[] = new Ocorrencia($nextOcorrenciaId++, Ocorrencia::LARGADA, $startMs);
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
                }

                $passMs += $lapMs;
            }
        }

        usort($track->passes, fn(array $a, array $b) => [$a['tsMs'], $a['car']] <=> [$b['tsMs'], $b['car']]);
        return $track;
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
