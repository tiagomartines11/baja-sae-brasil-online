<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Contagem\Engine\PublishedValues;
use Baja\Formula\SafeEngine;
use Baja\Model\Input;
use Baja\Model\Prova;
use Baja\Results\ResultsConfig;
use Baja\Results\TeamInput;
use Throwable;

/**
 * Variables a formula reads that nothing gives it: almost always a typo
 * ($ACEmin for $ACE_MIN), and the cell or point silently uses null.
 *
 * Each formula is read by SafeEngine's parser, which lists its variables
 * without running it (Formula\Syntax\ParsedFormula). A variable is known
 * when, at that place in the config, some team has it:
 *
 *   resultado  the team's own (NUM, EQUIPE...; docs/results-rules.md R7),
 *              anything its inputs give (dados, each run's fields, vars,
 *              pontos), anything its provas' configs name (declaredBy(): a
 *              penalty prova nobody got a penalty in has no input row, yet
 *              $PENPTS is no typo), and the columns to the left (R1); the
 *              tiebreak also sees every column
 *   prova      vars: the input fields (declared, or in some team's dados);
 *              min_max: those and the vars; pontos: those, the min_max
 *              variables, the prova's totals ($Xmax, $Xmin, $Xcom, $Xcount,
 *              $Xsum, $Xavg) and the pontos before it in alphabetical
 *              order, which is the order they are worked out
 *
 * A variable that exists only LATER (a column further right, a pontos that
 * comes after in alphabetical order) is said to be so: it is still empty
 * when this formula runs. A formula the parser cannot read is left out;
 * its error is reported already.
 */
final class UnknownVariables
{
    /**
     * @param list<TeamInput>     $inputs
     * @param array<string, true> $declared what the resultado's provas' configs name (declaredBy())
     * @return list<Finding>
     */
    public static function ofResultado(object $colunas, array $inputs, array $declared = []): array
    {
        if ($inputs === [] && $declared === []) {
            return [];  // nothing to tell a variable from a typo
        }
        $config = ResultsConfig::fromColunas($colunas);
        $base = ['TEAM_ID' => true, 'NUM' => true, 'EQUIPE' => true, 'ESCOLA' => true, 'ESTADO' => true, 'DESCLASSIFICADO' => true] + $declared;
        foreach ($inputs as $input) {
            $base += self::keys($input->dados) + self::keys($input->vars) + self::keys($input->pontos);
            foreach (self::runs($input->dados) as $run) {
                $base += self::keys($run);
            }
        }
        unset($base['entries']);

        // The columns' keys, by their position in the JSON (FormulaSlot's path).
        $columns = [];
        foreach (array_keys((array)($colunas->colunas ?? null)) as $position => $index) {
            $columns[$index] = (string)$config->columns[$position]->key();
        }

        $findings = [];
        foreach (FormulaSlot::ofResultado($colunas) as $slot) {
            $known = $base;
            $later = [];
            $seen = $slot->path[0] === 'tiebreak';
            foreach ($columns as $index => $key) {
                if (!$seen && $slot->path[0] === 'colunas' && $slot->path[1] === $index) {
                    $seen = true;
                }
                if ($seen && $slot->path[0] !== 'tiebreak') {
                    $later[$key] = true;
                } else {
                    $known[$key] = true;
                }
            }
            array_push($findings, ...self::findings($slot, $known, $later, 'is a column at or to the right of this one: columns are worked out left to right, so here it is still empty'));
        }
        return $findings;
    }

    /**
     * Every name a prova's config gives its teams: its input fields, vars,
     * min_max (and the totals made from them), pontos, and stored totals.
     *
     * @return array<string, true>
     */
    public static function declaredBy(Prova $prova): array
    {
        $params = $prova->getParams();
        if (!is_object($params)) {
            return [];
        }
        $names = self::inputFields($params) + self::keys($params->vars ?? null) + self::keys($params->pontos ?? null)
            + self::keys($prova->getTotals()) + self::systemFields($prova->getType());
        foreach (array_keys(self::keys($params->min_max ?? null)) as $variable) {
            $names[$variable] = true;
            foreach (['max', 'min', 'com', 'count', 'sum', 'avg'] as $total) {
                $names[$variable . $total] = true;
            }
        }
        return $names;
    }

    /**
     * @param iterable<Input> $inputs
     * @return list<Finding>
     */
    public static function ofProva(Prova $prova, iterable $inputs): array
    {
        $params = $prova->getParams();
        if (!is_object($params)) {
            return [];
        }
        $fields = self::inputFields($params) + self::systemFields($prova->getType());
        foreach ($inputs as $input) {
            $fields += self::keys($input->getDados());
        }
        $vars = self::keys($params->vars ?? null);
        $minMax = self::keys($params->min_max ?? null);
        $totals = self::keys($prova->getTotals());
        foreach (array_keys($minMax) as $variable) {
            foreach (['max', 'min', 'com', 'count', 'sum', 'avg'] as $total) {
                $totals[$variable . $total] = true;
            }
        }
        $pontos = array_keys(self::keys($params->pontos ?? null));
        sort($pontos, SORT_STRING);  // as ksort() in Input::updatePontos()

        $findings = [];
        foreach (FormulaSlot::ofProva($params) as $slot) {
            [$group, $name] = $slot->path;
            $known = match ($group) {
                'vars' => $fields,
                'min_max' => $fields + $vars,
                default => $fields + $vars + $minMax + $totals,
            };
            $later = [];
            $why = '';
            if ($group === 'pontos') {
                $position = array_search((string)$name, $pontos, true);
                foreach ($pontos as $at => $other) {
                    if ($at < $position) {
                        $known[$other] = true;
                    } else {
                        $later[$other] = true;
                    }
                }
                $why = 'is a pontos at or after this one in alphabetical order, the order they are worked out, so here it is still empty';
            } elseif ($group === 'vars') {
                $later = $vars + $minMax;
                $why = 'is a var or a min_max: a var only sees the input fields, so here it is empty';
            } else {
                $later = $minMax;
                $why = 'is a min_max: they only see the input fields and the vars, so here it is still empty';
            }
            array_push($findings, ...self::findings($slot, $known, $later, $why));
        }
        return $findings;
    }

    /**
     * @param array<string, true> $known
     * @param array<string, true> $later
     * @return list<Finding>
     */
    private static function findings(FormulaSlot $slot, array $known, array $later, string $laterWhy): array
    {
        try {
            $variables = SafeEngine::read($slot->formula)->variables;
        } catch (Throwable) {
            return [];
        }
        $findings = [];
        foreach ($variables as $variable) {
            if (isset($known[$variable])) {
                continue;
            }
            $detail = '$' . $variable . ' ' . (isset($later[$variable])
                ? $laterWhy
                : 'is given by nothing here, so it is empty for every team' . self::lookAlike($variable, $known));
            $findings[] = new Finding(Finding::UNKNOWN_VARIABLE, $slot->where, $detail, $slot->formula, slot: $slot);
        }
        return $findings;
    }

    /** " (there is $ACEMIN; names care about case)": a known name that differs only in case. */
    private static function lookAlike(string $variable, array $known): string
    {
        $close = [];
        foreach (array_keys($known) as $name) {
            if (strcasecmp((string)$name, $variable) === 0) {
                $close[] = '$' . $name;
            }
        }
        return $close === [] ? '' : ' (there is ' . implode(', ', $close) . '; names care about case)';
    }

    /**
     * Fields the site writes into a team's dados itself: a rolling prova's
     * entry time (juiz/save_rolling_entry.php), and what lap counting
     * publishes (Contagem\Engine\PublishedValues).
     *
     * @return array<string, true>
     */
    private static function systemFields(string $type): array
    {
        return match ($type) {
            'rolling' => ['ts' => true],
            'laps' => [PublishedValues::VOLTAS => true, PublishedValues::LAST => true, PublishedValues::BEST => true],
            default => [],
        };
    }

    /** @return array<string, true> the codes of a prova's "inputs" */
    private static function inputFields(object $params): array
    {
        $fields = [];
        foreach ((array)($params->inputs ?? []) as $field) {
            if (is_object($field) && isset($field->code) && is_scalar($field->code)) {
                $fields[(string)$field->code] = true;
            }
        }
        return $fields;
    }

    /** @return array<string, true> the names of an object's or array's fields */
    private static function keys(mixed $value): array
    {
        if (!is_array($value) && !is_object($value)) {
            return [];
        }
        return array_fill_keys(array_map('strval', array_keys((array)$value)), true);
    }

    /** @return list<mixed> the runs of a dados with "entries" (a list or an object, R8) */
    private static function runs(mixed $dados): array
    {
        $entries = is_object($dados) ? ($dados->entries ?? null) : null;
        return is_array($entries) || is_object($entries) ? array_values((array)$entries) : [];
    }
}
