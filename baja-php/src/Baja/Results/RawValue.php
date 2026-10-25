<?php
declare(strict_types=1);

namespace Baja\Results;

use Baja\Results\Rules\Numbers;

/**
 * Turns what a cell shows into a value a program can use (docs/
 * results-rules.md, "Raw values"): struck-through content and HTML go, and
 * text that is exactly a number becomes that number.
 *
 *     12.5                         -> 12.5
 *     "<b>12,5</b>"                -> 12.5
 *     " 7 "                        -> 7
 *     "<b>0</b> <s>13.87</s>"      -> 0
 *     "53.9%"                      -> "53.9%"   (not a number as a whole)
 *     "DNF<br />&nbsp;"            -> "DNF"
 *     "", null or "<s>12</s>"      -> null
 */
final class RawValue
{
    public static function of(mixed $display): mixed
    {
        if ($display === null || is_int($display) || is_float($display) || is_bool($display)) {
            return $display;
        }
        $text = Numbers::plainText($display);
        if ($text === '') {
            return null;
        }
        return Numbers::exact($text) ?? $text;
    }
}
