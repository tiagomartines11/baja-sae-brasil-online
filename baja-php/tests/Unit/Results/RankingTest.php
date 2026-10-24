<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\ResultRow;
use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Positions: which team is 1st, 2nd, 3rd... (docs/results-rules.md, R2 to
 * R5 and R10). The results pages and the premiação rank the same way.
 *
 * A resultado ranks its teams when it has a "pos": the header of the column
 * to rank by. "pos_order": "asc" ranks the LOWEST first (times); otherwise
 * the highest is first (points). A "tiebreak" formula separates teams with
 * the same value.
 *
 * $table->rows is in POSITION order (best first), which is also how the
 * premiação lists them. The results page lists rows differently (by car
 * number, see the last tests), and the browser re-sorts them anyway.
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

    /** Without "pos_order", the highest value is 1st. */
    public function testHighestFirstByDefault(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(1, ['PONTOS' => 50]),
            Fixtures::team(2, ['PONTOS' => 80]),
            Fixtures::team(3, ['PONTOS' => 65]),
        ]);

        $this->assertSame([2 => 1, 3 => 2, 1 => 3], Fixtures::positions($table->rows));
    }

    /** With "pos_order": "asc", the lowest value is 1st (a time, say). Capital letters are fine. */
    public function testLowestFirstWithAscInAnyCase(): void
    {
        foreach (['asc', 'ASC', ' Asc '] as $order) {
            $config = Fixtures::config('{"pos": "Tempo", "pos_order": "' . $order . '", "colunas": [{"header": "Tempo", "val": "$TEMPO"}]}');
            $table = Fixtures::build($config, [
                Fixtures::team(1, ['TEMPO' => 61.2]),
                Fixtures::team(2, ['TEMPO' => 59.9]),
            ]);

            $this->assertSame([2 => 1, 1 => 2], Fixtures::positions($table->rows), "pos_order \"$order\"");
        }
    }

    /** Equal values share a position, the next one is skipped (1, 2, 2, 4), and tied teams go by car number. */
    public function testTiesSharePositionAndAreListedByCarNumber(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(1, ['PONTOS' => 90]),
            Fixtures::team(9, ['PONTOS' => 70]),
            Fixtures::team(3, ['PONTOS' => 70]),
            Fixtures::team(4, ['PONTOS' => 10]),
        ]);

        $this->assertSame([1 => 1, 3 => 2, 9 => 2, 4 => 4], Fixtures::positions($table->rows));
    }

    /** Equal NUMBERS tie, whatever the text around them: 74.81 and "74.81 Finalista" are equal. */
    public function testEqualNumbersTieEvenWithDifferentText(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$TXT"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['TXT' => '<b>74.81<br><span>Finalista</span></b>']),
            Fixtures::team(2, ['TXT' => '<b>74.81</b>']),
            Fixtures::team(3, ['TXT' => '74,81']),
        ]);

        $this->assertSame([1 => 1, 2 => 1, 3 => 1], Fixtures::positions($table->rows));
    }

    /** The tiebreak separates equal values, in the same direction (higher is better here). */
    public function testTiebreakSeparatesEqualValues(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "tiebreak": "$VOLTAS", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 70, 'VOLTAS' => 10]),
            Fixtures::team(2, ['PONTOS' => 70, 'VOLTAS' => 12]),
            Fixtures::team(3, ['PONTOS' => 70, 'VOLTAS' => 12]),
        ]);

        // Cars 2 and 3 are still tied after the tiebreak: they share 1st.
        $this->assertSame([2 => 1, 3 => 1, 1 => 3], Fixtures::positions($table->rows));
    }

    /** With "asc", the tiebreak also goes lowest first. */
    public function testTiebreakFollowsAsc(): void
    {
        $config = Fixtures::config('{"pos": "Tempo", "pos_order": "asc", "tiebreak": "$PENALIDADE", "colunas": [{"header": "Tempo", "val": "$TEMPO"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['TEMPO' => 60, 'PENALIDADE' => 5]),
            Fixtures::team(2, ['TEMPO' => 60, 'PENALIDADE' => 1]),
        ]);

        $this->assertSame([2 => 1, 1 => 2], Fixtures::positions($table->rows));
    }

    /** The tiebreak is read like any value (R2): HTML ignored, "," a decimal point. */
    public function testTiebreakIsReadAsANumber(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "tiebreak": "$TB", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 70, 'TB' => '<b>1,2</b>']),
            Fixtures::team(2, ['PONTOS' => 70, 'TB' => '1,5']),
        ]);

        $this->assertSame([2 => 1, 1 => 2], Fixtures::positions($table->rows));
    }

    /** A team whose tiebreak has no value goes after the tied teams whose tiebreak has one. */
    public function testTiebreakWithoutValueComesLast(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "tiebreak": "$TB", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 70, 'TB' => 'DNF']),
            Fixtures::team(2, ['PONTOS' => 70, 'TB' => -100]),
        ]);

        $this->assertSame([2 => 1, 1 => 2], Fixtures::positions($table->rows));
    }

    /** Without a tiebreak, the same teams simply tie. */
    public function testWithoutTiebreakEqualValuesTie(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(2, ['PONTOS' => 70, 'VOLTAS' => 10]),
            Fixtures::team(1, ['PONTOS' => 70, 'VOLTAS' => 12]),
        ]);

        $this->assertSame([1 => 1, 2 => 1], Fixtures::positions($table->rows));
    }

    /**
     * A team with no value (empty cell, no number, or only struck-through
     * content) gets NO position (R3): the Pos column shows "–", and it comes
     * after every ranked team, by car number. It never ties with anyone.
     */
    public function testTeamsWithoutValueHaveNoPosition(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(5, ['PONTOS' => '<s>40</s>']),
            Fixtures::team(4, ['PONTOS' => 10]),
            Fixtures::team(3, ['PONTOS' => '']),
            Fixtures::team(2, ['PONTOS' => 0]),
        ]);

        $this->assertSame([4 => 1, 2 => 2, 3 => null, 5 => null], Fixtures::positions($table->rows));
        $this->assertSame(ResultRow::NO_POSITION, $table->rows[2]->display('Pos'));
        $this->assertNull($table->rows[2]->cell('Pos')->raw);
    }

    /** When NO team has a value (a broken "Pontos" formula, say), nobody is ranked. */
    public function testAllTeamsWithoutValue(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pos", "val": "Pos"}, {"header": "Pontos", "val": "\"<b>\" + $P"}]}');
        $table = Fixtures::build($config, [Fixtures::team(2, ['P' => 1]), Fixtures::team(1, ['P' => 2])]);

        $this->assertSame([1 => null, 2 => null], Fixtures::positions($table->rows));
        $this->assertSame(['–', '–'], array_map(fn($row) => $row->display('Pos'), $table->rows));
    }

    /** The case from 25BR_P2X: struck-through scores are ignored, so zeroed teams tie. */
    public function testStruckThroughScoresAreIgnored(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [{"header": "Pontos", "val": "$TXT"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(35, ['TXT' => '<b>0</b><br> <s>13.87</s>']),
            Fixtures::team(40, ['TXT' => '<b>0</b><br> <s>26.08</s>']),
            Fixtures::team(39, ['TXT' => '<b>20.88</b>']),
        ]);

        $this->assertSame([39 => 1, 35 => 2, 40 => 2], Fixtures::positions($table->rows));
    }

    /** "pos" may name a formula variable when no column has that header (R5). */
    public function testPosCanNameAVariable(): void
    {
        $config = Fixtures::config('{"pos": "PONTOS", "colunas": [{"header": "#", "val": "EquipeNum"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 5]), Fixtures::team(2, ['PONTOS' => 9])]);

        $this->assertSame([2 => 1, 1 => 2], Fixtures::positions($table->rows));
    }

    /** A column wins over a variable of the same name: here the column doubles the points. */
    public function testColumnWinsOverVariableOfTheSameName(): void
    {
        $config = Fixtures::config('{"pos": "PONTOS", "colunas": [{"header": "PONTOS", "val": "-$PONTOS"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 5]), Fixtures::team(2, ['PONTOS' => 9])]);

        $this->assertSame([1 => 1, 2 => 2], Fixtures::positions($table->rows), '-5 is more than -9');
    }

    /** "pos" naming nothing at all (a typo, say) leaves every team without a value. */
    public function testPosNamingNothing(): void
    {
        $config = Fixtures::config('{"pos": "Total", "colunas": [{"header": "Pontos", "val": "$PONTOS"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 5])]);

        $this->assertSame([1 => null], Fixtures::positions($table->rows));
    }

    /** Without "pos" (or with "pos": ""), nobody is ranked. */
    public function testNoPosMeansNoRanking(): void
    {
        foreach (['{', '{"pos": "", '] as $start) {
            $config = Fixtures::config($start . '"colunas": [{"header": "Pos", "val": "Pos"}, {"header": "Pontos", "val": "$PONTOS"}]}');
            $table = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 1]), Fixtures::team(2, ['PONTOS' => 2])]);

            $this->assertSame([1 => null, 2 => null], Fixtures::positions($table->rows));
            $this->assertSame('–', $table->rows[0]->display('Pos'));
        }
    }

    /** The premiação ranks exactly like the page (only its viewer differs). */
    public function testPremiacaoRanksLikeThePage(): void
    {
        $inputs = [
            Fixtures::team(7, ['PONTOS' => '1,5 pts']),
            Fixtures::team(5, ['PONTOS' => '1,2 pts']),
            Fixtures::team(3, ['PONTOS' => '1,5 pts']),
        ];
        $page = Fixtures::build(Fixtures::config(self::POINTS), $inputs);
        $premiacao = Fixtures::build(Fixtures::config(self::POINTS), $inputs, ViewerContext::premiacao());

        $this->assertSame([3 => 1, 7 => 1, 5 => 3], Fixtures::positions($page->rows));
        $this->assertSame(Fixtures::positions($page->rows), Fixtures::positions($premiacao->rows));
    }

    /**
     * The results page lists rows by the "ts" variable of each run (when a
     * prova has one), then by car number, whatever their position (R10).
     */
    public function testPageOrderIsByTimestampThenCarNumber(): void
    {
        $table = Fixtures::build(Fixtures::config(self::POINTS), [
            Fixtures::team(3, ['PONTOS' => 99]),
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 50, 'ts' => 1700000000]),
        ]);

        $this->assertSame([3, 2, 1], Fixtures::numbers($table->rows), 'position order');
        $this->assertSame([1, 3, 2], Fixtures::numbers($table->pageOrder()), 'no ts counts as 0, so car 2 comes last');
    }
}
