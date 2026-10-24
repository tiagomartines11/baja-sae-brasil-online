<?php
declare(strict_types=1);

namespace Baja\Results\Ranking;

use Baja\Results\Build\Entry;

/**
 * How the results page ranks its rows.
 *
 * "pos" names what to rank by: usually a column header ("Total"), but any
 * variable works. Its value is read as a number after removing HTML
 * ("<b>12.5</b>" is 12.5; text that does not start with a number is 0).
 * Highest first, or lowest first when "pos_order" is exactly "asc".
 * Equal values are ordered by the "tiebreak" variable, in the same
 * direction; rows still equal keep the order they came in.
 *
 * Positions are "1, 2, 2, 4": a row gets the next position unless its
 * value AND its tiebreak are the same as the row before, compared as they
 * are, not as numbers. So "10" and "10.0" are different positions even
 * though they sort as equal.
 *
 * The position is also written into the variables as POS.
 */
final class PageRanking
{
    /** @param list<Entry> $entries ranked in place */
    public static function rank(array &$entries, mixed $pos, mixed $posOrder): void
    {
        if (!$pos) {
            return;
        }
        $direction = ($posOrder == 'asc') ? 1 : -1;
        usort($entries, function (Entry $a, Entry $b) use ($pos, $direction): int {
            // Only $a is checked; a missing $b counts as 0.
            if (!array_key_exists($pos, $a->variables)) {
                return 0;
            }
            $va = self::number($a->variables[$pos]);
            $vb = self::number($b->variables[$pos] ?? null);
            if ($va == $vb) {
                $ta = floatval($a->variables['tiebreak'] ?? 0);
                $tb = floatval($b->variables['tiebreak'] ?? 0);
                if ($ta == $tb) {
                    return 0;
                }
                return ($ta > $tb) ? $direction : -$direction;
            }
            return ($va > $vb) ? $direction : -$direction;
        });

        $position = 0;
        $ordinal = 0;
        $lastValue = null;
        $lastTiebreak = null;
        foreach ($entries as $entry) {
            $ordinal++;
            $value = $entry->variables[$pos] ?? null;
            $tiebreak = $entry->variables['tiebreak'] ?? 0;
            if ($lastValue !== $value || $lastTiebreak !== $tiebreak) {
                $position = $ordinal;
            }
            $entry->position = $position;
            $entry->variables['POS'] = $position;
            $lastValue = $value;
            $lastTiebreak = $tiebreak;
        }
    }

    private static function number(mixed $value): float
    {
        return floatval(strip_tags((string)$value));
    }
}
