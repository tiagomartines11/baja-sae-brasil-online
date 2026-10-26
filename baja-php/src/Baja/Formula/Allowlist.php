<?php
declare(strict_types=1);

namespace Baja\Formula;

/**
 * The functions and constants a formula may use with SafeEngine. Anything
 * else is refused when the formula is read, before any of it runs.
 *
 * The functions are those found in production's configs (design survey of
 * 2026-10-18: 6,072 formulas), and do what PHP's functions of the same
 * name do, because they ARE PHP's functions (see Interpreter). Names are
 * read whatever their case (ROUND, Round, round), as PHP does.
 *
 * The constants are those found in the configs (STR_PAD_LEFT; Pos,
 * EquipeNum and Equipe, which src/bootstrap.php defines so that a formula
 * naming them gets the text) plus a few harmless ones a formula could
 * plausibly use. They are case-sensitive, as in PHP 8. A constant off the
 * list is an "Undefined constant" error when the formula runs, as an
 * undefined constant is in PHP: so 'SPOILERS' without quotes gives null.
 */
final class Allowlist
{
    /** name => the most arguments allowed (null: PHP's own limit). */
    public const FUNCTIONS = [
        'round' => null,
        'max' => null,
        'min' => null,
        'array_diff' => null,
        'date' => null,
        'ceil' => null,
        'floor' => null,
        'count' => null,
        'array_sum' => null,
        'abs' => null,
        'substr' => null,
        'time' => null,
        'str_pad' => null,
        'implode' => null,
        // Only array_filter($list), which drops the empty values: its second
        // argument names a function to call, which could be any function.
        'array_filter' => 1,
    ];

    public const CONSTANTS = [
        'STR_PAD_LEFT', 'STR_PAD_RIGHT', 'STR_PAD_BOTH',
        'Pos', 'EquipeNum', 'Equipe',
        'PHP_EOL', 'PHP_INT_MAX', 'PHP_INT_MIN', 'PHP_FLOAT_EPSILON', 'INF', 'NAN', 'M_PI', 'M_E',
        'PHP_ROUND_HALF_UP', 'PHP_ROUND_HALF_DOWN', 'PHP_ROUND_HALF_EVEN', 'PHP_ROUND_HALF_ODD',
        'COUNT_RECURSIVE', 'COUNT_NORMAL',
    ];

    public static function isFunction(string $name): bool
    {
        return array_key_exists(strtolower($name), self::FUNCTIONS);
    }

    public static function isConstant(string $name): bool
    {
        return in_array($name, self::CONSTANTS, true);
    }
}
