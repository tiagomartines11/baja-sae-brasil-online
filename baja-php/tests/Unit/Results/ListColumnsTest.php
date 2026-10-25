<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * List columns: a column whose "val" is a LIST of formulas shows one line
 * per formula, joined with "<br /> " (an HTML line break). A typical use is
 * a prova with two attempts, where the best one is put in bold with
 * "highlight".
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 */
final class ListColumnsTest extends TestCase
{
    private const TWO_ATTEMPTS = '{"colunas": [{
        "header": "Tempos",
        "val": ["$T1", "$T2"],
        "highlight": "min($T1, $T2)"
    }]}';

    /** Each formula is one line; the line equal to "highlight" is in bold. */
    public function testShowsOneLinePerFormulaWithTheHighlightInBold(): void
    {
        $table = Fixtures::build(Fixtures::config(self::TWO_ATTEMPTS), [
            Fixtures::team(1, ['T1' => 12.5, 'T2' => 11.0]),
            Fixtures::team(2, ['T1' => 9.75, 'T2' => 10.5]),
        ]);

        $this->assertSame([
            1 => '12.5<br /> <b>11</b>',
            2 => '<b>9.75</b><br /> 10.5',
        ], Fixtures::column($table->rows, 'Tempos'));
    }

    /** Two equal lines are both in bold. */
    public function testEqualLinesAreBothHighlighted(): void
    {
        $table = Fixtures::build(Fixtures::config(self::TWO_ATTEMPTS), [Fixtures::team(1, ['T1' => 10, 'T2' => 10])]);

        $this->assertSame('<b>10</b><br /> <b>10</b>', $table->rows[0]->display('Tempos'));
    }

    /**
     * The raw value of a list column is a list: one value per line, without
     * the bold. It is what a program (the API) reads.
     */
    public function testRawValueIsOneValuePerLine(): void
    {
        $table = Fixtures::build(Fixtures::config(self::TWO_ATTEMPTS), [Fixtures::team(1, ['T1' => 12.5, 'T2' => 11])]);

        $this->assertSame([12.5, 11], $table->rows[0]->cell('Tempos')->raw);
    }

    /** Without a "highlight", nothing is in bold (R6), not even empty or 0 lines. */
    public function testWithoutHighlightNothingIsBold(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "val": ["$A", "$B", "$C"]}]}');
        foreach ([null, ViewerContext::premiacao()] as $viewer) {
            $table = Fixtures::build($config, [Fixtures::team(1, ['A' => 5, 'B' => 0, 'C' => ''])], $viewer);

            $this->assertSame('5<br /> 0<br /> ', $table->rows[0]->display('L'));
        }
    }

    /** A "highlight" of "" is the same as none (R6). */
    public function testEmptyHighlightIsNone(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "val": ["$A", "$B"], "highlight": ""}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['A' => 5, 'B' => 0])]);

        $this->assertSame('5<br /> 0', $table->rows[0]->display('L'));
    }

    /** A "highlight" of "0" IS a formula (R6): it gives 0, so the 0 line is in bold. */
    public function testZeroHighlightIsAFormula(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "val": ["$A", "$B"], "highlight": "0"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['A' => 5, 'B' => 0])]);

        $this->assertSame('5<br /> <b>0</b>', $table->rows[0]->display('L'));
    }

    /** A highlight formula that gives nothing (broken, or a missing variable) puts nothing in bold. */
    public function testHighlightThatGivesNothingBoldsNothing(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "val": ["$A", "$B"], "highlight": "$NAO_EXISTE"}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['A' => 5, 'B' => ''])]);

        $this->assertSame('5<br /> ', $table->rows[0]->display('L'));
    }
}
