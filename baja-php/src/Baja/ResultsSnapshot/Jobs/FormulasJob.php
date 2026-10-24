<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Jobs;

use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\ProvaQuery;
use Throwable;

/**
 * Recomputes the formulas of one prova, in memory, and records what they give.
 *
 * Each team's raw input ("dados") goes through the prova's formulas on its
 * way to a results page: Input::updateVars() turns it into "vars" (times,
 * distances, best of two runs...), and Input::updatePontos() turns the vars
 * and the prova's totals (the minimum and maximum over all teams) into
 * "pontos". Both evaluate the formulas with eval(), inside
 * Input::solveFormula(). BAJAAPP-35 replaces that eval(); this snapshot is
 * what proves the replacement computes the same numbers.
 *
 * Both methods are called exactly as Prova::refreshVarsAndPontos() calls
 * them, but nothing is saved: they only change the Input objects in memory,
 * and the values are read from those objects.
 *
 * One step of refreshVarsAndPontos() is NOT repeated: Prova::updateTotals(),
 * which works the minimum and maximum out in SQL and then saves the prova.
 * It cannot run without writing, so updatePontos() is given the totals as
 * they are stored. The totals involve no eval(); what can change them is a
 * change in the vars, and that already shows in this snapshot.
 *
 * The snapshot also says which teams' stored values differ from the
 * recomputed ones. The results pages show the STORED values; a difference
 * means that page would change the next time anyone saved an input of that
 * prova.
 */
final class FormulasJob
{
    /** @return array<string, mixed> */
    public static function run(string $eventoId, string $provaId): array
    {
        $prova = ProvaQuery::create()->filterByEventoId($eventoId)->findOneByProvaId($provaId);
        if ($prova === null) {
            throw new \RuntimeException('Prova not found: ' . $eventoId . '_' . $provaId);
        }

        // The same selection as refreshVarsAndPontos() (inputs that have a
        // team), in team order so that two runs list them the same way.
        $inputs = InputQuery::create()
            ->filterByEventoId($eventoId)
            ->joinEquipe()
            ->filterByProvaId($provaId)
            ->orderByEquipeId()
            ->find();

        $snapshot = [];
        $differs = ['vars' => [], 'pontos' => []];
        $error = null;
        try {
            foreach ($inputs as $input) {
                /** @var Input $input */
                $storedVars = self::plain($input->getVars());
                $storedPontos = self::plain($input->getPontos());

                // Input::getProva() hands back the same Prova object as
                // above (Propel keeps one object per row), so updatePontos()
                // reads the stored totals from it.
                $input->updateVars();
                $input->updatePontos();

                $equipe = (int)$input->getEquipeId();
                $vars = self::plain($input->getVars());
                $pontos = self::plain($input->getPontos());
                $snapshot[] = ['equipe' => $equipe, 'vars' => $vars, 'pontos' => $pontos];

                if (!self::same($storedVars, $vars)) {
                    $differs['vars'][] = $equipe;
                }
                if (!self::same($storedPontos, $pontos)) {
                    $differs['pontos'][] = $equipe;
                }
            }
        } catch (Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }

        return [
            'evento' => $eventoId,
            'prova' => $provaId,
            'nome' => $prova->getNome(),
            'type' => $prova->getType(),
            'stored_totals' => self::plain($prova->getTotals()),
            'inputs' => $snapshot,
            'differs_from_stored' => $differs,
            'recompute_error' => $error,
        ];
    }

    /**
     * Whether a stored value and a recomputed one are the same.
     *
     * Key order does not matter (MySQL sorts the keys of a JSON column), 1
     * and 1.0 are the same number, and so are two numbers that differ only
     * in the last of their 17 digits: MySQL does not always write a double
     * back with the digits PHP computed (19.260204081632654 is stored for
     * 19.260204081632658), and that is not a result that would change.
     */
    private static function same(mixed $stored, mixed $recomputed): bool
    {
        if (is_array($stored) && is_array($recomputed)) {
            if (count($stored) !== count($recomputed)) {
                return false;
            }
            foreach ($stored as $key => $value) {
                if (!array_key_exists($key, $recomputed) || !self::same($value, $recomputed[$key])) {
                    return false;
                }
            }
            return true;
        }
        if ((is_int($stored) || is_float($stored)) && (is_int($recomputed) || is_float($recomputed))) {
            return abs($stored - $recomputed) <= 1e-12 * max(1.0, abs($stored), abs($recomputed));
        }
        return $stored === $recomputed;
    }

    /** A decoded JSON value (objects and all) as plain PHP arrays. */
    private static function plain(mixed $value): mixed
    {
        return json_decode((string)json_encode($value), true);
    }
}
