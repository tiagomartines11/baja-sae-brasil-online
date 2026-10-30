<?php
declare(strict_types=1);

namespace Baja\Rbac;

use DateTimeImmutable;

/**
 * "Now", for deciding whether a grant has expired. Tests give a fixed one,
 * so that "expired" does not depend on the day the test runs.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
