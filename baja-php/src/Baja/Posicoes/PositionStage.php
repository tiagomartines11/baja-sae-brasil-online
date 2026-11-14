<?php
declare(strict_types=1);

namespace Baja\Posicoes;

use Baja\Model\Input;
use Baja\Model\Prova;

/**
 * The "posicoes" stage of a prova's calculation (BAJAAPP-102):
 *
 *     inputs → vars → min_max → Totais → POSICOES → pontos
 *
 * For each entry of the prova's "posicoes", every team gets a variable of
 * that name holding its position (1, 2, 3...) or null. It is written into
 * the team's vars, next to the min_max values, so the "pontos" formulas
 * read it like any other variable, and so do the resultados' columns.
 *
 * Only the Input objects in memory change; whoever runs the stage saves
 * them (Prova::refreshVarsAndPontos() does, with the points). It reads the
 * database (another resultado's ranking), never writes it, so the
 * read-only tools (config check, results snapshots) can run it too.
 *
 * A prova with no "posicoes" is not touched at all.
 */
final class PositionStage
{
    /**
     * Run after every team's updateVars() and the prova's totals, before
     * any updatePontos().
     *
     * @param iterable<Input> $inputs the prova's inputs, each with its team
     *                                (as refreshVarsAndPontos() selects them)
     */
    public static function apply(Prova $prova, iterable $inputs): void
    {
        $specs = PositionSpec::allOf($prova->getParams());
        if ($specs === []) {
            return;
        }

        $teams = [];
        $byTeam = [];
        foreach ($inputs as $input) {
            $variables = array_merge((array)$input->getDados(), (array)$input->getVars());
            $teams[] = [
                'teamId' => $input->getEquipeId(),
                'variables' => $variables,
                'disqualified' => (bool)$input->getEquipe()?->isDesclassificado(),
            ];
            $byTeam[$input->getEquipeId()] = $input;
        }
        if ($byTeam === []) {
            return;  // no team to give a position to
        }

        $positions = [];
        foreach ($specs as $spec) {
            $positions[$spec->name] = $spec->isExternal()
                ? ExternalPositions::ofResultado((string)$prova->getEventoId(), (string)$spec->resultadoId())
                : InternalPositions::rank($spec, $teams);
        }

        foreach ($byTeam as $teamId => $input) {
            $vars = (array)$input->getVars();
            foreach ($positions as $name => $ofTeams) {
                $vars[$name] = $ofTeams[$teamId] ?? null;
            }
            $input->setVars($vars);
        }
    }
}
