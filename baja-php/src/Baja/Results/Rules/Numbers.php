<?php
declare(strict_types=1);

namespace Baja\Results\Rules;

/**
 * How a cell is read as a number or as plain text (docs/results-rules.md,
 * rule R2 and "Raw values").
 *
 * Cells often hold HTML: bold totals, line breaks, and struck-through
 * values (<s>13.87</s>) for a score that no longer counts. Struck-through
 * content is dropped first, with the tags; then the remaining HTML.
 *
 *   read()   the FIRST number left in the text, for ranking and tiebreaks:
 *              "<b>0</b><br /> <s>13.87</s>"  -> 0.0
 *              "1,5 pts"                      -> 1.5   ("," is a decimal point)
 *              "<s>12</s>"  or  "DNF"         -> null  (no value)
 *
 *   exact()  a number only when the text is EXACTLY a number, for the raw
 *            value the API gives: "12,5" -> 12.5, "12,5 pts" -> null.
 */
final class Numbers
{
    /** Optional minus, digits, optional decimal part with "." or ",". */
    private const NUMBER = '-?\d+(?:[.,]\d+)?';

    /** Opening tag of an element whose content is struck through. */
    private const STRUCK = '<(?:s|strike|del)\b[^>]*>';

    public static function read(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float)$value) ? (float)$value : null;
        }
        if (!preg_match('/' . self::NUMBER . '/', self::plainText($value), $match)) {
            return null;
        }
        return (float)str_replace(',', '.', $match[0]);
    }

    public static function exact(string $text): int|float|null
    {
        if (!preg_match('/^' . self::NUMBER . '$/', $text)) {
            return null;
        }
        return str_replace(',', '.', $text) + 0;
    }

    /**
     * The text a cell shows, without what is struck through and without
     * HTML. Tags become spaces, so "12<br>3" stays two numbers; spaces
     * (including &nbsp;) are collapsed and trimmed.
     */
    public static function plainText(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }
        $html = self::withoutStruck((string)$value);
        $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html) ?? $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text);
    }

    /**
     * Removes struck-through elements and what is inside them. Innermost
     * first, so nested ones go too. An element left open strikes through
     * to the end of the text, as a browser shows it.
     */
    private static function withoutStruck(string $html): string
    {
        $innermost = '#' . self::STRUCK . '(?:(?!' . self::STRUCK . ').)*?</(?:s|strike|del)\s*>#is';
        do {
            $before = $html;
            $html = preg_replace($innermost, '', $html) ?? $before;
        } while ($html !== $before);
        return preg_replace('#' . self::STRUCK . '.*$#is', '', $html) ?? $html;
    }
}
