<?php
declare(strict_types=1);

namespace Baja\Formula\Syntax;

/**
 * A formula the Parser has read: its tree, and what it names.
 *
 *   root       the tree (Node)
 *   variables  the variables it reads, without "$", in the order they
 *              first appear: "ROUND($B, 2) + $A + $B" gives ['B', 'A']
 *   functions  the functions it calls, in lower case, in the same order
 *
 * Whether a variable is read depends on the data ("$A ? $B : $C" reads $B
 * or $C), so these are the variables the formula CAN read.
 */
final class ParsedFormula
{
    /**
     * @param list<string> $variables
     * @param list<string> $functions
     */
    public function __construct(
        public readonly Node $root,
        public readonly array $variables,
        public readonly array $functions,
    ) {
    }
}
