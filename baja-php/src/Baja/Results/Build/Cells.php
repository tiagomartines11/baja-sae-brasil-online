<?php
declare(strict_types=1);

namespace Baja\Results\Build;

use Baja\Results\Cell;
use Baja\Results\Column;
use Baja\Results\RawValue;
use Baja\Results\ResultRow;
use Baja\Results\TeamIdentity;

/**
 * Makes the cells of one row, once its columns are worked out and it is
 * ranked. Internal to Baja\Results.
 *
 * The number, team and position columns show the row's own data; every
 * other column shows its value from the entry's variables. See Cell for
 * what "raw" is.
 */
final class Cells
{
    /**
     * @param list<Column> $columns
     * @return array<string|int, Cell>
     */
    public static function of(array $columns, Entry $entry, TeamIdentity $team): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $kind = $column->kind();
            $display = match ($kind) {
                Column::KIND_TEAM_NUMBER => $team->number,
                Column::KIND_TEAM => $team->name,
                Column::KIND_POSITION => $entry->position ?? ResultRow::NO_POSITION,
                default => $entry->variables[$column->key()] ?? null,
            };
            $cells[$column->key()] = new Cell($kind, $display, self::raw($column, $kind, $entry, $display));
        }
        return $cells;
    }

    private static function raw(Column $column, string $kind, Entry $entry, mixed $display): mixed
    {
        $key = $column->key();
        if ($kind === Column::KIND_POSITION) {
            return $entry->position;
        }
        if ($kind !== Column::KIND_FORMULA && $kind !== Column::KIND_LIST) {
            return $display;
        }
        if (array_key_exists($key, $entry->apiValues)) {
            return $entry->apiValues[$key];
        }
        if (array_key_exists($key, $entry->lines)) {
            return array_map([RawValue::class, 'of'], $entry->lines[$key]);
        }
        return RawValue::of($display);
    }
}
