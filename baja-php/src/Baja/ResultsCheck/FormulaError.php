<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

/**
 * One error a formula raised: the formula as written in the config, and
 * the error as "Class: message" ("TypeError: Unsupported operand types:
 * string + float").
 */
final class FormulaError
{
    public function __construct(
        public readonly mixed $formula,
        public readonly string $message,
    ) {
    }
}
