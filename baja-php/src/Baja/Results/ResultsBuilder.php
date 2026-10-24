<?php
declare(strict_types=1);

namespace Baja\Results;

use Baja\Model\Resultado;
use Baja\Results\Behaviour\Behaviour;
use Baja\Results\Behaviour\PageBehaviour;
use Baja\Results\Behaviour\PremiacaoBehaviour;
use Baja\Results\Build\TeamEntries;

/**
 * Works out a resultado's table: every team's row, each column's value,
 * the ranking. The one place results are computed; the pages only draw
 * what it returns.
 *
 *     $config = ResultsConfig::fromColunas($resultado->getColunas());
 *     $viewer = ViewerContext::publicView(EventStatus::of($evento));
 *     $table  = ResultsBuilder::forPages()->buildFor($resultado, $config, $viewer);
 *     foreach ($table->rows as $row) { ... $row->display('Total') ... }
 *
 * How, step by step:
 *
 *   1. The inputs of the resultado's provas are merged into one set of
 *      formula variables per team, one entry per run (Build\TeamEntries).
 *   2. The viewer decides whether spoilers are hidden (ViewerContext).
 *   3. Each entry's columns and tiebreak are worked out with
 *      Input::solveFormula.
 *   4. The entries are ranked and ordered, and become ResultRows.
 *
 * Steps 1, 3 and 4 have small differences between the pages and the
 * premiação that change results, so they are kept apart until fixed on
 * purpose: see Behaviour\Behaviour. forPages() is the one to use for
 * anything new, the API included.
 */
final class ResultsBuilder
{
    public function __construct(private readonly Behaviour $behaviour)
    {
    }

    /** As resultados/prova.php and prova_juiz.php compute (and the API). */
    public static function forPages(): self
    {
        return new self(new PageBehaviour());
    }

    /** As Baja\Premiacao\Resolver computes. */
    public static function forPremiacao(): self
    {
        return new self(new PremiacaoBehaviour());
    }

    /** Reads the inputs of $resultado from the database and builds its table. */
    public function buildFor(Resultado $resultado, ResultsConfig $config, ViewerContext $viewer): ResultTable
    {
        return $this->build($config, TeamInput::forResultado($resultado), $viewer);
    }

    /** @param list<TeamInput> $inputs in database order */
    public function build(ResultsConfig $config, array $inputs, ViewerContext $viewer): ResultTable
    {
        $hideSpoilers = $viewer->hidesSpoilers($config->authorizedUsers);
        $entries = TeamEntries::of($inputs, $config->filter, $this->behaviour);
        foreach ($entries as $entry) {
            $this->behaviour->evaluate($entry, $config, $hideSpoilers);
        }
        return new ResultTable($config->columns, $this->behaviour->rows($entries, $config), $hideSpoilers);
    }
}
