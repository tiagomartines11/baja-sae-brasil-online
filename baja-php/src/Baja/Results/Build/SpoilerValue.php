<?php
declare(strict_types=1);

namespace Baja\Results\Build;

use Baja\Model\Input;
use Baja\Results\Column;

/**
 * What a column shows while spoilers are hidden: its "val_spoilers"
 * formula, or for a list of formulas one line each (never in bold).
 * Internal to Baja\Results.
 */
final class SpoilerValue
{
    /** @param array<string|int, mixed> $variables */
    public static function of(Column $column, array $variables, Entry $entry): mixed
    {
        if (!is_array($column->valSpoilers)) {
            return Input::solveFormula($variables, $column->valSpoilers);
        }
        $lines = array_map(fn($formula) => Input::solveFormula($variables, $formula), $column->valSpoilers);
        $entry->lines[$column->key()] = $lines;
        return implode('<br /> ', $lines);
    }
}
