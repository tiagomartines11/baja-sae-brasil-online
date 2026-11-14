<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Model\Input;
use Baja\Model\Prova;
use Baja\Posicoes\EventGraph;
use Baja\Posicoes\PosicoesRules;
use Baja\Posicoes\PositionSpec;
use Baja\Posicoes\PositionStage;

/**
 * Runs a prova's formulas ("params": vars, min_max, posicoes, pontos) for
 * every team and lists the errors they raise, and the variables they read
 * that nothing gives them (UnknownVariables). Report only: the check never
 * proposes a fix for a prova, because saving a prova recomputes every stored
 * point (BAJAAPP-56).
 *
 * The formulas are run by Input::updateVars(), the posicoes stage and
 * Input::updatePontos(), as Prova::refreshVarsAndPontos() runs them, but
 * nothing is saved: they only change the Input objects in memory. As in the
 * results snapshots (ResultsSnapshot\Jobs\FormulasJob), the prova's totals
 * are used as stored, because working them out again would save the prova.
 *
 * Given the event ($graph), it also lists what is wrong with the prova's
 * "posicoes" (Posicoes\PosicoesRules): an unknown resultado, a cycle, and
 * a position taken from behind spoilers.
 */
final class ProvaCheck
{
    /**
     * @param iterable<Input> $inputs the prova's inputs, each with its team
     * @return array{teams: int, findings: list<Finding>}
     */
    public static function run(Prova $prova, iterable $inputs, ?EventGraph $graph = null): array
    {
        $errors = [];
        $teams = 0;
        $note = function (array $raised, mixed $teamId) use (&$errors): void {
            foreach ($raised as $error) {
                $key = json_encode([$error->formula, $error->message]);
                $errors[$key] ??= ['error' => $error, 'teams' => []];
                $errors[$key]['teams'][$teamId] = true;
            }
        };

        foreach ($inputs as $input) {
            $teams++;
            [, $raised] = FormulaErrors::during(fn() => $input->updateVars());
            $note($raised, $input->getEquipeId());
        }
        // The posicoes formulas, team by team so that each error is put down
        // to its team; then the stage itself, whose errors are those again.
        $specs = PositionSpec::allOf($prova->getParams());
        foreach ($inputs as $input) {
            [, $raised] = FormulaErrors::during(fn() => self::positionFormulas($specs, $input));
            $note($raised, $input->getEquipeId());
        }
        PositionStage::apply($prova, $inputs);
        foreach ($inputs as $input) {
            [, $raised] = FormulaErrors::during(fn() => $input->updatePontos());
            $note($raised, $input->getEquipeId());
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
        foreach ($prova->getExpandedInputs()->errors as $error) {
            $findings[] = new Finding(Finding::REPEATED, 'inputs', $error);
        }
        array_push($findings, ...UnknownVariables::ofProva($prova, $inputs));
        if ($graph !== null) {
            array_push($findings, ...self::positionFindings($graph, $prova));
        }
        return ['teams' => $teams, 'findings' => $findings];
    }

    /**
     * Runs the "valor" and "desempate" of each position inside the prova,
     * with what the stage gives them, only to hear their errors.
     *
     * @param list<PositionSpec> $specs
     */
    private static function positionFormulas(array $specs, Input $input): void
    {
        $variables = array_merge((array)$input->getDados(), (array)$input->getVars());
        foreach ($specs as $spec) {
            if (!$spec->isExternal()) {
                Input::solveFormula($variables, $spec->valor);
                if ($spec->tiebreakFormula() !== null) {
                    Input::solveFormula($variables, $spec->tiebreakFormula());
                }
            }
        }
    }

    /** @return list<Finding> */
    private static function positionFindings(EventGraph $graph, Prova $prova): array
    {
        $provaId = (string)$prova->getProvaId();
        $findings = [];
        foreach (PosicoesRules::errorsForProva($graph, $provaId, $prova->getParams()) as $error) {
            $findings[] = new Finding(Finding::POSITIONS, 'posicoes', $error);
        }
        foreach (PosicoesRules::warningsForProva($graph, $provaId) as $warning) {
            $findings[] = new Finding(Finding::POSITIONS, 'posicoes', 'warning: ' . $warning);
        }
        return $findings;
    }
}
