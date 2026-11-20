<?php
// No declare(strict_types=1) here, on purpose: min() and max() below get
// the formula's own values and must compare them as they would when a
// formula calls them directly (see Interpreter.php).

namespace Baja\Formula;

use TypeError;

/**
 * MENOR(), MAIOR() and CONTAR(): the formula helpers for lists, made for
 * the $T_lista of repeated input groups (BAJAAPP-103), usable on any list.
 *
 *   MENOR($T_lista)   the smallest value, or null when there is none
 *   MAIOR($T_lista)   the largest value, or null when there is none
 *   CONTAR($T_lista)  how many values there are
 *
 * They exist because PHP's min() and max() throw on an empty list (the
 * max([]) problem of BAJAAPP-56): a team that ran no attempt made the
 * whole formula null, and the config needed
 * "count(array_diff([...], [null])) > 0 ? min(...) : null" to avoid it.
 * Here an empty list is simply "no value": null, never an error.
 *
 * Empty values (null and "") are not values: MENOR([$T_1, $T_2]) with an
 * empty $T_2 is $T_1, and CONTAR() does not count them. A missing list
 * (null, e.g. a variable nobody filled) is an empty list. Anything else
 * that is not a list is an error (the formula gives null), as min(5) is.
 *
 * Both engines run them: SafeEngine allows them (Allowlist), and for
 * eval() they are also plain PHP functions, menor(), maior() and
 * contar(), defined in list-functions.php. PHP's function names ignore
 * case, so MENOR, Menor and menor are the same, as with ROUND.
 */
final class ListFunctions
{
    public static function menor($list)
    {
        $values = self::values('MENOR', $list);
        return $values === [] ? null : min($values);
    }

    public static function maior($list)
    {
        $values = self::values('MAIOR', $list);
        return $values === [] ? null : max($values);
    }

    public static function contar($list)
    {
        return count(self::values('CONTAR', $list));
    }

    /**
     * The one argument a helper takes. MENOR() and MENOR($A, $B) are
     * errors (the formula gives null), as a PHP function called with the
     * wrong number of arguments is.
     */
    public static function one(string $function, array $arguments)
    {
        if (count($arguments) !== 1) {
            throw new \ArgumentCountError($function . '() recebe exatamente 1 argumento (uma lista), ' . count($arguments) . ' recebido(s)');
        }
        return $arguments[0];
    }

    /** The list's values, without the empty ones. */
    private static function values(string $function, $list): array
    {
        if ($list === null) {
            return [];
        }
        if (!is_array($list)) {
            throw new TypeError($function . '(): o argumento precisa ser uma lista, ' . get_debug_type($list) . ' recebido');
        }
        return array_values(array_filter($list, fn($value) => $value !== null && $value !== ''));
    }
}
