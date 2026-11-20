<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Posicoes;

use Baja\Posicoes\ExternalPositions;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * A position taken from another resultado's ranking (BAJAAPP-102):
 *
 *     "posicoes": {"P_TRA": {"resultado": "26SU_TRA"}}
 *
 * The resultado's table is built by the same builder as its results page
 * (see tests/Unit/Results for its rules), and each team gets the position
 * of its row. These tests build small tables in memory with the results
 * tests' Fixtures and check what ExternalPositions::ofTable() reads off
 * them. tests/Prova/Db/ExternalPositionsAgainstSeedTest checks the same
 * against every real results page of the dev seed.
 */
final class ExternalPositionsTest extends TestCase
{
    /** Ties share a position (1, 1, 3); a team with no value has none and is left out. */
    public function testPositionsAreTheRowsPositions(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 30]),
            Fixtures::team(3, ['PONTOS' => 30]),
            Fixtures::team(4, []),
        ]);

        $this->assertSame([2 => 1, 3 => 1, 1 => 3], ExternalPositions::ofTable($table));
    }

    /** The resultado's "tiebreak" separates a tie, as on its page. */
    public function testTheTiebreakApplies(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "tiebreak": "$T", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 30, 'T' => 1]),
            Fixtures::team(2, ['PONTOS' => 30, 'T' => 2]),
        ]);

        $this->assertSame([2 => 1, 1 => 2], ExternalPositions::ofTable($table));
    }

    /** A team the resultado's "filter" leaves out has no position. */
    public function testTheFilterApplies(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "filter": "OK", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 10], ['OK' => 1]),
            Fixtures::team(2, ['PONTOS' => 99], ['OK' => 0]),
        ]);

        $this->assertSame([1 => 1], ExternalPositions::ofTable($table));
    }

    /**
     * A disqualified team is ranked on the results page like any other
     * (tests/Unit/Results/TeamEntriesTest), so it is here too: the position
     * is the page's, whatever it is.
     */
    public function testADisqualifiedTeamKeepsThePagesPosition(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 99], [], 'MG', true),
        ]);

        $this->assertSame([2 => 1, 1 => 2], ExternalPositions::ofTable($table));
    }

    /** A team with several runs, so several rows, gets its best position. */
    public function testATeamWithSeveralRunsGetsItsBest(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['entries' => [['PONTOS' => 5], ['PONTOS' => 50]]]),
            Fixtures::team(2, ['PONTOS' => 20]),
        ]);

        $this->assertSame([1 => 1, 2 => 2], ExternalPositions::ofTable($table));
    }
}
