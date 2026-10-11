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
 * Only "clean" laps feed the model: counted, not lap 1 (it starts at the
 * start line, not at the counting line), and not overlapping a red flag or
 * a pit stay.
 */
final class PaceModel
{
    /** Fewer laps than this in the window and the field pace is unknown. */
    private const MIN_LAPS_FOR_FIELD_PACE = 8;

    /** A lap at or above this many times the field pace says nothing about the car's normal pace. */
    private const MAX_CLEAN_RATIO = 1.6;

    private const CAR_FACTOR_SAMPLE_SIZE = 6;
    private const MIN_LAPS_FOR_CAR_FACTOR = 3;

    /**
     * The field pace is evaluated on a 10-second grid. It moves slowly, and
     * the grid is what keeps a full recompute of a long race fast: thousands
     * of questions share a few hundred answers.
     */
    private const GRID_MS = 10000;

    private int $windowMs;

    /** @var int[] end time of every clean lap of the field, ascending */
    private array $fieldLapEnds = [];

    /** @var int[] duration of the same laps, same order */
    private array $fieldLapDurations = [];

    /** @var array<int, ?float> grid moment => field pace */
    private array $fieldPaceCache = [];

    /** @var array<int, int[]> carro => end times of its laps that qualify for the car factor */
    private array $carLapEnds = [];

    /** @var array<int, float[]> carro => lap ÷ field pace, same order */
    private array $carLapRatios = [];

    /**
     * @param array<int, Lap[]> $lapsByCar
     */
    public function __construct(array $lapsByCar, Settings $settings)
    {
        $this->windowMs = $settings->janelaRitmoS * 1000;

        $cleanLaps = [];
        foreach ($lapsByCar as $laps) {
            foreach ($laps as $lap) {
                if (self::isClean($lap)) {
                    $cleanLaps[] = $lap;
                }
            }
        }
        usort($cleanLaps, fn(Lap $a, Lap $b) => $a->tsMs <=> $b->tsMs);

        foreach ($cleanLaps as $lap) {
            $this->fieldLapEnds[] = $lap->tsMs;
            $this->fieldLapDurations[] = (int) $lap->tempoVoltaMs;
        }

        // Second pass, now that the field pace can be asked: how does each
        // lap compare with the field pace at the moment the lap began?
        foreach ($cleanLaps as $lap) {
            $lapStartMs = $lap->tsMs - (int) $lap->tempoVoltaMs;
            $fieldPace = $this->fieldPaceAt($lapStartMs);
            if ($fieldPace === null || $fieldPace <= 0) {
                continue;
            }
            $ratio = $lap->tempoVoltaMs / $fieldPace;
            if ($ratio < self::MAX_CLEAN_RATIO) {
                $this->carLapEnds[$lap->carro][] = $lap->tsMs;
                $this->carLapRatios[$lap->carro][] = $ratio;
            }
        }
    }

    /** Median lap of the field around $tsMs, in ms. Null while there is too little data. */
    public function fieldPaceAt(int $tsMs): ?float
    {
        $gridMoment = intdiv($tsMs + self::GRID_MS - 1, self::GRID_MS) * self::GRID_MS;
        if (array_key_exists($gridMoment, $this->fieldPaceCache)) {
            return $this->fieldPaceCache[$gridMoment];
        }

        $firstIndex = Stats::countAtMost($this->fieldLapEnds, $gridMoment - $this->windowMs);
        $endIndex = Stats::countAtMost($this->fieldLapEnds, $gridMoment);
        $lapsInWindow = $endIndex - $firstIndex;

        $pace = null;
        if ($lapsInWindow >= self::MIN_LAPS_FOR_FIELD_PACE) {
            $pace = Stats::median(array_slice($this->fieldLapDurations, $firstIndex, $lapsInWindow));
        }

        return $this->fieldPaceCache[$gridMoment] = $pace;
    }

    /**
     * How the car compares to the field, from its last clean laps finished
     * by $tsMs. 1.0 (an average car) until it has enough laps of its own.
     */
    public function carFactorAt(int $carro, int $tsMs): float
    {
        $ends = $this->carLapEnds[$carro] ?? [];
        $lapsFinished = Stats::countAtMost($ends, $tsMs);
        if ($lapsFinished < self::MIN_LAPS_FOR_CAR_FACTOR) {
            return 1.0;
        }
        $firstIndex = max(0, $lapsFinished - self::CAR_FACTOR_SAMPLE_SIZE);
        $recentRatios = array_slice($this->carLapRatios[$carro], $firstIndex, $lapsFinished - $firstIndex);
        return (float) Stats::median($recentRatios);
    }

    /** Expected lap of the car around $tsMs, in ms. Null while the field pace is unknown. */
    public function expectedLapMs(int $carro, int $tsMs): ?int
    {
        $fieldPace = $this->fieldPaceAt($tsMs);
        if ($fieldPace === null) {
            return null;
        }
        return (int) round($fieldPace * $this->carFactorAt($carro, $tsMs));
    }

    private static function isClean(Lap $lap): bool
    {
        return $lap->isCounted()
            && $lap->numeroVolta >= 2
            && $lap->tempoVoltaMs !== null
            && $lap->tempoVoltaMs > 0
            && !$lap->interrompida;
    }
}
