<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\Column;
use Baja\Results\EventStatus;
use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Repeated columns hide the passes that have no result (BAJAAPP-103).
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A results column can show one value per attempt, one per line of the
 * cell. Written with "repetir", the formula is typed once with {n} where
 * the attempt number goes:
 *
 *     {"header": "Tentativas", "repetir": 6, "val": "$T_{n} !== null ? ROUND($T_{n}, 3) : null"}
 *
 * A team that ran 3 of its 6 attempts has no value (null) for attempts 4
 * to 6. In a column written with "repetir", those lines are left out of the
 * cell: the team shows 3 lines, not 3 lines and 3 blank ones. A value of
 * null or "" (empty text) is "no value"; 0 and "-" are values and show.
 *
 * A column written BY HAND as a list (no "repetir") shows every line,
 * blank ones included, exactly as it always did: the published results of
 * past events must not change.
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Results/RepeatedColumnEmptyLinesTest.php
 */
final class RepeatedColumnEmptyLinesTest extends TestCase
{
    /**
     * Six attempts; empty ones give null. "highlight" puts the best one in
     * bold: $T, the best time, which the prova works out (here typed by hand).
     */
    private const SIX_ATTEMPTS = '{"colunas": [{
        "header": "Tentativas", "repetir": 6, "sort": "duasmenor",
        "val": "$T_{n} !== null ? ROUND($T_{n}, 3) : null",
        "highlight": "$T"
    }]}';

    /** Attempts 1 to 3 were run, 4 to 6 not: three lines, the best in bold. */
    public function testOnlyThePassesWithAResultAreShown(): void
    {
        $team = Fixtures::team(7, ['T_1' => 4.5123, 'T_2' => 4.25, 'T_3' => 4.3, 'T_4' => null, 'T_5' => null, 'T_6' => null, 'T' => 4.25]);

        $table = Fixtures::build(Fixtures::config(self::SIX_ATTEMPTS), [$team]);

        // Three lines, two line breaks, nothing after the last one.
        $this->assertSame('4.512<br /> <b>4.25</b><br /> 4.3', $table->rows[0]->display('Tentativas'));
    }

    /** "" (empty text) counts as no value too, for a formula ending in : ''. */
    public function testAnEmptyTextIsLeftOutToo(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Tentativas", "repetir": 4, "val": "$T_{n} ? ROUND($T_{n}, 3) : \'\'"}]}');

        $table = Fixtures::build($config, [Fixtures::team(7, ['T_1' => 4.1, 'T_3' => 4.3])]);

        // Attempt 2 is empty but between two runs: it is left out as well.
        $this->assertSame('4.1<br /> 4.3', $table->rows[0]->display('Tentativas'));
    }

    /** A team with no attempt at all has an empty cell: no line, no line break. */
    public function testNoPassAtAllGivesAnEmptyCell(): void
    {
        $table = Fixtures::build(Fixtures::config(self::SIX_ATTEMPTS), [Fixtures::team(8, ['T' => null])]);

        $this->assertSame('', $table->rows[0]->display('Tentativas'));
    }

    /** 0 and "-" are values, not empty: they still show. */
    public function testZeroAndDashStillShow(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "repetir": 3, "val": "$A_{n}"}]}');

        $table = Fixtures::build($config, [Fixtures::team(1, ['A_1' => 0, 'A_2' => '-', 'A_3' => null])]);

        $this->assertSame('0<br /> -', $table->rows[0]->display('L'));
    }

    /**
     * The API's value (the "raw" value) keeps one entry per attempt, null
     * where there was none: its place in the list still says which attempt
     * it is, and null is not a value for whoever reads it.
     */
    public function testTheApiStillGetsOneValuePerAttemptWithNullForTheEmptyOnes(): void
    {
        $team = Fixtures::team(7, ['T_1' => 4.5, 'T_2' => 4.25, 'T_3' => null, 'T_4' => null, 'T_5' => null, 'T_6' => null, 'T' => 4.25]);

        $table = Fixtures::build(Fixtures::config(self::SIX_ATTEMPTS), [$team]);

        $this->assertSame([4.5, 4.25, null, null, null, null], $table->rows[0]->cell('Tentativas')->raw);
    }

    /**
     * The ranking reads a cell's first number. With the empty lines gone
     * nothing changes there: an empty line never had a number.
     */
    public function testRankingByARepeatedColumnStillReadsTheFirstValue(): void
    {
        $config = Fixtures::config('{"pos": "Tentativas", "pos_order": "asc", "colunas": [
            {"header": "Tentativas", "repetir": 3, "val": "$T_{n}"}
        ]}');

        $table = Fixtures::build($config, [
            Fixtures::team(1, ['T_1' => null, 'T_2' => 5.0, 'T_3' => null]),
            Fixtures::team(2, ['T_1' => 4.0, 'T_2' => null, 'T_3' => null]),
            Fixtures::team(3, []),
        ]);

        $this->assertSame([2 => 1, 1 => 2, 3 => null], Fixtures::positions($table->rows));
    }

    /**
     * A column written by hand as a list keeps every line, even an empty
     * one: past events' results must look exactly as they were published.
     */
    public function testAHandWrittenListIsUnchanged(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "L", "val": ["$T_1", "$T_2", "$T_3"], "highlight": "$T"}]}');

        $table = Fixtures::build($config, [Fixtures::team(1, ['T_1' => 5, 'T_2' => null, 'T_3' => 6, 'T' => 6])]);

        $this->assertSame('5<br /> <br /> <b>6</b>', $table->rows[0]->display('L'));
    }

    /**
     * How a column knows it came from "repetir": Column::fromJson() marks
     * it while expanding. A hand-written list is not marked, and neither is
     * a "repetir" that could not be expanded (the config check reports it).
     */
    public function testOnlyAnExpandedRepeatedColumnIsMarked(): void
    {
        $this->assertTrue(Column::fromJson(json_decode('{"header": "A", "repetir": 2, "val": "$T_{n}"}'))->repeated);
        $this->assertFalse(Column::fromJson(json_decode('{"header": "A", "val": ["$T_1", "$T_2"]}'))->repeated);
        $this->assertFalse(Column::fromJson(json_decode('{"header": "A", "repetir": 2, "val": "$T"}'))->repeated, 'no {n}: not expanded');
        $this->assertFalse(Column::fromJson(json_decode('{"header": "A", "repetir": 0, "val": "$T_{n}"}'))->repeated, 'bad repetir: not expanded');
    }

    /**
     * While spoilers are on, a "val_spoilers" with {n} is repeated too, and
     * leaves out its empty lines the same way.
     */
    public function testSpoilerLinesLeaveOutTheEmptyOnesToo(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "T", "repetir": 3, "val": "$T_{n}", "val_spoilers": "$T_{n} !== null ? \'?\' : null"}]}');
        $running = ViewerContext::publicView(new EventStatus(spoilers: true, finished: false));

        $table = Fixtures::build($config, [Fixtures::team(1, ['T_1' => 4.0, 'T_2' => null, 'T_3' => null])], $running);

        $this->assertSame('?', $table->rows[0]->display('T'));
    }
}
