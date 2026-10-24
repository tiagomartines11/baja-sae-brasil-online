<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\ResultsBuilder;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * How the builder works out each column of a results table.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment:
 *
 *   1. it describes a resultado: its columns, written as the JSON the
 *      organisers type in the judge pages (Fixtures::config);
 *   2. it makes a few teams with made-up scores (Fixtures::team);
 *   3. it builds the table (Fixtures::build), as the results page would;
 *   4. it "asserts" what must come out. assertSame(expected, actual) fails
 *      the test, and shows both values, when they are not exactly equal
 *      (same value AND same type: 10 is not "10").
 *
 * A column's "val" is a formula: a small piece of PHP. $PONTOS in it means
 * "this team's variable PONTOS". Input::solveFormula() works it out; these
 * tests do not change how, they check what the builder does around it.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Results/ColumnFormulasTest.php
 */
final class ColumnFormulasTest extends TestCase
{
    /** A formula column shows the formula's value for each team. */
    public function testFormulaColumnShowsTheValueOfTheFormula(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Dobro", "val": "$PONTOS * 2"}]}');
        $table = Fixtures::build($config, [
            Fixtures::team(1, ['PONTOS' => 10]),
            Fixtures::team(2, ['PONTOS' => 7.5]),
        ]);

        $this->assertSame([1 => 20, 2 => 15.0], Fixtures::column($table->rows, 'Dobro'));
    }

    /**
     * A formula can write text, including HTML. It is shown as it is: the
     * "display" of a cell is exactly what the page puts in the table.
     */
    public function testFormulaCanProduceHtml(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Status", "val": "\"<b>\" . $STATUS . \"</b>\""}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['STATUS' => 'OK'])]);

        $this->assertSame('<b>OK</b>', $table->rows[0]->display('Status'));
    }

    /**
     * On the results page, a column can use an EARLIER column by its header.
     * Here "Total" adds up the columns "Pontos" and "Bonus".
     */
    public function testLaterColumnCanReadAnEarlierColumnByHeader(): void
    {
        $config = Fixtures::config('{"colunas": [
            {"header": "Pontos", "val": "$P1 + $P2"},
            {"header": "Bonus",  "val": "$B"},
            {"header": "Total",  "val": "$Pontos + $Bonus"}
        ]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['P1' => 3, 'P2' => 4, 'B' => 10])]);

        $this->assertSame(17, $table->rows[0]->display('Total'));
    }

    /**
     * The premiação has never been able to do that: there "$Pontos" is a
     * variable of the inputs, and this team has none, so the formula gives
     * nothing. This difference is kept on purpose (see PremiacaoBehaviour);
     * fixing it would change awards, so it needs its own change.
     */
    public function testPremiacaoCannotReadAnEarlierColumn(): void
    {
        $config = Fixtures::config('{"colunas": [
            {"header": "Pontos", "val": "$P1 + $P2"},
            {"header": "Total",  "val": "$Pontos + 1"}
        ]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['P1' => 3, 'P2' => 4])], null, ResultsBuilder::forPremiacao());

        $this->assertSame(7, $table->rows[0]->display('Pontos'));
        $this->assertSame(1, $table->rows[0]->display('Total'), '$Pontos is undefined (null), and null + 1 is 1');
    }

    /** "EquipeNum", "Equipe" and "Pos" are not formulas: they show the team and its position. */
    public function testSpecialColumnsShowTheTeam(): void
    {
        $config = Fixtures::config('{"pos": "Pontos", "colunas": [
            {"header": "Pos",    "val": "Pos"},
            {"header": "#",      "val": "EquipeNum"},
            {"header": "Equipe", "val": "Equipe"},
            {"header": "Pontos", "val": "$PONTOS"}
        ]}');
        $table = Fixtures::build($config, [Fixtures::team(42, ['PONTOS' => 5])]);
        $row = $table->rows[0];

        $this->assertSame(1, $row->display('Pos'));
        $this->assertSame(42, $row->display('#'));
        $this->assertSame('Equipe 42', $row->display('Equipe'));
        $this->assertSame('Escola 42', $row->team->school);
        $this->assertSame('SP', $row->team->state);
    }

    /**
     * A formula that is broken (here "+" on text, which PHP 8 refuses) does
     * not break the page: the cell is just empty (null).
     */
    public function testBrokenFormulaGivesAnEmptyCell(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "X", "val": "\"<b>\" + $PONTOS"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 5])]);

        $this->assertNull($table->rows[0]->display('X'));
    }

    /** The table keeps the columns in the order they were written. */
    public function testColumnsKeepTheirOrder(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "B", "val": "1"}, {"header": "A", "val": "2"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, [])]);

        $this->assertSame(['B', 'A'], array_map(fn($column) => $column->header, $table->columns));
        $this->assertSame(['B', 'A'], array_keys($table->rows[0]->cells()));
    }

    /** A resultado with no columns at all still lists its teams; the page then shows its notice instead. */
    public function testNoColumns(): void
    {
        $table = Fixtures::build(Fixtures::config('{}'), [Fixtures::team(1, [])]);

        $this->assertSame([], $table->columns);
        $this->assertSame([1], Fixtures::numbers($table->rows));
    }
}
