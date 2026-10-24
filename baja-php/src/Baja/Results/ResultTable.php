<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * What ResultsBuilder::build() returns: the columns to show, and the rows
 * in the order to show them.
 *
 *   spoilersHidden  whether columns with a "val_spoilers" show that
 *                   instead of their value, for this viewer
 */
final class ResultTable
{
    /**
     * @param list<Column> $columns
     * @param list<ResultRow> $rows
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly bool $spoilersHidden,
    ) {
    }
}
