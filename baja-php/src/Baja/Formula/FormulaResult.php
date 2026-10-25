<?php
declare(strict_types=1);

namespace Baja\Formula;

/**
 * What a formula's raw value becomes, the same for every engine: text and
 * numbers are kept; NAN, INF and anything that is not text or a number
 * (true, false, an array, an object) give null.
 *
 * This is the end of Input::evaluateFormula() as it was before BAJAAPP-35.
 */
final class FormulaResult
{
    public static function of(mixed $value): string|int|float|null
    {
        if (is_string($value)) {
            return $value;
        }
        // is_nan()/is_infinite() só aceitam float; chamar com null gera deprecation.
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        return (is_nan($value) || is_infinite($value)) ? null : $value;
    }
}
