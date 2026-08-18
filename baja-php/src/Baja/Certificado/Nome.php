<?php

namespace Baja\Certificado;

use Normalizer;

/**
 * Comparing a name somebody typed against a name somebody typed years ago.
 *
 * Both sides go through the same normalization, and the comparison is on token
 * sets rather than strings, because the stored names are user-provided, were
 * entered per event, and are inconsistent in every way a name can be:
 * accented or not, with or without middle names, with trailing spaces.
 */
final class Nome
{
    /**
     * Portuguese connectives, dropped from both sides.
     *
     * "João da Silva" and "João Silva" are the same name, and which form got
     * typed at registration is not something the person searching can know.
     */
    private const CONNECTIVES = ['de', 'da', 'do', 'dos', 'das', 'e'];

    /**
     * Letters with no canonical decomposition, so NFD leaves them intact and
     * the ASCII filter below would turn them into spaces.
     *
     * Only relevant for foreign participants, who are exactly the people this
     * system is worst at already.
     */
    private const NON_DECOMPOSABLE = [
        'Æ' => 'AE', 'æ' => 'ae', 'Ø' => 'O',  'ø' => 'o',
        'Đ' => 'D',  'đ' => 'd',  'Þ' => 'TH', 'þ' => 'th',
        'ß' => 'ss', 'Ł' => 'L',  'ł' => 'l',  'Œ' => 'OE',
        'œ' => 'oe', 'Ð' => 'D',  'ð' => 'd',  'İ' => 'I',
        'ı' => 'i',
    ];

    /**
     * A name reduced to a set of comparable tokens.
     *
     * Two deviations from the specification, both because the specified
     * version was verified to do the opposite of what it says on this stack:
     *
     * 1. It called iconv('ISO-8859-1', 'UTF-8', ...) on the stored name first.
     *    The `nome` column is latin1, but the connection runs SET NAMES
     *    utf8mb4, so MySQL has already converted it and Propel hands back
     *    valid UTF-8. Converting again mangles exactly the accented names the
     *    step was meant to protect. There is a guard below for the case where
     *    a value really is not UTF-8.
     *
     * 2. It transliterated with iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE').
     *    Under musl, which is what the php:8.3-fpm-alpine image uses, that
     *    does not fold accents — it decomposes them into ASCII punctuation, so
     *    "João" becomes "Jo~ao" and then, once non-letters become spaces,
     *    the two tokens "jo" and "ao". Every accented name would fail to match
     *    its unaccented spelling, and a bare first name would satisfy the
     *    two-token minimum that exists to reject bare first names. Normalizer
     *    (ext-intl, already in the image) decomposes properly and behaves the
     *    same on any libc.
     */
    public static function normalize(string $name): array
    {
        // Defensive: if a value really did arrive as latin1 bytes, salvage it
        // rather than dropping every accented character in the filter below.
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }

        $name = strtr($name, self::NON_DECOMPOSABLE);

        // NFD splits "ã" into "a" + combining tilde; dropping the combining
        // marks leaves the base letters.
        $decomposed = Normalizer::normalize($name, Normalizer::FORM_D);
        if ($decomposed !== false) {
            $name = preg_replace('/\p{Mn}/u', '', $decomposed);
        }

        $name   = strtolower(preg_replace('/[^a-zA-Z ]/', ' ', $name));
        $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_diff($tokens, self::CONNECTIVES));
    }

    /**
     * Whether a submitted name matches one stored name.
     *
     * Every submitted token must appear in the stored set, and there must be
     * at least two distinct ones. That accepts "João Bresolin", "João Silva"
     * and the full name against a stored "João Pedro Bresolin Silva", and
     * rejects a bare first name — which matters, because the name is the only
     * credential here and first names are not secret.
     *
     * Distinct rather than merely two, so that "Silva Silva" does not clear a
     * bar meant to require two pieces of information.
     */
    public static function matches(array $submitted, array $stored): bool
    {
        if (count(array_unique($submitted)) < 2) {
            return false;
        }

        foreach ($submitted as $token) {
            if (!in_array($token, $stored, true)) {
                return false;
            }
        }

        return true;
    }
}
