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

    // ----- per car -----

    /** @var array<int, Lap[]> carro => its clean laps in time order */
    private array $cleanLapsByCar = [];
    /** @var array<int, int[]> carro => end times of its laps that qualify for the car factor */
    private array $carLapEnds = [];
    /** @var array<int, float[]> carro => lap ÷ field pace, same order */
    private array $carLapRatios = [];
    /** @var array<int, array<int, ?int>> carro => moment => the answer expectedLapMs() gave */
    private array $expectedLapByCarAndMoment = [];
    /** @var array<int, array<int, float>> carro => number of its laps finished => its factor then */
    private array $carFactorByLapsFinished = [];

    /**
     * @param array<int, Lap[]> $lapsByCar
     */
    public function __construct(array $lapsByCar, Settings $settings)
    {
        $this->windowMs = $settings->janelaRitmoS * 1000;

        $endTimes = [];
        $durations = [];
        foreach ($lapsByCar as $carro => $laps) {
            foreach ($laps as $lap) {
                if (self::isClean($lap)) {
                    $this->cleanLapsByCar[$carro][] = $lap;
                    $endTimes[] = $lap->tsMs;
                    $durations[] = (int) $lap->tempoVoltaMs;
                }
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
        // The moment is rounded up to the grid, so that a question asked
        // "now" sees every lap completed so far.
        $step = intdiv($tsMs + self::GRID_MS - 1, self::GRID_MS);
        if (!array_key_exists($step, $this->paceByGridStep)) {
            $moment = $step * self::GRID_MS;
            $this->paceByGridStep[$step] = $this->medianOfLapsEndedBetween($moment - $this->windowMs, $moment);
        }
        return $this->paceByGridStep[$step];
    }

    /**
     * The field's laps are kept in the order they ended, so the laps of a
     * period are one stretch of the list, found by two binary searches.
     * A lap that ended exactly at $afterMs is no longer in the period; one
     * that ended exactly at $untilMs is.
     */
    private function medianOfLapsEndedBetween(int $afterMs, int $untilMs): ?float
    {
        $firstIndex = Stats::countAtMost($this->fieldLapEnds, $afterMs);
        $lapCount = Stats::countAtMost($this->fieldLapEnds, $untilMs) - $firstIndex;
        if ($lapCount < self::MIN_LAPS_FOR_FIELD_PACE) {
            return null;
        }
        return Stats::median(array_slice($this->fieldLapDurations, $firstIndex, $lapCount));
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
        $lapsFinished = Stats::countAtMost($this->carLapEnds[$carro], $tsMs);
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

    /** Expected lap of the car around $tsMs, in ms. Null while the field pace is unknown. */
    public function expectedLapMs(int $carro, int $tsMs): ?int
    {
        // Asked twice for every lap (is it too short? may it be a best
        // lap?), with the same car and moment both times.
        if (isset($this->expectedLapByCarAndMoment[$carro]) && array_key_exists($tsMs, $this->expectedLapByCarAndMoment[$carro])) {
            return $this->expectedLapByCarAndMoment[$carro][$tsMs];
        }
        $fieldPace = $this->fieldPaceAt($tsMs);
        $expectedMs = $fieldPace === null ? null : (int) round($fieldPace * $this->carFactorAt($carro, $tsMs));
        $this->expectedLapByCarAndMoment[$carro][$tsMs] = $expectedMs;
        return $expectedMs;
    }

    /** How each clean lap of the car compares with the field pace at the moment the lap began. */
    private function measureCar(int $carro): void
    {
        $this->carLapEnds[$carro] = [];
        $this->carLapRatios[$carro] = [];
        foreach ($this->cleanLapsByCar[$carro] ?? [] as $lap) {
            $lapStartMs = $lap->tsMs - (int) $lap->tempoVoltaMs;
            $fieldPace = $this->fieldPaceAt($lapStartMs);
            if ($fieldPace === null || $fieldPace <= 0) {
                continue;
            }
            $ratio = $lap->tempoVoltaMs / $fieldPace;
            if ($ratio < self::MAX_CLEAN_RATIO) {
                $this->carLapEnds[$carro][] = $lap->tsMs;
                $this->carLapRatios[$carro][] = $ratio;
            }
        }
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
