<?php
declare(strict_types=1);

namespace Baja\Results\Behaviour;

use Baja\Model\Input;
use Baja\Results\Build\Cells;
use Baja\Results\Build\Entry;
use Baja\Results\Build\SpoilerValue;
use Baja\Results\Column;
use Baja\Results\Ranking\PageRanking;
use Baja\Results\ResultRow;
use Baja\Results\ResultsConfig;
use Baja\Results\TeamIdentity;
use Baja\Results\TeamInput;

/**
 * How the public results page (resultados/prova.php) works out its table.
 * The judge page and the results API use it too.
 *
 * Columns, in order:
 *
 *   - while spoilers are hidden, a column with a "val_spoilers" shows that
 *     (a list of formulas gives one line each, never in bold);
 *   - a list of formulas gives one line each, and a line equal to the
 *     "highlight" formula's value is put in bold. With no highlight, a line
 *     that is empty or 0 is the one put in bold (it equals null);
 *   - anything else is one formula. "EquipeNum", "Equipe" and "Pos" are
 *     formulas too, which give back the same word (src/bootstrap.php defines
 *     them as constants); the page then shows the team instead.
 *
 * Each column's value is written into the variables under its header, so a
 * later column can use an earlier one: a column "Total" with the formula
 * $Pontos + $Bonus reads the columns "Pontos" and "Bonus".
 *
 * Then "tiebreak" is worked out (it can read the columns too), the rows are
 * ranked (PageRanking) and put in the page's order: by the run's "ts"
 * variable, then by car number. The browser sorts the table again.
 */
final class PageBehaviour implements Behaviour
{
    public function teamVariables(TeamInput $firstInput): array
    {
        return [
            'NUM' => $firstInput->number,
            'EQUIPE' => $firstInput->name,
            'ESCOLA' => $firstInput->school,
            'ESTADO' => $firstInput->state,
            'DESCLASSIFICADO' => $firstInput->disqualified,
        ];
    }

    public function runsOf(array $team): iterable
    {
        $runs = $team['entries'] ?? null;
        return is_array($runs) || is_object($runs) ? (array)$runs : [];
    }

    public function evaluate(Entry $entry, ResultsConfig $config, bool $hideSpoilers): void
    {
        foreach ($config->columns as $column) {
            $key = $column->key();
            unset($entry->lines[$key], $entry->apiValues[$key]);
            $scope = $entry->variables;

            if ($hideSpoilers && $column->valSpoilers) {
                $entry->variables[$key] = SpoilerValue::of($column, $scope, $entry);
                continue;
            }

            if (is_array($column->val)) {
                $highlight = $column->highlight ? Input::solveFormula($scope, $column->highlight) : null;
                $lines = array_map(fn($formula) => Input::solveFormula($scope, $formula), $column->val);
                $entry->lines[$key] = $lines;
                $entry->variables[$key] = implode('<br /> ', array_map(
                    fn($line) => $line == $highlight ? "<b>$line</b>" : $line,
                    $lines,
                ));
            } else {
                $entry->variables[$key] = Input::solveFormula($scope, $column->val);
            }
            if ($column->valApi !== null) {
                $entry->apiValues[$key] = Input::solveFormula($scope, $column->valApi);
            }
        }

        if ($config->tiebreak) {
            $entry->variables['tiebreak'] = Input::solveFormula($entry->variables, $config->tiebreak);
        }
        $entry->tiebreak = $entry->variables['tiebreak'] ?? null;
    }

    public function rows(array $entries, ResultsConfig $config): array
    {
        PageRanking::rank($entries, $config->pos, $config->posOrder);
        usort($entries, fn(Entry $a, Entry $b) => self::pageOrder($a, $b));

        $rows = [];
        foreach ($entries as $entry) {
            $team = $this->team($entry);
            $cells = Cells::of($config->columns, $entry, $team, fn(Column $column) => $entry->variables[$column->key()] ?? null);
            $rows[] = new ResultRow($team, $cells, $entry->position, $entry->tiebreak, $entry->variables);
        }
        return $rows;
    }

    /**
     * The page's order: by "ts" (the run's time stamp), then car number. It
     * never says two rows are equal, so for equal ones the result depends on
     * the order they came in (the ranking's); that is kept as it was.
     */
    private static function pageOrder(Entry $a, Entry $b): int
    {
        $ta = $a->variables['ts'] ?? 0;
        $tb = $b->variables['ts'] ?? 0;
        if ($ta != $tb) {
            return ($ta > $tb) ? 1 : -1;
        }
        return (($a->variables['NUM'] ?? null) > ($b->variables['NUM'] ?? null)) ? 1 : -1;
    }

    /** The team as the page shows it: from the variables, which an input can override. */
    private function team(Entry $entry): TeamIdentity
    {
        $v = $entry->variables;
        return new TeamIdentity(
            $entry->teamId,
            $v['NUM'] ?? null,
            $v['EQUIPE'] ?? null,
            $v['ESCOLA'] ?? null,
            $v['ESTADO'] ?? null,
            $v['DESCLASSIFICADO'] ?? null,
        );
    }
}
