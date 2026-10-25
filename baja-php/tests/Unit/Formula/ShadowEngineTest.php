<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\EvalEngine;
use Baja\Formula\FormulaEngine;
use Baja\Formula\SafeEngine;
use Baja\Formula\ShadowEngine;
use Baja\Model\Input;
use Baja\ResultsCheck\FormulaErrors;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * FORMULA_ENGINE=shadow: both engines run, the page gets eval()'s answer,
 * and the log gets every formula where the new engine would differ.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment.
 * assertSame(expected, actual) fails, showing both, when they differ.
 * Most tests use two made-up engines (self::answering()) that give
 * whatever the test says, so that each rule is easy to see; the last ones
 * use the real two.
 *
 * BACKGROUND
 *
 * Before the new engine (SafeEngine) replaces eval() on the site, it will
 * run beside it ("in the shadow"): visitors see exactly what they always
 * saw, and any formula where the two disagree is logged with what is
 * needed to reproduce it (the formula, the variables it reads, both
 * results). docs/formula-engine.md explains the plan.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/ShadowEngineTest.php
 */
final class ShadowEngineTest extends TestCase
{
    /** @var list<array> what the shadow engine reported */
    private array $reports = [];

    protected function tearDown(): void
    {
        Input::setFormulaEngine(null);
    }

    /** The page always gets the first engine's answer, whatever the second says. */
    public function testThePageGetsEvalsAnswer(): void
    {
        $shadow = $this->shadow(self::answering(10), self::answering(11));

        $this->assertSame(10, $shadow->evaluate([], '$X'));
    }

    /** A difference is reported with the formula, the variables it reads, and both results. */
    public function testADifferenceIsReported(): void
    {
        $shadow = $this->shadow(self::answering(10), self::answering('10'));

        $shadow->evaluate(['X' => 5, 'Y' => 6, 'UNRELATED' => 7], '$X + $Y');

        $this->assertSame([[
            'formula' => '$X + $Y',
            'variables' => ['X' => 5, 'Y' => 6],
            'eval' => ['value' => 10, 'error' => null],
            'safe' => ['value' => '10', 'error' => null],
        ]], $this->reports, '10 and "10" differ: a whole number and a text. Only $X and $Y are logged.');
    }

    /** The same answer is not reported; nor are two errors, since both give null. */
    public function testNoDifferenceNoReport(): void
    {
        $this->shadow(self::answering(2.5), self::answering(2.5))->evaluate([], '1');
        try {
            $this->shadow(self::failing('TypeError'), self::failing('ParseError'))->evaluate([], '1');
        } catch (RuntimeException) {
            // eval()'s error, thrown on as always
        }

        $this->assertSame([], $this->reports);
    }

    /** 0.0 and -0.0 are equal for PHP's ===, but a page shows "0" and "-0". */
    public function testMinusZeroIsADifference(): void
    {
        $this->shadow(self::answering(0.0), self::answering(-0.0))->evaluate([], '1');

        $this->assertCount(1, $this->reports);
    }

    /** eval()'s error is thrown, so Input::solveFormula() gives null and tells the config check, as before. */
    public function testEvalsErrorIsWhatTheListenerHears(): void
    {
        Input::setFormulaEngine($this->shadow(self::failing('TypeError'), self::answering(3)));

        [$value, $errors] = FormulaErrors::during(fn() => Input::solveFormula([], "'a' + 1"));

        $this->assertNull($value);
        $this->assertSame(['RuntimeException: TypeError'], array_map(fn($e) => $e->message, $errors));
        $this->assertSame(null, $this->reports[0]['eval']['value']);
        $this->assertSame('RuntimeException: TypeError', $this->reports[0]['eval']['error']);
        $this->assertSame(3, $this->reports[0]['safe']['value']);
    }

    /** A page runs the same formula for every team: the same difference is logged once. */
    public function testTheSameDifferenceIsReportedOnce(): void
    {
        $shadow = $this->shadow(self::answering(1), self::answering(2));
        for ($team = 0; $team < 50; $team++) {
            $shadow->evaluate(['X' => 1], '$X');
        }
        $shadow->evaluate(['X' => 9], '$X');

        $this->assertCount(2, $this->reports, 'once for X = 1, once for X = 9');
    }

    /** A page where everything differs logs MOST_REPORTS differences and one note, not thousands. */
    public function testReportsStopAfterAHundred(): void
    {
        $shadow = $this->shadow(self::answering(1), self::answering(2));
        for ($team = 0; $team < 500; $team++) {
            $shadow->evaluate(['X' => $team], '$X');
        }

        $this->assertCount(ShadowEngine::MOST_REPORTS + 1, $this->reports);
        $this->assertStringStartsWith('More differences in this request', end($this->reports)['note']);
        $this->assertSame(500 - ShadowEngine::MOST_REPORTS, $shadow->notReported());
    }

    /** With the real engines: a constant off SafeEngine's list is the kind of thing the log would show. */
    public function testWithTheRealEngines(): void
    {
        $shadow = $this->shadow(new EvalEngine(), new SafeEngine());

        $this->assertSame(PHP_VERSION, $shadow->evaluate([], 'PHP_VERSION'), 'the page gets eval()\'s answer');
        $this->assertSame(3.0, $shadow->evaluate(['X' => 2.5], 'ROUND($X)'));

        $this->assertCount(1, $this->reports, 'only PHP_VERSION differs');
        $this->assertSame('PHP_VERSION', $this->reports[0]['formula']);
        $this->assertSame('Error: Undefined constant "PHP_VERSION"', $this->reports[0]['safe']['error']);
    }

    private function shadow(FormulaEngine $answers, FormulaEngine $checked): ShadowEngine
    {
        return new ShadowEngine($answers, $checked, function (array $difference): void {
            $this->reports[] = $difference;
        });
    }

    /** A made-up engine that always gives $value. */
    private static function answering(string|int|float|null $value): FormulaEngine
    {
        return new class ($value) implements FormulaEngine {
            public function __construct(private string|int|float|null $value)
            {
            }

            public function evaluate(mixed $vars, mixed $formula): string|int|float|null
            {
                return $this->value;
            }
        };
    }

    /** A made-up engine that always fails with $message. */
    private static function failing(string $message): FormulaEngine
    {
        return new class ($message) implements FormulaEngine {
            public function __construct(private string $message)
            {
            }

            public function evaluate(mixed $vars, mixed $formula): string|int|float|null
            {
                throw new RuntimeException($this->message);
            }
        };
    }
}
