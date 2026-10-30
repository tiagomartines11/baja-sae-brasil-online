<?php
declare(strict_types=1);

namespace Baja\Tests\Support;

use Baja\Rbac\Clock;
use DateTimeImmutable;

/** A clock that says what the test tells it to, and moves only when told. */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now)
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function moveTo(string $now): void
    {
        $this->now = new DateTimeImmutable($now);
    }
}
