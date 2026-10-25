<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\SafeEngine;
use Baja\Formula\Syntax\FormulaSyntaxError;
use Baja\Tests\Unit\Formula\Support\ComparesEngines;
use PHPUnit\Framework\TestCase;

/**
 * Every piece of formula syntax, worked out by the new engine and by PHP's
 * eval(), which must agree.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment.
 *
 *     $this->assertGives(6, '$A + 1', ['A' => 5]);
 *
 * means: with the variable $A set to 5, the formula "$A + 1" gives 6 with
 * BOTH engines. The value is exact, type included: 6 is a whole number
 * ("int"), 6.0 a decimal number ("float"), '6' a text ("string").
 *
 *     $this->assertFails('TypeError', "'a' + 1");
 *
 * means: both engines raise an error that starts like that, so the page
 * shows an empty cell. assertSameAsEval() (in Support/ComparesEngines.php)
 * does the comparing: same value, or same error and same message.
 *
 * BACKGROUND
 *
 * Organisers write formulas in a small part of PHP: "ROUND($PONTOS, 2)",
 * "$T1 < $T2 ? $T1 : $T2", "'<b>' . $X . '</b>'". For years the site ran
 * them with eval(), which runs ANY PHP, so whoever can edit a config can
 * run anything on the server. SafeEngine reads the formula itself and
 * allows only that small part of PHP. It must give the same results as
 * eval(), down to PHP's odd corners, which these tests show one by one.
 * docs/formula-engine.md has the whole story.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/GrammarTest.php
 */
final class GrammarTest extends TestCase
{
    use ComparesEngines;

    // ---- Values written in the formula ----

    /** Numbers, as PHP reads them. */
    public function testNumbers(): void
    {
        $this->assertGives(12, '12');
        $this->assertGives(1.5, '1.5');
        $this->assertGives(0.5, '.5', [], 'no 0 before the point');
        $this->assertGives(2.0, '2.', [], 'nothing after the point: still a decimal number');
        $this->assertGives(1500.0, '1.5e3');
        $this->assertGives(1000, '1_000', [], '"_" between digits is allowed');
        $this->assertGives(31, '0x1F', [], 'hexadecimal');
        $this->assertGives(3, '0b11', [], 'binary');
        $this->assertGives(8, '010', [], 'a leading 0 means octal: 010 is 8');
        $this->assertGives(8.5, '08.5', [], 'but not in a decimal number');
        $this->assertGives(9.2233720368547758E+18, '9223372036854775808', [], 'too big for a whole number: PHP makes it a decimal one');
        $this->assertFails('ParseError', '08', [], 'octal has no 8');
        $this->assertFails('ParseError', '1.2.3');
    }

    /** 'text' and "text". */
    public function testTexts(): void
    {
        $this->assertGives('DNF', "'DNF'");
        $this->assertGives("it's", "'it\\'s'", [], "\\' is a quote inside '...'");
        $this->assertGives('a\\nb', "'a\\nb'", [], 'in single quotes \\n is a backslash and an n');
        $this->assertGives("a\nb", '"a\\nb"', [], 'in double quotes \\n is a new line');
        $this->assertGives('<span style="color:red">DNF</span>', "'<span style=\"color:red\">DNF</span>'");
        $this->assertGives('é', '"\\u{e9}"');
        $this->assertGives('A', '"\\x41"');
        $this->assertGives('A', '"\\101"', [], 'octal escape');
        $this->assertGives('\\d', '"\\d"', [], 'an unknown escape is kept as written');
        $this->assertGives('$', '"\\$"');
        $this->assertGives('', "''");
        $this->assertFails('ParseError', "'never closed");
    }

    /** "...$NAME..." puts the variable's value in the text. */
    public function testVariablesInsideDoubleQuotes(): void
    {
        $this->assertGives('12.5 pts', '"$P pts"', ['P' => 12.5]);
        $this->assertGives('a  b', '"a $MISSING b"', [], 'a missing variable is empty');
        $this->assertGives('5$', '"5$"', [], 'a "$" not followed by a name is just a "$"');
    }

    /** null, true and false, in any case. A formula's true or false is not a value a page can show: null. */
    public function testNullTrueFalse(): void
    {
        $this->assertGives(null, 'null');
        $this->assertGives(null, 'NULL');
        $this->assertGives(null, 'true', [], 'true is neither text nor a number');
        $this->assertGives(1, 'true + 0');
        $this->assertGives(0, 'FALSE + 0');
    }

    /** [1, 2] and array(1, 2), with or without keys, and a comma after the last item. */
    public function testArrays(): void
    {
        $this->assertGives(3, 'count([1, 2, 3])');
        $this->assertGives(2, 'count(array(1, 2,))');
        $this->assertGives(0, 'count([])');
        $this->assertGives(9, "array_sum(['a' => 4, 'b' => 5])");
        $this->assertGives(1, "count(['k' => 1, 'k' => 2])", [], 'the same key twice: one item');
        $this->assertGives(2, 'count(["1" => 1, 1 => 2, 2])', [], 'PHP makes the key "1" the number 1');
        // (Written [[] => 1], PHP refuses it before running, with a fatal
        // error; see testFatalErrorsOfEvalAreNormalErrors in LegacyFormulasTest.)
        $this->assertFails('TypeError', 'count([$K => 1])', ['K' => []], 'an array cannot be a key');
    }

    // ---- Variables ----

    /** $NAME reads the variable; one that does not exist is null (no error). */
    public function testVariables(): void
    {
        $this->assertGives(5, '$A', ['A' => 5]);
        $this->assertGives('x', '$a', ['a' => 'x', 'A' => 'X'], 'names care about case');
        $this->assertGives(null, '$NOBODY');
        $this->assertGives(7, '$Segurança + 0', ['Segurança' => 7], 'accented letters are allowed in names');
        $this->assertGives(3, '$_x + $x1', ['_x' => 1, 'x1' => 2]);
        $this->assertGives(5, '$A', (object)['A' => 5], 'the variables can be an object (a JSON row)');
    }

    /** $A[0], $A['key'], and [...] after a text, a bracket, a call. */
    public function testReadingAnItem(): void
    {
        $this->assertGives(20, '$A[1]', ['A' => [10, 20]]);
        $this->assertGives(4, "\$A['x']['y']", ['A' => ['x' => ['y' => 4]]]);
        $this->assertGives(null, '$A[5]', ['A' => [10]], 'a missing item is null (with a warning)');
        $this->assertGives('b', "'abc'[1]", [], 'a text read as a list of letters');
        $this->assertGives('c', "'abc'[-1]");
        $this->assertGives(3, 'max([1, 3])', [], '(no [] after a call here; see the next line)');
        $this->assertGives(2, '[1, 2][1]');
        $this->assertGives(2, '($A)[1]', ['A' => [1, 2]]);
        $this->assertFails('TypeError: Cannot access offset of type string on string', "'abc'['x']");
        $this->assertFails('Error: Cannot use object of type stdClass as array', '$A[0]', ['A' => (object)['x' => 1]]);
        $this->assertFails('ParseError', '1[0]', [], 'a number cannot be read like that');
    }

    /** isset(): the variable (or item) exists and is not null. */
    public function testIsset(): void
    {
        $this->assertGives(1, 'isset($A) ? 1 : 0', ['A' => 0]);
        $this->assertGives(0, 'isset($A) ? 1 : 0', ['A' => null]);
        $this->assertGives(0, 'isset($A) ? 1 : 0');
        $this->assertGives(1, 'isset($A, $B) ? 1 : 0', ['A' => 1, 'B' => 2]);
        $this->assertGives(0, 'isset($A, $B) ? 1 : 0', ['A' => 1]);
        $this->assertGives(1, "isset(\$A['x']) ? 1 : 0", ['A' => ['x' => 1]]);
        $this->assertGives(0, "isset(\$A['x']['y']) ? 1 : 0", ['A' => ['x' => 5]]);
        $this->assertGives(0, "isset(\$A['x']) ? 1 : 0", ['A' => 'text'], 'no error for a text read with a word');
    }

    // ---- Arithmetic ----

    /** + - * / % **, with PHP's rules for whole and decimal numbers. */
    public function testArithmetic(): void
    {
        $this->assertGives(7, '3 + 4');
        $this->assertGives(-1, '3 - 4');
        $this->assertGives(12, '3 * 4');
        $this->assertGives(0.75, '3 / 4');
        $this->assertGives(2, '4 / 2', [], 'a division that comes out whole is a whole number');
        $this->assertGives(1, '7 % 3');
        $this->assertGives(8, '2 ** 3');
        $this->assertGives(0.5, '2 ** -1');
        $this->assertGives(0.30000000000000004, '0.1 + 0.2');
        $this->assertGives(9.2233720368547758E+18, '9223372036854775807 + 1', [], 'too big: a decimal number');
    }

    /** Dividing by zero is an error, so the cell is empty. */
    public function testDivisionByZero(): void
    {
        $this->assertFails('DivisionByZeroError: Division by zero', '100 / $T', ['T' => 0]);
        $this->assertFails('DivisionByZeroError: Division by zero', '100 / $T', [], 'a missing variable is null, which is 0 here');
        $this->assertFails('DivisionByZeroError: Modulo by zero', '5 % 0');
    }

    /** Minus and plus in front, and "!". */
    public function testSignsAndNot(): void
    {
        $this->assertGives(-5, '-$A', ['A' => 5]);
        $this->assertGives(5, '- -5');
        $this->assertGives(-4, '-2 ** 2', [], '** first: -(2 ** 2)');
        $this->assertGives(5, '+"5"', [], 'a plus in front makes text a number');
        $this->assertGives(5, '!0 * 5', [], '"!" comes before "*": (!0) * 5, and true * 5 is 5');
        $this->assertGives(0, '!$A + 0', ['A' => 'x']);
        $this->assertFails('TypeError: Unsupported operand types: string * int', '-$A', ['A' => 'abc'], 'PHP works -$A out as $A * -1');
    }

    /** Text in arithmetic: numeric text counts; other text is an error in PHP 8. */
    public function testTextInArithmetic(): void
    {
        $this->assertGives(6, '"5" + 1');
        $this->assertGives(10.0, '"5" + "5.0"');
        $this->assertGives(6, '"5 pts" + 1', [], 'text that starts with a number: the number (and a warning)');
        $this->assertGives(1, '$EMPTY + 1', ['EMPTY' => null]);
        $this->assertFails('TypeError: Unsupported operand types: string + float', "'<strong>' + \$X", ['X' => 1.5]);
        $this->assertFails('TypeError: Unsupported operand types: string + int', "'a' + 1");
        $this->assertFails('TypeError: Unsupported operand types: string + string', "'' + ''", [], 'not even the empty text');
        $this->assertFails('TypeError: Unsupported operand types: array + int', '[1] + 1');
        $this->assertGives(2, 'count([1] + [5, 6])', [], 'array + array keeps the keys of the left one: [1, 6]');
    }

    // ---- Joining text ----

    /** "." joins text, and makes numbers text the way PHP writes them. */
    public function testJoiningText(): void
    {
        $this->assertGives('<b>12.5</b>', "'<b>' . \$X . '</b>'", ['X' => 12.5]);
        $this->assertGives('2', "'' . 2.0", [], '2.0 is written "2"');
        $this->assertGives('0.3', "'' . (0.1 + 0.2)", [], 'decimal numbers are written with 14 digits at most');
        $this->assertGives('1.0E+25', "'' . 1e25");
        $this->assertGives('x', "'x' . null");
        $this->assertGives('1', "'' . true");
        $this->assertGives('33', '1 + 2 . 3', [], 'in PHP 8, + comes before ".": (1 + 2) . 3');
        $this->assertGives('1-1', '1 . -1 . ""');
        $this->assertFails('Error: Object of class stdClass could not be converted to string', "'x' . \$O", ['O' => (object)[]]);
        $this->assertFails('ParseError', '1.$X', [], '"1." is a number, then $X makes no sense');
    }

    // ---- Comparing ----

    /** == compares loosely, by PHP 8's rules; === also wants the same type. */
    public function testLooseComparison(): void
    {
        $this->assertGives(1, '("2 - Até 10 min" == 2) ? 0 : 1', [], 'PHP 8: text that is not a number is compared as text');
        $this->assertGives(0, '("2" == 2) ? 0 : 1', [], 'numeric text is compared as a number');
        $this->assertGives(0, '("2.0" == "2") ? 0 : 1', [], 'two numeric texts too');
        $this->assertGives(0, '(null == 0) ? 0 : 1');
        $this->assertGives(0, '(null == "") ? 0 : 1');
        $this->assertGives(1, '("abc" == 0) ? 0 : 1', [], 'true in PHP 7, false in PHP 8');
        $this->assertGives(0, '($A == null) ? 0 : 1', ['A' => 0]);
        $this->assertGives(1, '($A === null) ? 0 : 1', ['A' => 0]);
        $this->assertGives(0, '($A != "x") ? 0 : 1', ['A' => 'y']);
        $this->assertGives(0, '($A <> "x") ? 0 : 1', ['A' => 'y']);
        $this->assertGives(1, '($A !== 1.0) ? 1 : 0', ['A' => 1]);
        $this->assertGives(0, '([1, 2] == [1, 2]) ? 0 : 1');
    }

    /** < <= > >=. */
    public function testOrderComparison(): void
    {
        $this->assertGives(1, '$T < 9999 ? 1 : 0', ['T' => 50.5]);
        $this->assertGives(1, '$A <= $B ? 1 : 0', ['A' => 2, 'B' => 2]);
        $this->assertGives(0, '"10" < "9" ? 1 : 0', [], 'numeric texts compare as numbers');
        $this->assertGives(1, '"abc" < "abd" ? 1 : 0', [], 'other texts compare as text');
        $this->assertGives(1, '$MISSING < 1 ? 1 : 0');
        $this->assertGives(1, '1 < 2 == true ? 1 : 0', [], '< comes before ==');
    }

    /** PHP refuses two == (or two <) in a row; so does SafeEngine. */
    public function testComparisonsCannotBeChained(): void
    {
        $this->assertFails('ParseError', '1 == 1 == 1');
        $this->assertFails('ParseError', '1 < 2 < 3');
        $this->assertFails('ParseError', '1 == 1 != 0');
    }

    // ---- Logic ----

    /** && || and !, which give true or false. The right side is only worked out when needed. */
    public function testLogic(): void
    {
        $this->assertGives(1, '($A > 0 && $B > 0) ? 1 : 0', ['A' => 1, 'B' => 1]);
        $this->assertGives(1, '($A == "OK" || $B == "OK") ? 1 : 0', ['A' => 'X', 'B' => 'OK']);
        $this->assertGives(1, '!($A > 0) ? 1 : 0', ['A' => -1]);
        $this->assertGives(0, '(0 && 1 / 0) ? 1 : 0', [], 'the right side is never worked out: no division by zero');
        $this->assertGives(1, '(1 || SPOILERS) ? 1 : 0', [], 'same for ||');
        $this->assertGives(1, '(1 || 0 && 0) ? 1 : 0', [], '&& comes before ||');
    }

    /** ?? : the left side unless it is null (or missing); no warning for a missing variable. */
    public function testNullCoalescing(): void
    {
        $this->assertGives(0, '$A ?? 0');
        $this->assertGives(5, '$A ?? 0', ['A' => 5]);
        $this->assertGives(0, '$A ?? 0', ['A' => null]);
        $this->assertGives(2, '$A ?? $B ?? 0', ['B' => 2], 'right to left');
        $this->assertGives(1, "\$A['x'] ?? 1", ['A' => 'text'], 'no error for a text read with a word');
        $this->assertGives('n', 'null ?? 0 ? "y" : "n"', [], '?? comes before ? :');
    }

    // ---- If-then-else ----

    /** a ? b : c, and a ?: c (a, unless it is empty). */
    public function testTernary(): void
    {
        $this->assertGives('DNF', "\$T == null ? 'DNF' : ROUND(\$T, 2)");
        $this->assertGives(12.35, "\$T == null ? 'DNF' : ROUND(\$T, 2)", ['T' => 12.345]);
        $this->assertGives(7, '$A ?: 7');
        $this->assertGives(3, '$A ?: 7', ['A' => 3]);
        $this->assertGives(5, '0 ?: 0 ?: 5', [], 'several ?: side by side are allowed');
        $this->assertGives(4, '1 ? 0 ? 3 : 4 : 5', [], 'one inside the middle of another is allowed');
        $this->assertGives(-45, '($DTA == 2 ? -30 : $DTA == 3) ? -45 : -90', ['DTA' => 2], 'brackets around the first');
        $this->assertGives(-30, '$DTA == 2 ? -30 : ($DTA == 3 ? -45 : -90)', ['DTA' => 2], 'brackets around the second');
        $this->assertGives(1, '$A ? 1 : SPOILERS', ['A' => 1], 'the side not taken is never worked out');
    }

    /**
     * The one deliberate difference in the syntax that production has
     * (18SU's APR): two "? :" side by side without brackets. PHP 8 refuses
     * them with a FATAL error that stops the whole page (or the judge's
     * save), which no code can catch, so eval() is tried in a separate PHP
     * process here. SafeEngine refuses them with PHP's own words, as a
     * normal error: the formula gives null and the page goes on.
     */
    public function testTwoUnbracketedTernariesAreRefusedInsteadOfKillingThePage(): void
    {
        $formulas = [
            '$A ? 1 : $B ? 2 : 3' => 'Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)`',
            '$A ? 1 : $B ?: 3' => 'Unparenthesized `a ? b : c ?: d` is not supported. Use either `(a ? b : c) ?: d` or `a ? b : (c ?: d)`',
            '$A ?: $B ? 2 : 3' => 'Unparenthesized `a ?: b ? c : d` is not supported. Use either `(a ?: b) ? c : d` or `a ?: (b ? c : d)`',
        ];
        foreach ($formulas as $formula => $message) {
            $this->assertSame($message, $this->assertEvalDiesButSafeEngineFails($formula), 'what kills eval() of ' . $formula);
            try {
                (new SafeEngine())->evaluate(['A' => 0, 'B' => 1], $formula);
                $this->fail('SafeEngine should refuse ' . $formula);
            } catch (FormulaSyntaxError $error) {
                $this->assertSame($message, $error->problem, 'SafeEngine says the same about ' . $formula);
            }
        }
    }

    // ---- The whole formula ----

    /** A formula may end with ";", as eval() allowed; nothing may come after it. */
    public function testOneFormulaOptionallyEndingWithASemicolon(): void
    {
        $this->assertGives(3, '1 + 2;');
        $this->assertGives(3, '1 + 2;;');
        $this->assertFails('ParseError', '');
        $this->assertFails('ParseError', '   ');
        $this->assertFails('ParseError', ';');
    }

    /** Spaces, new lines and comments are ignored; a // comment needs a new line after it. */
    public function testSpacesAndComments(): void
    {
        $this->assertGives(3, " 1\n+\t2 ");
        $this->assertGives(3, '1 /* the base */ + 2');
        $this->assertGives(3, "1 + 2 // the base\n");
        $this->assertGives(3, "1 + 2 # the base\n");
        $this->assertFails('ParseError', '1 + 2 // the base', [], 'eval() adds ";" after the formula, and the comment swallows it');
        $this->assertFails('ParseError', '1 /* never closed');
    }

    /** Function names and null/true/false do not care about case; variables and constants do. */
    public function testCase(): void
    {
        $this->assertGives(3.0, 'ROUND(2.5)');
        $this->assertGives(3.0, 'Round(2.5)');
        $this->assertGives(3.0, '\round(2.5)', [], 'a backslash in front is allowed');
        $this->assertGives(null, 'Null');
        $this->assertFails('Error: Undefined constant "pos"', 'pos');

        // On the site, src/bootstrap.php defines Pos as the text "Pos" (so a
        // formula naming the column works). The tests do not load it.
        if (!defined('Pos')) {
            $this->assertFails('Error: Undefined constant "Pos"', 'Pos', [], 'not defined yet');
            define('Pos', 'Pos');
        }
        $this->assertGives('Pos', 'Pos');
    }

    /** A name that is not a constant fails when it is reached, as in PHP. */
    public function testUndefinedConstants(): void
    {
        $this->assertFails('Error: Undefined constant "SPOILERS"', 'SPOILERS');
        $this->assertFails('Error: Undefined constant "DNF"', '$T == null ? DNF : $T');
        $this->assertGives(5, '$T == null ? DNF : $T', ['T' => 5], 'not reached: no error');
        $this->assertGives(0, 'STR_PAD_LEFT');
    }
}
