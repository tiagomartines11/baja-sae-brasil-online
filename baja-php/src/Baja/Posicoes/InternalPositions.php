<?php
declare(strict_types=1);

namespace Baja\Posicoes;

use Baja\Model\Input;
use Baja\Results\ResultRow;
use Baja\Results\Ranking\Ranking;
use Baja\Results\TeamIdentity;

/**
 * Positions inside one prova: {"valor": ..., "ordem": ..., "desempate": ...}.
 *
 * Each team's "valor" (and "desempate") is worked out with the team's
 * inputs, vars and min_max, then the teams are ranked by the same code as
 * the results pages (Results\Ranking\Ranking), so the rules are theirs:
 *
 *   - highest first, or lowest first with "ordem": "asc";
 *   - equal values share a position and the next one is skipped: 1, 1, 3;
 *   - "desempate" separates equal values, in the same direction; a team
 *     with a desempate goes before one without;
 *   - a team with no value (null, "", text that is not a number) has no
 *     position: null.
 *
 * One rule is this stage's own: a disqualified team has no position, and
 * the others are ranked as if it were not there (the prova's Totais leave
 * it out the same way).
 */
final class InternalPositions
{
    /**
     * @param list<array{teamId: int|string, variables: array<string|int, mixed>, disqualified: bool}> $teams
     * @return array<int|string, ?int> team id => position (null: none)
     */
    public static function rank(PositionSpec $spec, array $teams): array
    {
        $positions = [];
        $rows = [];
        $tiebreakFormula = $spec->tiebreakFormula();
        foreach ($teams as $team) {
            $positions[$team['teamId']] = null;
            if ($team['disqualified']) {
                continue;
            }
            $value = Input::solveFormula($team['variables'], $spec->valor);
            $tiebreak = $tiebreakFormula === null ? null : Input::solveFormula($team['variables'], $tiebreakFormula);
            // Ranking reads the value from the row's variables, under the key
            // it is given, and orders teams that stay tied by car number:
            // here the team id, which is the car number.
            $identity = new TeamIdentity($team['teamId'], $team['teamId'], null, null, null, false);
            $rows[] = new ResultRow($identity, [], null, $tiebreak, ['valor' => $value]);
        }

        foreach (Ranking::rank($rows, 'valor', $spec->direction(), $tiebreakFormula !== null) as $row) {
            $positions[$row->team->teamId] = $row->position;
        }
        return $positions;
    }
}
