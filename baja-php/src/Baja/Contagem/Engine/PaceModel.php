<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Lap;

/**
 * What lap time should we expect from a given car at a given moment?
 *
 *   expected lap = field pace × car factor
 *
 * - Field pace: the median duration of the laps the whole field finished in
 *   the last `janela_ritmo_s` seconds. It follows the track as it gets
 *   muddier and slower through the race.
 * - Car factor: how this car compares to the field (0.9 = ten percent
 *   faster), taken from its own recent clean laps.
 *
 * Only "clean" laps feed the model: full laps (from one crossing of the
 * counting line to the next, so not a lap measured from the start time)
 * that do not overlap a red flag or a pit stay.
 *
 * Nothing is computed until somebody asks, and every answer is remembered.
 */
final class PaceModel
{
    /** Fewer laps than this in the window and the field pace is unknown. */
    public const MIN_LAPS_FOR_FIELD_PACE = 8;

    /** A lap at or above this many times the field pace says nothing about the car's normal pace. */
    public const MAX_CLEAN_RATIO = 1.6;

    public const CAR_FACTOR_SAMPLE_SIZE = 6;
    public const MIN_LAPS_FOR_CAR_FACTOR = 3;

    /**
     * The field pace is evaluated once per minute of the race. It is a
     * median over a quarter of an hour, so it does not move within a
     * minute, and thousands of questions then share a few hundred answers.
     */
    private const GRID_MS = 60000;

    private int $windowMs;

    /** @var int[] end time of every clean lap of the field, ascending */
    private array $fieldLapEnds = [];

    /** @var int[] duration of the same laps, same order */
    private array $fieldLapDurations = [];

    /** @var array<int, ?float> grid step => field pace, for every step asked about so far */
    private array $paceByGridStep = [];

    /** @var array<int, int> grid step => how many laps the field pace of that step was taken from */
    private array $lapCountByGridStep = [];

    // ----- per car -----

    /** @var array<int, Lap[]> carro => its clean laps in time order */
    private array $cleanLapsByCar = [];
    /** @var array<int, int[]> carro => end times of its laps that qualify for the car factor */
    private array $carLapEnds = [];
    /** @var array<int, float[]> carro => lap ÷ field pace, same order */
    private array $carLapRatios = [];
    /** @var array<int, array<int, int>> carro => moment => the answer expectedLapMs() gave (0 for "unknown") */
    private array $expectedLapByCarAndMoment = [];
    /** @var array<int, array<int, float>> carro => number of its laps finished => its factor then */
    private array $carFactorByLapsFinished = [];
    /** @var array<int, int> carro => the moment last asked about */
    private array $lastMomentAskedByCar = [];
    /** @var array<int, int> carro => how many of its laps were finished by that moment */
    private array $lapsFinishedThenByCar = [];

    /**
     * @param array<int, Lap[]> $lapsByCar
     */
    public function __construct(array $lapsByCar, Settings $settings)
    {
        $this->windowMs = $settings->janelaRitmoS * 1000;

        $endTimes = [];
        $durations = [];
        foreach ($lapsByCar as $carro => $laps) {
            $cleanLaps = [];
            foreach ($laps as $lap) {
                if (self::isClean($lap)) {
                    $cleanLaps[] = $lap;
                    $endTimes[] = $lap->tsMs;
                    $durations[] = (int) $lap->tempoVoltaMs;
                }
            }
            if ($cleanLaps !== []) {
                $this->cleanLapsByCar[$carro] = $cleanLaps;
            }
        }
        asort($endTimes);
        foreach ($endTimes as $index => $endTime) {
            $this->fieldLapEnds[] = $endTime;
            $this->fieldLapDurations[] = $durations[$index];
        }
    }

    /** Median lap of the field around $tsMs, in ms. Null while there is too little data. */
    public function fieldPaceAt(int $tsMs): ?float
    {
        $step = self::gridStepOf($tsMs);
        if (!array_key_exists($step, $this->paceByGridStep)) {
            $this->measureFieldAt($step);
        }
        return $this->paceByGridStep[$step];
    }

    /**
     * How many clean laps of the field fieldPaceAt($tsMs) is taken from:
     * those that ended in the window. Below MIN_LAPS_FOR_FIELD_PACE the
     * pace is unknown, and this says how far it is from being known.
     */
    public function fieldLapCountAt(int $tsMs): int
    {
        $step = self::gridStepOf($tsMs);
        if (!array_key_exists($step, $this->lapCountByGridStep)) {
            $this->measureFieldAt($step);
        }
        return $this->lapCountByGridStep[$step];
    }

    /**
     * The field pace at every step of the grid from $fromMs to $untilMs,
     * oldest first: what a chart of the pace through the race draws. Each
     * point carries the moment of its step, except the last, which carries
     * $untilMs itself (its step may be up to a minute later).
     *
     * @return array<int, array{ts_ms: int, volta_mediana_ms: ?int, voltas_na_janela: int}>
     */
    public function fieldPaceHistory(int $fromMs, int $untilMs): array
    {
        $points = [];
        $lastStep = self::gridStepOf($untilMs);
        for ($step = self::gridStepOf($fromMs); $step <= $lastStep; $step++) {
            $momentMs = $step === $lastStep ? $untilMs : $step * self::GRID_MS;
            $pace = $this->fieldPaceAt($momentMs);
            $points[] = [
                'ts_ms' => $momentMs,
                'volta_mediana_ms' => $pace === null ? null : (int) round($pace),
                'voltas_na_janela' => $this->fieldLapCountAt($momentMs),
            ];
        }
        return $points;
    }

    /**
     * The moment is rounded up to the grid, so that a question asked "now"
     * sees every lap completed so far.
     */
    private static function gridStepOf(int $tsMs): int
    {
        return intdiv($tsMs + self::GRID_MS - 1, self::GRID_MS);
    }

    /**
     * The field's laps are kept in the order they ended, so the laps of a
     * window are one stretch of the list, found by two binary searches.
     * A lap that ended exactly at the start of the window is no longer in
     * it; one that ended exactly at its end is.
     */
    private function measureFieldAt(int $step): void
    {
        $untilMs = $step * self::GRID_MS;
        $firstIndex = Stats::countAtMost($this->fieldLapEnds, $untilMs - $this->windowMs);
        $lapCount = Stats::countAtMost($this->fieldLapEnds, $untilMs) - $firstIndex;
        $this->lapCountByGridStep[$step] = $lapCount;
        $this->paceByGridStep[$step] = $this->medianOf($firstIndex, $lapCount);
    }

    private function medianOf(int $firstIndex, int $lapCount): ?float
    {
        if ($lapCount < self::MIN_LAPS_FOR_FIELD_PACE) {
            return null;
        }
        $durations = array_slice($this->fieldLapDurations, $firstIndex, $lapCount);
        sort($durations);
        $middle = $lapCount >> 1;
        return $lapCount % 2 === 1
            ? (float) $durations[$middle]
            : ($durations[$middle - 1] + $durations[$middle]) / 2;
    }

    /**
     * How the car compares to the field, from its last clean laps finished
     * by $tsMs. 1.0 (an average car) until it has enough laps of its own.
     */
    public function carFactorAt(int $carro, int $tsMs): float
    {
        if (!isset($this->carLapEnds[$carro])) {
            $this->measureCar($carro);
        }
        $lapsFinished = $this->lapsFinishedBy($carro, $tsMs);
        if ($lapsFinished < self::MIN_LAPS_FOR_CAR_FACTOR) {
            return 1.0;
        }
        // The answer only changes when the car finishes another lap, and
        // it is asked for at least twice per lap (flags, best lap).
        if (!isset($this->carFactorByLapsFinished[$carro][$lapsFinished])) {
            $firstIndex = max(0, $lapsFinished - self::CAR_FACTOR_SAMPLE_SIZE);
            $recentRatios = array_slice($this->carLapRatios[$carro], $firstIndex, $lapsFinished - $firstIndex);
            $this->carFactorByLapsFinished[$carro][$lapsFinished] = (float) Stats::median($recentRatios);
        }
        return $this->carFactorByLapsFinished[$carro][$lapsFinished];
    }

    /**
     * How many of the car's qualifying laps had ended by $tsMs.
     *
     * Questions about one car nearly always come in time order (lap after
     * lap), so the previous answer is remembered and the count simply moves
     * on from there. A question about an earlier moment is answered by a
     * binary search instead.
     */
    private function lapsFinishedBy(int $carro, int $tsMs): int
    {
        $lapEnds = $this->carLapEnds[$carro];
        if ($tsMs < ($this->lastMomentAskedByCar[$carro] ?? PHP_INT_MAX)) {
            $finished = Stats::countAtMost($lapEnds, $tsMs);
        } else {
            $finished = $this->lapsFinishedThenByCar[$carro];
            $lapCount = count($lapEnds);
            while ($finished < $lapCount && $lapEnds[$finished] <= $tsMs) {
                $finished++;
            }
        }
        $this->lastMomentAskedByCar[$carro] = $tsMs;
        $this->lapsFinishedThenByCar[$carro] = $finished;
        return $finished;
    }

    /** Expected lap of the car around $tsMs, in ms. Null while the field pace is unknown. */
    public function expectedLapMs(int $carro, int $tsMs): ?int
    {
        // Asked twice for every lap (is it too short? may it be a best
        // lap?), with the same car and moment both times. The answers are
        // remembered with "unknown" written as 0, so that one look-up tells
        // "not asked yet" (nothing there) from "asked, and unknown".
        $remembered = $this->expectedLapByCarAndMoment[$carro][$tsMs] ?? -1;
        if ($remembered >= 0) {
            return $remembered === 0 ? null : $remembered;
        }
        $fieldPace = $this->fieldPaceAt($tsMs);
        $expectedMs = $fieldPace === null ? null : (int) round($fieldPace * $this->carFactorAt($carro, $tsMs));
        $this->expectedLapByCarAndMoment[$carro][$tsMs] = $expectedMs ?? 0;
        return $expectedMs;
    }

    /** How each clean lap of the car compares with the field pace at the moment the lap began. */
    private function measureCar(int $carro): void
    {
        $this->carLapEnds[$carro] = [];
        $this->carLapRatios[$carro] = [];
        $lapEnds = [];
        $lapRatios = [];
        foreach ($this->cleanLapsByCar[$carro] ?? [] as $lap) {
            $lapStartMs = $lap->tsMs - (int) $lap->tempoVoltaMs;
            // The same grid step fieldPaceAt() uses; an answer already
            // worked out is read straight from the list.
            $step = self::gridStepOf($lapStartMs);
            $fieldPace = $this->paceByGridStep[$step] ?? $this->fieldPaceAt($lapStartMs);
            if ($fieldPace === null || $fieldPace <= 0) {
                continue;
            }
            $ratio = $lap->tempoVoltaMs / $fieldPace;
            if ($ratio < self::MAX_CLEAN_RATIO) {
                $lapEnds[] = $lap->tsMs;
                $lapRatios[] = $ratio;
            }
        }
        $this->carLapEnds[$carro] = $lapEnds;
        $this->carLapRatios[$carro] = $lapRatios;
    }

    private static function isClean(Lap $lap): bool
    {
        return $lap->isCounted()
            && $lap->grupoAnterior !== null
            && $lap->tempoVoltaMs !== null
            && $lap->tempoVoltaMs > 0
            && !$lap->interrompida;
    }
}
