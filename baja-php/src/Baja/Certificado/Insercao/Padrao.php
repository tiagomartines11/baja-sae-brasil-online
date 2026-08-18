<?php

namespace Baja\Certificado\Insercao;

/**
 * A search box's contents, turned into a SQL LIKE pattern.
 *
 * `*` is the wildcard, because it is what people type and because a `%` in a
 * search box is far more likely to be part of a name than an instruction.
 * Whatever is typed is matched as a substring, so "Silva" finds "José da
 * Silva Santos" without anybody having to know that.
 *
 * Everything LIKE would otherwise treat as special is escaped. Somebody
 * searching for a literal "%" gets rows containing one, not every row in the
 * table.
 */
final class Padrao
{
    /** Straight and curly apostrophes, stripped from the term and the column alike. */
    public const APOSTROFOS = ["'", "\u{2019}", "\u{2018}", '`', "\u{00B4}"];

    /**
     * A name search.
     *
     * Apostrophes come out because the same person writes them inconsistently
     * on different days — D'Ávila, D Ávila, Davila — and a search box is
     * exactly where that bites. Accents and case need no handling at all:
     * `nome` is latin1_swedish_ci, which ignores both, in both directions.
     * Verified against the running server rather than assumed.
     */
    public static function paraNome(string $termo): ?string
    {
        return self::montar(str_replace(self::APOSTROFOS, '', $termo));
    }

    /**
     * A CPF search.
     *
     * Reduced to digits, because the column holds eleven of them and nothing
     * else while people type 529.982.247-25. Substring matching means a CPF
     * whose leading zeros a spreadsheet ate still finds its padded row.
     */
    public static function paraCpf(string $termo): ?string
    {
        return self::montar(preg_replace('/[^0-9*]/', '', $termo));
    }

    /**
     * A passport search.
     *
     * Letters and digits only on both sides, since the column holds whatever
     * was typed at registration and that includes every separator anybody has
     * ever reached for.
     */
    public static function paraPassaporte(string $termo): ?string
    {
        return self::montar(preg_replace('/[^\p{L}\p{N}*]/u', '', $termo));
    }

    /**
     * @return string|null null when the term constrains nothing — empty, or
     *                     wildcards alone. A pattern of "%" would be a filter
     *                     that quietly matches the whole table, which is worse
     *                     than no filter because it looks like one.
     */
    private static function montar(string $termo): ?string
    {
        $termo = Texto::limpar($termo);

        if ($termo === '') {
            return null;
        }

        // Split on the wildcard first, then escape each literal piece. In this
        // order there is never a question of whether a % in the finished
        // pattern came from the user or from us.
        $partes = array_filter(
            array_map(
                static fn (string $parte): string => str_replace(
                    ['\\', '%', '_'],
                    ['\\\\', '\\%', '\\_'],
                    $parte
                ),
                explode('*', $termo)
            ),
            static fn (string $parte): bool => $parte !== ''
        );

        if ($partes === []) {
            return null;
        }

        return '%' . implode('%', $partes) . '%';
    }
}
