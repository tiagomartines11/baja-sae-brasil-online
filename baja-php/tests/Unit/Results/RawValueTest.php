<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\EventStatus;
use Baja\Results\RawValue;
use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * Every cell has two values: "display", exactly what the page shows (it
 * may contain HTML), and "raw", the same thing for a program to read (the
 * results API, BAJAAPP-9). Raw has the HTML removed, and is a number when
 * the text is a number.
 *
 * A column can also say how its raw value is worked out, with its own
 * formula "val_api", for when the display is not easy to read back (a time
 * shown as "1:02.5", say).
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 */
final class RawValueTest extends TestCase
{
    /** Numbers stay numbers; nothing to do. */
    public function testNumbersStayAsTheyAre(): void
    {
        $this->assertSame(12, RawValue::of(12));
        $this->assertSame(12.5, RawValue::of(12.5));
        $this->assertNull(RawValue::of(null));
    }

    /** HTML is removed, and text that is a number becomes one. */
    public function testHtmlIsRemovedAndNumbersAreRead(): void
    {
        $this->assertSame(12.5, RawValue::of('<b>12.5</b>'));
        $this->assertSame(7, RawValue::of(' 7 '));
        $this->assertSame(-3, RawValue::of('<span style="color:red">-3</span>'));
    }

    /** Text that is not a number as a whole stays text, without its HTML. */
    public function testOtherTextStaysText(): void
    {
        $this->assertSame('53.9%', RawValue::of('<b>53.9%</b>'));
        $this->assertSame('DNF', RawValue::of('DNF<br />&nbsp;'));
        $this->assertSame('Ação', RawValue::of('A&ccedil;&atilde;o'));
    }

    /** An empty cell has no raw value. */
    public function testEmptyIsNull(): void
    {
        $this->assertNull(RawValue::of(''));
        $this->assertNull(RawValue::of('<br />'));
    }

    /** In a table, the raw value comes from the display. */
    public function testCellRawValueComesFromTheDisplay(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Total", "val": "\"<b>\" . $PONTOS . \"</b>\""}]}');
        $cell = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 81.25])])->rows[0]->cell('Total');

        $this->assertSame('<b>81.25</b>', $cell->display);
        $this->assertSame(81.25, $cell->raw);
    }

    /** With "val_api", the raw value is that formula's value instead; the display does not change. */
    public function testValApiGivesTheRawValue(): void
    {
        $config = Fixtures::config('{"colunas": [{
            "header": "Tempo",
            "val": "floor($SEGUNDOS / 60) . \":\" . ($SEGUNDOS % 60)",
            "val_api": "$SEGUNDOS"
        }]}');
        $cell = Fixtures::build($config, [Fixtures::team(1, ['SEGUNDOS' => 125])])->rows[0]->cell('Tempo');

        $this->assertSame('2:5', $cell->display);
        $this->assertSame(125, $cell->raw);
    }

    /**
     * While spoilers hide a column, "val_api" is NOT used: otherwise the
     * API would give away the very value the page hides.
     */
    public function testValApiIsNotUsedWhileSpoilersHideTheColumn(): void
    {
        $config = Fixtures::config('{"colunas": [{
            "header": "Pontos",
            "val": "$PONTOS",
            "val_spoilers": "\"?\"",
            "val_api": "$PONTOS"
        }]}');
        $duringEvent = ViewerContext::publicView(new EventStatus(spoilers: true, finished: false));
        $cell = Fixtures::build($config, [Fixtures::team(1, ['PONTOS' => 42])], $duringEvent)->rows[0]->cell('Pontos');

        $this->assertSame('?', $cell->display);
        $this->assertSame('?', $cell->raw);
    }

    /** Team and position columns give their plain values as raw too. */
    public function testTeamAndPositionColumnsAreRawAlready(): void
    {
        $config = Fixtures::config('{"pos": "P", "colunas": [
            {"header": "Pos", "val": "Pos"},
            {"header": "#", "val": "EquipeNum"},
            {"header": "P", "val": "$P"}
        ]}');
        $row = Fixtures::build($config, [Fixtures::team(9, ['P' => 1])])->rows[0];

        $this->assertSame(1, $row->cell('Pos')->raw);
        $this->assertSame(9, $row->cell('#')->raw);
    }
}
