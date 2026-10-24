<?php
declare(strict_types=1);

namespace Baja\Results\Behaviour;

use Baja\Model\Input;
use Baja\Results\Build\Cells;
use Baja\Results\Build\Entry;
use Baja\Results\Build\SpoilerValue;
use Baja\Results\Column;
use Baja\Results\Ranking\ScoreRanking;
use Baja\Results\ResultRow;
use Baja\Results\ResultsConfig;
use Baja\Results\TeamIdentity;
use Baja\Results\TeamInput;

/**
 * How the premiação (Baja\Premiacao\Resolver) works out a resultado's table.
 *
 * It does the same as PageBehaviour, except for these differences, which
 * are kept so that no award changes in this refactor:
 *
 *   1. A column cannot use an earlier column by its header: column values
 *      are kept apart from the formula variables. (On the page, a column
 *      "Total" with $Pontos + $Bonus reads the columns "Pontos" and "Bonus";
 *      here it reads input variables of those names, if any.) The tiebreak
 *      cannot read columns either.
 *   2. Ranking is ScoreRanking, not PageRanking: the first number in the
 *      text ("1,5 pts" is 1.5, where the page reads 1), "pos" must be a
 *      column header (the page also accepts any variable), "pos_order" is
 *      not case sensitive, ties left after the tiebreak go by car number
 *      (the page keeps the incoming order), and equal numbers always share
 *      a position (the page compares the text: "10" and "10.0" differ).
 *   3. A list column with no "highlight" puts nothing in bold (the page puts
 *      empty and 0 lines in bold). A highlight written as "" or "0" is a
 *      formula here; the page ignores it.
 *   4. A "tiebreak" of "0" is a formula here; the page ignores it.
 *   5. Columns with no header are left out.
 *   6. Rows stay in ranking order (the page orders them by "ts", then car
 *      number).
 *   7. The formulas can read TEAM_ID (the team's id), not DESCLASSIFICADO;
 *      the page is the other way round.
 *   8. "entries" that are a JSON object, not a list, give no rows (the page
 *      takes the object's values as the runs).
 *
 * Spoilers are not one of the differences: they are the viewer's to decide
 * (ViewerContext::premiacao() never hides them, as the premiação never did).
 */
final class PremiacaoBehaviour implements Behaviour
{
    public function teamVariables(TeamInput $firstInput): array
    {
        return [
            'TEAM_ID' => $firstInput->teamId,
            'NUM' => $firstInput->number,
            'EQUIPE' => $firstInput->name,
            'ESCOLA' => $firstInput->school,
            'ESTADO' => $firstInput->state,
        ];
    }

    public function runsOf(array $team): iterable
    {
        $runs = $team['entries'] ?? null;
        return is_array($runs) ? $runs : [];
    }

    public function evaluate(Entry $entry, ResultsConfig $config, bool $hideSpoilers): void
    {
        $variables = $entry->variables;
        foreach (self::columns($config) as $column) {
            $key = $column->key();
            unset($entry->lines[$key], $entry->apiValues[$key]);

            if ($hideSpoilers && $column->valSpoilers) {
                $entry->values[$key] = SpoilerValue::of($column, $variables, $entry);
                continue;
            }

            $entry->values[$key] = match ($column->kind()) {
                Column::KIND_TEAM_NUMBER => $variables['NUM'] ?? null,
                Column::KIND_TEAM => $variables['EQUIPE'] ?? null,
                Column::KIND_POSITION => null,
                Column::KIND_LIST => $this->listValue($column, $variables, $entry),
                default => Input::solveFormula($variables, $column->val),
            };
            $isFormula = in_array($column->kind(), [Column::KIND_LIST, Column::KIND_FORMULA], true);
            if ($isFormula && $column->valApi !== null) {
                $entry->apiValues[$key] = Input::solveFormula($variables, $column->valApi);
            }
        }

        if (self::usesTiebreak($config)) {
            $entry->tiebreak = Input::solveFormula($variables, $config->tiebreak);
        }
    }

    public function rows(array $entries, ResultsConfig $config): array
    {
        $columns = self::columns($config);
        $rows = [];
        foreach ($entries as $entry) {
            $team = $this->team($entry);
            $cells = Cells::of($columns, $entry, $team, fn(Column $column) => $entry->values[$column->key()] ?? null);
            $rows[] = new ResultRow($team, $cells, null, $entry->tiebreak, $entry->variables);
        }

        if ($config->pos) {
            $rows = ScoreRanking::rank($rows, self::header($config->pos), self::direction($config), self::usesTiebreak($config));
        }
        return $rows;
    }

    /** "pos_order" in lower case; highest first ("desc") when missing. */
    public static function direction(ResultsConfig $config): string
    {
        return $config->posOrder !== null ? strtolower((string)$config->posOrder) : 'desc';
    }

    /** Whether a "tiebreak" formula is written (blank text is none). */
    public static function usesTiebreak(ResultsConfig $config): bool
    {
        return $config->tiebreak !== null && trim((string)$config->tiebreak) !== '';
    }

    /** @return list<Column> the columns that have a header */
    public static function columns(ResultsConfig $config): array
    {
        return array_values(array_filter($config->columns, fn(Column $column) => $column->header !== null));
    }

    private static function header(mixed $pos): string|int
    {
        return is_int($pos) ? $pos : (string)$pos;
    }

    /** @param array<string|int, mixed> $variables */
    private function listValue(Column $column, array $variables, Entry $entry): string
    {
        $highlight = $column->highlight !== null ? Input::solveFormula($variables, $column->highlight) : null;
        $lines = array_map(fn($formula) => Input::solveFormula($variables, $formula), $column->val);
        $entry->lines[$column->key()] = $lines;
        return implode('<br /> ', array_map(
            fn($line) => ($highlight !== null && $line == $highlight) ? '<b>' . $line . '</b>' : $line,
            $lines,
        ));
    }

    private function team(Entry $entry): TeamIdentity
    {
        $v = $entry->variables;
        return new TeamIdentity(
            $v['TEAM_ID'] ?? null,
            $v['NUM'] ?? null,
            $v['EQUIPE'] ?? null,
            $v['ESCOLA'] ?? null,
            $v['ESTADO'] ?? null,
            $entry->firstInput->disqualified,
        );
    }
}
