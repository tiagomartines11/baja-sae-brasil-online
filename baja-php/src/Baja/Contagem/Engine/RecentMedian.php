<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

/**
 * The median of the last N numbers it was given.
 *
 * Used for a counter's typing habit, which is asked for after every single
 * entry of a race, so it has to be cheap. Sorting the numbers again after
 * each new one was the slowest step of the whole engine. Instead the
 * numbers are kept in two lists: in the order they arrived (to know which
 * one leaves next) and sorted (to read the median off the middle). When a
 * number arrives, the one that leaves gives up its place in the sorted
 * list, and the new one slides from there to where it belongs, which is
 * usually only a few places away.
 */
final class RecentMedian
{
    private int $capacity;

    /** @var int[] the numbers in the order they arrived; the oldest is overwritten once full */
    private array $inArrivalOrder = [];

    /** Position in `$inArrivalOrder` of the oldest number, once full. */
    private int $oldestPosition = 0;

    /** @var int[] the same numbers, smallest first */
    private array $sorted = [];

    public function __construct(int $capacity)
    {
        $this->capacity = $capacity;
    }

    public function add(int $value): void
    {
        $this->addAndRound($value);
    }

    /**
     * Adds a number and returns the new median rounded to a whole number
     * (a half rounds away from zero, as round() does): what the habit loop
     * needs after every entry, in one call.
     */
    public function addAndRound(int $value): int
    {
        // The sorted list is worked on as a local variable and put back at
        // the end; emptying the property first avoids copying the list.
        $sorted = $this->sorted;
        $this->sorted = [];
        $count = count($sorted);

        if ($count < $this->capacity) {
            $this->inArrivalOrder[] = $value;
            $sorted[] = $value;
            $position = $count;
            $last = $count;
        } else {
            $leaving = $this->inArrivalOrder[$this->oldestPosition];
            $this->inArrivalOrder[$this->oldestPosition] = $value;
            $this->oldestPosition = ($this->oldestPosition + 1) % $this->capacity;
            // The new number takes the place of the one that leaves...
            $position = (int) array_search($leaving, $sorted, true);
            $last = $count - 1;
        }

        // ...and slides down or up until its neighbours are in order.
        while ($position > 0 && $sorted[$position - 1] > $value) {
            $sorted[$position] = $sorted[$position - 1];
            $position--;
        }
        while ($position < $last && $sorted[$position + 1] < $value) {
            $sorted[$position] = $sorted[$position + 1];
            $position++;
        }
        $sorted[$position] = $value;
        $this->sorted = $sorted;

        $middle = ($last + 1) >> 1;
        if ($last % 2 === 0) {
            // An odd count: the middle number itself.
            return $sorted[$middle];
        }
        // Halfway between the two middle numbers, a half rounding away
        // from zero as round() does, in whole-number arithmetic.
        $sum = $sorted[$middle - 1] + $sorted[$middle];
        return intdiv($sum + ($sum >= 0 ? 1 : -1), 2);
    }

    /** Null while no number was given. */
    public function median(): ?float
    {
        $count = count($this->sorted);
        if ($count === 0) {
            return null;
        }
        $middle = $count >> 1;
        // With an odd count the median is the middle number; with an even
        // count it is halfway between the two in the middle.
        return $count % 2 === 1
            ? (float) $this->sorted[$middle]
            : ($this->sorted[$middle - 1] + $this->sorted[$middle]) / 2;
    }
}
