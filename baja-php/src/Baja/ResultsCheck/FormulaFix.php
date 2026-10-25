<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

/**
 * The one mechanical fix the check proposes: text joined with "+".
 *
 * Formulas are PHP. Many were written as if "+" joined text, as in
 * JavaScript: '<strong>' + ROUND($PTS, 2) + '</strong>'. PHP joins text with
 * ".", and PHP 8 refuses "+" on text with a TypeError, so the cell is empty
 * (in PHP 7 the text counted as 0 and only the number was shown).
 *
 * propose() rewrites such a formula:
 *
 *   - a "+" with a string literal right before or right after it becomes
 *     ".". Other "+" stay: $A + $B + 1 adds numbers, and a "+" inside a
 *     string literal ('1+1') is text, not an operator. A string that is a
 *     number ('5' + $X) is left alone, since PHP adds those fine;
 *   - and, only in a formula that needed that, a tag opened twice and never
 *     closed ('<strong>' ... '<strong>') gets its second one closed
 *     ('<strong>' ... '</strong>').
 *
 * The formula is read with PHP's own tokenizer, so string literals are
 * recognised exactly as PHP sees them. Whether the result runs is not
 * decided here: FixProposal runs it for every team before proposing it.
 */
final class FormulaFix
{
    /** Elements that never have a closing tag: '<br>' twice is fine. */
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** The fixed formula, or null when there is no "+" on text to fix. */
    public static function propose(string $formula): ?string
    {
        $joined = self::plusToDot($formula);
        if ($joined === $formula) {
            return null;
        }
        return self::closeRepeatedTags($joined);
    }

    /** Every "+" next to a string literal (not a numeric one) becomes ".". */
    public static function plusToDot(string $formula): string
    {
        $tokens = self::tokens($formula);
        if ($tokens === null) {
            return $formula;
        }
        $items = self::items($tokens);
        foreach ($items as $i => $item) {
            if ($item['type'] !== '+') {
                continue;
            }
            $before = $items[$i - 1] ?? null;
            $after = $items[$i + 1] ?? null;
            // A "+" after a string literal always joins it to something. A
            // "+" before one only when something comes first: "+'x'" alone
            // would be a sign, not an operator.
            $joinsText = self::isText($before)
                || (self::isText($after) && $before !== null && $before['endsOperand']);
            if (!$joinsText) {
                continue;
            }
            // "1+'x'" must not become "1.'x'": PHP would read "1." as a number.
            $dot = '.';
            if ($before !== null && $before['type'] === 'number' && $before['last'] === $item['first'] - 1) {
                $dot = ' ' . $dot;
            }
            if ($after !== null && $after['type'] === 'number' && $after['first'] === $item['last'] + 1) {
                $dot .= ' ';
            }
            $tokens[$item['first']] = $dot;
        }
        return self::text($tokens);
    }

    /**
     * '<b>' ... '<b>' becomes '<b>' ... '</b>': for each tag (with no
     * attributes, not a void element) opened exactly twice and closed
     * nowhere in the formula's string literals, the second one is closed.
     */
    public static function closeRepeatedTags(string $formula): string
    {
        $tokens = self::tokens($formula);
        if ($tokens === null) {
            return $formula;
        }
        $opened = [];
        $closed = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            preg_match_all('/<(\/?)([A-Za-z][A-Za-z0-9]*)\s*>/', $token[1], $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $name = strtolower($match[2][0]);
                if ($match[1][0] === '/') {
                    $closed[$name] = true;
                } else {
                    $opened[$name][] = ['token' => $index, 'offset' => $match[0][1], 'tag' => $match[0][0], 'name' => $match[2][0]];
                }
            }
        }
        foreach ($opened as $name => $places) {
            if (count($places) !== 2 || isset($closed[$name]) || in_array($name, self::VOID_ELEMENTS, true)) {
                continue;
            }
            $second = $places[1];
            $text = $tokens[$second['token']][1];
            $tokens[$second['token']][1] = substr_replace($text, '</' . $second['name'] . '>', $second['offset'], strlen($second['tag']));
        }
        return self::text($tokens);
    }

    /**
     * The formula's tokens, as PHP reads "$formula = <formula>;" (that is
     * how Input::solveFormula runs it), without the "<?php " and ";" added
     * around it. Null when the formula does not end where it should (an
     * unfinished string, say): then nothing is rewritten.
     *
     * @return list<string|array{0: int, 1: string, 2: int}>|null
     */
    private static function tokens(string $formula): ?array
    {
        $tokens = token_get_all('<?php ' . $formula . ';');
        $open = array_shift($tokens);
        $end = array_pop($tokens);
        if (!is_array($open) || $open[0] !== T_OPEN_TAG || $open[1] !== '<?php ' || $end !== ';' || self::text($tokens) !== $formula) {
            return null;
        }
        return $tokens;
    }

    /**
     * The tokens that matter for "+", with whitespace and comments left out,
     * and each string (a '...' or "..." literal, or a "..." with variables
     * in it, which PHP splits in several tokens) as one item:
     *
     *   type        'string', 'number', '+' or 'other'
     *   first/last  where it starts and ends in $tokens
     *   numeric     for a string: whether its text is a number ('5')
     *   endsOperand whether a binary operator could follow it ($X, 2, ")"...)
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $tokens
     * @return list<array{type: string, first: int, last: int, numeric: bool, endsOperand: bool}>
     */
    private static function items(array $tokens): array
    {
        $items = [];
        $openQuote = null;
        foreach ($tokens as $index => $token) {
            if ($openQuote !== null) {
                if ($token === '"') {
                    $items[] = self::item('string', $openQuote, $index, false, true);
                    $openQuote = null;
                }
                continue;
            }
            if ($token === '"') {
                $openQuote = $index;
                continue;
            }
            $id = is_array($token) ? $token[0] : null;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }
            $items[] = match (true) {
                $id === T_CONSTANT_ENCAPSED_STRING => self::item('string', $index, $index, is_numeric(substr($token[1], 1, -1)), true),
                $id === T_LNUMBER || $id === T_DNUMBER => self::item('number', $index, $index, false, true),
                $token === '+' => self::item('+', $index, $index, false, false),
                default => self::item('other', $index, $index, false, in_array($id, [T_VARIABLE, T_STRING], true) || $token === ')' || $token === ']'),
            };
        }
        return $items;
    }

    /** @return array{type: string, first: int, last: int, numeric: bool, endsOperand: bool} */
    private static function item(string $type, int $first, int $last, bool $numeric, bool $endsOperand): array
    {
        return ['type' => $type, 'first' => $first, 'last' => $last, 'numeric' => $numeric, 'endsOperand' => $endsOperand];
    }

    /** @param array{type: string, numeric: bool}|null $item */
    private static function isText(?array $item): bool
    {
        return $item !== null && $item['type'] === 'string' && !$item['numeric'];
    }

    /** @param list<string|array{0: int, 1: string, 2: int}> $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(fn($token) => is_array($token) ? $token[1] : $token, $tokens));
    }
}
