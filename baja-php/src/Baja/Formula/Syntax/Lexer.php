<?php
declare(strict_types=1);

namespace Baja\Formula\Syntax;

/**
 * Cuts a formula into tokens (see Token), the way PHP 8 would read the same
 * text, so that a formula means what it meant under eval():
 *
 *   - numbers as PHP writes them: 12, 1.5, .5, 1., 1e3, 1_000, 0x1F, 0b11,
 *     010 (octal, 8), 0o17; "08" is invalid, as in PHP; a whole number too
 *     big for an int is a float;
 *   - 'text' (only \\ and \' are escapes) and "text" (\n, \t, \x41, \u{e9},
 *     \$, ... and $NAME inside it is the variable's value);
 *   - $NAME: letters, digits, "_" and any byte from 0x80 (so $Segurança is
 *     a name), not starting with a digit ($25PC is not a variable);
 *   - names (ROUND, null, STR_PAD_LEFT), with an optional leading "\";
 *   - comments: /* ... * /, and // or # up to the end of the line.
 *
 * What it refuses, with a FormulaSyntaxError: backticks (they run a shell
 * command), $$NAME and ${...} (variable variables), "=" and the other
 * assignments, ++ and --, "::", "?>", the bitwise operators, @, and any
 * character PHP itself would not accept here. "{$...}" and "$X[...]",
 * "$X->..." inside "..." are refused too: only plain "$NAME" is read
 * there.
 */
final class Lexer
{
    /** Longest first, so that "===" is not read as "==" and "=". */
    private const OPERATORS = [
        '===', '!==', '**', '==', '!=', '<>', '<=', '>=', '&&', '||', '??', '->', '=>',
        '<', '>', '!', '+', '-', '*', '/', '%', '.', '?', ':', '(', ')', '[', ']', ',', ';',
    ];

    /** Read by PHP as something that changes or runs things: never in a formula. */
    private const REFUSED = [
        '`' => 'backticks (`...`) run a shell command and are not allowed in a formula',
        '$$' => 'variable variables ($$NAME) are not allowed in a formula',
        '${' => 'variable variables (${...}) are not allowed in a formula',
        '::' => 'static access (::) is not allowed in a formula',
        '?>' => '"?>" is not allowed in a formula',
        '++' => '"++" changes a variable and is not allowed in a formula',
        '--' => '"--" changes a variable and is not allowed in a formula',
        '**=' => 'assignments are not allowed in a formula', '??=' => 'assignments are not allowed in a formula',
        '<<=' => 'assignments are not allowed in a formula', '>>=' => 'assignments are not allowed in a formula',
        '+=' => 'assignments are not allowed in a formula', '-=' => 'assignments are not allowed in a formula',
        '*=' => 'assignments are not allowed in a formula', '/=' => 'assignments are not allowed in a formula',
        '.=' => 'assignments are not allowed in a formula', '%=' => 'assignments are not allowed in a formula',
        '&=' => 'assignments are not allowed in a formula', '|=' => 'assignments are not allowed in a formula',
        '^=' => 'assignments are not allowed in a formula',
    ];

    /** Digits, with PHP's optional "_" between them. */
    private const LNUM = '[0-9]+(?:_[0-9]+)*';

    private int $at = 0;

    /** @var list<Token> */
    private array $tokens = [];

    private function __construct(private readonly string $source)
    {
    }

    /**
     * @return list<Token> the tokens, the last one always END
     * @throws FormulaSyntaxError
     */
    public static function tokens(string $source): array
    {
        $lexer = new self($source);
        $lexer->run();
        return $lexer->tokens;
    }

    /** Where a byte offset is, for a message: the character count from 1 (UTF-8 aware). */
    public static function position(string $source, int $offset): int
    {
        return mb_strlen(substr($source, 0, $offset), 'UTF-8') + 1;
    }

    private function run(): void
    {
        $length = strlen($this->source);
        while (true) {
            $this->skipSpaceAndComments();
            if ($this->at >= $length) {
                $this->tokens[] = new Token(Token::END, '', null, $length);
                return;
            }
            $char = $this->source[$this->at];
            $this->tokens[] = match (true) {
                $char === '$' => $this->variable(),
                $char === "'" => $this->singleQuoted(),
                $char === '"' => $this->doubleQuoted(),
                ctype_digit($char) || ($char === '.' && ctype_digit($this->source[$this->at + 1] ?? '')) => $this->number(),
                $char === '\\' || self::startsName($char) => $this->name(),
                default => $this->operator(),
            };
        }
    }

    private function skipSpaceAndComments(): void
    {
        $length = strlen($this->source);
        while ($this->at < $length) {
            $rest = substr($this->source, $this->at, 2);
            if (strspn($this->source, " \t\n\r", $this->at, 1) === 1) {
                $this->at++;
            } elseif ($rest === '/*') {
                $end = strpos($this->source, '*/', $this->at + 2);
                if ($end === false) {
                    $this->fail('a comment "/*" is never closed', $this->at);
                }
                $this->at = $end + 2;
            } elseif ($rest === '#[') {
                $this->fail('"#[" is not allowed in a formula', $this->at);
            } elseif ($rest === '//' || $rest[0] === '#') {
                // eval() adds ";" after the formula. A comment that runs to
                // the end swallows it, and PHP then refuses the formula.
                $end = strcspn($this->source, "\n\r", $this->at);
                if ($this->at + $end >= $length) {
                    $this->fail('a comment runs to the end of the formula', $this->at);
                }
                if (str_contains(substr($this->source, $this->at, $end), '?>')) {
                    $this->fail(self::REFUSED['?>'], $this->at);
                }
                $this->at += $end;
            } else {
                return;
            }
        }
    }

    private function variable(): Token
    {
        $start = $this->at;
        $next = $this->source[$start + 1] ?? '';
        if ($next === '$' || $next === '{') {
            $this->fail(self::REFUSED['$' . $next], $start);
        }
        if (preg_match('/\G[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', $this->source, $match, 0, $start + 1) !== 1) {
            $this->fail('"$" must be followed by a variable name (a letter or "_" first, not a digit)', $start);
        }
        $this->at = $start + 1 + strlen($match[0]);
        return new Token(Token::VARIABLE, '$' . $match[0], $match[0], $start);
    }

    private function name(): Token
    {
        $start = $this->at;
        if (preg_match('/\G\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*/', $this->source, $match, 0, $start) !== 1) {
            $this->fail('unexpected "\\"', $start);
        }
        $this->at = $start + strlen($match[0]);
        $name = ltrim($match[0], '\\');
        if (str_contains($name, '\\')) {
            $this->fail('namespaced names (' . $match[0] . ') are not allowed in a formula', $start);
        }
        return new Token(Token::NAME, $match[0], $name, $start);
    }

    private static function startsName(string $char): bool
    {
        return ctype_alpha($char) || $char === '_' || ord($char) >= 0x80;
    }

    /**
     * A number, by PHP's own rules (zend_language_scanner.l): the longest of
     * a float with "." and/or an exponent, a hexadecimal, binary or octal
     * whole number, or a decimal one.
     */
    private function number(): Token
    {
        $start = $this->at;
        $lnum = self::LNUM;
        $dnum = "(?:(?:$lnum)?\\.$lnum|$lnum\\.(?:$lnum)?)";
        $patterns = [
            'float' => "/\\G(?:(?:$dnum|$lnum)[eE][+-]?$lnum|$dnum)/",
            'hex' => '/\G0[xX][0-9a-fA-F]+(?:_[0-9a-fA-F]+)*/',
            'binary' => '/\G0[bB][01]+(?:_[01]+)*/',
            'octal' => '/\G0[oO][0-7]+(?:_[0-7]+)*/',
            'decimal' => "/\\G$lnum/",
        ];
        $best = null;
        foreach ($patterns as $kind => $pattern) {
            if (preg_match($pattern, $this->source, $match, 0, $start) === 1 && ($best === null || strlen($match[0]) > strlen($best[1]))) {
                $best = [$kind, $match[0]];
            }
        }
        [$kind, $text] = $best;
        $this->at = $start + strlen($text);
        $digits = str_replace('_', '', $text);
        $value = match ($kind) {
            'float' => (float)$digits,
            // These give a float when the number is too big for an int, as PHP's reader does.
            'hex' => hexdec(substr($digits, 2)),
            'binary' => bindec(substr($digits, 2)),
            'octal' => octdec(substr($digits, 2)),
            'decimal' => $this->decimal($digits, $start),
        };
        return new Token(Token::NUMBER, $text, $value, $start);
    }

    /** 123, or 0123 (octal: a leading 0 means base 8, and then 8 and 9 are invalid). */
    private function decimal(string $digits, int $start): int|float
    {
        if (strlen($digits) > 1 && $digits[0] === '0') {
            if (strspn($digits, '01234567') !== strlen($digits)) {
                $this->fail('invalid number ' . $digits . ' (a leading 0 means octal, which has no 8 or 9)', $start);
            }
            return octdec($digits);
        }
        // Too big for an int: PHP reads it as a float.
        $int = filter_var($digits, FILTER_VALIDATE_INT);
        return $int !== false ? $int : (float)$digits;
    }

    private function singleQuoted(): Token
    {
        $start = $this->at;
        $value = '';
        $i = $start + 1;
        $length = strlen($this->source);
        while ($i < $length) {
            $char = $this->source[$i];
            if ($char === "'") {
                $this->at = $i + 1;
                return new Token(Token::STRING, substr($this->source, $start, $this->at - $start), $value, $start);
            }
            if ($char === '\\' && in_array($this->source[$i + 1] ?? '', ['\\', "'"], true)) {
                $value .= $this->source[$i + 1];
                $i += 2;
                continue;
            }
            $value .= $char;
            $i++;
        }
        $this->fail("a text started with ' is never closed", $start);
    }

    /**
     * "..." with PHP's escapes, and plain $NAME read as the variable. A
     * TEMPLATE token when it has a variable, else a STRING.
     */
    private function doubleQuoted(): Token
    {
        $start = $this->at;
        $parts = [];
        $text = '';
        $i = $start + 1;
        $length = strlen($this->source);
        while ($i < $length) {
            $char = $this->source[$i];
            if ($char === '"') {
                $this->at = $i + 1;
                $written = substr($this->source, $start, $this->at - $start);
                if ($parts === []) {
                    return new Token(Token::STRING, $written, $text, $start);
                }
                if ($text !== '') {
                    $parts[] = $text;
                }
                return new Token(Token::TEMPLATE, $written, $parts, $start);
            }
            if ($char === '\\') {
                [$add, $used] = $this->escape($i);
                $text .= $add;
                $i += $used;
                continue;
            }
            if ($char === '{' && ($this->source[$i + 1] ?? '') === '$') {
                $this->fail('"{$" inside "..." is not allowed in a formula: write the variable outside the quotes', $i);
            }
            if ($char === '$') {
                $next = $this->source[$i + 1] ?? '';
                if ($next === '{') {
                    $this->fail(self::REFUSED['${'], $i);
                }
                if (preg_match('/\G[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', $this->source, $match, 0, $i + 1) === 1) {
                    $after = $i + 1 + strlen($match[0]);
                    if (($this->source[$after] ?? '') === '[' || substr($this->source, $after, 2) === '->') {
                        $this->fail('only a plain $NAME can be inside "...": write $' . $match[0] . '[...] or ->... outside the quotes', $i);
                    }
                    if ($text !== '') {
                        $parts[] = $text;
                        $text = '';
                    }
                    $parts[] = ['variable' => $match[0]];
                    $i = $after;
                    continue;
                }
            }
            $text .= $char;
            $i++;
        }
        $this->fail('a text started with " is never closed', $start);
    }

    /**
     * One backslash escape inside "...", at $i.
     *
     * @return array{0: string, 1: int} the text it stands for, and how many bytes it takes
     */
    private function escape(int $i): array
    {
        $next = $this->source[$i + 1] ?? '';
        $simple = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
        if (isset($simple[$next])) {
            return [$simple[$next], 2];
        }
        if (preg_match('/\G[0-7]{1,3}/', $this->source, $match, 0, $i + 1) === 1) {
            // "\400" and above wrap around, as in PHP.
            return [chr(octdec($match[0]) & 0xFF), 1 + strlen($match[0])];
        }
        if (preg_match('/\Gx([0-9A-Fa-f]{1,2})/', $this->source, $match, 0, $i + 1) === 1) {
            return [chr(hexdec($match[1])), 1 + strlen($match[0])];
        }
        if ($next === 'u' && ($this->source[$i + 2] ?? '') === '{') {
            if (preg_match('/\Gu\{([0-9A-Fa-f]+)\}/', $this->source, $match, 0, $i + 1) !== 1) {
                $this->fail('invalid "\u{...}" in a text', $i);
            }
            $codepoint = hexdec($match[1]);
            if ($codepoint > 0x10FFFF) {
                $this->fail('invalid "\u{...}" in a text: there is no character ' . $match[1], $i);
            }
            return [self::utf8((int)$codepoint), 1 + strlen($match[0])];
        }
        // Anything else is kept as written, backslash included ("\d" stays "\d").
        return ['\\', 1];
    }

    /** A code point as UTF-8, as PHP writes it (surrogates included). */
    private static function utf8(int $codepoint): string
    {
        return match (true) {
            $codepoint < 0x80 => chr($codepoint),
            $codepoint < 0x800 => chr(0xC0 | $codepoint >> 6) . chr(0x80 | $codepoint & 0x3F),
            $codepoint < 0x10000 => chr(0xE0 | $codepoint >> 12) . chr(0x80 | $codepoint >> 6 & 0x3F) . chr(0x80 | $codepoint & 0x3F),
            default => chr(0xF0 | $codepoint >> 18) . chr(0x80 | $codepoint >> 12 & 0x3F) . chr(0x80 | $codepoint >> 6 & 0x3F) . chr(0x80 | $codepoint & 0x3F),
        };
    }

    private function operator(): Token
    {
        $start = $this->at;
        foreach ([3, 2] as $size) {
            $text = substr($this->source, $start, $size);
            if (isset(self::REFUSED[$text])) {
                $this->fail(self::REFUSED[$text], $start);
            }
        }
        foreach (self::OPERATORS as $operator) {
            if (substr_compare($this->source, $operator, $start, strlen($operator)) === 0) {
                $this->at = $start + strlen($operator);
                return new Token($operator, $operator, null, $start);
            }
        }
        $char = $this->source[$start];
        $this->fail(match ($char) {
            '`' => self::REFUSED['`'],
            '=' => 'assignments ("=") are not allowed in a formula: to compare, write "=="',
            '&', '|', '^', '~' => 'the bitwise operator "' . $char . '" is not allowed in a formula: did you mean "' . $char . $char . '"?',
            '@' => '"@" is not allowed in a formula',
            default => 'unexpected "' . $char . '"',
        }, $start);
    }

    private function fail(string $problem, int $offset): never
    {
        throw new FormulaSyntaxError($problem, self::position($this->source, $offset));
    }
}
