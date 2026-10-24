<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\Model\Input;
use Baja\ResultsCheck\FormulaErrors;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * How the check finds out that a formula is broken.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment. It
 * runs some code, then "asserts" what must be true afterwards:
 * assertSame(expected, actual) fails the test, and shows both values, when
 * they are not exactly equal.
 *
 * BACKGROUND
 *
 * A resultado's columns are "formulas": small pieces of PHP typed by the
 * organisers, such as  ROUND($PONTOS, 2). Input::solveFormula() runs them.
 * When a formula is broken, PHP raises an error (a "Throwable"), and
 * solveFormula() catches it and gives back null, so the results page shows
 * an empty cell instead of crashing. The pages need that, but it also
 * hides the mistake. FormulaErrors::during() runs some code while listening
 * to those errors, and gives them back as a list.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/FormulaErrorsTest.php
 */
final class FormulaErrorsTest extends TestCase
{
    /**
     * The most common broken formula: "+" used to join text. PHP joins text
     * with "."; since PHP 8, "+" on text is an error.
     */
    public function testAFormulaThatRaisesAnErrorIsRecorded(): void
    {
        $formula = "'<b>' + \$PONTOS";

        [$value, $errors] = FormulaErrors::during(fn() => Input::solveFormula(['PONTOS' => 5.5], $formula));

        $this->assertNull($value, 'The page would still get an empty value...');
        $this->assertCount(1, $errors, '...but now we know why.');
        $this->assertSame($formula, $errors[0]->formula, 'The error says which formula raised it.');
        $this->assertSame('TypeError: Unsupported operand types: string + float', $errors[0]->message);
    }

    /** Any kind of error is caught, not only "+": here PHP 8's max() of an empty list. */
    public function testOtherErrorsAreRecordedToo(): void
    {
        [, $errors] = FormulaErrors::during(function () {
            Input::solveFormula([], 'max([])');
            Input::solveFormula(['T' => 0], '100 / $T');
            Input::solveFormula([], 'ROUND(');   // a typo: the formula is not even valid PHP
        });

        $this->assertSame([
            'ValueError: max(): Argument #1 ($value) must contain at least one element',
            'DivisionByZeroError: Division by zero',
            'ParseError: syntax error, unexpected token ";"',
        ], array_map(fn($error) => $error->message, $errors));
    }

    /** A formula that works records nothing. */
    public function testAWorkingFormulaRecordsNothing(): void
    {
        [$value, $errors] = FormulaErrors::during(fn() => Input::solveFormula(['PONTOS' => 5.5], "'<b>' . \$PONTOS . '</b>'"));

        $this->assertSame('<b>5.5</b>', $value);
        $this->assertSame([], $errors);
    }

    /**
     * Once during() is over, nobody listens any more: the pages, which never
     * call during(), behave exactly as they did before this tool existed.
     * That stays true when the code it ran crashed.
     */
    public function testListeningStopsAfterwardsEvenAfterACrash(): void
    {
        // The listener is a private setting of Input; reading it needs PHP's
        // "reflection", which lets a test look inside a class.
        $listener = new ReflectionProperty(Input::class, 'formulaErrorListener');

        FormulaErrors::during(fn() => null);
        $this->assertNull($listener->getValue(), 'Nobody listens after a normal run.');

        try {
            FormulaErrors::during(function () {
                throw new RuntimeException('something else went wrong');
            });
        } catch (RuntimeException) {
            // expected
        }
        $this->assertNull($listener->getValue(), 'Nobody listens after a crash either.');

        $this->assertNull(Input::solveFormula([], "'a' + 1"), 'solveFormula() still returns null for a broken formula.');
    }
}
