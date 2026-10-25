<?php
declare(strict_types=1);

namespace Baja\Formula\Syntax;

/**
 * One piece of a read formula. "ROUND($A + 1, 2)" is
 *
 *     CALL round
 *       BINARY +
 *         VARIABLE A
 *         LITERAL 1
 *       LITERAL 2
 *
 * The kinds, with their value and children:
 *
 *   LITERAL   a number, a text, null, true or false   value: it    children: -
 *   TEMPLATE  "x $A y"                     value: -                children: the parts, texts (string) and VARIABLE nodes
 *   VARIABLE  $A                           value: the name (A)     children: -
 *   CONSTANT  STR_PAD_LEFT, SPOILERS       value: the name         children: -
 *   ARRAY     [1, 'a' => 2]                value: -                children: one [key or null, value] pair per item
 *   INDEX     $A[0]                        value: -                children: [what, which]
 *   CALL      round(...)                   value: the name, lower case  children: the arguments
 *   ISSET     isset($A, $B[1])             value: -                children: the VARIABLE and INDEX nodes
 *   UNARY     !$A, -$A, +$A                value: the operator     children: [operand]
 *   BINARY    $A + $B, $A && $B, $A ?? $B  value: the operator     children: [left, right]
 *   TERNARY   $A ? $B : $C, $A ?: $C       value: -                children: [if, then (null for ?:), else]
 *   DATETIME  (new \DateTime(a, new \DateTimeZone(z)))->setTimestamp(t)->format(f)
 *                                          value: -                children: [a's (list), z's (list) or null,
 *                                                                             t's (list) or null, f's (list)]
 *
 * offset: where the piece starts in the formula, in bytes from 0.
 * parenthesized: it was written in brackets, "($A ? 1 : 2)"; PHP 8 needs
 * that to tell nested "? :" apart (Parser::ternary()).
 */
final class Node
{
    public const LITERAL = 'literal';
    public const TEMPLATE = 'template';
    public const VARIABLE = 'variable';
    public const CONSTANT = 'constant';
    public const ARRAY = 'array';
    public const INDEX = 'index';
    public const CALL = 'call';
    public const ISSET = 'isset';
    public const UNARY = 'unary';
    public const BINARY = 'binary';
    public const TERNARY = 'ternary';
    public const DATETIME = 'datetime';

    public bool $parenthesized = false;

    /** @param list<mixed> $children */
    public function __construct(
        public readonly string $kind,
        public readonly mixed $value,
        public readonly array $children,
        public readonly int $offset,
    ) {
    }
}
