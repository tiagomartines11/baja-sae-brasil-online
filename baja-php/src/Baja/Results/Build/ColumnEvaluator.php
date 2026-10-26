<?php
declare(strict_types=1);

namespace Baja\Results\Build;

use Baja\Model\Input;
use Baja\Results\Column;
use Baja\Results\ResultsConfig;

/**
 * Works out every column of one entry, then its tiebreak. Internal to
 * Baja\Results; the rules are in docs/results-rules.md.
 *
 * Columns go in order, and each value is written into the entry's
 * variables under the column's header (R1), so a later column can read an
 * earlier one: "Total" with "$Pontos + $Bonus" reads the columns "Pontos"
 * and "Bonus". Columns with no header are kept, under "" (R9).
 *
 *   - while spoilers are hidden, a column with a "val_spoilers" shows that
 *     (one line per formula for a list, never in bold);
 *   - "EquipeNum" and "Equipe" are the car number and team name; "Pos" is
 *     the position, unknown until the ranking, so later columns see null;
 *   - a list of formulas gives one line each, joined with "<br /> ". With a
 *     "highlight" (R6), lines equal to its value are put in bold;
 *   - anything else is one formula.
 *
 * The tiebreak formula comes last and so can read every column (R1).
 */
final class ColumnEvaluator
{
    public static function evaluate(Entry $entry, ResultsConfig $config, bool $hideSpoilers): void
    {
        foreach ($config->columns as $column) {
            $key = $column->key();
            unset($entry->lines[$key], $entry->apiValues[$key]);
            $variables = $entry->variables;

            if ($hideSpoilers && $column->hasSpoilerValue()) {
                $entry->variables[$key] = SpoilerValue::of($column, $variables, $entry);
                continue;
            }

            $kind = $column->kind();
            $entry->variables[$key] = match ($kind) {
                Column::KIND_TEAM_NUMBER => $variables['NUM'] ?? null,
                Column::KIND_TEAM => $variables['EQUIPE'] ?? null,
                Column::KIND_POSITION => null,
                Column::KIND_LIST => self::lines($column, $variables, $entry),
                default => Input::solveFormula($variables, $column->val),
            };
            $isFormula = $kind === Column::KIND_LIST || $kind === Column::KIND_FORMULA;
            if ($isFormula && $column->valApi !== null) {
                $entry->apiValues[$key] = Input::solveFormula($variables, $column->valApi);
            }
        }

        $tiebreak = $config->tiebreakFormula();
        $entry->tiebreak = $tiebreak === null ? null : Input::solveFormula($entry->variables, $tiebreak);
    }

    /**
     * One line per formula. A line is bold when it equals the highlight's
     * value; a highlight that gives nothing (null) puts nothing in bold.
     *
     * @param array<string|int, mixed> $variables
     */
    private static function lines(Column $column, array $variables, Entry $entry): string
    {
        $formula = $column->highlightFormula();
        $highlight = $formula === null ? null : Input::solveFormula($variables, $formula);
        $lines = array_map(fn($line) => Input::solveFormula($variables, $line), $column->val);
        $entry->lines[$column->key()] = $lines;
        return implode('<br /> ', array_map(
            fn($line) => ($highlight !== null && $line == $highlight) ? "<b>$line</b>" : $line,
            $lines,
        ));
    }
}
