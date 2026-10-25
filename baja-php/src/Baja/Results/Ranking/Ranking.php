<?php
declare(strict_types=1);

namespace Baja\Results\Ranking;

use Baja\Results\ResultRow;
use Baja\Results\Rules\Numbers;

/**
 * Who is 1st, 2nd, 3rd (docs/results-rules.md, R2 to R5). The results
 * pages and the premiação both rank with this, the premiação also by any
 * column it picks ("column_top").
 *
 *   - Each row's value is read as a number (Numbers::read, R2) from the
 *     variable $key: the column with that header, or else a formula
 *     variable of that name (R5). Highest first, or lowest first with "asc".
 *   - Rows with no value get no position (shown "–"). They come after all
 *     ranked rows, by car number, and never tie with anyone (R3).
 *   - Equal values share a position and the next one is skipped: 1, 2, 2, 4.
 *     With $useTiebreak, the tiebreak (also read as a number) separates
 *     them, in the same direction; a row with a tiebreak value goes before
 *     one without. Rows still equal keep the shared position and are listed
 *     by car number (R4).
 */
final class Ranking
{
    /**
     * @param list<ResultRow> $rows
     * @param string $direction "asc" or "desc"
     * @return list<ResultRow> ranked rows best first, then the rows with no value
     */
    public static function rank(array $rows, string|int $key, string $direction, bool $useTiebreak): array
    {
        $ranked = [];
        $unranked = [];
        foreach ($rows as $row) {
            $value = Numbers::read($row->variables()[$key] ?? null);
            if ($value === null) {
                $unranked[] = $row->withPosition(null);
            } else {
                $ranked[] = [$row, $value, $useTiebreak ? Numbers::read($row->tiebreak) : null];
            }
        }

        $sign = $direction === 'asc' ? 1 : -1;
        usort($ranked, function (array $a, array $b) use ($sign): int {
            return $sign * ($a[1] <=> $b[1])
                ?: self::compareTiebreaks($a[2], $b[2], $sign)
                ?: self::compareCarNumbers($a[0], $b[0]);
        });
        usort($unranked, [self::class, 'compareCarNumbers']);

        $result = [];
        $position = 0;
        $previous = null;
        foreach ($ranked as $ordinal => [$row, $value, $tiebreak]) {
            if ($previous === null || $previous[0] != $value || $previous[1] !== $tiebreak) {
                $position = $ordinal + 1;
            }
            $result[] = $row->withPosition($position);
            $previous = [$value, $tiebreak];
        }
        return array_merge($result, $unranked);
    }

    /** Same direction as the value; a tiebreak with a value goes before one without. */
    private static function compareTiebreaks(?float $a, ?float $b, int $sign): int
    {
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }
        return $sign * ($a <=> $b);
    }

    /** Lowest car number first; a row with no car number last. */
    public static function compareCarNumbers(ResultRow $a, ResultRow $b): int
    {
        return [$a->team->number === null, (int)$a->team->number] <=> [$b->team->number === null, (int)$b->team->number];
    }
}
