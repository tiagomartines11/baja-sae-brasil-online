<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine\Support;

use Random\Randomizer;

/**
 * Part 2 of a synthetic race: which mistakes the counters make, and where.
 *
 * WHAT THE ENGINE CAN DO ABOUT EACH KIND
 *
 * Harmless, or repaired by accepting the engine's suggestions:
 *   miss, typo, double_tap, repeat, late, late_beyond_window,
 *   typo_onto_passing_car, repeat_and_latecomer, and two simple mistakes by
 *   two counters on neighbouring crossings.
 *
 * Only a person can settle (the engine flags them, with no suggestion, and
 * the car stays one lap short until somebody looks at the video):
 *   lone_real_pass   two counters missed the same crossing
 *   miss_and_typo    one missed it and another typed a wrong number
 * In both, a real crossing rests on one counter's word. No algorithm can
 * tell that from an entry that should not exist.
 *
 * RULES THAT KEEP THE RACE HONEST
 *
 * - Unrelated mistakes are at least a minute apart. The combinations above
 *   are the deliberate exceptions.
 * - Two counters never invent the SAME wrong number at the same moment:
 *   that is indistinguishable from a real pass, for any system.
 * - During a handover the source being handed over makes no mistakes (two
 *   people are watching the line for it).
 * - Every kind whose rate is not zero happens at least once per race.
 */
final class SyntheticMistakes
{
    public const MISS = 'miss';
    public const TYPO = 'typo';
    public const DOUBLE_TAP = 'double_tap';
    public const REPEAT = 'repeat';
    public const LATE = 'late';
    public const LATE_BEYOND_WINDOW = 'late_beyond_window';
    public const TYPO_ONTO_PASSING_CAR = 'typo_onto_passing_car';
    public const LONE_REAL_PASS = 'lone_real_pass';
    public const MISS_AND_TYPO = 'miss_and_typo';
    public const REPEAT_AND_LATECOMER = 'repeat_and_latecomer';
    public const NEIGHBOURS = 'neighbours';

    private const SIMPLE_KINDS = [self::MISS, self::TYPO, self::DOUBLE_TAP, self::LATE];

    /** Unrelated mistakes are kept at least this far apart, in ms (three matching windows). */
    private const MIN_GAP_MS = 60000;

    // What one counter does on one crossing instead of typing it normally.
    public const DOES_NOT_TYPE = 'does_not_type';
    public const TYPES_ANOTHER_CAR = 'types_another_car';
    public const TYPES_LATE = 'types_late';
    public const TYPES_AT_EXACTLY = 'types_at_exactly';
    public const TYPES_AGAIN = 'types_again';

    /** @var array<int, array<int, array{does: string, car?: int, ms?: int}>> pass index => source => what it does there */
    public array $actions = [];

    /** @var array<int, array{tipo: string, car: int, tsMs: int, sources: int[]}> every mistake, in time order */
    public array $injected = [];

    /** @var array<int, int> car => laps it is short of the truth until a person settles them */
    public array $lapsOnlyAPersonCanRestore = [];

    /** @var array<int, array{car: int, tsMs: int}> the crossings only a person can settle */
    public array $momentsOnlyAPersonCanSettle = [];

    /** How many times two counters made simple mistakes on neighbouring crossings. */
    public int $neighbouringMistakes = 0;

    /** @var array<int, true> cars involved in any mistake, as the real car or as the wrong number typed */
    public array $carsTouched = [];

    private SyntheticRaceConfig $config;
    private SyntheticTrack $track;
    private Randomizer $random;
    private SyntheticMistakeChoices $choices;

    /** @var array<string, int> kind => the moment from which it is owed to the race */
    private array $owedFromMs = [];

    public static function plan(SyntheticRaceConfig $config, SyntheticTrack $track, Randomizer $random, int $startMs, int $endMs): self
    {
        $mistakes = new self();
        $mistakes->config = $config;
        $mistakes->track = $track;
        $mistakes->random = $random;
        $mistakes->choices = new SyntheticMistakeChoices($config, $track, $random, $startMs);

        $rates = array_filter($config->mistakeRates(), fn(float $rate) => $rate > 0);
        if ($rates !== [] && $config->neighbourMistakeChance > 0) {
            $rates[self::NEIGHBOURS] = 0.0;
        }
        $raceLengthMs = $endMs - $startMs;
        foreach (array_keys($rates) as $kind) {
            $mistakes->owedFromMs[$kind] = $startMs + (int) ($random->getFloat(0.1, 0.8) * $raceLengthMs);
        }

        $lastMistakeMs = PHP_INT_MIN;
        foreach ($track->passes as $passIndex => $pass) {
            $tooSoon = $pass['tsMs'] - $lastMistakeMs < self::MIN_GAP_MS;
            if ($tooSoon || isset($mistakes->actions[$passIndex]) || $pass['tsMs'] > $endMs) {
                continue;
            }
            $kind = $mistakes->owedKindAt($pass['tsMs']) ?? $mistakes->randomKind($rates);
            if ($kind === null) {
                continue;
            }
            if ($mistakes->inject($kind, $passIndex)) {
                unset($mistakes->owedFromMs[$kind]);
                $canHaveNeighbour = in_array($kind, self::SIMPLE_KINDS, true) && $config->neighbourMistakeChance > 0;
                if ($canHaveNeighbour && $random->getFloat(0.0, 1.0) < $config->neighbourMistakeChance) {
                    $mistakes->injectOnTheNextCrossing($passIndex);
                }
            }
            // The gap is counted from the last crossing that got a mistake,
            // which may be the neighbour.
            if (isset($mistakes->actions[$passIndex + 1])) {
                $lastMistakeMs = $track->passes[$passIndex + 1]['tsMs'];
            } elseif (isset($mistakes->actions[$passIndex])) {
                $lastMistakeMs = $pass['tsMs'];
            }
        }
        return $mistakes;
    }

    private function owedKindAt(int $tsMs): ?string
    {
        foreach ($this->owedFromMs as $kind => $fromMs) {
            if ($tsMs >= $fromMs) {
                return $kind;
            }
        }
        return null;
    }

    /** @param array<string, float> $rates */
    private function randomKind(array $rates): ?string
    {
        $roll = $this->random->getFloat(0.0, 1.0);
        foreach ($rates as $kind => $rate) {
            if ($roll < $rate) {
                return $kind;
            }
            $roll -= $rate;
        }
        return null;
    }

    private function inject(string $kind, int $passIndex): bool
    {
        if ($kind === self::NEIGHBOURS) {
            $simpleKind = self::SIMPLE_KINDS[$this->random->getInt(0, count(self::SIMPLE_KINDS) - 1)];
            return $this->inject($simpleKind, $passIndex) && $this->injectOnTheNextCrossing($passIndex);
        }

        $pass = $this->track->passes[$passIndex];
        $twoCounters = in_array($kind, [self::LONE_REAL_PASS, self::MISS_AND_TYPO, self::REPEAT_AND_LATECOMER], true);
        $counters = $this->choices->countersFreeAt($pass['tsMs'], $twoCounters ? 2 : 1, []);
        if ($counters === null || ($twoCounters && $this->config->counters < 3)) {
            return false;
        }
        [$first, $second] = [$counters[0], $counters[1] ?? 0];

        switch ($kind) {
            case self::MISS:
            case self::TYPO:
            case self::DOUBLE_TAP:
            case self::LATE:
                $action = $this->simpleAction($kind, $passIndex, []);
                if ($action === null) {
                    return false;
                }
                $this->actions[$passIndex][$first] = $action;
                break;
            case self::REPEAT:
                $this->actions[$passIndex][$first] = ['does' => self::TYPES_AGAIN, 'ms' => $this->random->getInt(8000, 15000)];
                break;
            case self::LATE_BEYOND_WINDOW:
                $this->actions[$passIndex][$first] = ['does' => self::TYPES_AT_EXACTLY, 'ms' => $this->random->getInt(22500, 30000)];
                break;
            case self::TYPO_ONTO_PASSING_CAR:
                $otherCar = $this->choices->carPassingNear($passIndex);
                if ($otherCar === null) {
                    return false;
                }
                $this->actions[$passIndex][$first] = ['does' => self::TYPES_ANOTHER_CAR, 'car' => $otherCar];
                break;
            case self::LONE_REAL_PASS:
                $this->actions[$passIndex][$first] = ['does' => self::DOES_NOT_TYPE];
                $this->actions[$passIndex][$second] = ['does' => self::DOES_NOT_TYPE];
                $this->onlyAPersonCanSettle($pass);
                break;
            case self::MISS_AND_TYPO:
                $wrongCar = $this->choices->wrongCarFor($passIndex, []);
                if ($wrongCar === null) {
                    return false;
                }
                $this->actions[$passIndex][$first] = ['does' => self::DOES_NOT_TYPE];
                $this->actions[$passIndex][$second] = ['does' => self::TYPES_ANOTHER_CAR, 'car' => $wrongCar];
                $this->onlyAPersonCanSettle($pass);
                break;
            case self::REPEAT_AND_LATECOMER:
                // The repeat opens a second group for the same crossing, and
                // the latecomer lands in it: two counters "agree" on a pass
                // that never happened.
                $this->actions[$passIndex][$first] = ['does' => self::TYPES_AGAIN, 'ms' => $this->random->getInt(10000, 15000)];
                $this->actions[$passIndex][$second] = ['does' => self::TYPES_AT_EXACTLY, 'ms' => $this->random->getInt(22500, 26000)];
                break;
            default:
                return false;
        }

        $this->record($kind, $passIndex);
        return true;
    }

    /** Another counter makes a simple mistake on the crossing right after $passIndex. */
    private function injectOnTheNextCrossing(int $passIndex): bool
    {
        $nextIndex = $passIndex + 1;
        $pass = $this->track->passes[$passIndex];
        $next = $this->track->passes[$nextIndex] ?? null;
        if ($next === null || isset($this->actions[$nextIndex]) || $next['car'] === $pass['car'] || $next['tsMs'] - $pass['tsMs'] > 20000) {
            return false;
        }
        $busyCounters = array_keys($this->actions[$passIndex]);
        $counters = $this->choices->countersFreeAt($next['tsMs'], 1, $busyCounters);
        if ($counters === null) {
            return false;
        }
        $wrongCarsAlreadyTyped = [];
        foreach ($this->actions[$passIndex] as $action) {
            if (isset($action['car'])) {
                $wrongCarsAlreadyTyped[] = $action['car'];
            }
        }
        $kind = self::SIMPLE_KINDS[$this->random->getInt(0, count(self::SIMPLE_KINDS) - 1)];
        $action = $this->simpleAction($kind, $nextIndex, $wrongCarsAlreadyTyped);
        if ($action === null) {
            return false;
        }
        $this->actions[$nextIndex][$counters[0]] = $action;
        $this->record($kind, $nextIndex);
        $this->neighbouringMistakes++;
        unset($this->owedFromMs[self::NEIGHBOURS]);
        return true;
    }

    /**
     * @param int[] $notTheseCars wrong numbers another counter has just typed
     * @return array{does: string, car?: int, ms?: int}|null
     */
    private function simpleAction(string $kind, int $passIndex, array $notTheseCars): ?array
    {
        switch ($kind) {
            case self::MISS:
                return ['does' => self::DOES_NOT_TYPE];
            case self::DOUBLE_TAP:
                return ['does' => self::TYPES_AGAIN, 'ms' => $this->random->getInt(300, 3000)];
            case self::LATE:
                return ['does' => self::TYPES_LATE, 'ms' => $this->random->getInt(6000, 12000)];
            default:
                $wrongCar = $this->choices->wrongCarFor($passIndex, $notTheseCars);
                return $wrongCar === null ? null : ['does' => self::TYPES_ANOTHER_CAR, 'car' => $wrongCar];
        }
    }

    /** @param array{car: int, tsMs: int, official: bool} $pass */
    private function onlyAPersonCanSettle(array $pass): void
    {
        $this->lapsOnlyAPersonCanRestore[$pass['car']] = ($this->lapsOnlyAPersonCanRestore[$pass['car']] ?? 0) + 1;
        $this->momentsOnlyAPersonCanSettle[] = ['car' => $pass['car'], 'tsMs' => $pass['tsMs']];
    }

    private function record(string $kind, int $passIndex): void
    {
        $pass = $this->track->passes[$passIndex];
        $this->injected[] = [
            'tipo' => $kind,
            'car' => $pass['car'],
            'tsMs' => $pass['tsMs'],
            'sources' => array_keys($this->actions[$passIndex]),
        ];
        $this->carsTouched[$pass['car']] = true;
        foreach ($this->actions[$passIndex] as $action) {
            if (isset($action['car'])) {
                $this->carsTouched[$action['car']] = true;
            }
        }
    }
}
