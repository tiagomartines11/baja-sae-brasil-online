<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\ResultsBuilder;
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

    /**
     * Without a "highlight", the results page compares each line with
     * nothing (null). In PHP, 0 and an empty text are "equal" to null, so
     * those lines end up in bold. That is how the page has always looked,
     * and it is kept.
     */
    public function testWithoutHighlightThePageBoldsEmptyAndZeroLines(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "val": ["$A", "$B", "$C"]}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['A' => 5, 'B' => 0, 'C' => ''])]);

        $this->assertSame('5<br /> <b>0</b><br /> <b></b>', $table->rows[0]->display('L'));
    }

    /** The premiação, in the same case, puts nothing in bold (a kept difference). */
    public function testWithoutHighlightThePremiacaoBoldsNothing(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "val": ["$A", "$B", "$C"]}]}');
        $table = Fixtures::build($config, [Fixtures::team(1, ['A' => 5, 'B' => 0, 'C' => ''])], null, ResultsBuilder::forPremiacao());

        $this->assertSame('5<br /> 0<br /> ', $table->rows[0]->display('L'));
    }
}
