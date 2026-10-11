<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\RecentMedian;
use Baja\Contagem\Engine\Stats;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The little arithmetic the engine is built on.
 *
 * The MEDIAN of some numbers is the one in the middle once they are put in
 * order (or halfway between the two in the middle when there is an even
 * number of them). The engine uses it everywhere instead of the average,
 * because one absurd value must not move the result: of three counters'
 * times 10.0 s, 10.2 s and 17.0 s, the median is 10.2 s and the average
 * 12.4 s.
 */
final class StatsTest extends TestCase
{
    /** @return array<string, array{0: array<int, int|float>, 1: ?float}> */
    public static function medians(): array
    {
        return [
            'no numbers at all' => [[], null],
            'one number' => [[7], 7.0],
            'two numbers: halfway' => [[10, 13], 11.5],
            'three: the middle one, first position' => [[5, 1, 9], 5.0],
            'three: the middle one, second position' => [[1, 5, 9], 5.0],
            'three: the middle one, third position' => [[9, 1, 5], 5.0],
            'three, descending' => [[9, 5, 1], 5.0],
            'three with two equal' => [[4, 4, 9], 4.0],
            'three decimals stay exact' => [[0.8, 0.8, 0.8], 0.8],
            'one late counter of three does not move it' => [[10000, 10200, 17000], 10200.0],
            'four: halfway between the middle two' => [[40, 10, 20, 30], 25.0],
            'five' => [[5, 3, 1, 4, 2], 3.0],
            'keys are irrelevant' => [[7 => 3, 2 => 1, 9 => 2], 2.0],
        ];
    }

    /** @param array<int, int|float> $values */
    #[DataProvider('medians')]
    public function testTheMedianIsTheMiddleValue(array $values, ?float $expected): void
    {
        $this->assertSame($expected, Stats::median($values));
    }

    /**
     * EXPECTED: in an ascending list, "how many numbers are at most X" is
     * also the position of the first number greater than X. The engine
     * uses it to find its place in long sorted lists of times.
     */
    public function testCountAtMostFindsThePlaceInASortedList(): void
    {
        $sorted = [10, 20, 20, 30];

        $this->assertSame(0, Stats::countAtMost($sorted, 9));
        $this->assertSame(1, Stats::countAtMost($sorted, 10));
        $this->assertSame(1, Stats::countAtMost($sorted, 19));
        $this->assertSame(3, Stats::countAtMost($sorted, 20), 'Both 20s are "at most 20".');
        $this->assertSame(4, Stats::countAtMost($sorted, 30));
        $this->assertSame(4, Stats::countAtMost($sorted, 99));
        $this->assertSame(0, Stats::countAtMost([], 5));
    }

    /**
     * SITUATION: RecentMedian keeps "the median of the last N numbers"
     * without sorting them again each time. It is fed a few hundred random
     * numbers (with many repeats), for several sizes of N.
     *
     * EXPECTED: after every single number, exactly what sorting the last N
     * numbers from scratch would give. Before the first number it has no
     * median.
     */
    public function testTheRunningMedianAlwaysEqualsTheMedianOfTheLastNumbers(): void
    {
        $this->assertNull((new RecentMedian(5))->median());

        $random = new Randomizer(new Mt19937(11));
        foreach ([1, 2, 5, 30] as $capacity) {
            $running = new RecentMedian($capacity);
            $all = [];
            for ($i = 0; $i < 400; $i++) {
                // A narrow range in the first half so that equal numbers are common.
                $value = $i < 200 ? $random->getInt(-3, 3) : $random->getInt(-2000, 2000);
                $all[] = $value;
                $running->add($value);

                $this->assertSame(Stats::median(array_slice($all, -$capacity)), $running->median(), "Capacity $capacity, after number $i.");
            }
        }
    }
}
