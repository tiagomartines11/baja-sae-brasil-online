<?php
declare(strict_types=1);

namespace Baja\Formula;

use Baja\Formula\Syntax\FormulaSyntaxError;
use Baja\Formula\Syntax\ParsedFormula;
use Baja\Formula\Syntax\Parser;
use Closure;
use Error;

/**
 * Works a formula out without eval(): Syntax\Parser reads it (or refuses
 * it: nothing of a refused formula ever runs), Interpreter works it out
 * with PHP's own operators and the functions of Allowlist.
 *
 * It gives what EvalEngine gives, for every formula in use, quirks
 * included; the few deliberate differences are listed in
 * docs/formula-engine.md. Before running, it does what eval() did around
 * the formula:
 *
 *   - the variables are an array or an object's properties (extract());
 *     one called "this" was an Error, so it still is;
 *   - a variable called "formula" REPLACED the formula (extract() wrote
 *     over $formula before "$formula = <formula>;" was put together), so
 *     it still does;
 *   - the formula is made text as "...$formula..." made it: a number as
 *     PHP writes it (2.0 is "2", so the whole number 2), an array is
 *     "Array" (an undefined constant), null and false are "" (invalid).
 *
 * A read formula is kept, by its text, for the rest of the request: a
 * results page runs the same few formulas for every team.
 */
final class SafeEngine implements FormulaEngine
{
    /**
     * Formulas kept at most, and their total length; then the store starts
     * again. A read formula takes about 1,000 times its length in memory, so
     * the length counts more than the number. A results page has a few
     * dozen formulas of a few hundred bytes.
     */
    private const KEEP = 10_000;
    private const KEEP_BYTES = 200_000;

    private static int $keptBytes = 0;

    /** @var array<string, Closure|FormulaSyntaxError> by the formula's text */
    private static array $compiled = [];

    /**
     * @param int|null $now a fixed "now", in seconds, for time() and date();
     *                      null (always, on the site) for the real clock
     */
    public function __construct(private ?int $now = null)
    {
    }

    public function evaluate(mixed $vars, mixed $formula): string|int|float|null
    {
        $variables = (array)$vars;
        if (array_key_exists('this', $variables)) {
            throw new Error('Cannot re-assign $this');
        }
        if (array_key_exists('formula', $variables)) {
            $formula = $variables['formula'];
        }
        $run = self::compiled(self::source($formula));
        // "@", as in "@eval(...)": PHP's warnings ("Undefined array key",
        // "A non-numeric value") are not logged. Errors still are thrown.
        return FormulaResult::of(@$run(new Scope($variables, $vars, $formula, $this->now)));
    }

    /**
     * Reads a formula without running it: its tree, variables and functions.
     *
     * @throws FormulaSyntaxError when it is not valid or not allowed
     * @throws Error when it cannot even be made text (an object)
     */
    public static function read(mixed $formula): ParsedFormula
    {
        return Parser::parse(self::source($formula));
    }

    /** The formula as text, the way eval("\$formula = $formula;") made it text. */
    private static function source(mixed $formula): string
    {
        // (string) would say the same for an array, with a warning.
        return is_array($formula) ? 'Array' : (string)$formula;
    }

    /** @throws FormulaSyntaxError */
    private static function compiled(string $source): Closure
    {
        if (!isset(self::$compiled[$source])) {
            if (count(self::$compiled) >= self::KEEP || self::$keptBytes + strlen($source) > self::KEEP_BYTES) {
                self::$compiled = [];
                self::$keptBytes = 0;
            }
            self::$keptBytes += strlen($source);
            try {
                self::$compiled[$source] = Interpreter::compile(Parser::parse($source)->root);
            } catch (FormulaSyntaxError $error) {
                // Kept too: a broken formula is asked for once per team.
                self::$compiled[$source] = $error;
            }
        }
        $compiled = self::$compiled[$source];
        if ($compiled instanceof FormulaSyntaxError) {
            throw $compiled;
        }
        return $compiled;
    }
}
