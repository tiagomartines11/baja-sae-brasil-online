<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * One row of a results table: one run of one team (a prova with several
 * runs gives a team several rows).
 *
 *   team       who it is
 *   cells      one Cell per column header. Two columns with the same
 *              header share one cell, the later one's, as on the page.
 *   position   1 for the best, equal for a tie; null when the row has no
 *              value to rank by, the resultado ranks nothing ("pos" not
 *              set), or the ranking is hidden while spoilers apply
 *              (ViewerContext::hidesRanking). A "Pos" column then shows
 *              NO_POSITION.
 *   tiebreak   the value of the "tiebreak" formula, if there is one; null
 *              while the ranking is hidden
 *   variables  the formula variables the row was computed from
 */
final class ResultRow
{
    /** What a "Pos" column shows for a row with no position (R3). */
    public const NO_POSITION = '–';

    /**
     * @param array<string|int, Cell> $cells
     * @param array<string|int, mixed> $variables
     */
    public function __construct(
        public readonly TeamIdentity $team,
        private readonly array $cells,
        public readonly ?int $position,
        public readonly mixed $tiebreak,
        private readonly array $variables,
    ) {
    }

    /** @return array<string|int, Cell> keyed by header */
    public function cells(): array
    {
        return $this->cells;
    }

    public function cell(string|int $header): ?Cell
    {
        return $this->cells[$header] ?? null;
    }

    /** What the page shows under $header; null when there is no such column. */
    public function display(string|int $header): mixed
    {
        return $this->cell($header)?->display;
    }

    /** @return array<string|int, mixed> */
    public function variables(): array
    {
        return $this->variables;
    }

    /** The same row at another position; its "Pos" columns follow. */
    public function withPosition(?int $position): self
    {
        $cells = $this->cells;
        foreach ($cells as $header => $cell) {
            if ($cell->kind === Column::KIND_POSITION) {
                $cells[$header] = new Cell(Column::KIND_POSITION, $position ?? self::NO_POSITION, $position);
            }
        }
        return new self($this->team, $cells, $position, $this->tiebreak, $this->variables);
    }
}
