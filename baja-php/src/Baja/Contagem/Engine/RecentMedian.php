<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

/**
 * The median of the last N numbers it was given.
 *
 * Used for a counter's typing habit, which is asked for after every single
 * entry of a race, so it has to be cheap. Sorting the numbers again after
 * each new one would be the slowest step of the engine. It is rarely
 * needed: the middle of a sorted list stays where it is when one number
 * leaves and another arrives on the SAME side of it. Only when the two are
 * on different sides (or touch the middle) are the numbers sorted again.
 */
final class RecentMedian
{
    private int $capacity;

    /** @var int[] the numbers in the order they arrived; the oldest is overwritten once full */
    private array $inArrivalOrder = [];

    /** Position in `$inArrivalOrder` of the oldest number, once full. */
    private int $oldestPosition = 0;

    /**
     * The two numbers in the middle of the sorted list; the median is
     * halfway between them. With an odd count both are the middle number.
     */
    private int $lowerMiddle = 0;
    private int $upperMiddle = 0;

    public function __construct(int $capacity)
    {
        $this->capacity = $capacity;
    }

    public function add(int $value): void
    {
        if (count($this->inArrivalOrder) < $this->capacity) {
            $this->inArrivalOrder[] = $value;
            $this->findTheMiddle();
            return;
        }

        $leaving = $this->inArrivalOrder[$this->oldestPosition];
        $this->inArrivalOrder[$this->oldestPosition] = $value;
        $this->oldestPosition = ($this->oldestPosition + 1) % $this->capacity;

        $bothBelowTheMiddle = $leaving < $this->lowerMiddle && $value < $this->lowerMiddle;
        $bothAboveTheMiddle = $leaving > $this->upperMiddle && $value > $this->upperMiddle;
        if (!$bothBelowTheMiddle && !$bothAboveTheMiddle) {
            $this->findTheMiddle();
        }
    }

    /** Null while no number was given. */
    public function median(): ?float
    {
        if ($this->inArrivalOrder === []) {
            return null;
        }
        return ($this->lowerMiddle + $this->upperMiddle) / 2;
    }

    private function findTheMiddle(): void
    {
        $sorted = $this->inArrivalOrder;
        sort($sorted);
        $count = count($sorted);
        $middle = intdiv($count, 2);
        $this->upperMiddle = $sorted[$middle];
        $this->lowerMiddle = $count % 2 === 1 ? $sorted[$middle] : $sorted[$middle - 1];
    }
}
