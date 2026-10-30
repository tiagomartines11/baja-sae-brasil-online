<?php
declare(strict_types=1);

namespace Baja\Rbac\Expiry;

use DateTimeImmutable;

/**
 * Reads the LAST day of an event from evento.data.
 *
 * evento.data is free text typed by an admin, in Portuguese, for people to
 * read: "20 a 24 de abril de 2022", "25 a 28 de Setembro de 2025",
 * "30 de abril a 2 de maio de 2025". Some may write "24/04/2022".
 *
 * The last day is the last full date in the text: the last "<day> de
 * <month> de <year>", or the last "dd/mm/yyyy". A text with neither, or
 * whose date does not exist (31 de fevereiro), gives null: the caller must
 * then not guess (see GrantExpiry).
 */
final class EventDateParser
{
    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4,
        'maio' => 5, 'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9,
        'outubro' => 10, 'novembro' => 11, 'dezembro' => 12,
    ];

    public static function lastDay(?string $text): ?DateTimeImmutable
    {
        if ($text === null || trim($text) === '') {
            return null;
        }
        // Admins type entities ("24&nbsp;de abril"); a non-breaking space is
        // still a space here.
        $text = str_replace("\u{00A0}", ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = mb_strtolower($text, 'UTF-8');

        $candidates = [];
        if (preg_match_all('/(?<!\d)(\d{1,2})\s*(?:º|o)?\s+de\s+(\p{L}+)\s+de\s+(\d{4})/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $month = self::MONTHS[$m[2][0]] ?? null;
                if ($month !== null) {
                    $candidates[$m[0][1]] = [(int) $m[3][0], $month, (int) $m[1][0]];
                }
            }
        }
        if (preg_match_all('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/u', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $candidates[$m[0][1]] = [(int) $m[3][0], (int) $m[2][0], (int) $m[1][0]];
            }
        }
        if ($candidates === []) {
            return null;
        }

        // The one furthest into the text.
        ksort($candidates);
        [$year, $month, $day] = end($candidates);
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return (new DateTimeImmutable('today'))->setDate($year, $month, $day)->setTime(0, 0);
    }
}
