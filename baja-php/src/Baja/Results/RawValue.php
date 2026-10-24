<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * Turns what a cell shows into a value a program can use: the HTML goes,
 * and a number stays a number.
 *
 *     12.5                         -> 12.5
 *     "<b>12.5</b>"                -> 12.5
 *     " 7 "                        -> 7
 *     "53.9%"                      -> "53.9%"   (not a number as a whole)
 *     "DNF<br />&nbsp;"            -> "DNF"
 *     "" or null                   -> null
 */
final class RawValue
{
    public static function of(mixed $display): mixed
    {
        if ($display === null || is_int($display) || is_float($display) || is_bool($display)) {
            return $display;
        }
        $text = html_entity_decode(strip_tags((string)$display), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // &nbsp; decodes to U+00A0, which trim() does not know.
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }
        return is_numeric($text) ? $text + 0 : $text;
    }
}
