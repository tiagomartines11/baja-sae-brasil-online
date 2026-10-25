<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Html;

/**
 * The small text clean-ups that make a snapshot stable.
 *
 * A snapshot must change when a result changes and stay the same otherwise.
 * Two things in a page change without any result changing: indentation and
 * line breaks in the HTML source, and the clock (the "last updated" line
 * under a table, the running time of a prova still in progress).
 */
final class Text
{
    /**
     * Turns every run of spaces, tabs and line breaks into one space and
     * trims both ends. "  12,5 \n pts " and "12,5 pts" are the same cell.
     */
    public static function normaliseWhitespace(string $text): string
    {
        // \s with /u also matches the non-breaking space U+00A0.
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Replaces what depends on the clock, not on the results:
     *
     *   "Última Atualização: 2024-03-24 17:02:11"  ->  "Última Atualização: [timestamp]"
     *   "Tempo de Prova: 01:25"                     ->  "Tempo de Prova: [elapsed]"
     *
     * Only used on the text around a table (its footer), never on the cells:
     * a date inside a cell is data, and a change to it must show.
     */
    public static function maskClock(string $text): string
    {
        $text = (string)preg_replace(
            '~\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?|\d{2}/\d{2}/\d{4} \d{2}:\d{2}(:\d{2})?~',
            '[timestamp]',
            $text
        );
        return (string)preg_replace('/(Tempo de Prova:\s*)\d+:\d+/u', '$1[elapsed]', $text);
    }
}
