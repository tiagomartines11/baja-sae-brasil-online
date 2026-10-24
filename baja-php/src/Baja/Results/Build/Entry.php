<?php
declare(strict_types=1);

namespace Baja\Results\Build;

use Baja\Results\TeamInput;

/**
 * One row while it is being worked out; the builder turns it into a
 * ResultRow at the end. Internal to Baja\Results.
 *
 *   variables   what the formulas can read: the team's merged inputs plus
 *               this run's own values. On the page, every column's value
 *               is written back in under its header, so a later column can
 *               use an earlier one ($Total).
 *   values      column key => value, for behaviours that keep the column
 *               values apart from the variables (the premiação)
 *   lines       column key => one value per line, for list columns, before
 *               the highlight is applied (the raw value is made from these)
 *   apiValues   column key => the value of its "val_api" formula
 *   tiebreak    the value of the resultado's "tiebreak" formula
 *   position    set by the ranking
 */
final class Entry
{
    /** @var array<string|int, mixed> */
    public array $values = [];
    /** @var array<string|int, list<mixed>> */
    public array $lines = [];
    /** @var array<string|int, mixed> */
    public array $apiValues = [];
    public mixed $tiebreak = null;
    public ?int $position = null;

    /** @param array<string|int, mixed> $variables */
    public function __construct(
        public array $variables,
        public readonly mixed $teamId,
        public readonly TeamInput $firstInput,
    ) {
    }
}
