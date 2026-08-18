<?php

namespace Baja\Certificado\Insercao;

/**
 * Text on its way into a latin1 table, from a spreadsheet.
 *
 * Two problems, both of which surface at insert time as something unhelpful
 * if they are not caught first.
 */
final class Texto
{
    /**
     * Whitespace, as a paste actually contains it.
     *
     * trim() removes ASCII spaces and nothing else. A cell copied out of a
     * browser or a PDF routinely carries U+00A0, and a clipboard from Excel
     * on Windows can carry a BOM on the first cell of the paste. Both look
     * exactly like a space and neither is one, so a name arrives with an
     * invisible character on the front and never matches anything again.
     */
    private const ESPACOS = " \t\n\r\0\x0B\u{00A0}\u{FEFF}\u{200B}";

    public static function limpar(string $raw): string
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return trim($raw);
        }

        return trim($raw, self::ESPACOS);
    }

    /**
     * Characters in this value that the table cannot hold, if any.
     *
     * `participantes` is latin1, and MySQL's latin1 is cp1252 — which is
     * wider than ISO-8859-1 and matters here: curly quotes and the euro sign
     * do have cp1252 code points, so a name carrying them stores and reads
     * back unchanged and must not be reported. What has no mapping is any
     * non-Latin script, and a handful of typographic characters a modern
     * spreadsheet substitutes silently, U+2011 NON-BREAKING HYPHEN among
     * them.
     *
     * Verified against the running server rather than assumed. Under
     * STRICT_TRANS_TABLES, MySQL 8.4 answers an unmappable character with
     * error 3988 and refuses the statement — so the failure is not the silent
     * truncation it would have been in a permissive configuration. It is
     * still worth catching here: inside a batch transaction that error aborts
     * every other row with it, and it names neither the row nor the
     * character.
     *
     * @return array<int, string> the offending characters, each once
     */
    public static function naoArmazenaveis(string $valor): array
    {
        if ($valor === '') {
            return [];
        }

        if (!mb_check_encoding($valor, 'UTF-8')) {
            // Not decodable at all, so there are no characters to name. The
            // caller reports this as its own error.
            return [];
        }

        // The common case is one call and no allocation. Only a value that
        // fails gets walked character by character to name the offenders.
        if (@iconv('UTF-8', 'CP1252', $valor) !== false) {
            return [];
        }

        $ruins = [];
        foreach (preg_split('//u', $valor, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            if (@iconv('UTF-8', 'CP1252', $char) === false) {
                $ruins[$char] = true;
            }
        }

        return array_keys($ruins);
    }

    public static function utf8Valido(string $valor): bool
    {
        return mb_check_encoding($valor, 'UTF-8');
    }

    /**
     * A character named so a person can find it in their spreadsheet.
     *
     * The character alone is not enough — the ones that cause this are
     * invisible or look like something else, which is why they survived to
     * the paste. The code point is what makes it findable.
     */
    public static function descrever(string $char): string
    {
        $codepoint = mb_ord($char, 'UTF-8');

        return sprintf('"%s" (U+%04X)', $char, $codepoint === false ? 0 : $codepoint);
    }
}
