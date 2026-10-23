<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Random\Randomizer;

/**
 * The choices behind each synthetic mistake: WHO makes it, and WHICH wrong
 * number gets typed. Kept apart from SyntheticMistakes, which decides where
 * the mistakes go and what kind they are.
 */
final class SyntheticMistakeChoices
{
    private SyntheticRaceConfig $config;
    private SyntheticTrack $track;
    private Randomizer $random;
    private int $startMs;

    public function __construct(SyntheticRaceConfig $config, SyntheticTrack $track, Randomizer $random, int $startMs)
    {
        $this->config = $config;
        $this->track = $track;
        $this->random = $random;
        $this->startMs = $startMs;
    }

    /**
     * @param int[] $except
     * @return int[]|null $howMany different counters free to make a mistake at that moment
     */
    public function countersFreeAt(int $tsMs, int $howMany, array $except): ?array
    {
        $free = [];
        for ($source = 1; $source <= $this->config->counters; $source++) {
            $isBusy = $this->isBeingHandedOver($source, $tsMs) || $this->isIn($this->config->estimatedTimePeriods, $source, $tsMs);
            if (!in_array($source, $except, true) && !$isBusy) {
                $free[] = $source;
            }
        }
        if (count($free) < $howMany) {
            return null;
        }
        return array_slice($this->random->shuffleArray($free), 0, $howMany);
    }

    /**
     * True around a start that was recorded late (until half a minute after
     * it) and while any counter's phone has no usable time (with a minute
     * to spare on each side).
     */
    public function nobodyMakesMistakesAt(int $tsMs, int $recordedStartMs): bool
    {
        if ($this->config->startRecordedLate && $tsMs < $recordedStartMs + 30000) {
            return true;
        }
        return $this->isIn($this->config->unknownTimePeriods, null, $tsMs);
    }

    /**
     * @param array<int, array{source: int, fromSeconds: int, toSeconds: int}> $periods
     * @param int|null $source null: a period of any source
     * @return bool true from a minute before one of the periods to a minute after it
     */
    private function isIn(array $periods, ?int $source, int $tsMs): bool
    {
        foreach ($periods as $period) {
            $fromMs = $this->startMs + ($period['fromSeconds'] - 60) * 1000;
            $toMs = $this->startMs + ($period['toSeconds'] + 60) * 1000;
            if (($source === null || $period['source'] === $source) && $tsMs >= $fromMs && $tsMs <= $toMs) {
                return true;
            }
        }
        return false;
    }

    /** True from a minute before the handover to a minute after it. */
    private function isBeingHandedOver(int $source, int $tsMs): bool
    {
        foreach ($this->config->handovers as $handover) {
            $fromMs = $this->startMs + ($handover['fromSeconds'] - 60) * 1000;
            $toMs = $this->startMs + ($handover['toSeconds'] + 60) * 1000;
            if ($handover['source'] === $source && $tsMs >= $fromMs && $tsMs <= $toMs) {
                return true;
            }
        }
        return false;
    }

    /**
     * The number typed instead of the right one: the two digits swapped when
     * that is another car in the race (41 becomes 14), otherwise any other
     * car. A car that is itself crossing the line around that moment is not
     * used here; that is a different mistake (typo_onto_passing_car).
     *
     * @param int[] $notTheseCars
     */
    public function wrongCarFor(int $passIndex, array $notTheseCars): ?int
    {
        $car = $this->track->passes[$passIndex]['car'];
        $carsNearby = $this->track->carsPassingAround($passIndex, 45000);

        $candidates = [(int) strrev((string) $car)];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidates[] = $this->random->getInt(1, $this->config->cars);
        }
        foreach ($candidates as $candidate) {
            $isAnotherRealCar = $candidate !== $car && $candidate >= 1 && $candidate <= $this->config->cars;
            if ($isAnotherRealCar && !isset($carsNearby[$candidate]) && !in_array($candidate, $notTheseCars, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * A car that crosses the line between 15 and 25 seconds before or after
     * the given crossing, and not closer than that: its real entries are
     * then about one matching window away from the stray entry.
     */
    public function carPassingAtTheWindowEdge(int $passIndex): ?int
    {
        $car = $this->track->passes[$passIndex]['car'];
        $tooClose = $this->track->carsPassingAround($passIndex, 15000);
        $atTheEdge = [];
        foreach (array_keys($this->track->carsPassingAround($passIndex, 25000)) as $other) {
            if ($other !== $car && !isset($tooClose[$other])) {
                $atTheEdge[] = $other;
            }
        }
        if ($atTheEdge === []) {
            return null;
        }
        return $atTheEdge[$this->random->getInt(0, count($atTheEdge) - 1)];
    }

    public function carPassingNear(int $passIndex): ?int
    {
        $car = $this->track->passes[$passIndex]['car'];
        $carsNearby = array_keys($this->track->carsPassingAround($passIndex, 15000));
        $otherCars = array_values(array_filter($carsNearby, fn(int $other) => $other !== $car));
        if ($otherCars === []) {
            return null;
        }
        return $otherCars[$this->random->getInt(0, count($otherCars) - 1)];
    }
}
