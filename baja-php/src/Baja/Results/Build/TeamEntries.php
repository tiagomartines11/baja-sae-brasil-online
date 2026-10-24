<?php
declare(strict_types=1);

namespace Baja\Results\Build;

use Baja\Results\Behaviour\Behaviour;
use Baja\Results\TeamInput;

/**
 * Turns the input rows of a resultado into one Entry per run of each team.
 *
 * A resultado can be made of several provas ("inputs"), so a team has one
 * input row per prova. Those are merged into one set of variables per team,
 * in database order, later ones winning: first the team (Behaviour::
 * teamVariables), then each input's dados, vars and pontos.
 *
 * Runs ("entries"): an input whose dados is {"entries": [...]} has several
 * runs, and each run becomes a row. Any other dados is one run. When a team
 * already has runs, a single-run input is merged into its FIRST run only,
 * and the runs after it are dropped.
 *
 * Filter: with a "filter", a team whose variable of that name is not truthy
 * after an input is merged is dropped. Only the team's own variables count:
 * the vars and pontos, and the dados of an input that lists its runs; the
 * dados of a one-run input belong to its run and are not looked at. A later
 * input of the same team starts it again from scratch, without what came
 * before. (That is BAJAAPP-44, kept as it is until its own fix.)
 */
final class TeamEntries
{
    /**
     * @param list<TeamInput> $inputs
     * @return list<Entry>
     */
    public static function of(array $inputs, mixed $filter, Behaviour $behaviour): array
    {
        $teams = [];
        $firstInputs = [];
        foreach ($inputs as $input) {
            $key = $input->teamId;
            if (!array_key_exists($key, $teams)) {
                $teams[$key] = $behaviour->teamVariables($input);
                $firstInputs[$key] = $input;
            }
            $teams[$key] = array_merge(
                $teams[$key],
                self::runs($teams[$key], $input->dados),
                (array)$input->vars,
                (array)$input->pontos,
            );
            if ($filter && !($teams[$key][$filter] ?? null)) {
                unset($teams[$key], $firstInputs[$key]);
            }
        }

        $entries = [];
        foreach ($teams as $key => $team) {
            foreach ($behaviour->runsOf($team) as $run) {
                $variables = array_merge($team, (array)$run);
                unset($variables['entries']);
                $entries[] = new Entry($variables, $key, $firstInputs[$key]);
            }
        }
        return $entries;
    }

    /**
     * What one input adds to its team: its dados as they are when they list
     * their runs, or else a one-run list.
     *
     * @param array<string|int, mixed> $team
     * @return array<string|int, mixed>
     */
    private static function runs(array $team, mixed $dados): array
    {
        $listsRuns = is_object($dados) && ($dados->entries ?? null);
        if ($listsRuns) {
            return (array)$dados;
        }
        if (!empty($team['entries'])) {
            return ['entries' => [array_merge((array)$team['entries'][0], (array)$dados)]];
        }
        return ['entries' => [$dados]];
    }
}
