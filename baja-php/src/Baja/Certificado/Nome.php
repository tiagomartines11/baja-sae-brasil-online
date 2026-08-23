<?php

namespace Baja\Certificado;

use Normalizer;

/**
 * Comparing a name somebody typed against a name somebody typed years ago.
 *
 * Both sides go through the same normalization, and the comparison is on token
 * sets rather than strings, because the stored names are user-provided, were
 * entered per event, and are inconsistent in every way a name can be:
 * accented or not, punctuated or not, with or without middle names, with
 * trailing spaces.
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
     * the ASCII filter would otherwise turn them into spaces.
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
     * How many tokens the person may have that the record does not.
     *
     * This is the whole security budget of the second matching direction, so
     * it is small on purpose. Requiring every stored part to be present
     * already means an attacker must know the recorded name in full; the
     * allowance only lets somebody add names the record is missing. Raising it
     * turns the form into a coverage attack — submit a first name plus twenty
     * common surnames and match anyone whose record is a subset of the pile.
     */
    private const MAX_PARTS_ABSENT_FROM_RECORD = 2;

    /**
     * The parts of a name: what a person would call the individual names.
     *
     * Punctuation is removed rather than split on, so "D'Àngelo" is one part,
     * "dangelo" — the same part somebody gets by typing it without the
     * apostrophe. This is the set that decides how many names were supplied.
     *
     * @return array<int, string>
     */
    public static function parts(string $name): array
    {
        return self::tokenize(self::fold($name), true);
    }

    /**
     * Everything a name could reasonably be written as, for membership tests.
     *
     * The union of two readings: punctuation removed ("dangelo") and
     * punctuation split on ("d", "angelo"). Apostrophes in Brazilian names —
     * D'Ângelo, D'Ávila, Sant'Ana — are used inconsistently by the same person
     * on different days, and whichever way the record went, the search must
     * reach it from any of "D'Ângelo", "D Angelo" and "Dangelo".
     *
     * Union rather than one or the other because the choice cannot be made
     * correctly in isolation: joining alone misses a record typed with a
     * space, splitting alone misses one typed without the apostrophe.
     *
     * @return array<int, string>
     */
    public static function normalize(string $name): array
    {
        $folded = self::fold($name);

        return array_values(array_unique(array_merge(
            self::tokenize($folded, false),
            self::tokenize($folded, true)
        )));
    }

    /**
     * Whether a submitted name matches one stored name.
     *
     * Takes the raw strings rather than token sets so that the two-name
     * minimum below can be counted in parts. Counting tokens instead would
     * let a bare "D'Angelo" through, since punctuation splits it into two.
     *
     * Two ways to match, because either name can be the incomplete one and
     * neither party knows which:
     *
     * 1. Every submitted part is somewhere in the record. This is the person
     *    typing less than is on file — "João Bresolin" or "João Silva"
     *    against a stored "João Pedro Bresolin Silva".
     *
     * 2. Every stored part is somewhere in the submission, with at most a
     *    couple of extras. This is the record being the incomplete one, which
     *    is just as common: names were entered per event, by hand, and get
     *    truncated. Somebody on file as "Fulano da Silva Testeson" typing
     *    their full "Fulano da Silva Testeson dos Santos" should not be told
     *    their certificate does not exist.
     *
     * Both directions need at least two parts in common. That is what rejects
     * a bare first name — the name is the only credential here, and first
     * names are not secret — and it also means a record holding a single part
     * cannot be matched at all, which is correct: one word is not a credential
     * either.
     *
     * Note what case 2 deliberately does not accept: a submission that is
     * missing stored parts *and* adds its own. "João Bresolin Ferreira"
     * against "João Pedro Bresolin Silva" stays a non-match, because nothing
     * about it suggests a truncated record rather than a different person.
     */
    public static function matches(string $submittedName, string $storedName): bool
    {
        $submittedParts = self::parts($submittedName);
        $storedParts    = self::parts($storedName);

        if (count($submittedParts) < 2) {
            return false;
        }

        // Membership is tested against the union, so that a part written one
        // way reaches a record written the other.
        $submittedAny = self::normalize($submittedName);
        $storedAny    = self::normalize($storedName);

        // Both sides as unions here, so that "Bresolin-Silva" and "Bresolin
        // Silva" recognise each other whichever one the record holds. The
        // two-name minimum is already enforced on parts above, so widening
        // this cannot let a single name through.
        $recognised = array_intersect($submittedAny, $storedAny);
        if (count($recognised) < 2) {
            return false;
        }

        $absentFromRecord = array_diff($submittedParts, $storedAny);

        // 1. The submission is contained in the record.
        if (count($absentFromRecord) === 0) {
            return true;
        }

        // 2. The record is contained in the submission, give or take.
        $absentFromSubmission = array_diff($storedParts, $submittedAny);

        return count($absentFromSubmission) === 0
            && count($absentFromRecord) <= self::MAX_PARTS_ABSENT_FROM_RECORD;
    }

    /**
     * Lowercase ASCII, accents folded, punctuation still in place.
     *
     * Public because anything else comparing user-supplied text against
     * stored text has to fold it the same way or the two agree only by
     * accident. Funcao matches whole strings with it; this class goes on to
     * tokenize the result.
     *
     * Two things here were specified differently and verified to do the
     * opposite of what they claimed on this stack:
     *
     * 1. iconv('ISO-8859-1', 'UTF-8', ...) on the stored name. The `nome`
     *    column is latin1, but the connection runs SET NAMES utf8mb4, so MySQL
     *    has already converted it and Propel hands back valid UTF-8.
     *    Converting again mangles exactly the accented names the step was
     *    meant to protect. The guard below covers a value that genuinely is
     *    not UTF-8.
     *
     * 2. iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE') for the accents. Under
     *    musl, which is what the php:8.3-fpm-alpine image uses, that does not
     *    fold them — it decomposes them into ASCII punctuation, so "João"
     *    becomes "Jo~ao" and then the two tokens "jo" and "ao". Every accented
     *    name would fail to match its unaccented spelling, and a bare first
     *    name would satisfy the two-part minimum. Normalizer (ext-intl,
     *    already in the image) decomposes properly on any libc.
     */
    public static function fold(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
        }

        $name = strtr($name, self::NON_DECOMPOSABLE);

        // NFD splits "ã" into "a" plus a combining tilde; dropping the
        // combining marks leaves the base letters.
        $decomposed = Normalizer::normalize($name, Normalizer::FORM_D);
        if ($decomposed !== false) {
            $name = preg_replace('/\p{Mn}/u', '', $decomposed);
        }

        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * A whole string reduced to something two spellings of it can be compared
     * on.
     *
     * fold() is for names, where the token split depends on the spacing and
     * the punctuation, so it leaves both alone. This is for values matched
     * entire — a role, an event's name — where the opposite holds:
     * "Comissão Técnica", "comissao  tecnica" and "Comissão-Técnica" are the
     * same answer typed by three people, and only the letters carry meaning.
     *
     * Ordinal indicators fold to their letter rather than being dropped.
     * Event names are full of them ("27ª Competição"), NFD leaves them intact
     * because their decomposition is compatibility rather than canonical, and
     * dropping them would make "27ª" and the "27a" somebody types instead
     * differ by more than the character they are arguing about.
     */
    public static function chave(string $value): string
    {
        $folded = strtr(self::fold($value), ['ª' => 'a', 'º' => 'o']);

        // Everything that is not a letter or a digit becomes a space, which
        // takes punctuation, non-breaking spaces, and the literal entity text
        // some stored event names carry, with it.
        $folded = preg_replace('/[^a-z0-9]+/', ' ', $folded);

        return trim($folded);
    }

    /**
     * @param bool $joinPunctuation true removes punctuation, false splits on it
     *
     * @return array<int, string>
     */
    private static function tokenize(string $folded, bool $joinPunctuation): array
    {
        $folded = preg_replace('/[^a-z ]/', $joinPunctuation ? '' : ' ', $folded);
        $tokens = preg_split('/\s+/', $folded, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_diff($tokens, self::CONNECTIVES)));
    }
}
