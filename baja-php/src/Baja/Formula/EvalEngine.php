<?php
// No declare(strict_types=1) here, on purpose: this is Input::solveFormula()'s
// code as it was, and the formula's calls (round("2.5")...) must keep PHP's
// usual type juggling. (eval()'d code does not inherit strict_types anyway.)

namespace Baja\Formula;

/**
 * Runs a formula as PHP code, with eval(): what the site has always done.
 *
 * Anything PHP can do, a formula can do (system('...'), file_get_contents()),
 * so anyone who can edit a config can run code on the server. SafeEngine
 * replaces it; this one stays, unchanged, until SafeEngine has been proven
 * on a copy of production (docs/formula-engine.md).
 *
 * The variables are made into PHP variables with extract(), so "$PONTOS"
 * in the formula reads $vars['PONTOS']. Then "$formula = <the formula>;"
 * is run. Its quirks, which SafeEngine copies, are listed in
 * docs/formula-engine.md.
 */
final class EvalEngine implements FormulaEngine
{
    /**
     * @param int|null $now a fixed "now", in seconds, for time() and date()
     *                      in the formulas; null (always, on the site) for
     *                      the real clock. Only the corpus comparison
     *                      (scripts/formula-engine-compare.php) sets it.
     */
    public function __construct(private ?int $now = null)
    {
    }

    public function evaluate(mixed $vars, mixed $formula): string|int|float|null
    {
        if ($this->now === null) {
            return FormulaResult::of(self::evaluateFormula($vars, $formula));
        }
        PinnedClock::$now = $this->now;
        try {
            return FormulaResult::of(self::evaluateWithPinnedClock($vars, $formula));
        } finally {
            PinnedClock::$now = null;
        }
    }

    /**
     * The formula's value. Its own static function so that the formula
     * sees only $vars, $formula and the extracted variables, exactly as
     * before (no $this: a formula that says $this gets an Error).
     */
    private static function evaluateFormula($vars, $formula) {
        extract((array)$vars);

        @eval("\$formula = $formula;");

        return $formula;
    }

    /**
     * The same, but the formula's code is put in the namespace
     * Baja\Formula\PinnedClock, where time() and date() read the fixed
     * clock (see PinnedClock.php). PHP looks a function up in the current
     * namespace first and in the global one after, so every other call
     * still reaches PHP's own function.
     */
    private static function evaluateWithPinnedClock($vars, $formula) {
        extract((array)$vars);

        @eval("namespace Baja\\Formula\\PinnedClock; \$formula = $formula;");

        return $formula;
    }
}
