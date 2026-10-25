<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\ResultsCheck\NestedTernary;
use PHPUnit\Framework\TestCase;

/**
 * The two readings of a nested ternary, as the report shows them.
 *
 * BACKGROUND
 *
 * "a ? b : c" is PHP's short if/else: if a, then b, else c. Put two side
 * by side with no brackets and it is ambiguous:
 *
 *     $A ? 1 : $B ? 2 : 3
 *
 *   PHP 7 read it as    ($A ? 1 : $B) ? 2 : 3     (the first one first)
 *   people mean         $A ? 1 : ($B ? 2 : 3)     (if $A then 1, else if $B then 2, else 3)
 *
 * PHP 8 stopped guessing: it refuses to compile such a formula, with a
 * FATAL error that ends the process. The check (IsolatedCheck) survives
 * that by running each resultado in a process of its own; NestedTernary
 * then writes the formula both ways, for a person to choose. It never
 * compiles the formula itself, so it is safe to call on one that would
 * kill PHP.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/NestedTernaryTest.php
 */
final class NestedTernaryTest extends TestCase
{
    /** The formula of 18SU's APR prova that stopped the check on production (BAJAAPP-63). */
    private const APR = '($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))';

    /**
     * The real case. Only the inner group is ambiguous (the outer "? :" has
     * its "else" in brackets already), so only it gets new brackets. The
     * rest, double spaces included, is left exactly as written, so the two
     * readings can be compared with the formula character by character.
     */
    public function testTheProductionFormulaGetsBothReadings(): void
    {
        $this->assertSame([
            'php7' => '($DTA == 1 ?  0 : (($DTA == 2 ?  -30 : $DTA == 3) ?  -45 : -90))',
            'meant' => '($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : ($DTA == 3 ?  -45 : -90)))',
        ], NestedTernary::readings(self::APR));
    }

    /**
     * Why a person must choose. Both readings are valid PHP 8, and they do
     * not give the same points: with 2 days late ($DTA = 2), PHP 7's reading
     * first works out ($DTA == 2 ? -30 : ...), which is -30, a "true"
     * value, and so answers -45. The reading the author surely meant answers
     * -30. Points worked out while production ran PHP 7 came from the
     * first reading, so picking one changes points: a person's decision.
     */
    public function testTheTwoReadingsGiveDifferentPoints(): void
    {
        $readings = NestedTernary::readings(self::APR);

        $points = [];
        foreach ([1, 2, 3, 4] as $dta) {
            $points['php7'][$dta] = self::evaluate($readings['php7'], $dta);
            $points['meant'][$dta] = self::evaluate($readings['meant'], $dta);
        }

        $this->assertSame([1 => 0, 2 => -45, 3 => -45, 4 => -90], $points['php7']);
        $this->assertSame([1 => 0, 2 => -30, 3 => -45, 4 => -90], $points['meant']);
    }

    /**
     * And the formula as written really is fatal in PHP 8. This is checked
     * in a separate "php" process: in this one it would end PHPUnit itself,
     * which is exactly the problem the check had.
     */
    public function testTheFormulaAsWrittenKillsPhp8(): void
    {
        $code = '$DTA = 2; @eval(' . var_export('return ' . self::APR . ';', true) . '); echo "still alive";';
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $output, $exitCode);

        $this->assertSame(255, $exitCode, 'PHP exits with 255 after a fatal error.');
        $this->assertStringContainsString('Unparenthesized `a ? b : c ? d : e` is not supported', implode("\n", $output));
        $this->assertStringNotContainsString('still alive', implode("\n", $output), 'Nothing after the formula ran.');
    }

    /**
     * Longer runs get one pair of brackets per extra "? :": PHP 7 groups
     * from the left, the meant reading from the right.
     */
    public function testThreeInARow(): void
    {
        $this->assertSame([
            'php7' => '(($A ? 1 : $B) ? 2 : $C) ? 3 : 4',
            'meant' => '$A ? 1 : ($B ? 2 : ($C ? 3 : 4))',
        ], NestedTernary::readings('$A ? 1 : $B ? 2 : $C ? 3 : 4'));
    }

    /** Inside a function's arguments, only that argument is bracketed: the comma ends it. */
    public function testInsideAFunctionCall(): void
    {
        $this->assertSame([
            'php7' => 'max(($A ? 1 : $B) ? 2 : 3, 4)',
            'meant' => 'max($A ? 1 : ($B ? 2 : 3), 4)',
        ], NestedTernary::readings('max($A ? 1 : $B ? 2 : 3, 4)'));
    }

    /** "?:" (a ?: b means "a if a is true, else b") mixed with "? :" is refused by PHP 8 too. */
    public function testShortTernaryMixedWithAFullOne(): void
    {
        $this->assertSame([
            'php7' => '($A ?: $B) ? 1 : 2',
            'meant' => '$A ?: ($B ? 1 : 2)',
        ], NestedTernary::readings('$A ?: $B ? 1 : 2'));
    }

    /**
     * Formulas PHP 8 accepts give null: nothing to choose.
     *
     *   brackets already         nothing is ambiguous
     *   inside the middle part   "$A ? $B ? 1 : 2 : 3" is fine: the first ":" can only close the inner "?"
     *   only "?:"                PHP 8 still reads "a ?: b ?: c", as "the first true one"
     *   inside text              the "?" and ":" in '...' are letters, not operators
     *   one "? :" only           the usual case
     */
    public function testFormulasPhp8AcceptsHaveNoReadings(): void
    {
        $this->assertNull(NestedTernary::readings('$A ? 1 : ($B ? 2 : 3)'));
        $this->assertNull(NestedTernary::readings('$A ? $B ? 1 : 2 : 3'));
        $this->assertNull(NestedTernary::readings('$A ?: $B ?: 3'));
        $this->assertNull(NestedTernary::readings("'a ? b : c ? d : e'"));
        $this->assertNull(NestedTernary::readings('ROUND($PTS, 2) > 0 ? ROUND($PTS, 2) : 0'));
    }

    /** What a formula gives for one value of $DTA, as Input::solveFormula() would run it. */
    private static function evaluate(string $formula, int $dta): mixed
    {
        $DTA = $dta;
        return eval('return ' . $formula . ';');
    }
}
