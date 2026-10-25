<?php
declare(strict_types=1);

namespace Baja\Formula;

use Throwable;

/**
 * Works out one formula of a prova or resultado config: "$P1 + $P2",
 * "ROUND($PONTOS, 2)", "'<b>' . $Total . '</b>'".
 *
 * Input::solveFormula() asks the configured engine (FormulaEngines) and
 * turns whatever it throws into null, so one broken formula leaves one
 * empty cell instead of breaking the page. An engine therefore throws for
 * every formula that fails; it never returns null "because of an error".
 *
 * Implementations:
 *   EvalEngine    runs the formula as PHP code with eval(), as the site
 *                 always has. Anyone who can edit a config can run any code.
 *   SafeEngine    reads the formula itself (Syntax\Parser) and works it out
 *                 with PHP's own operators and an allowlist of functions.
 *   ShadowEngine  both; answers with EvalEngine's result and logs where
 *                 SafeEngine would have answered something else.
 */
interface FormulaEngine
{
    /**
     * @param mixed $vars    the variables, by name: an array or an object
     *                       (a decoded JSON row); the formula reads $NAME
     * @param mixed $formula the formula as written in the config, usually a
     *                       string (a number is a formula too)
     * @return string|int|float|null the value; null when the formula gives
     *                       anything else (true, an array, NAN...), see
     *                       FormulaResult
     * @throws Throwable     whatever went wrong
     */
    public function evaluate(mixed $vars, mixed $formula): string|int|float|null;
}
