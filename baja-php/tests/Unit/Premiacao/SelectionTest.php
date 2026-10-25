<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Premiacao;

use Baja\Premiacao\Selection;
use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Which teams win a premiação category (Baja\Premiacao\Selection).
 *
 * A category picks teams from a ranked resultado: by "positions" (e.g. [1]
 * for the winner, [3] for third place) or the first "top_n". The ranking is
 * the same as on the results page (see tests/Unit/Results/RankingTest.php).
 *
 * A team with no value to rank by has no position, and is never selected,
 * even when there are fewer ranked teams than the category asks for
 * (docs/results-rules.md, R3).
 *
 * (How to read these tests: see the comment at the top of
 * tests/Unit/Results/ColumnFormulasTest.php.)
 */
final class SelectionTest extends TestCase
{
    /**
     * Four teams, ranked by Pontos: car 1 has 90, cars 2 and 3 tie with 70,
     * car 4 has an empty cell (no value, so no position).
     *
     * @return list<\Baja\Results\ResultRow>
     */
    private function rankedRows(): array
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        return Fixtures::build($config, [
            Fixtures::team(4, ['PONTOS' => '']),
            Fixtures::team(3, ['PONTOS' => 70]),
            Fixtures::team(2, ['PONTOS' => 70]),
            Fixtures::team(1, ['PONTOS' => 90]),
        ], ViewerContext::premiacao())->rows;
    }

    /** "positions": [1] is the winner. */
    public function testPositionOneIsTheWinner(): void
    {
        $this->assertSame([1], Fixtures::numbers(Selection::apply($this->rankedRows(), ['positions' => [1]])));
    }

    /** Tied teams both win their position (ties are included by default with "positions"). */
    public function testTiedTeamsShareTheirPosition(): void
    {
        $this->assertSame([2, 3], Fixtures::numbers(Selection::apply($this->rankedRows(), ['positions' => [2]])));
    }

    /** "top_n" takes the first N ranked teams. */
    public function testTopN(): void
    {
        $this->assertSame([1, 2], Fixtures::numbers(Selection::apply($this->rankedRows(), ['top_n' => 2])));
    }

    /** A team with no position is never selected, even when top_n asks for more teams than are ranked. */
    public function testTeamWithoutPositionIsNeverSelected(): void
    {
        $this->assertSame([1, 2, 3], Fixtures::numbers(Selection::apply($this->rankedRows(), ['top_n' => 10])));
        $this->assertSame([1, 2, 3], Fixtures::numbers(Selection::apply($this->rankedRows(), [])));
    }

    /** When nobody has a value, nobody wins. */
    public function testNobodyRankedMeansNoWinner(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $rows = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 'DNF'])], ViewerContext::premiacao())->rows;

        $this->assertSame([], Selection::apply($rows, ['positions' => [1]]));
    }
}
