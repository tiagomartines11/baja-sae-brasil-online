<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

/**
 * The little arithmetic the engine needs. The median is used everywhere
 * instead of the average because one absurd value (a counter who typed a
 * number ten seconds late, a lap that includes a breakdown) must not move
 * the result.
 */
final class Stats
{
    /**
     * @param array<int, int|float> $values
     */
    public static function median(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        $sorted = array_values($values);
        if ($count === 3) {
            // Nearly every median the engine takes is of the three times of
            // one pass. The middle of three needs no sorting: it is the
            // larger of "the smaller of the first two" and "the smaller of
            // the third and the larger of the first two".
            [$first, $second, $third] = $sorted;
            return (float) max(min($first, $second), min(max($first, $second), $third));
        }
        sort($sorted);
        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $sorted[$middle];
        }
        return ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }

    /**
     * Index of the first element greater than $value in an ascending list
     * (the number of elements that are <= $value).
     *
     * @param array<int, int> $sorted
     */
    public static function countAtMost(array $sorted, int $value): int
    {
        $low = 0;
        $high = count($sorted);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($sorted[$middle] <= $value) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        return $low;
    }
}
