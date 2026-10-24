<?php

namespace Baja\Premiacao;

use Baja\Results\ResultRow;

/**
 * Which ranked rows win a premiação category: the "positions" listed (with
 * or without ties), or the first "top_n". Rows with no position are never
 * selected (docs/results-rules.md, R3).
 */
class Selection
{
    /**
     * The rows that win the category, from rows in ranking order.
     *
     * @param list<ResultRow> $rows
     * @return list<ResultRow>
     */
    public static function apply(array $rows, array $categoria)
    {
        // A team with no position (no value to rank by) never wins (R3).
        $rows = array_values(array_filter($rows, function (ResultRow $row) {
            return $row->position !== null;
        }));
        $positions    = self::resolvePositions($categoria);
        $includeTies  = self::resolveIncludeTies($categoria, !empty($positions));

        if (!empty($positions)) {
            return self::selectByPositions($rows, $positions, $includeTies);
        }

        $topN = isset($categoria['top_n']) ? (int)$categoria['top_n'] : count($rows);
        if ($topN <= 0 || $topN >= count($rows)) {
            return $rows;
        }

        $selected = array_slice($rows, 0, $topN);
        if (!$includeTies) {
            return $selected;
        }

        $lastSelected   = end($selected);
        $cutoffPosition = $lastSelected->position;
        if ($cutoffPosition === null) {
            return $selected;
        }

        for ($i = $topN; $i < count($rows); $i++) {
            $position = $rows[$i]->position;
            if ($position !== $cutoffPosition) {
                break;
            }
            $selected[] = $rows[$i];
        }

        return $selected;
    }

    private static function selectByPositions(array $rows, array $positions, $includeTies)
    {
        $positionMap = array();
        foreach ($positions as $position) {
            $positionMap[(int)$position] = true;
        }

        $selected = array();
        $taken    = array();
        foreach ($rows as $row) {
            $position = $row->position;
            if ($position === null || !isset($positionMap[$position])) {
                continue;
            }
            if (!$includeTies && isset($taken[$position])) {
                continue;
            }
            $selected[]       = $row;
            $taken[$position] = true;
        }

        return $selected;
    }

    private static function resolvePositions(array $categoria)
    {
        if (isset($categoria['positions']) && is_array($categoria['positions'])) {
            return self::sanitizeIntArray($categoria['positions']);
        }
        if (isset($categoria['selection']) && is_array($categoria['selection'])) {
            if (isset($categoria['selection']['positions']) && is_array($categoria['selection']['positions'])) {
                return self::sanitizeIntArray($categoria['selection']['positions']);
            }
            if (isset($categoria['selection']['values']) && is_array($categoria['selection']['values'])) {
                return self::sanitizeIntArray($categoria['selection']['values']);
            }
        }
        return array();
    }

    private static function resolveIncludeTies(array $categoria, $default)
    {
        if (array_key_exists('include_ties', $categoria)) {
            return (bool)$categoria['include_ties'];
        }
        if (isset($categoria['selection']) && is_array($categoria['selection']) && array_key_exists('include_ties', $categoria['selection'])) {
            return (bool)$categoria['selection']['include_ties'];
        }
        return $default;
    }

    /** Positive whole numbers, each once, in the order given. */
    public static function sanitizeIntArray(array $values)
    {
        $result = array();
        foreach ($values as $value) {
            $value = (int)$value;
            if ($value <= 0 || in_array($value, $result, true)) {
                continue;
            }
            $result[] = $value;
        }
        return $result;
    }
}
