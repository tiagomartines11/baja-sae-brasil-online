<?php
// The list helpers of the formulas, MENOR(), MAIOR() and CONTAR(), as
// global PHP functions. Formulas run by EvalEngine are PHP code, and they
// call functions by name in the global namespace, so the helpers must be
// there. SafeEngine's Interpreter calls the same functions, so both
// engines give the same value and the same error.
//
// Loaded (require_once) by EvalEngine.php and Interpreter.php, the two
// files that run formulas, so they exist whenever a formula can run,
// without a change to composer's autoload. See ListFunctions.

if (!function_exists('menor')) {
    function menor(...$arguments)
    {
        return \Baja\Formula\ListFunctions::menor(\Baja\Formula\ListFunctions::one('MENOR', $arguments));
    }
}

if (!function_exists('maior')) {
    function maior(...$arguments)
    {
        return \Baja\Formula\ListFunctions::maior(\Baja\Formula\ListFunctions::one('MAIOR', $arguments));
    }
}

if (!function_exists('contar')) {
    function contar(...$arguments)
    {
        return \Baja\Formula\ListFunctions::contar(\Baja\Formula\ListFunctions::one('CONTAR', $arguments));
    }
}
