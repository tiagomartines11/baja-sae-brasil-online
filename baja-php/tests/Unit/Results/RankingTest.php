<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\ResultsBuilder;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Positions: which team is 1st, 2nd, 3rd...
 *
 * A resultado ranks its teams when it has a "pos": the header of the column
 * to rank by. "pos_order": "asc" ranks the LOWEST first (times); otherwise
 * the highest is first (points). A "tiebreak" formula decides between teams
 * with the same value.
 *
 * Teams that are still tied share a position, and the next one skips:
 * 1, 2, 2, 4 (like a sports table).
 *
 * Note that the results page does NOT show the rows in ranking order: it
 * shows them by car number (the browser sorts the table afterwards). So
 * these tests look at each team's position, not at the row order, unless
 * they say otherwise.
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 */
final class RankingTest extends TestCase
{
    private const POINTS = '{"pos": "Pontos", "colunas": [
        {"header": "Pos",    "val": "Pos"},
        {"header": "Pontos", "val": "$PONTOS"}
    ]}';

    private const TIMES = '{"pos": "Tempo", "pos_order": "asc", "colunas": [
        {"header": "Pos",   "val": "Pos"},
        {"header": "Tempo", "val": "$TEMPO"}
    ]}';

    /** Without "pos_order", the highest value is 1st. */
    public function testHighestFirstByDefault(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(1, ['PONTOS' => 50]),
            Fixtures::team(2, ['PONTOS' => 80]),
            Fixtures::team(3, ['PONTOS' => 65]),
        ]);

        $this->assertSame([1 => 3, 2 => 1, 3 => 2], Fixtures::positions($table->rows));
    }

    /** With "pos_order": "asc", the lowest value is 1st (a time, say). */
    public function testLowestFirstWithAsc(): void
    {
        $table = Fixtures::build(Fixtures::config(self::TIMES), [
            Fixtures::team(1, ['TEMPO' => 61.2]),
            Fixtures::team(2, ['TEMPO' => 59.9]),
            Fixtures::team(3, ['TEMPO' => 75.0]),
        ]);

        $this->assertSame([1 => 2, 2 => 1, 3 => 3], Fixtures::positions($table->rows));
    }

    /** Equal values share a position, and the next position is skipped: 1, 2, 2, 4. */
    public function testTiesSharePositionAndSkipTheNext(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(1, ['PONTOS' => 90]),
            Fixtures::team(2, ['PONTOS' => 70]),
            Fixtures::team(3, ['PONTOS' => 70]),
            Fixtures::team(4, ['PONTOS' => 10]),
        ]);

        $this->assertSame([1 => 1, 2 => 2, 3 => 2, 4 => 4], Fixtures::positions($table->rows));
    }

    /** The tiebreak decides between equal values, in the same direction (higher is better here). */
    public function testTiebreakSeparatesEqualValues(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "tiebreak": "$VOLTAS", "colunas": [
            {"header": "Pontos", "val": "$PONTOS"}
        ]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 70, 'VOLTAS' => 10]),
            Fixtures::team(2, ['PONTOS' => 70, 'VOLTAS' => 12]),
            Fixtures::team(3, ['PONTOS' => 70, 'VOLTAS' => 12]),
        ]);

        $this->assertSame([1 => 3, 2 => 1, 3 => 1], Fixtures::positions($table->rows));
        $this->assertSame(12, $table->rows[1]->tiebreak);
    }

    /** With "asc", the tiebreak also goes lowest first. */
    public function testTiebreakFollowsAsc(): void
    {
        $config = Fixtures::config('{"pos": "Tempo", "pos_order": "asc", "tiebreak": "$PENALIDADE", "colunas": [
            {"header": "Tempo", "val": "$TEMPO"}
        ]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['TEMPO' => 60, 'PENALIDADE' => 5]),
            Fixtures::team(2, ['TEMPO' => 60, 'PENALIDADE' => 1]),
        ]);

        $this->assertSame([1 => 2, 2 => 1], Fixtures::positions($table->rows));
    }

    /** The value is read as a number after removing HTML, so a bold total still ranks. */
    public function testRanksByTheNumberInsideHtml(): void
    {
        $config = Fixtures::config('{"pos": "Total", "colunas": [
            {"header": "Total", "val": "\"<b>\" . $PONTOS . \"</b>\""}
        ]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 9]),
            Fixtures::team(2, ['PONTOS' => 100]),
        ]);

        $this->assertSame([1 => 2, 2 => 1], Fixtures::positions($table->rows));
    }

    /** Without "pos" nobody has a position, and the "Pos" column is empty. */
    public function testNoPosMeansNoPositions(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Pos", "val": "Pos"}, {"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 1]), Fixtures::team(2, ['PONTOS' => 2])]);

        $this->assertSame([1 => null, 2 => null], Fixtures::positions($table->rows));
        $this->assertNull($table->rows[0]->display('Pos'));
    }

    /**
     * The page lists rows by the "ts" variable of each run (when a prova has
     * one), then by car number, whatever their position.
     */
    public function testPageListsRowsByTimestampThenCarNumber(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(3, ['PONTOS' => 99]),
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 50, 'ts' => 1700000000]),
        ]);

        $this->assertSame([1, 3, 2], Fixtures::numbers($table->rows), 'no ts counts as 0, so car 2 comes last');
    }

    /**
     * The premiação ranks in its own way (kept as it was): rows stay in
     * ranking order, ties are listed by car number, and "1,5" is read as 1.5.
     */
    public function testPremiacaoRankingOrderAndNumbers(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(7, ['PONTOS' => '1,5 pts']),
            Fixtures::team(5, ['PONTOS' => '1,2 pts']),
            Fixtures::team(3, ['PONTOS' => '1,5 pts']),
        ], null, ResultsBuilder::forPremiacao());

        $this->assertSame([3, 7, 5], Fixtures::numbers($table->rows));
        $this->assertSame([3 => 1, 7 => 1, 5 => 3], Fixtures::positions($table->rows));
    }

    /** On the page, the same text is read as 1 for everyone (up to the comma): all three tie. */
    public function testPageReadsTheSameTextOnlyUpToTheComma(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(7, ['PONTOS' => '1,5 pts']),
            Fixtures::team(5, ['PONTOS' => '1,2 pts']),
            Fixtures::team(3, ['PONTOS' => '1,5 pts']),
        ]);

        // They sort as equal (all 1), so they keep the order they came in:
        // 7, 5, 3. Positions then compare the TEXT with the row before, and
        // "1,2 pts" differs from "1,5 pts", so nobody shares a position.
        // (Listed by car number, as the page lists rows.)
        $this->assertSame([3 => 3, 5 => 2, 7 => 1], Fixtures::positions($table->rows));
    }
}
