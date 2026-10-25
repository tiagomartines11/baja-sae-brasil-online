<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use PhpToken;

/**
 * Finds the nested ternaries PHP 8 refuses, and writes the formula both
 * ways it could be read.
 *
 *     $A ? 1 : $B ? 2 : 3
 *
 * PHP 7 read this left to right, as ($A ? 1 : $B) ? 2 : 3. Almost every
 * other language, and almost every author, means $A ? 1 : ($B ? 2 : 3).
 * PHP 8 does not guess: it stops with a fatal error ("Unparenthesized
 * `a ? b : c ? d : e` is not supported") as soon as it reads the formula.
 * The check cannot pick a reading either, so it shows both:
 *
 *     readings('$A ? 1 : $B ? 2 : 3')
 *       php7   '($A ? 1 : $B) ? 2 : 3'
 *       meant  '$A ? 1 : ($B ? 2 : 3)'
 *
 * The formula is only split into tokens (PhpToken), never compiled, so a
 * formula that would kill PHP can be read here safely. Parentheses are
 * added and nothing else changes: spaces and the rest stay as written.
 */
final class NestedTernary
{
    /** Tokens that end an operand of "? :" without being brackets: lower precedence, or a separator. */
    private const BOUNDARIES = [
        ',', ';', '=', T_DOUBLE_ARROW, T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR,
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL,
    ];

    private const OPENING = ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];
    private const CLOSING = [')', ']', '}'];

    /**
     * @return array{php7: string, meant: string}|null null when the formula has no such ternary
     */
    public static function readings(string $formula): ?array
    {
        $tokens = PhpToken::tokenize('<?php ' . $formula);
        array_shift($tokens);  // the "<?php " added above
        $chains = self::chains($tokens);
        if ($chains === []) {
            return null;
        }

        $php7 = [];
        $meant = [];
        foreach ($chains as $chain) {
            // Left to right: "(" at the start for every ternary after the
            // first, and each of those closed just before its "?".
            //   a ? b : c ? d : e   ->   (a ? b : c) ? d : e
            $php7[] = [self::start($tokens[$chain['first']]), str_repeat('(', count($chain['ternaries']) - 1)];
            foreach (array_slice($chain['ternaries'], 1) as $ternary) {
                $php7[] = [self::end($tokens[self::previous($tokens, $ternary['question'])]), ')'];
            }
            // Right to left: every ternary but the last gets its "else" part
            // put in brackets, closed at the very end.
            //   a ? b : c ? d : e   ->   a ? b : (c ? d : e)
            foreach (array_slice($chain['ternaries'], 0, -1) as $ternary) {
                $meant[] = [self::start($tokens[self::next($tokens, $ternary['colon'])]), '('];
            }
            $meant[] = [self::end($tokens[$chain['last']]), str_repeat(')', count($chain['ternaries']) - 1)];
        }
        return ['php7' => self::insert($formula, $php7), 'meant' => self::insert($formula, $meant)];
    }

    /**
     * Every run of two or more ternaries side by side, in the same operand,
     * that PHP 8 refuses.
     *
     * Each "?" is matched with its ":" the way brackets are matched. A
     * ternary inside another's middle part (a ? b ? c : d : e) belongs to
     * that one and is fine; two that follow each other in the same place
     * (a ? b : c ? d : e) are the problem. Runs made only of "?:" (a ?: b ?: c)
     * are fine too: PHP 8 still reads those.
     *
     * @param list<PhpToken> $tokens
     * @return list<array{first: int, last: int, ternaries: list<array{question: int, colon: int}>}>
     *         first, last: the tokens the run starts and ends with
     */
    private static function chains(array $tokens): array
    {
        $chains = [];
        // One "level" per bracket: the ternaries of a level cannot see past its brackets.
        $levels = [self::level()];
        foreach ($tokens as $index => $token) {
            $top = count($levels) - 1;
            if ($token->isIgnorable()) {
                continue;
            }
            if ($token->is(self::OPENING)) {
                // The bracket is part of the operand it is in: "(...) ? a : b".
                $levels[$top]['first'] ??= $index;
                $levels[$top]['last'] = $index;
                $levels[] = self::level();
            } elseif ($token->is(self::CLOSING) && $top > 0) {
                array_push($chains, ...self::closeLevel(array_pop($levels), $tokens));
                $levels[$top - 1]['last'] = $index;
            } elseif ($token->text === '?') {
                $levels[$top]['open'][] = $index;
                $levels[$top]['first'] ??= $index;
            } elseif ($token->text === ':' && $levels[$top]['open'] !== []) {
                $question = array_pop($levels[$top]['open']);
                // Its parent is the "?" whose middle part it is in, or none.
                $parent = $levels[$top]['open'] === [] ? 'top' : (string)end($levels[$top]['open']);
                $levels[$top]['ternaries'][$parent][] = ['question' => $question, 'colon' => $index];
                $levels[$top]['colonOf'][$question] = $index;
                $levels[$top]['last'] = $index;
            } elseif ($token->is(self::BOUNDARIES) || $token->is(self::CLOSING) || $token->text === ':') {
                // A ":" with no "?" is a named argument, f(x: 1), or the like.
                array_push($chains, ...self::closeLevel($levels[$top], $tokens));
                $levels[$top] = self::level();
            } else {
                $levels[$top]['first'] ??= $index;
                $levels[$top]['last'] = $index;
            }
        }
        foreach ($levels as $level) {
            array_push($chains, ...self::closeLevel($level, $tokens));
        }
        return $chains;
    }

    /** An empty level: no tokens and no ternaries yet. */
    private static function level(): array
    {
        return ['first' => null, 'last' => null, 'open' => [], 'ternaries' => [], 'colonOf' => []];
    }

    /**
     * The runs of a level, now that it has ended.
     *
     * @param list<PhpToken> $tokens
     * @return list<array{first: int, last: int, ternaries: list<array{question: int, colon: int}>}>
     */
    private static function closeLevel(array $level, array $tokens): array
    {
        $chains = [];
        foreach ($level['ternaries'] as $parent => $ternaries) {
            if (count($ternaries) < 2 || self::allShort($ternaries, $tokens)) {
                continue;
            }
            if ($parent === 'top') {
                [$first, $last] = [$level['first'], $level['last']];
            } elseif (isset($level['colonOf'][$parent])) {
                // In the middle part of another ternary: between its "?" and its ":".
                [$first, $last] = [self::next($tokens, (int)$parent), self::previous($tokens, $level['colonOf'][$parent])];
            } else {
                continue;  // that "?" never got its ":": not a formula PHP could read anyway
            }
            $chains[] = ['first' => $first, 'last' => $last, 'ternaries' => $ternaries];
        }
        return $chains;
    }

    /**
     * Whether every ternary is a "?:", with nothing but spaces between the two.
     *
     * @param list<array{question: int, colon: int}> $ternaries
     * @param list<PhpToken> $tokens
     */
    private static function allShort(array $ternaries, array $tokens): bool
    {
        foreach ($ternaries as $ternary) {
            if (self::next($tokens, $ternary['question']) !== $ternary['colon']) {
                return false;
            }
        }
        return true;
    }

    /** The index of the next token that is not a space or a comment. */
    private static function next(array $tokens, int $index): int
    {
        do {
            $index++;
        } while ($tokens[$index]->isIgnorable());
        return $index;
    }

    /** The index of the previous token that is not a space or a comment. */
    private static function previous(array $tokens, int $index): int
    {
        do {
            $index--;
        } while ($tokens[$index]->isIgnorable());
        return $index;
    }

    /** Where the token starts in the formula (the tokens were read with "<?php " in front). */
    private static function start(PhpToken $token): int
    {
        return $token->pos - strlen('<?php ');
    }

    private static function end(PhpToken $token): int
    {
        return self::start($token) + strlen($token->text);
    }

    /**
     * The formula with each text put in at its place. Where a ")" and a "("
     * fall on the same place, the ")" comes first: it closes something
     * earlier, the "(" opens something later.
     *
     * @param list<array{int, string}> $insertions place, text
     */
    private static function insert(string $formula, array $insertions): string
    {
        $at = [];
        foreach ($insertions as [$place, $text]) {
            $at[$place][$text[0] === ')' ? 0 : 1][] = $text;
        }
        // From the end backwards, so the places still to do do not move.
        krsort($at);
        foreach ($at as $place => $texts) {
            ksort($texts);
            $formula = substr($formula, 0, $place) . implode('', array_merge(...$texts)) . substr($formula, $place);
        }
        return $formula;
    }
}
