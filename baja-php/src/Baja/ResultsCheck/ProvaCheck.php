<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Model\Input;
use Baja\Model\Prova;

/**
 * Runs a prova's formulas ("params": vars, min_max, pontos) for every team
 * and lists the errors they raise. Report only: the check never proposes a
 * fix for a prova, because saving a prova recomputes every stored point
 * (BAJAAPP-56).
 *
 * The formulas are run by Input::updateVars() and Input::updatePontos(),
 * as Prova::refreshVarsAndPontos() runs them, but nothing is saved: they
 * only change the Input objects in memory. As in the results snapshots
 * (ResultsSnapshot\Jobs\FormulasJob), the prova's totals are used as
 * stored, because working them out again would save the prova.
 */
final class ProvaCheck
{
    /**
     * @param iterable<Input> $inputs the prova's inputs, each with its team
     * @return array{teams: int, findings: list<Finding>}
     */
    public static function run(Prova $prova, iterable $inputs): array
    {
        $errors = [];
        $teams = 0;
        foreach ($inputs as $input) {
            $teams++;
            [, $raised] = FormulaErrors::during(function () use ($input): void {
                $input->updateVars();
                $input->updatePontos();
            });
            foreach ($raised as $error) {
                $key = json_encode([$error->formula, $error->message]);
                $errors[$key] ??= ['error' => $error, 'teams' => []];
                $errors[$key]['teams'][$input->getEquipeId()] = true;
            }
        }

        $findings = [];
        foreach (FormulaSlot::ofProva($prova->getParams()) as $slot) {
            foreach ($errors as $error) {
                if ($error['error']->formula === $slot->formula) {
                    $findings[] = new Finding(
                        Finding::FORMULA_ERROR,
                        $slot->where,
                        $error['error']->message,
                        $slot->formula,
                        count($error['teams']),
                        $teams,
                        $slot,
                    );
                }
            }
        }
        return ['teams' => $teams, 'findings' => $findings];
    }
}
