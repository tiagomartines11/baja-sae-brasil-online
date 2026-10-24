<?php
declare(strict_types=1);

namespace Baja\Results\Ranking;

use Baja\Results\ResultRow;

/**
 * How the premiação ranks rows (moved here unchanged from
 * Baja\Premiacao\Resolver, which still uses it to rank by any column).
 *
 * The value under $header is read as a number: the first number found in
 * the text once HTML is removed, with "," read as "." ("<b>1,5</b> pts"
 * is 1.5). No number at all, or an empty cell, is "no value". PHP compares
 * "no value" with a number as false/true, so where such rows land is not
 * consistent; it is kept as it was.
 *
 * Highest first, or lowest first when $direction is "asc". With
 * $useTiebreak, equal values are ordered by the row's tiebreak in the same
 * direction. Rows still equal are ordered by car number, lowest first.
 *
 * Positions are "1, 2, 2, 4": a row gets the next position unless its
 * number (and tiebreak, with $useTiebreak) equals the row before.
 */
final class ScoreRanking
{
    /**
     * @param list<ResultRow> $rows
     * @return list<ResultRow> sorted, each with its position
     */
    public static function rank(array $rows, string|int $header, string $direction, bool $useTiebreak): array
    {
        return self::positions(self::sort($rows, $header, $direction, $useTiebreak), $header, $useTiebreak);
    }

    /**
     * @param list<ResultRow> $rows
     * @return list<ResultRow>
     */
    private static function sort(array $rows, string|int $header, string $direction, bool $useTiebreak): array
    {
        usort($rows, function (ResultRow $left, ResultRow $right) use ($header, $direction, $useTiebreak): int {
            $leftValue = self::numericValue($left->display($header));
            $rightValue = self::numericValue($right->display($header));

            if ($leftValue === $rightValue) {
                if ($useTiebreak) {
                    $leftTiebreak = self::tiebreakValue($left);
                    $rightTiebreak = self::tiebreakValue($right);

                    if ($leftTiebreak !== $rightTiebreak) {
                        if ($direction === 'asc') {
                            return ($leftTiebreak < $rightTiebreak) ? -1 : 1;
                        }
                        return ($leftTiebreak > $rightTiebreak) ? -1 : 1;
                    }
                }

                $leftNum = isset($left->team->number) ? (int)$left->team->number : 999999;
                $rightNum = isset($right->team->number) ? (int)$right->team->number : 999999;
                return ($leftNum === $rightNum) ? 0 : (($leftNum < $rightNum) ? -1 : 1);
            }

            if ($direction === 'asc') {
                return ($leftValue < $rightValue) ? -1 : 1;
            }
            return ($leftValue > $rightValue) ? -1 : 1;
        });
        return $rows;
    }

    /**
     * @param list<ResultRow> $rows
     * @return list<ResultRow>
     */
    private static function positions(array $rows, string|int $header, bool $useTiebreak): array
    {
        $position = 0;
        $ordinal = 0;
        $lastValue = null;
        $lastTiebreak = null;
        $ranked = [];
        foreach ($rows as $row) {
            $ordinal++;
            $currentValue = self::numericValue($row->display($header));
            $currentTiebreak = $useTiebreak ? self::tiebreakValue($row) : null;
            if ($lastValue === null || $currentValue !== $lastValue || ($useTiebreak && $currentTiebreak !== $lastTiebreak)) {
                $position = $ordinal;
            }
            $ranked[] = $row->withPosition($position);
            $lastValue = $currentValue;
            $lastTiebreak = $currentTiebreak;
        }
        return $ranked;
    }

    public static function numericValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        $text = strip_tags((string)$value);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace(',', '.', $text);
        if (!preg_match('/-?\d+(?:\.\d+)?/', $text, $matches)) {
            return null;
        }
        return (float)$matches[0];
    }

    private static function tiebreakValue(ResultRow $row): float
    {
        return self::numericValue($row->tiebreak) ?? 0.0;
    }
}
