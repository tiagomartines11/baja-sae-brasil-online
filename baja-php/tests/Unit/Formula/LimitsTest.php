<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\Interpreter;
use Baja\Formula\SafeEngine;
use Baja\Formula\Syntax\FormulaSyntaxError;
use Baja\Formula\Syntax\Lexer;
use Baja\Formula\Syntax\Parser;
use Baja\Tests\Unit\Formula\Support\ComparesEngines;
use PHPUnit\Framework\TestCase;

/**
 * Formulas that try to use up the server: a huge text, a huge formula,
 * brackets nested thousands deep. A formula must cost one empty cell at
 * worst, never the whole page.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment.
 * assertEvalDiesButSafeEngineFails(formula) (Support/ComparesEngines.php)
 * runs the formula with eval() in a separate PHP process and checks that
 * it kills PHP there, then checks that SafeEngine refuses it with a normal
 * error instead. The others read a formula and expect a FormulaSyntaxError
 * saying what is wrong.
 *
 * BACKGROUND
 *
 * PHP stops a request that uses more memory than allowed with a fatal
 * error, which no code can catch: the visitor gets no results at all. Under
 * eval() a single str_pad('', 2000000000) in a column did that. These
 * limits are far above what any real config uses (the longest formula of
 * the dev seed is 2.1 KB; str_pad pads lap numbers to 2 or 5 characters),
 * and they are deliberate differences (docs/formula-engine.md).
 *
 * Found by an adversarial review of the engine, as were the cases added to
 * GrammarTest and SecurityTest at the same time.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/LimitsTest.php
 */
final class LimitsTest extends TestCase
{
    use ComparesEngines;

    /** A text of two thousand million spaces: eval() runs out of memory, SafeEngine says no. */
    public function testAHugeStrPadIsAnErrorNotAFatalOne(): void
    {
        $this->assertStringStartsWith('Allowed memory size', $this->assertEvalDiesButSafeEngineFails("str_pad('', 2000000000)"));

        $error = self::outcome(new SafeEngine(), [], "str_pad('', 2000000000)")['error'];
        $this->assertSame('ValueError: str_pad(): Argument #2 ($length) must be at most ' . Interpreter::MOST_PADDING . ' in a formula', $error);
    }

    /** Up to the limit, str_pad() is PHP's own, as always. */
    public function testStrPadUpToTheLimit(): void
    {
        $this->assertGives('07', "str_pad(7, 2, '0', STR_PAD_LEFT)");
        $this->assertSame(Interpreter::MOST_PADDING, strlen((string)(new SafeEngine())->evaluate([], "str_pad('', " . Interpreter::MOST_PADDING . ')')));
        $this->assertFails('ValueError', "str_pad('a', 3, '')", [], 'its own errors are still its own');
    }

    /** A formula longer than Lexer::MOST_BYTES is refused before it is read. */
    public function testAHugeFormulaIsRefused(): void
    {
        $formula = '1' . str_repeat('+1', Lexer::MOST_BYTES);
        try {
            SafeEngine::read($formula);
            $this->fail('A ' . strlen($formula) . '-byte formula should be refused.');
        } catch (FormulaSyntaxError $error) {
            $this->assertSame('the formula is too long (' . strlen($formula) . ' bytes; at most ' . Lexer::MOST_BYTES . ')', $error->problem);
        }
        $this->assertSame(intdiv(Lexer::MOST_BYTES, 2) - 1, (new SafeEngine())->evaluate([], '1' . str_repeat('+1', intdiv(Lexer::MOST_BYTES, 2) - 2)), 'just under the limit it works');
    }

    /** Brackets (or minus signs, or "? :") nested deeper than Parser::MOST_DEPTH are refused. */
    public function testVeryDeepNestingIsRefused(): void
    {
        $deep = Parser::MOST_DEPTH + 1;
        foreach ([str_repeat('(', $deep) . '1' . str_repeat(')', $deep), str_repeat('- ', $deep) . '1', str_repeat('1 ? ', $deep) . '1' . str_repeat(' : 1', $deep)] as $formula) {
            try {
                Parser::parse($formula);
                $this->fail('Should be refused: ' . substr($formula, 0, 20) . '...');
            } catch (FormulaSyntaxError $error) {
                $this->assertStringStartsWith('the formula nests too deep', $error->problem);
            }
        }
        $ok = Parser::MOST_DEPTH - 2;
        $this->assertGives(1, str_repeat('(', $ok) . '1' . str_repeat(')', $ok), [], 'below the limit it works');
    }

    /**
     * When the regular expression that cuts the formula gives up (PCRE's
     * backtrack limit), the formula is refused. It used to be read only up
     * to there: "1 + 1 /* ... * / + 5" gave 2 instead of 7.
     */
    public function testAFormulaTheLexerCannotFinishIsRefused(): void
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '10');
        try {
            Lexer::tokens('1 + 1 /*' . str_repeat('x', 200) . '*/ + 5');
            $this->fail('The lexer should refuse a formula it could not cut to the end.');
        } catch (FormulaSyntaxError $error) {
            $this->assertStringStartsWith('the formula could not be read', $error->problem);
        } finally {
            ini_set('pcre.jit', (string)$jit);
            ini_set('pcre.backtrack_limit', (string)$limit);
        }
        $this->assertSame(7, (new SafeEngine())->evaluate([], '1 + 1 /*' . str_repeat('x', 200) . '*/ + 5'), 'with the usual limits it reads it whole');
    }
}
