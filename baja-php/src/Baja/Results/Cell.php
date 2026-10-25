<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * One column's value for one row, in two forms:
 *
 *   display  what the page shows, exactly as it always did. May contain
 *            HTML ("<b>53.9%</b><br /> 14.37").
 *   raw      the same value for a program to read: HTML removed, and a
 *            number when the text is one ("<b>12.5</b>" gives 12.5). A list
 *            column gives a list, one value per line. A column with a
 *            "val_api" formula gives that formula's value instead, except
 *            while spoilers hide the column. See RawValue.
 *
 * kind is Column::kind() of the column it came from.
 */
final class Cell
{
    public function __construct(
        public readonly string $kind,
        public readonly mixed $display,
        public readonly mixed $raw,
    ) {
    }
}
