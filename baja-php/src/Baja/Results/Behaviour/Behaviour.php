<?php
declare(strict_types=1);

namespace Baja\Results\Behaviour;

use Baja\Results\Build\Entry;
use Baja\Results\ResultRow;
use Baja\Results\ResultsConfig;
use Baja\Results\TeamInput;

/**
 * The places where the results pages and the premiação have always worked
 * out the same table differently.
 *
 * Before ResultsBuilder, resultados/prova.php and Baja\Premiacao\Resolver
 * each had their own copy of this code, and the copies drifted apart. The
 * builder now does everything they agree on once; what they do not agree
 * on is behind this interface, so that neither changes any result:
 *
 *   PageBehaviour       resultados/prova.php and prova_juiz.php, and the API
 *   PremiacaoBehaviour  Baja\Premiacao\Resolver
 *
 * Each difference is listed in the class comment of PremiacaoBehaviour.
 * Making them agree changes published results, so it is left for its own
 * change, with its own snapshot diff.
 */
interface Behaviour
{
    /**
     * The variables a team starts with, before its inputs are merged in.
     *
     * @return array<string, mixed>
     */
    public function teamVariables(TeamInput $firstInput): array;

    /**
     * The runs of a team, from its merged variables ("entries").
     *
     * @param array<string|int, mixed> $team
     * @return iterable<mixed>
     */
    public function runsOf(array $team): iterable;

    /** Works out every column, and the tiebreak, of one entry. */
    public function evaluate(Entry $entry, ResultsConfig $config, bool $hideSpoilers): void;

    /**
     * Ranks the entries and gives them back as rows, in the order to show them.
     *
     * @param list<Entry> $entries
     * @return list<ResultRow>
     */
    public function rows(array $entries, ResultsConfig $config): array;
}
