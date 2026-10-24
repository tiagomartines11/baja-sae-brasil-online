<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results;

use Baja\Results\Rules\Numbers;
use PHPUnit\Framework\TestCase;

/**
 * Rule R2 (docs/results-rules.md): how a cell is read as a number for the
 * ranking and the tiebreak.
 *
 * A cell often holds HTML: a bold total, two lines, or a score that no
 * longer counts shown struck through (<s>13.87</s>). To read it:
 *
 *   1. drop struck-through content (<s>, <strike>, <del> and what's inside);
 *   2. remove the rest of the HTML;
 *   3. take the FIRST number left: optional minus, digits, optional
 *      decimal part with "." or "," ("," is a decimal point, as in Brazil).
 *
 * No number left means "no value": the team then gets no position (R3).
 *
 * (How to read these tests: see the comment at the top of
 * ColumnFormulasTest.)
 */
final class NumbersTest extends TestCase
{
    /** Plain numbers are read as they are. */
    public function testPlainNumbers(): void
    {
        $this->assertSame(12.0, Numbers::read(12));
        $this->assertSame(81.25, Numbers::read(81.25));
        $this->assertSame(81.25, Numbers::read('81.25'));
    }

    /** HTML around the number does not matter. */
    public function testHtmlIsIgnored(): void
    {
        $this->assertSame(812.4, Numbers::read('<b>812.4</b>'));
        $this->assertSame(5.0, Numbers::read('<span style="color:red">5</span> pts'));
    }

    /** "," is a decimal point: "1,5 pts" is one and a half. */
    public function testCommaIsADecimalPoint(): void
    {
        $this->assertSame(1.5, Numbers::read('1,5 pts'));
        $this->assertSame(1.5, Numbers::read('1.5 pts'));
    }

    /** A minus sign right before the digits makes the number negative. */
    public function testNegativeNumbers(): void
    {
        $this->assertSame(-15.0, Numbers::read('-15'));
        $this->assertSame(-0.5, Numbers::read('<b>-0,5</b>'));
    }

    /** Only the FIRST number counts; the rest of the text is ignored. */
    public function testFirstNumberWins(): void
    {
        $this->assertSame(53.9, Numbers::read('<b>53.9%</b><br /> 14.37'));
    }

    /**
     * The case from 25BR_P2X: a team whose points were zeroed shows "0" and,
     * struck through, what it would have scored. It is read as 0.
     */
    public function testStruckThroughLineIsIgnored(): void
    {
        $this->assertSame(0.0, Numbers::read('<b>0</b><br /> <s>13.87</s>'));
    }

    /** Struck-through content is dropped wherever it is, even before the real value. */
    public function testStruckFirstLineThenPlainLine(): void
    {
        $this->assertSame(42.0, Numbers::read('<s>99</s><br /> 42'));
        $this->assertSame(42.0, Numbers::read('<strike>99</strike> 42'));
        $this->assertSame(42.0, Numbers::read('<DEL class="x">99</DEL> 42'));
    }

    /** A cell that is ONLY struck through has no value. */
    public function testOnlyStruckThroughMeansNoValue(): void
    {
        $this->assertNull(Numbers::read('<s>12</s>'));
        $this->assertNull(Numbers::read('<s><b>12</b> <s>3</s></s>'), 'nested ones go too');
    }

    /** <strong>, <span> and <sup> are not struck through: only s, strike and del are. */
    public function testOtherTagsStartingWithSAreNotStruck(): void
    {
        $this->assertSame(7.0, Numbers::read('<strong>7</strong>'));
        $this->assertSame(7.0, Numbers::read('<sup>7</sup>'));
    }

    /** Empty, text without digits, and null have no value. 0 IS a value. */
    public function testEmptyIsNoValueButZeroIsAValue(): void
    {
        $this->assertNull(Numbers::read(''));
        $this->assertNull(Numbers::read(null));
        $this->assertNull(Numbers::read('DNF'));
        $this->assertNull(Numbers::read('SPOILERS'));
        $this->assertSame(0.0, Numbers::read('0'));
        $this->assertSame(0.0, Numbers::read(0));
    }

    /** A line break between two numbers keeps them apart: "12<br>3" is 12, not 123. */
    public function testLineBreakSeparatesNumbers(): void
    {
        $this->assertSame(12.0, Numbers::read('12<br>3'));
    }

    /** exact() is for raw values: a number only when the text is exactly one. */
    public function testExactNumber(): void
    {
        $this->assertSame(12.5, Numbers::exact('12,5'));
        $this->assertSame(-3, Numbers::exact('-3'));
        $this->assertNull(Numbers::exact('12,5 pts'));
        $this->assertNull(Numbers::exact('1e3'));
    }
}
