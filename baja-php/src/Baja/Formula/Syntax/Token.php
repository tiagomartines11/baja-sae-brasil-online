<?php
declare(strict_types=1);

namespace Baja\Formula\Syntax;

/**
 * One piece of a formula, as the Lexer cuts it: "ROUND($X, 2)" is the
 * tokens NAME "ROUND", "(", VARIABLE "X", ",", NUMBER 2, ")" and END.
 *
 *   type    one of the constants below; for an operator or a bracket, the
 *           operator itself ("+", "===", "(")
 *   text    as written in the formula
 *   value   NUMBER: the int or float; STRING: the text, escapes worked
 *           out; TEMPLATE: the parts of a "..." with variables in it
 *           (strings, and ['variable' => NAME]); VARIABLE: the name,
 *           without "$"; NAME: as written, without a leading "\"
 *   offset  where it starts, in bytes from 0
 */
final class Token
{
    public const NUMBER = 'number';
    public const STRING = 'string';
    public const TEMPLATE = 'template';
    public const VARIABLE = 'variable';
    public const NAME = 'name';
    public const END = 'end of the formula';

    public function __construct(
        public readonly string $type,
        public readonly string $text,
        public readonly mixed $value,
        public readonly int $offset,
    ) {
    }

    public function is(string ...$types): bool
    {
        return in_array($this->type, $types, true);
    }

    /** A NAME that is this word, whatever the case ("NULL", "Null"). */
    public function isWord(string $word): bool
    {
        return $this->type === self::NAME && strcasecmp($this->value, $word) === 0;
    }

    /** How an error message names it: '")"', 'variable $X', 'end of the formula'. */
    public function describe(): string
    {
        return match ($this->type) {
            self::END => 'end of the formula',
            self::VARIABLE => 'variable ' . $this->text,
            self::NUMBER => 'number ' . $this->text,
            self::STRING, self::TEMPLATE => 'text ' . $this->text,
            self::NAME => 'name "' . $this->text . '"',
            default => '"' . $this->text . '"',
        };
    }
}
