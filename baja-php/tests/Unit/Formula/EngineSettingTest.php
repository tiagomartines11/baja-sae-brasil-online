<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\EvalEngine;
use Baja\Formula\FormulaEngine;
use Baja\Formula\FormulaEngines;
use Baja\Model\Input;
use PHPUnit\Framework\TestCase;

/**
 * Which engine works the formulas out, and how Input::solveFormula() uses it.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment. It
 * runs some code, then "asserts" what must be true afterwards:
 * assertSame(expected, actual) fails the test, and shows both values, when
 * they are not exactly equal; assertInstanceOf(Class, value) fails when
 * the value is not an object of that class.
 *
 * BACKGROUND
 *
 * Every formula of a prova or resultado config goes through
 * Input::solveFormula(). That used to run the formula with PHP's eval();
 * it now asks a "formula engine" (src/Baja/Formula/) and turns any error
 * into null. The setting FORMULA_ENGINE in baja-infra/.env picks the
 * engine. Unset, it is "eval": the same code as before, moved into
 * EvalEngine. See docs/formula-engine.md.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/EngineSettingTest.php
 */
final class EngineSettingTest extends TestCase
{
    protected function tearDown(): void
    {
        // Back to the configured engine, so that no other test sees ours.
        Input::setFormulaEngine(null);
    }

    /** Nothing set, or "eval" in any case: the site runs as it always has. */
    public function testTheDefaultIsEval(): void
    {
        $this->assertInstanceOf(EvalEngine::class, FormulaEngines::named(null));
        $this->assertInstanceOf(EvalEngine::class, FormulaEngines::named(''));
        $this->assertInstanceOf(EvalEngine::class, FormulaEngines::named(' EVAL '));
    }

    /** A typo in .env is reported, and the site keeps the default rather than breaking. */
    public function testAnUnknownSettingFallsBackToEvalAndSaysSo(): void
    {
        $warnings = [];
        $engine = FormulaEngines::named('saef', function (string $message, array $context) use (&$warnings): void {
            $warnings[] = $message . ': ' . $context['value'];
        });

        $this->assertInstanceOf(EvalEngine::class, $engine);
        $this->assertSame(['Unknown FORMULA_ENGINE, using eval: saef'], $warnings);
    }

    /** solveFormula() hands the variables and the formula to the engine, and returns its answer. */
    public function testSolveFormulaAsksTheEngine(): void
    {
        $engine = new class implements FormulaEngine {
            public array $asked = [];

            public function evaluate(mixed $vars, mixed $formula): string|int|float|null
            {
                $this->asked[] = [$vars, $formula];
                return 42;
            }
        };
        Input::setFormulaEngine($engine);

        $this->assertSame(42, Input::solveFormula(['X' => 1], '$X + 1'));
        $this->assertSame([[['X' => 1], '$X + 1']], $engine->asked);
    }

    /** Whatever the engine throws, the page gets null (an empty cell), as before. */
    public function testAnythingTheEngineThrowsBecomesNull(): void
    {
        Input::setFormulaEngine(new class implements FormulaEngine {
            public function evaluate(mixed $vars, mixed $formula): string|int|float|null
            {
                throw new \TypeError('Unsupported operand types: string + int');
            }
        });

        $this->assertNull(Input::solveFormula([], "'a' + 1"));
    }

    /** EvalEngine is the old code: a few formulas, with what eval() gives for them. */
    public function testEvalEngineIsTheOldCode(): void
    {
        $engine = new EvalEngine();

        $this->assertSame(3.5, $engine->evaluate(['A' => 1.25, 'B' => 2.25], '$A + $B'));
        $this->assertSame('<b>5</b>', $engine->evaluate((object)['P' => 5], "'<b>' . \$P . '</b>'"));
        $this->assertSame(2, $engine->evaluate([], 2.0), 'A number is a formula too: 2.0 is written "2", so it gives the whole number 2.');
        $this->assertNull($engine->evaluate([], '1 == 1'), 'true is neither text nor a number: null.');
        $this->assertNull($engine->evaluate([], '-log(0)'), 'INF gives null.');
    }

    /**
     * For the corpus comparison only: EvalEngine can be given a fixed
     * "now", so that formulas that read the clock (time(), date()) give
     * the same answer every run. On the site the clock is never fixed.
     */
    public function testEvalEngineCanBeGivenAFixedNow(): void
    {
        $now = 1_700_000_000;   // 14 November 2023, 19:13:20 in São Paulo
        $engine = new EvalEngine($now);

        $this->assertSame($now, $engine->evaluate([], 'time()'));
        $this->assertSame($now, $engine->evaluate([], 'TIME()'), 'Function names do not care about case.');
        $this->assertSame('2023-11-14 19:13:20', $engine->evaluate([], "date('Y-m-d H:i:s')"));
        $this->assertSame('2001', $engine->evaluate([], "date('Y', 1_000_000_000)"), 'A date() given its own time is left alone.');
        $this->assertSame(3.0, $engine->evaluate([], 'round(2.5)'), 'Every other function is still PHP\'s own.');
        $this->assertSame(
            '22:13:20',
            $engine->evaluate(['ts' => $now], "(new DateTime('now', new DateTimeZone('UTC')))->setTimestamp(\$ts)->format('H:i:s')"),
            'DateTime still works without its backslash.',
        );

        $this->assertNotSame($now, (new EvalEngine())->evaluate([], 'time()'), 'Without it, the real clock.');
    }
}
