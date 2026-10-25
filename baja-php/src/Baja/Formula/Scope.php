<?php
declare(strict_types=1);

namespace Baja\Formula;

/**
 * What a formula can see while SafeEngine works it out.
 *
 *   vars     the variables by name, as extract() would make them: the
 *            given array, or the given object's properties
 *   given    the variables as they were given; under eval() the formula
 *            could read them as $vars (unless a variable is called "vars")
 *   formula  the formula as given; under eval(), $formula (same rule)
 *   now      the fixed "now" for time() and date(), or null for the clock
 */
final class Scope
{
    /** @param array<int|string, mixed> $vars */
    public function __construct(
        public readonly array $vars,
        public readonly mixed $given,
        public readonly mixed $formula,
        public readonly ?int $now,
    ) {
    }
}
