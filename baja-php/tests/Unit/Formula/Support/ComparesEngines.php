<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula\Support;

use Baja\Formula\EvalEngine;
use Baja\Formula\FormulaEngine;
use Baja\Formula\SafeEngine;
use ParseError;
use Throwable;

/**
 * For the formula engine tests: runs one formula with both engines and
 * checks that the new one (SafeEngine) gives exactly what the old one
 * (EvalEngine, PHP's eval()) gives.
 *
 * "Exactly" means:
 *   - the same value, of the same type: 3 (a whole number) is not 3.0,
 *     and "3" (text) is neither;
 *   - or, when eval() raised an error, SafeEngine raises one too, of the
 *     same kind and with the same message ("TypeError: Unsupported
 *     operand types: string + int"). The page shows null either way, but
 *     the config checker prints the message, so it should not change.
 *     One exception: when eval() says the formula is not valid PHP (a
 *     ParseError), SafeEngine's message is its own, more precise one.
 */
trait ComparesEngines
{
    /**
     * @param mixed  $formula the formula, as in a config
     * @param array  $vars    the variables it can read, by name
     * @return array{value: mixed, error: ?string} what both gave
     */
    protected function assertSameAsEval(mixed $formula, array|object $vars = [], string $why = ''): array
    {
        $old = self::outcome(new EvalEngine(), $vars, $formula);
        $new = self::outcome(new SafeEngine(), $vars, $formula);
        $label = 'Formula ' . var_export($formula, true) . ($why === '' ? '' : ' (' . $why . ')');

        if ($old['throwable'] instanceof ParseError) {
            $this->assertInstanceOf(ParseError::class, $new['throwable'], $label . ': eval() refuses it, so must SafeEngine.');
        } else {
            $this->assertSame($old['error'], $new['error'], $label . ': the same error, or none.');
        }
        $this->assertSame($old['value'], $new['value'], $label . ': the same value.');
        return ['value' => $old['value'], 'error' => $old['error']];
    }

    /**
     * Both engines give $expected, with no error. The formula's value is
     * written in the test, so a reader sees what PHP really does.
     */
    protected function assertGives(mixed $expected, mixed $formula, array|object $vars = [], string $why = ''): void
    {
        $both = $this->assertSameAsEval($formula, $vars, $why);
        $this->assertNull($both['error'], 'Formula ' . var_export($formula, true) . ' should work.');
        $this->assertSame($expected, $both['value'], 'Formula ' . var_export($formula, true) . ($why === '' ? '' : ' (' . $why . ')'));
    }

    /**
     * Both engines fail, so the page shows null. $error is the start of
     * eval()'s message: "TypeError", or the whole "TypeError: ...".
     */
    protected function assertFails(string $error, mixed $formula, array|object $vars = [], string $why = ''): void
    {
        $both = $this->assertSameAsEval($formula, $vars, $why);
        $this->assertNotNull($both['error'], 'Formula ' . var_export($formula, true) . ' should fail.');
        $this->assertStringStartsWith($error, $both['error'], 'Formula ' . var_export($formula, true));
    }

    /**
     * eval() does not just fail on this formula: PHP stops dead (a "fatal
     * error"), which no try/catch stops, so on the site the whole page
     * dies. That is tried in a PHP process of its own, so that only that
     * process dies. SafeEngine, on the other hand, must raise a normal
     * error: one empty cell, and the page goes on.
     *
     * @return string the fatal error PHP printed
     */
    protected function assertEvalDiesButSafeEngineFails(string $formula): string
    {
        $code = '@eval(' . var_export('$formula = ' . $formula . ';', true) . '); echo "still alive";';
        $output = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stdout -r ' . escapeshellarg($code) . ' 2>&1');
        $this->assertStringNotContainsString('still alive', $output, 'eval() of ' . $formula . ' should kill PHP.');
        $this->assertStringContainsString('Fatal error', $output);

        $new = self::outcome(new SafeEngine(), [], $formula);
        $this->assertNotNull($new['throwable'], 'SafeEngine should refuse ' . $formula . ' with a normal error.');
        $this->assertNull($new['value']);
        // "Fatal error: <message> in <file>(<line>) : eval()'d code on line 1"
        return trim(preg_replace('/ in (?:(?! in ).)* : eval\(\)\'d code.*$/s', '', substr($output, (int)strpos($output, 'Fatal error: ') + 13)));
    }

    /** @return array{value: mixed, error: ?string, throwable: ?Throwable} */
    protected static function outcome(FormulaEngine $engine, array|object $vars, mixed $formula): array
    {
        try {
            return ['value' => $engine->evaluate($vars, $formula), 'error' => null, 'throwable' => null];
        } catch (Throwable $e) {
            return ['value' => null, 'error' => get_class($e) . ': ' . $e->getMessage(), 'throwable' => $e];
        }
    }
}
