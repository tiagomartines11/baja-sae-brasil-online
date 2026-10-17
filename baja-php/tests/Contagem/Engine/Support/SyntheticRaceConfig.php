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
    /**
     * A counter types the number of another car that crosses the line 15 to
     * 25 seconds earlier or later: at the edge of the matching window. The
     * stray entry then sits one window away from that car's real entries,
     * where a careless matcher makes two passes out of one crossing.
     */
    public float $windowEdgeTypoRate = 0.003;

    // ----- two counters get the same crossing wrong -----

    /** Two counters miss the same crossing; only one saw it. Only a person can settle it. */
    public float $loneRealPassRate = 0.002;
    /** One counter misses the crossing and another types a wrong number. Only a person can settle it. */
    public float $missAndTypoRate = 0.002;
    /** One counter records the car again 10 to 15 s later and another records it 22 to 26 s late. */
    public float $repeatAndLatecomerRate = 0.003;
    /**
     * Two counters type the SAME wrong number for a crossing; the third
     * types the right one. Two counters "agree" on a pass of a car that is
     * somewhere else on the track. No system that counts by two-of-three
     * agreement can always tell this from a real pass, so it is OFF by
     * default and has tests of its own (see FullRaceTest).
     */
    public float $sharedTypoRate = 0.0;

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
    /** A car refuels for 1 to 3 minutes, logged by the fueling station log. */
    public float $fuelStopRate = 0.01;

    // ----- race-day trouble: not the counters' mistakes, but work for a reviewer -----

    /**
     * Race control records the start a moment LATE: the first cars (up to
     * three) have already crossed the counting line by then, so their
     * first crossing was typed before the recorded start.
     */
    public bool $startRecordedLate = false;

    /**
     * This many teams are present but not classified (numbers after the
     * last regular car) and go out anyway for three laps. Every counter
     * types them. They must never count.
     */
    public int $unclassifiedCarsOnTrack = 0;

    /**
     * Periods during which a counter's phone stamps its entries with an
     * ESTIMATED time (typed offline, page reloaded): each entry is
     * `errorMs` off. The counter itself types flawlessly meanwhile.
     *
     * @var array<int, array{source: int, fromSeconds: int, toSeconds: int, errorMs: int}>
     */
    public array $estimatedTimePeriods = [];

    /**
     * Periods during which a counter's phone has NO usable time: each
     * entry carries the phone's own clock, `errorMs` away from the truth.
     * Nobody makes a mistake meanwhile, so that every crossing still has
     * two good entries.
     *
     * @var array<int, array{source: int, fromSeconds: int, toSeconds: int, errorMs: int}>
     */
    public array $unknownTimePeriods = [];

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
        $this->windowEdgeTypoRate = 0.0;
        $this->sharedTypoRate = 0.0;
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

    /**
     * Adds everything a race day can bring that is nobody's typing mistake
     * and still needs a reviewer: a start recorded late, two unclassified
     * cars on track, four minutes of estimated times on counter 3 and two
     * minutes of unknown times (the phone's calendar is three days behind)
     * on counter 2.
     */
    public function withRaceDayTrouble(): self
    {
        $this->startRecordedLate = true;
        $this->unclassifiedCarsOnTrack = 2;
        $this->estimatedTimePeriods = [['source' => 3, 'fromSeconds' => 20 * 60, 'toSeconds' => 24 * 60, 'errorMs' => 1200]];
        $this->unknownTimePeriods = [['source' => 2, 'fromSeconds' => 70 * 60, 'toSeconds' => 72 * 60, 'errorMs' => -3 * 24 * 3600 * 1000]];
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
        // The timing races are kept exactly as they were when the budgets
        // were set, so that times can be compared from round to round.
        $config->fuelStopRate = 0.0;
        $config->windowEdgeTypoRate = 0.0;
        return $config;
    }

    /** A race in which the only mistake the counters make is the given one, at the given rate. */
    public function withOnly(string $kind, float $rate): self
    {
        $this->withoutHumanErrors();
        $property = [
            SyntheticMistakes::MISS => 'missRate',
            SyntheticMistakes::TYPO => 'typoRate',
            SyntheticMistakes::DOUBLE_TAP => 'doubleTapRate',
            SyntheticMistakes::REPEAT => 'repeatRate',
            SyntheticMistakes::LATE => 'lateRate',
            SyntheticMistakes::LATE_BEYOND_WINDOW => 'lateBeyondWindowRate',
            SyntheticMistakes::TYPO_ONTO_PASSING_CAR => 'typoOntoPassingCarRate',
            SyntheticMistakes::WINDOW_EDGE_TYPO => 'windowEdgeTypoRate',
            SyntheticMistakes::LONE_REAL_PASS => 'loneRealPassRate',
            SyntheticMistakes::MISS_AND_TYPO => 'missAndTypoRate',
            SyntheticMistakes::REPEAT_AND_LATECOMER => 'repeatAndLatecomerRate',
            SyntheticMistakes::SHARED_TYPO => 'sharedTypoRate',
        ][$kind];
        $this->$property = $rate;
        return $this;
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
            SyntheticMistakes::WINDOW_EDGE_TYPO => $this->windowEdgeTypoRate,
            SyntheticMistakes::SHARED_TYPO => $this->sharedTypoRate,
        ];
    }
}
