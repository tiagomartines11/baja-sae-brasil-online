<?php
declare(strict_types=1);

namespace Baja\Rbac;

use DateTimeImmutable;

/** The real time, in the application's time zone. */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
