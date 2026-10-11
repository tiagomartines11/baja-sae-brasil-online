<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

/**
 * The knobs of the synthetic race generator. Every rate is "per real
 * crossing": 0.02 means roughly one crossing in fifty gets that kind of
 * mistake. Whatever the rates, a race contains every kind of mistake whose
 * rate is not zero at least once (see SyntheticMistakes).
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

    // ----- one counter gets one crossing wrong -----

    /** A counter does not record a car at all. */
    public float $missRate = 0.02;
    /** A counter types another number (digits swapped when that gives a real car). */
    public float $typoRate = 0.01;
    /** A counter presses Enter twice within three seconds. */
    public float $doubleTapRate = 0.01;
    /** A counter records the same car again 8 to 15 seconds later. */
    public float $repeatRate = 0.004;
    /** A counter records the car 6 to 12 seconds late (still inside the matching window). */
    public float $lateRate = 0.02;
    /** A counter records the car 22 to 30 seconds late (outside the matching window). */
    public float $lateBeyondWindowRate = 0.003;
    /** A counter types the number of ANOTHER car that is crossing within 15 seconds. */
    public float $typoOntoPassingCarRate = 0.003;

    // ----- two counters get the same crossing wrong -----

    /** Two counters miss the same crossing; only one saw it. Only a person can settle it. */
    public float $loneRealPassRate = 0.002;
    /** One counter misses the crossing and another types a wrong number. Only a person can settle it. */
    public float $missAndTypoRate = 0.002;
    /** One counter records the car again 10 to 15 s later and another records it 22 to 26 s late. */
    public float $repeatAndLatecomerRate = 0.003;

    /**
     * After a simple mistake (miss, typo, double tap, late), the chance that
     * ANOTHER counter makes a simple mistake on the very next crossing, a few
     * seconds later.
     */
    public float $neighbourMistakeChance = 0.25;

    // ----- racing incidents, which are not mistakes -----

    /** A car breaks down on track and loses 3 to 10 minutes on that lap. */
    public float $breakdownRate = 0.015;
    /** A car makes a pit stop of 2 to 5 minutes, logged by the pit log. */
    public float $pitStopRate = 0.02;

    /**
     * Race control ends the race at `raceMinutes`. Every car then crosses
     * the line once more (that pass counts), and some cross it a second
     * time on their way in (that one does not).
     */
    public bool $finishAfterRace = false;

    /**
     * Periods during which a counter's phone has no connection. It keeps
     * counting, and uploads everything when the period ends.
     *
     * @var array<int, array{source: int, fromSeconds: int, toSeconds: int}>
     */
    public array $offlinePeriods = [];

    /**
     * Handovers: for the given period a second person counts on the same
     * source with their own phone, and both record every crossing. After
     * it, only the newcomer's phone goes on.
     *
     * @var array<int, array{source: int, fromSeconds: int, toSeconds: int}>
     */
    public array $handovers = [['source' => 1, 'fromSeconds' => 50 * 60, 'toSeconds' => 56 * 60]];

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
        $this->lateBeyondWindowRate = 0.0;
        $this->typoOntoPassingCarRate = 0.0;
        $this->loneRealPassRate = 0.0;
        $this->missAndTypoRate = 0.0;
        $this->repeatAndLatecomerRate = 0.0;
        $this->neighbourMistakeChance = 0.0;
        return $this;
    }

    public function withFinish(): self
    {
        $this->finishAfterRace = true;
        return $this;
    }

    /** A race of the given size with no incidents and no handover, for timing the engine. */
    public static function forTiming(int $seed, int $cars, int $counters, int $raceMinutes, int $baseLapSeconds): self
    {
        $config = self::withSeed($seed);
        $config->cars = $cars;
        $config->counters = $counters;
        $config->raceMinutes = $raceMinutes;
        $config->baseLapSeconds = $baseLapSeconds;
        $config->handovers = [];
        return $config;
    }

    /** @return array<string, float> kind of mistake => rate per crossing */
    public function mistakeRates(): array
    {
        return [
            SyntheticMistakes::MISS => $this->missRate,
            SyntheticMistakes::TYPO => $this->typoRate,
            SyntheticMistakes::DOUBLE_TAP => $this->doubleTapRate,
            SyntheticMistakes::REPEAT => $this->repeatRate,
            SyntheticMistakes::LATE => $this->lateRate,
            SyntheticMistakes::LATE_BEYOND_WINDOW => $this->lateBeyondWindowRate,
            SyntheticMistakes::TYPO_ONTO_PASSING_CAR => $this->typoOntoPassingCarRate,
            SyntheticMistakes::LONE_REAL_PASS => $this->loneRealPassRate,
            SyntheticMistakes::MISS_AND_TYPO => $this->missAndTypoRate,
            SyntheticMistakes::REPEAT_AND_LATECOMER => $this->repeatAndLatecomerRate,
        ];
    }
}
