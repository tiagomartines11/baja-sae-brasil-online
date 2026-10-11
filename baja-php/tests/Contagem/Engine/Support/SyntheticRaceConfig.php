<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

/**
 * The knobs of the synthetic race generator. Every rate is "per real pass":
 * 0.02 means roughly one pass in fifty gets that kind of mistake.
 */
final class SyntheticRaceConfig
{
    /** Same seed, same race, every time. A failing seed can be replayed. */
    public int $seed = 1;

    public int $cars = 30;
    public int $raceMinutes = 120;
    /** Lap of an average car on a fresh track, in seconds. */
    public int $baseLapSeconds = 170;
    /** How many human counters (sources 1..N). */
    public int $counters = 3;

    /** A counter does not record a car at all. */
    public float $missRate = 0.02;
    /** A counter types another number (digits swapped when that gives a real car). */
    public float $typoRate = 0.01;
    /** A counter presses Enter twice within three seconds. */
    public float $doubleTapRate = 0.01;
    /** A counter records the same car again 8 to 15 seconds later. */
    public float $repeatRate = 0.004;
    /** A counter records the car 6 to 12 seconds late. */
    public float $lateRate = 0.02;

    /** A car breaks down on track and loses 3 to 10 minutes on that lap. */
    public float $breakdownRate = 0.015;
    /** A car makes a pit stop of 2 to 5 minutes, logged by the pit log. */
    public float $pitStopRate = 0.02;

    /**
     * Periods during which a counter's phone has no connection. It keeps
     * counting, and uploads everything when the period ends.
     *
     * @var array<int, array{source: int, fromSeconds: int, toSeconds: int}>
     */
    public array $offlinePeriods = [];

    public static function withSeed(int $seed): self
    {
        $config = new self();
        $config->seed = $seed;
        return $config;
    }

    /** A race in which the counters make no mistakes at all. */
    public function withoutHumanErrors(): self
    {
        $this->missRate = 0.0;
        $this->typoRate = 0.0;
        $this->doubleTapRate = 0.0;
        $this->repeatRate = 0.0;
        $this->lateRate = 0.0;
        return $this;
    }
}
