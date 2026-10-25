<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * What ResultsBuilder::build() returns: the columns to show, and the rows.
 *
 *   rows            in position order: ranked rows best first, then the
 *                   rows with no position (the premiação's order). When
 *                   nobody is ranked, in the order the inputs were read.
 *   spoilersHidden  whether columns with a "val_spoilers" show that
 *                   instead of their value, for this viewer
 *   rankingHidden   whether the ranking is hidden with them: no row has a
 *                   position or a tiebreak value (ViewerContext::hidesRanking)
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
        public readonly bool $rankingHidden,
    ) {
    }

    /**
     * The rows in the results page's order (R10): by the run's "ts"
     * variable, then by car number. The browser sorts the table again.
     *
     * Kept exactly as the page always ordered: the comparison never says
     * two rows are equal, so rows with the same ts and car number (runs of
     * one team) come out as PHP's sort leaves them.
     *
     * @return list<ResultRow>
     */
    public function pageOrder(): array
    {
        $rows = $this->rows;
        usort($rows, function (ResultRow $a, ResultRow $b): int {
            $ta = $a->variables()['ts'] ?? 0;
            $tb = $b->variables()['ts'] ?? 0;
            if ($ta != $tb) {
                return ($ta > $tb) ? 1 : -1;
            }
            return (($a->variables()['NUM'] ?? null) > ($b->variables()['NUM'] ?? null)) ? 1 : -1;
        });
        return $rows;
    }
}
