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
 *
 * HOW
 *
 * One regular expression (PIECES) knows every kind of piece, each marked
 * with its kind by (*MARK:...). preg_match_all() cuts the whole formula
 * with it in one go, in C, which is several times faster than reading it
 * character by character in PHP. Where two kinds could start at the same
 * place, the one listed first wins, and they are listed so that this is
 * PHP's choice too ("1.5e3" is one number, "**=" is not "**" then "=").
 * The last kind, BAD, takes any single character, so every byte of the
 * formula belongs to some piece and nothing is skipped silently.
 */
final class Lexer
{
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

    /** A name's first character, then the others (PHP's rule: any byte from 0x80 is a letter). */
    private const NAME = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*+';

    /**
     * The longest formula read, in bytes. The longest in the dev seed is
     * 2.1 KB; reading one costs about 2.5 KB of memory per byte, so without
     * a limit a huge formula could use up a page's memory.
     */
    public const MOST_BYTES = 10_000;

    private static ?string $pieces = null;

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

    /** The regular expression of every piece, in the order they are tried. */
    private static function pieces(): string
    {
        $lnum = self::LNUM;
        $dnum = "(?:(?:$lnum)?\\.$lnum|$lnum\\.(?:$lnum)?)";
        $name = self::NAME;
        $refused = implode('|', array_map(fn($text) => preg_quote($text, '/'), array_keys(self::REFUSED)));
        $kinds = [
            'SPACE' => '[ \t\n\r]++',
            'COMMENT' => '\/\*.*?\*\/',
            'OPEN_COMMENT' => '\/\*',
            'ATTRIBUTE' => '\#\[',
            'LINE_COMMENT' => '(?:\/\/|\#)[^\r\n]*+',
            'SINGLE' => "'(?:[^'\\\\]++|\\\\.)*+'",
            'OPEN_SINGLE' => "'",
            'DOUBLE' => '"(?:[^"\\\\]++|\\\\.)*+"',
            'OPEN_DOUBLE' => '"',
            'REFUSED' => $refused,
            'VARIABLE' => '\$' . $name,
            'DOLLAR' => '\$',
            'FLOAT' => "(?:$dnum|$lnum)[eE][+-]?$lnum|$dnum",
            'HEX' => '0[xX][0-9a-fA-F]+(?:_[0-9a-fA-F]+)*',
            'BINARY' => '0[bB][01]+(?:_[01]+)*',
            'OCTAL' => '0[oO][0-7]+(?:_[0-7]+)*',
            'DECIMAL' => $lnum,
            'NAME' => '\\\\?' . $name . '(?:\\\\' . $name . ')*+',
            'OPERATOR' => '===|!==|\*\*|==|!=|<>|<=|>=|&&|\|\||\?\?|->|=>|[<>!+\-*\/%.?:()\[\],;]',
            'BAD' => '.',
        ];
        $alternatives = [];
        foreach ($kinds as $kind => $pattern) {
            $alternatives[] = '(?:' . $pattern . ')(*MARK:' . $kind . ')';
        }
        return '/' . implode('|', $alternatives) . '/s';
    }

    private function run(): void
    {
        $length = strlen($this->source);
        if ($length > self::MOST_BYTES) {
            $this->fail('the formula is too long (' . $length . ' bytes; at most ' . self::MOST_BYTES . ')', 0);
        }
        self::$pieces ??= self::pieces();
        // A regular expression can give up (PCRE's backtrack limit); then
        // the pieces found so far are not the whole formula, and reading
        // only those would silently work out a shorter formula.
        if (preg_match_all(self::$pieces, $this->source, $pieces, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            $this->fail('the formula could not be read (' . preg_last_error_msg() . ')', 0);
        }
        foreach ($pieces as $piece) {
            [$text, $offset] = $piece[0];
            $token = match ($piece['MARK']) {
                'SPACE', 'COMMENT' => null,
                'LINE_COMMENT' => $this->lineComment($text, $offset, $length),
                'SINGLE' => new Token(Token::STRING, $text, strtr(substr($text, 1, -1), ['\\\\' => '\\', "\\'" => "'"]), $offset),
                'DOUBLE' => strpbrk($text, '\\$') === false
                    ? new Token(Token::STRING, $text, substr($text, 1, -1), $offset)
                    : $this->doubleQuoted($offset),
                'VARIABLE' => new Token(Token::VARIABLE, $text, substr($text, 1), $offset),
                'FLOAT' => new Token(Token::NUMBER, $text, (float)str_replace('_', '', $text), $offset),
                // A float when too big for an int, rounded as PHP's reader rounds it (based()).
                'HEX' => new Token(Token::NUMBER, $text, self::based(substr(str_replace('_', '', $text), 2), 16), $offset),
                'BINARY' => new Token(Token::NUMBER, $text, self::based(substr(str_replace('_', '', $text), 2), 2), $offset),
                'OCTAL' => new Token(Token::NUMBER, $text, self::based(substr(str_replace('_', '', $text), 2), 8), $offset),
                'DECIMAL' => new Token(Token::NUMBER, $text, $this->decimal(str_replace('_', '', $text), $offset), $offset),
                'NAME' => $this->name($text, $offset),
                'OPERATOR' => new Token($text, $text, null, $offset),
                default => $this->refuse($piece['MARK'], $text, $offset),
            };
            if ($token !== null) {
                $this->tokens[] = $token;
            }
        }
        $this->tokens[] = new Token(Token::END, '', null, $length);
    }

    /** What is wrong with a piece that is not allowed. */
    private function refuse(string $kind, string $text, int $offset): never
    {
        $this->fail(match ($kind) {
            'OPEN_COMMENT' => 'a comment "/*" is never closed',
            'ATTRIBUTE' => '"#[" is not allowed in a formula',
            'OPEN_SINGLE' => "a text started with ' is never closed",
            'OPEN_DOUBLE' => 'a text started with " is never closed',
            'REFUSED' => self::REFUSED[$text],
            'DOLLAR' => '"$" must be followed by a variable name (a letter or "_" first, not a digit)',
            default => match ($text) {
                '=' => 'assignments ("=") are not allowed in a formula: to compare, write "=="',
                '&', '|', '^', '~' => 'the bitwise operator "' . $text . '" is not allowed in a formula: did you mean "' . $text . $text . '"?',
                '@' => '"@" is not allowed in a formula',
                '\\' => 'unexpected "\\"',
                default => 'unexpected "' . $text . '"',
            },
        }, $offset);
    }

    /**
     * eval() adds ";" after the formula. A // or # comment that runs to the
     * end swallows it, and PHP then refuses the formula. A "?>" in it would
     * leave PHP.
     */
    private function lineComment(string $text, int $offset, int $length): ?Token
    {
        if ($offset + strlen($text) >= $length) {
            $this->fail('a comment runs to the end of the formula', $offset);
        }
        if (str_contains($text, '?>')) {
            $this->fail(self::REFUSED['?>'], $offset);
        }
        return null;
    }

    private function name(string $text, int $offset): Token
    {
        $name = ltrim($text, '\\');
        if (str_contains($name, '\\')) {
            $this->fail('namespaced names (' . $text . ') are not allowed in a formula', $offset);
        }
        return new Token(Token::NAME, $text, $name, $offset);
    }

    /**
     * A hexadecimal, binary or octal number; a float when too big for an
     * int. hexdec() and octdec() round such a float as PHP's own reader
     * does. For binary, PHP's reader (zend_bin_strtod, in C) builds the
     * number as "value * 2 + c - '0'", c being the digit's character code:
     * so (value * 2 + c) - '0', rounded twice at each step once past 2^53.
     * bindec() rounds otherwise, so that is done here as PHP does it.
     * (Measured against eval() in GrammarTest::testNumbers.)
     */
    private static function based(string $digits, int $base): int|float
    {
        if ($base === 16) {
            return hexdec($digits);
        }
        if ($base === 8) {
            return octdec($digits);
        }
        $exact = bindec($digits);
        if (is_int($exact)) {
            return $exact;
        }
        $value = 0.0;
        foreach (str_split($digits) as $digit) {
            $value = ($value * 2 + ord($digit)) - ord('0');
        }
        return $value;
    }

    /** 123, or 0123 (octal: a leading 0 means base 8, and then 8 and 9 are invalid). */
    private function decimal(string $digits, int $start): int|float
    {
        if (strlen($digits) > 1 && $digits[0] === '0') {
            if (strspn($digits, '01234567') !== strlen($digits)) {
                $this->fail('invalid number ' . $digits . ' (a leading 0 means octal, which has no 8 or 9)', $start);
            }
            return self::based($digits, 8);
        }
        // Too big for an int: PHP reads it as a float.
        $int = filter_var($digits, FILTER_VALIDATE_INT);
        return $int !== false ? $int : (float)$digits;
    }

    /**
     * "..." with PHP's escapes, and plain $NAME read as the variable. A
     * TEMPLATE token when it has a variable, else a STRING.
     */
    private function doubleQuoted(int $start): Token
    {
        $parts = [];
        $text = '';
        $i = $start + 1;
        $length = strlen($this->source);
        while ($i < $length) {
            $char = $this->source[$i];
            if ($char === '"') {
                $written = substr($this->source, $start, $i + 1 - $start);
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
                if (preg_match('/\G' . self::NAME . '/', $this->source, $match, 0, $i + 1) === 1) {
                    $after = $i + 1 + strlen($match[0]);
                    if (($this->source[$after] ?? '') === '[' || substr($this->source, $after, 2) === '->' || substr($this->source, $after, 3) === '?->') {
                        $this->fail('only a plain $NAME can be inside "...": write $' . $match[0] . '[...] or ->... outside the quotes', $i);
                    }
                    if (in_array($match[0], Parser::SUPERGLOBALS, true)) {
                        $this->fail('$' . $match[0] . ' is not allowed in a formula', $i);
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

    private function fail(string $problem, int $offset): never
    {
        throw new FormulaSyntaxError($problem, self::position($this->source, $offset));
    }
}
