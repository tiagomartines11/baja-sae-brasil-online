<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Model\Input;
use Throwable;

/**
 * Collects the errors formulas raise while some code runs.
 *
 * Input::solveFormula() turns any error a formula raises (a TypeError for
 * '<b>' + $X, a ValueError for max([]), a syntax error...) into an empty
 * value, so that one broken formula does not break a whole results page.
 * That is right for the pages, but it also hides the broken formula. This
 * class asks solveFormula() to tell it about each error first, runs the
 * code, and stops listening again, so the pages behave exactly as before.
 *
 *     [$table, $errors] = FormulaErrors::during(fn() => ResultsBuilder::build(...));
 */
final class FormulaErrors
{
    /**
     * @template T
     * @param callable(): T $work
     * @return array{0: T, 1: list<FormulaError>} what $work returned, and the errors in the order raised
     */
    public static function during(callable $work): array
    {
        $errors = [];
        Input::setFormulaErrorListener(function (Throwable $error, mixed $formula) use (&$errors): void {
            $errors[] = new FormulaError($formula, get_class($error) . ': ' . $error->getMessage());
        });
        try {
            $result = $work();
        } finally {
            Input::setFormulaErrorListener(null);
        }
        return [$result, $errors];
    }
}
