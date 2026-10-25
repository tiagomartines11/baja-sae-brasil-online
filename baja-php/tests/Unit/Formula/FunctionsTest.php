<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\Allowlist;
use Baja\Formula\EvalEngine;
use Baja\Formula\SafeEngine;
use Baja\Tests\Unit\Formula\Support\ComparesEngines;
use PHPUnit\Framework\TestCase;

/**
 * Every function a formula may call, worked out by the new engine and by
 * PHP's eval(), which must agree, including where PHP 8 is picky.
 *
 * HOW TO READ THESE TESTS
 *
 *     $this->assertGives(12.35, 'ROUND($X, 2)', ['X' => 12.345]);
 *
 * means: with $X set to 12.345, "ROUND($X, 2)" gives 12.35 with BOTH
 * engines, exactly (12.35 the decimal number, not the text "12.35").
 * assertFails('ValueError', ...) means both raise that error, so the page
 * shows an empty cell. See GrammarTest for more.
 *
 * BACKGROUND
 *
 * SafeEngine only lets a formula call the functions of Allowlist: those
 * found in production's configs. They are not copies: SafeEngine calls
 * PHP's own round(), max()... with the formula's values, so they behave
 * exactly as before, by construction. These tests show that on the cases
 * where PHP's behaviour is surprising, and guard the one way it could go
 * wrong (see testFunctionsAreCalledWithoutStrictTypes).
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/FunctionsTest.php
 */
final class FunctionsTest extends TestCase
{
    use ComparesEngines;

    /** Every function on the list has a test below. */
    public function testTheListIsTheOneThisFileTests(): void
    {
        $this->assertSame(
            ['round', 'max', 'min', 'array_diff', 'date', 'ceil', 'floor', 'count', 'array_sum', 'abs', 'substr', 'time', 'str_pad', 'implode', 'array_filter'],
            array_keys(Allowlist::FUNCTIONS),
        );
    }

    /**
     * PHP checks a function's arguments by the rules of the file the call
     * is written in. eval()'d formulas were never "strict", so round("2.5")
     * turns the text into a number. SafeEngine's calls are in
     * src/Baja/Formula/Interpreter.php, which must not be strict either
     * (it says so at the top): in a strict file round("2.5") is a
     * TypeError, and every formula rounding a text would give null.
     */
    public function testFunctionsAreCalledWithoutStrictTypes(): void
    {
        $this->assertGives(3.0, 'round("2.5")');
        $this->assertGives(3.0, 'round($X)', ['X' => '2.5'], 'a number typed in a form arrives as text');
        $this->assertGives(0.0, 'round(null)', [], 'null is 0 (PHP warns that this is deprecated)');
        $this->assertGives(3.0, 'ceil(true + 2)');
        $this->assertDoesNotMatchRegularExpression('/^declare\(strict_types/m', (string)file_get_contents(__DIR__ . '/../../../src/Baja/Formula/Interpreter.php'));
    }

    /** round(): PHP rounds half away from zero; the precision can be negative. */
    public function testRound(): void
    {
        $this->assertGives(12.35, 'ROUND($X, 2)', ['X' => 12.345]);
        $this->assertGives(3.0, 'round(2.5)');
        $this->assertGives(-3.0, 'round(-2.5)');
        $this->assertGives(1.96, 'round(1.955, 2)', [], 'PHP pre-rounds, so 1.955 (really 1.95499...) still gives 1.96');
        $this->assertGives(1200.0, 'round(1234, -2)');
        $this->assertGives(5.0, 'round(5)', [], 'a whole number comes back as a decimal one');
        $this->assertGives(0.3, 'round(0.1 + 0.2, 10)');
        $this->assertGives(null, 'round(9 ** 9 ** 9)', [], 'too big: INF, which a page cannot show');
        $this->assertFails('TypeError: round(): Argument #1 ($num) must be of type int|float, string given', "round('DNF')");
        $this->assertFails('TypeError: round(): Argument #1 ($num) must be of type int|float, array given', 'round([1])');
        $this->assertFails('ArgumentCountError', 'round()');
    }

    /** max() and min() of several values or of one list; PHP 8 refuses an empty list. */
    public function testMaxAndMin(): void
    {
        $this->assertGives(3, 'max(1, 3, 2)');
        $this->assertGives(1, 'min([1, 3, 2])');
        $this->assertGives(2.5, 'max(2, 2.5)');
        $this->assertGives(2, 'max(2, 2.0)', [], 'equal: the first one');
        $this->assertGives(2.0, 'max(2.0, 2)');
        $this->assertGives('abc', "max('abc', 0)", [], 'PHP 8: "abc" > 0');
        $this->assertGives(0, "min('abc', 0)");
        $this->assertGives('10', "max('10', '9')", [], 'numeric texts compare as numbers, and stay text');
        $this->assertGives(null, 'max(null, null)');
        $this->assertGives(null, 'max(null, 0)', [], 'for PHP null and 0 are equal, and max() keeps the first');
        $this->assertGives(5, 'max([$A, $B, 5])', ['A' => null, 'B' => null]);
        $this->assertGives(null, 'min([$A, 5])', ['A' => null], 'null is the smallest');
        $this->assertFails('ValueError: max(): Argument #1 ($value) must contain at least one element', 'max([])');
        $this->assertFails('ValueError: min(): Argument #1 ($value) must contain at least one element', 'min(array_diff([$A], [null]))');
        $this->assertFails('TypeError: max(): Argument #1 ($value) must be of type array, int given', 'max(5)');
        $this->assertFails('ArgumentCountError', 'min()');
    }

    /** The configs' favourite: the best of the attempts that were made. */
    public function testBestOfTheAttemptsMade(): void
    {
        $formula = 'ROUND(max(array_diff([$T1, $T2, $T3], [null])), 2)';
        $this->assertGives(30.12, $formula, ['T1' => 12.5, 'T2' => 30.123, 'T3' => null]);
        $this->assertFails('ValueError', $formula, [], 'no attempt at all: max() of an empty list');
    }

    /** array_diff(): the values of the first list that are in none of the others, keys kept. */
    public function testArrayDiff(): void
    {
        $this->assertGives(2, 'count(array_diff([1, null, 3, null], [null]))');
        $this->assertGives(1, 'count(array_diff([0, null, ""], [null]))', [], 'compared as text: null and "" are both "", so only 0 is left');
        $this->assertGives(5, 'array_sum(array_diff([1, 2, 3], [1]))');
        $this->assertFails('TypeError', 'array_diff(1, [1])');
    }

    /** ceil(), floor() and abs(). */
    public function testCeilFloorAbs(): void
    {
        $this->assertGives(2.0, 'ceil(1.2)');
        $this->assertGives(-1.0, 'ceil(-1.2)');
        $this->assertGives(1.0, 'floor(1.8)');
        $this->assertGives(1700000000.0, 'floor($ts / 1000)', ['ts' => 1700000000123]);
        $this->assertGives(5, 'abs(-5)', [], 'a whole number stays whole');
        $this->assertGives(5.5, 'abs("-5.5")');
        $this->assertFails('TypeError', "abs('x')");
        $this->assertFails('TypeError', "floor('x')");
    }

    /** count() and array_sum(). */
    public function testCountAndArraySum(): void
    {
        $this->assertGives(2, 'count($L)', ['L' => [4, 5]]);
        $this->assertGives(3, 'count([[1, 2]], COUNT_RECURSIVE)');
        $this->assertGives(9, 'array_sum($L)', ['L' => [4, 5]]);
        $this->assertGives(9.5, 'array_sum([4, "5.5"])');
        $this->assertGives(0, 'array_sum([])');
        $this->assertFails('TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given', 'count($MISSING)');
        $this->assertFails('TypeError: count(): Argument #1 ($value) must be of type Countable|array, string given', "count('abc')");
    }

    /** substr(), str_pad() (with STR_PAD_LEFT) and implode(). */
    public function testText(): void
    {
        $this->assertGives('Bra', "substr('Brasil', 0, 3)");
        $this->assertGives('il', "substr('Brasil', -2)");
        $this->assertGives('', "substr('abc', 5)");
        $this->assertGives('2', 'substr(12, 1)', [], 'a number becomes text');
        $this->assertGives('07', "str_pad(\$VOLTA, 2, '0', STR_PAD_LEFT)", ['VOLTA' => 7]);
        $this->assertGives('7  ', "str_pad(7, 3)");
        $this->assertGives('12345', "str_pad(12345, 2, '0', STR_PAD_LEFT)", [], 'already long enough');
        $this->assertGives('a, b', "implode(', ', ['a', 'b'])");
        $this->assertGives('1-2.5-', "implode('-', [1, 2.5, null])");
        $this->assertFails('ValueError', "str_pad('a', 3, '')");
        $this->assertFails('TypeError', "implode(', ', 'x')");
    }

    /** array_filter() with one argument: drops the empty values (null, 0, "", "0", false). */
    public function testArrayFilter(): void
    {
        $this->assertGives(2, 'count(array_filter([1, null, 0, "", "0", 2]))');
        $this->assertGives(3, 'max(array_filter([$A, $B]))', ['A' => 3, 'B' => 0]);
        $this->assertFails('TypeError', 'array_filter(5)');
    }

    /**
     * time() and date() read the clock. On the site that is the real
     * clock; the corpus comparison gives both engines the same fixed
     * "now" (here 14 November 2023, 19:13:20 in São Paulo).
     */
    public function testTimeAndDateWithAFixedNow(): void
    {
        $now = 1_700_000_000;
        $old = new EvalEngine($now);
        $new = new SafeEngine($now);
        foreach ([
            'time()' => $now,
            "time() > 1600000000 ? 'Atrasado' : 'No prazo'" => 'Atrasado',
            "date('d/m/Y H:i')" => '14/11/2023 19:13',
            "date('Y', null)" => '2023',
            "date('d/m/Y', 1000000000)" => '08/09/2001',
        ] as $formula => $expected) {
            $this->assertSame($expected, $old->evaluate([], $formula), 'eval: ' . $formula);
            $this->assertSame($expected, $new->evaluate([], $formula), 'safe: ' . $formula);
        }
        foreach (['time(1)', 'date()', 'date([])'] as $broken) {
            $this->assertSame(get_class(self::outcome($old, [], $broken)['throwable']), get_class(self::outcome($new, [], $broken)['throwable']), $broken);
        }
    }

    /** Without a fixed now, both read the real clock. */
    public function testTimeAndDateWithTheRealClock(): void
    {
        $before = time();
        $value = (new SafeEngine())->evaluate([], 'time()');
        $this->assertGreaterThanOrEqual($before, $value);
        $this->assertLessThanOrEqual(time(), $value);
        $this->assertSame(date('Y'), (new SafeEngine())->evaluate([], "date('Y')"));
    }

    /**
     * The one object pattern production uses (32 formulas, the enduro
     * penalty times): a timestamp shown as a time of day. SafeEngine
     * recognises exactly this shape and runs the same PHP calls.
     */
    public function testTheDateTimePattern(): void
    {
        $vars = ['ts' => 1_700_000_000, 'TS_ATEND' => 1_700_000_000_123];
        $this->assertGives('19:13:20', "(new \\DateTime('now', new \\DateTimeZone('America/Sao_Paulo')))->setTimestamp(\$ts)->format('H:i:s')", $vars);
        $this->assertGives('14/11/2023 19:13:20', "(new \\DateTime('now', new \\DateTimeZone('America/Sao_Paulo')))->setTimestamp(floor(\$TS_ATEND/1000))->format('d/m/Y H:i:s')", $vars);
        $this->assertGives('', "(\$TS_ATEND==null)?'':((new \\DateTime('now', new \\DateTimeZone('America/Sao_Paulo')))->setTimestamp(floor(\$TS_ATEND/1000))->format('d/m/Y H:i:s'))", []);
        $this->assertGives('22:13:20', "(new DateTime('now', new DateTimeZone('UTC')))->setTimestamp(\$ts)->format('H:i:s')", $vars, 'without the backslashes');
        $this->assertGives('2023', "(new \\DATETIME())->setTimestamp(\$ts)->FORMAT('Y')", $vars, 'any case');
        $this->assertGives('19:13:20', "(new \\DateTime)->setTimestamp(\$ts)->format('H:i:s')", $vars, 'the default time zone (São Paulo on the site)');
        $this->assertGives('2001-09-09', "(new \\DateTime('@1000000000'))->format('Y-m-d')", [], 'no setTimestamp()');
        $this->assertGives('21:00:00', "(new \\DateTime('now', new \\DateTimeZone('America/Sao_Paulo')))->setTimestamp(\$MISSING)->format('H:i:s')", [], 'null is 0: 1 January 1970, 00:00 UTC');
        $this->assertFails('TypeError', "(new \\DateTime())->setTimestamp('abc')->format('H')");
        $this->assertFails('DateInvalidTimeZoneException', "(new \\DateTime('now', new \\DateTimeZone('Mars/Olympus')))->format('H')");
        $this->assertFails('DateMalformedStringException', "(new \\DateTime('not a date'))->format('H')");
    }

    /** Functions that PHP has but formulas do not use are refused, even harmless ones. */
    public function testFunctionsOffTheListAreRefused(): void
    {
        foreach (['log(0)', 'strtoupper("a")', 'number_format(1.5, 2)', 'is_numeric($X)'] as $formula) {
            $new = self::outcome(new SafeEngine(), [], $formula);
            $this->assertInstanceOf(\Baja\Formula\Syntax\FormulaSyntaxError::class, $new['throwable'], $formula);
            $this->assertStringContainsString('is not allowed in a formula', $new['error']);
        }
    }
}
