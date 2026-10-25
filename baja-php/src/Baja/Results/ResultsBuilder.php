<?php
declare(strict_types=1);

namespace Baja\Results;

use Baja\Model\Resultado;
use Baja\Results\Build\Cells;
use Baja\Results\Build\ColumnEvaluator;
use Baja\Results\Build\Entry;
use Baja\Results\Build\TeamEntries;
use Baja\Results\Ranking\Ranking;

/**
 * Works out a resultado's table: every team's row, each column's value,
 * the ranking. The one place results are computed, for the results pages,
 * the premiação and the API alike; the rules it follows are in
 * docs/results-rules.md.
 *
 *     $config = ResultsConfig::fromColunas($resultado->getColunas());
 *     $viewer = ViewerContext::publicView(EventStatus::of($evento));
 *     $table  = ResultsBuilder::buildFor($resultado, $config, $viewer);
 *     foreach ($table->pageOrder() as $row) { ... $row->display('Total') ... }
 *
 * Step by step:
 *
 *   1. The inputs of the resultado's provas are merged into one set of
 *      formula variables per team, one entry per run (Build\TeamEntries).
 *   2. The viewer decides whether spoilers are hidden, and whether the
 *      ranking is hidden with them (ViewerContext).
 *   3. Each entry's columns and tiebreak are worked out with
 *      Input::solveFormula (Build\ColumnEvaluator).
 *   4. The rows are ranked by "pos" (Ranking\Ranking), unless the ranking
 *      is hidden: then no row has a position or a tiebreak value, so the
 *      rows cannot give away what the spoilers hide.
 */
final class ResultsBuilder
{
    /** Reads the inputs of $resultado from the database and builds its table. */
    public static function buildFor(Resultado $resultado, ResultsConfig $config, ViewerContext $viewer): ResultTable
    {
        return self::build($config, TeamInput::forResultado($resultado), $viewer);
    }

    /** @param list<TeamInput> $inputs in database order */
    public static function build(ResultsConfig $config, array $inputs, ViewerContext $viewer): ResultTable
    {
        $hideSpoilers = $viewer->hidesSpoilers($config->authorizedUsers);
        $hideRanking = $viewer->hidesRanking($config);
        $rows = [];
        foreach (TeamEntries::of($inputs, $config->filter) as $entry) {
            ColumnEvaluator::evaluate($entry, $config, $hideSpoilers);
            $team = self::team($entry);
            // The tiebreak is still worked out (a broken formula is still
            // reported), but a hidden ranking does not show it.
            $tiebreak = $hideRanking ? null : $entry->tiebreak;
            $rows[] = new ResultRow($team, Cells::of($config->columns, $entry, $team), null, $tiebreak, $entry->variables);
        }

        $key = $config->rankingKey();
        if ($key !== null && !$hideRanking) {
            $rows = Ranking::rank($rows, $key, $config->direction(), $config->tiebreakFormula() !== null);
        }
        return new ResultTable($config->columns, $rows, $hideSpoilers, $hideRanking);
    }

    /** The team, from the variables (which an input can override). */
    private static function team(Entry $entry): TeamIdentity
    {
        $v = $entry->variables;
        return new TeamIdentity(
            $v['TEAM_ID'] ?? $entry->teamId,
            $v['NUM'] ?? null,
            $v['EQUIPE'] ?? null,
            $v['ESCOLA'] ?? null,
            $v['ESTADO'] ?? null,
            $v['DESCLASSIFICADO'] ?? null,
        );
    }
}
