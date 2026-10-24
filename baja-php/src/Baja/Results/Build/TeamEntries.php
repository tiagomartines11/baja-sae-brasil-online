<?php
declare(strict_types=1);

namespace Baja\Results\Build;

use Baja\Results\TeamInput;

/**
 * Turns the input rows of a resultado into one Entry per run of each team.
 *
 * A resultado can be made of several provas ("inputs"), so a team has one
 * input row per prova. Those are merged into one set of variables per team,
 * in database order, later ones winning: first the team (TEAM_ID, NUM,
 * EQUIPE, ESCOLA, ESTADO, DESCLASSIFICADO; R7), then each input's dados,
 * vars and pontos.
 *
 * Runs ("entries"): an input whose dados is {"entries": [...]} has several
 * runs, and each run becomes a row; a JSON object there counts as a list of
 * its values (R8). Any other dados is one run. When a team
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
    public static function of(array $inputs, mixed $filter): array
    {
        $teams = [];
        $firstInputs = [];
        foreach ($inputs as $input) {
            $key = $input->teamId;
            if (!array_key_exists($key, $teams)) {
                $teams[$key] = self::teamVariables($input);
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
            foreach (self::runsOf($team) as $run) {
                $variables = array_merge($team, (array)$run);
                unset($variables['entries']);
                $entries[] = new Entry($variables, $key, $firstInputs[$key]);
            }
        }
        return $entries;
    }

    /** @return array<string, mixed> */
    private static function teamVariables(TeamInput $input): array
    {
        return [
            'TEAM_ID' => $input->teamId,
            'NUM' => $input->number,
            'EQUIPE' => $input->name,
            'ESCOLA' => $input->school,
            'ESTADO' => $input->state,
            'DESCLASSIFICADO' => $input->disqualified,
        ];
    }

    /**
     * @param array<string|int, mixed> $team
     * @return list<mixed>
     */
    private static function runsOf(array $team): array
    {
        $runs = $team['entries'] ?? null;
        return is_array($runs) || is_object($runs) ? array_values((array)$runs) : [];
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
