<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Results\Column;
use Baja\Results\EventStatus;
use Baja\Results\Ranking\Ranking;
use Baja\Results\ResultRow;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\TeamInput;
use Baja\Results\ViewerContext;

/**
 * Checks one resultado's config ("colunas") against its teams' data. No
 * database: it is given the decoded JSON and the teams' inputs.
 *
 *   1. Every formula, for every team, run by the real results builder:
 *      once as the public sees a finished event, and once more during an
 *      event with spoilers on when a column has a "val_spoilers". Each
 *      error a formula raises is a finding, with how many teams it hits.
 *   2. The ranking settings (docs/results-rules.md, R5): a "pos" that names
 *      no column and no variable, a "Pos" column with no "pos", a
 *      "pos_order" other than asc/desc, and a "pos" that leaves every team
 *      without a position.
 *   3. Formula columns that are empty for every team, although no formula
 *      raised an error: likely broken too.
 *   4. Variables a formula reads that nothing gives it (UnknownVariables).
 *
 * The builder is run one team at a time, so that each error can be put
 * down to the team it came from. A team's row depends only on that team's
 * inputs, so this gives the same rows as one run over all teams; the
 * ranking, which needs every row, is then done over all of them.
 */
final class ResultadoCheck
{
    /**
     * @param list<TeamInput>     $inputs
     * @param array<string, true> $declared the names its provas' configs declare (UnknownVariables)
     * @return array{teams: int, findings: list<Finding>}
     */
    public static function run(object $colunas, array $inputs, array $declared = []): array
    {
        $config = ResultsConfig::fromColunas($colunas);
        $slots = FormulaSlot::ofResultado($colunas);
        $hasSpoilers = array_filter($config->columns, fn(Column $column) => (bool)$column->valSpoilers) !== [];

        $rows = [];
        $teams = 0;
        $errors = [];
        foreach (self::byTeam($inputs) as $teamId => $teamInputs) {
            [$table, $raised] = FormulaErrors::during(fn() => ResultsBuilder::build($config, $teamInputs, self::finishedEvent()));
            if ($hasSpoilers) {
                [, $more] = FormulaErrors::during(fn() => ResultsBuilder::build($config, $teamInputs, self::eventWithSpoilers()));
                $raised = array_merge($raised, $more);
            }
            if ($table->rows === []) {
                continue;  // left out by the resultado's "filter"
            }
            $teams++;
            $rows = array_merge($rows, $table->rows);
            foreach ($raised as $error) {
                $key = json_encode([$error->formula, $error->message]);
                $errors[$key] ??= ['error' => $error, 'teams' => []];
                $errors[$key]['teams'][$teamId] = true;
            }
        }

        $findings = self::formulaErrors($slots, $errors, $teams);
        if ($teams > 0) {
            array_push($findings, ...self::emptyColumns($config, $colunas, $rows, $findings));
        }
        array_push($findings, ...self::ranking($config, $rows));
        array_push($findings, ...UnknownVariables::ofResultado($colunas, $inputs, $declared));
        return ['teams' => $teams, 'findings' => $findings];
    }

    /**
     * One finding per formula and message, under every place in the config
     * that has that formula (the builder only says which formula failed).
     *
     * @param list<FormulaSlot> $slots
     * @param array<string, array{error: FormulaError, teams: array<mixed, true>}> $errors
     * @return list<Finding>
     */
    private static function formulaErrors(array $slots, array $errors, int $teams): array
    {
        $findings = [];
        foreach ($slots as $slot) {
            foreach ($errors as $key => $error) {
                if ($error['error']->formula === $slot->formula) {
                    $findings[] = self::errorFinding($slot, $error, $teams);
                    $errors[$key]['found'] = true;
                }
            }
        }
        // Not expected: every formula the builder runs is in a slot. Kept
        // visible rather than dropped, in case the builder learns new ones.
        foreach ($errors as $error) {
            if (!isset($error['found'])) {
                $findings[] = self::errorFinding(new FormulaSlot('formula', [], $error['error']->formula), $error, $teams);
            }
        }
        return $findings;
    }

    /** @param array{error: FormulaError, teams: array<mixed, true>} $error */
    private static function errorFinding(FormulaSlot $slot, array $error, int $teams): Finding
    {
        return new Finding(
            Finding::FORMULA_ERROR,
            $slot->where,
            $error['error']->message,
            $slot->formula,
            count($error['teams']),
            $teams,
            $slot,
        );
    }

    /**
     * Formula and list columns whose cell is empty on every row, when no
     * formula of that column raised an error (that is reported already).
     *
     * @param list<ResultRow> $rows
     * @param list<Finding> $errorFindings
     * @return list<Finding>
     */
    private static function emptyColumns(ResultsConfig $config, object $colunas, array $rows, array $errorFindings): array
    {
        $findings = [];
        $jsonColumns = (array)($colunas->colunas ?? null);
        foreach (array_keys($jsonColumns) as $position => $index) {
            $column = $config->columns[$position];
            if ($column->kind() !== Column::KIND_FORMULA && $column->kind() !== Column::KIND_LIST) {
                continue;
            }
            $name = FormulaSlot::columnName($column, $index);
            foreach ($errorFindings as $finding) {
                if (str_starts_with($finding->where, $name . ' ')) {
                    continue 2;
                }
            }
            foreach ($rows as $row) {
                if (!self::isEmpty($row->display($column->key()))) {
                    continue 2;
                }
            }
            // A column that only shows a variable is often fine: nobody was
            // given a penalty, say. Saying so saves the reader a lookup.
            $variable = is_string($column->val) && preg_match('/^\s*\$(\w+)\s*$/', $column->val, $match) === 1
                ? ' (no team has a value for $' . $match[1] . ', which may be right)'
                : '';
            $findings[] = new Finding(
                Finding::EMPTY_COLUMN,
                $name . ' val',
                'no error, but the cell is empty for all ' . count($rows) . ' rows' . $variable,
                $column->val,
            );
        }
        return $findings;
    }

    /**
     * The ranking settings (R5), and whether anybody ends up with a position.
     *
     * @param list<ResultRow> $rows
     * @return list<Finding>
     */
    private static function ranking(ResultsConfig $config, array $rows): array
    {
        $findings = [];
        $order = $config->posOrder;
        if ($order !== null && !in_array(strtolower(trim(is_scalar($order) ? (string)$order : '')), ['asc', 'desc'], true)) {
            $findings[] = new Finding(
                Finding::RANKING,
                'pos_order',
                'pos_order ' . self::show($order) . ' is neither "asc" nor "desc", so it ranks as "desc" (highest first)',
            );
        }

        $key = $config->rankingKey();
        if ($key === null) {
            foreach ($config->columns as $position => $column) {
                if ($column->kind() === Column::KIND_POSITION) {
                    $findings[] = new Finding(
                        Finding::RANKING,
                        'pos',
                        FormulaSlot::columnName($column, $position) . ' shows positions but there is no "pos", so nobody is ranked and every team shows "–"',
                    );
                    break;
                }
            }
            return $findings;
        }
        if ($rows === []) {
            return $findings;  // no team: nothing to tell a variable from a typo
        }

        $isHeader = in_array((string)$key, array_map(fn(Column $column) => (string)$column->key(), $config->columns), true);
        $isVariable = array_filter($rows, fn(ResultRow $row) => array_key_exists($key, $row->variables())) !== [];
        if (!$isHeader && !$isVariable) {
            $findings[] = new Finding(
                Finding::RANKING,
                'pos',
                'pos ' . self::show($key) . ' names no column and no variable, so nobody is ranked and every team shows "–"',
            );
            return $findings;
        }

        $ranked = Ranking::rank($rows, $key, $config->direction(), $config->tiebreakFormula() !== null);
        if (array_filter($ranked, fn(ResultRow $row) => $row->position !== null) === []) {
            $findings[] = new Finding(
                Finding::RANKING,
                'pos',
                'pos ' . self::show($key) . ' has no number for any team, so every team shows "–"' . self::sample($rows, $key),
            );
        }
        return $findings;
    }

    /** What the ranked value looks like, to help tell why it is no number. */
    private static function sample(array $rows, string|int $key): string
    {
        foreach ($rows as $row) {
            $value = $row->variables()[$key] ?? null;
            if (!self::isEmpty($value)) {
                return ' (it shows e.g. ' . self::show($value) . ')';
            }
        }
        return ' (it is empty for every team)';
    }

    /**
     * The inputs grouped by team, each team's in their original order.
     *
     * @param list<TeamInput> $inputs
     * @return array<mixed, list<TeamInput>>
     */
    private static function byTeam(array $inputs): array
    {
        $teams = [];
        foreach ($inputs as $input) {
            $teams[$input->teamId][] = $input;
        }
        return $teams;
    }

    /** Nothing a visitor could read: null, "", or only HTML tags and spaces. */
    private static function isEmpty(mixed $display): bool
    {
        if ($display === null) {
            return true;
        }
        if (!is_string($display)) {
            return false;
        }
        return trim(str_replace('&nbsp;', ' ', strip_tags($display))) === '';
    }

    private static function show(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function finishedEvent(): ViewerContext
    {
        return ViewerContext::publicView(new EventStatus(spoilers: false, finished: true));
    }

    private static function eventWithSpoilers(): ViewerContext
    {
        return ViewerContext::publicView(new EventStatus(spoilers: true, finished: false));
    }
}
