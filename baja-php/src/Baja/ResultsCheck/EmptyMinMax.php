<?php
declare(strict_types=1);

namespace Baja\ResultsCheck;

use Baja\Formula\SafeEngine;
use Baja\Formula\Syntax\Node;
use Throwable;

/**
 * max() and min() of a list that can be empty for some team (BAJAAPP-56).
 *
 * Given one list, max() and min() give its highest or lowest value. Given an
 * EMPTY list, PHP 7 gave false (with a warning; false counts as 0 in a sum),
 * and PHP 8 throws a ValueError, which Input::solveFormula() turns into null:
 * an empty cell, or a point that was 0 and is now nothing. The new formula
 * engine does the same as PHP 8. The configs build these lists from a
 * team's runs, leaving out the ones that did not count:
 *
 *     max(array_diff([$CORR1_1, $CORR1_2], [null]))
 *
 * A team with no run that counts (absent, DNF, every run penalised to null)
 * gets an empty list. So the formula works for the teams that ran, and
 * fails for the others; the stored points, saved under PHP 7, say 0.
 *
 * Each formula is read by SafeEngine's parser, and a max() or min() with ONE
 * argument is reported when that argument can be an empty list:
 *
 *   []                          always empty
 *   array_diff([a, b...], x...) empty when every item is one of the values
 *                               taken out. A value written in the formula
 *                               (0, 'x') that no list takes out always
 *                               stays; anything else (a variable, a sum)
 *                               can be taken out. A first argument that
 *                               is not written as [...] can be empty too.
 *   array_filter(x)             empty when no item is truthy; [..] with a
 *                               truthy value written in it never is
 *   a ? b : c, a ?: c, a ?? c   when either answer can be empty
 *
 * With two or more arguments (max($A, $B), max(0, ...)) there is always
 * something to compare, so nothing is reported. A list written out without
 * array_diff ([$A, $B]) is never empty either: max([null, null]) is null,
 * not an error. Report only: what the value should be for a team with
 * nothing to count (0, null, a penalty) is a rule of the prova, so there is
 * no "fix:". A formula the parser cannot read is left out; its error is
 * reported already.
 */
final class EmptyMinMax
{
    /**
     * @param list<FormulaSlot> $slots
     * @return list<Finding>
     */
    public static function of(array $slots): array
    {
        $findings = [];
        foreach ($slots as $slot) {
            if (!is_string($slot->formula)) {
                continue;
            }
            try {
                $root = SafeEngine::read($slot->formula)->root;
            } catch (Throwable) {
                continue;
            }
            $details = [];
            foreach (self::calls($root, []) as $call) {
                $when = self::emptyWhen($call->children[0]);
                if ($when !== null) {
                    $details[$call->value . '() of a list that is ' . ($when === 'always' ? 'always empty' : 'empty ' . $when)
                        . '; then PHP 8 throws a ValueError and the value is empty (PHP 7 gave false, which counts as 0)'] = true;
                }
            }
            // The same call twice in one formula is said once.
            foreach (array_keys($details) as $detail) {
                $findings[] = new Finding(Finding::EMPTY_MIN_MAX, $slot->where, $detail, $slot->formula, slot: $slot);
            }
        }
        return $findings;
    }

    /**
     * Every max() and min() with one argument, in the order they are written,
     * but those a "? :" only reaches when its list has something in it:
     * count(L) > 0 ? max(L) : null.
     *
     * @param list<Node> $nonEmpty lists known to have something in them here
     * @return list<Node>
     */
    private static function calls(Node $node, array $nonEmpty): array
    {
        $found = [];
        if ($node->kind === Node::CALL && ($node->value === 'max' || $node->value === 'min') && count($node->children) === 1
            && !self::among($node->children[0], $nonEmpty)) {
            $found[] = $node;
        }
        if ($node->kind === Node::TERNARY) {
            [$if, $then, $else] = $node->children;
            array_push($found, ...self::calls($if, $nonEmpty));
            if ($then !== null) {
                array_push($found, ...self::calls($then, array_merge($nonEmpty, self::nonEmptyIf($if, true))));
            }
            array_push($found, ...self::calls($else, array_merge($nonEmpty, self::nonEmptyIf($if, false))));
            return $found;
        }
        foreach (self::childNodes($node) as $child) {
            array_push($found, ...self::calls($child, $nonEmpty));
        }
        return $found;
    }

    /**
     * The lists that have something in them when $condition is $true:
     * count(L) > 0, count(L) >= 1, count(L) != 0, 0 < count(L), count(L)
     * alone, and those joined with && (when true); count(L) == 0 and
     * !count(L) (when false).
     *
     * @return list<Node>
     */
    private static function nonEmptyIf(Node $condition, bool $true): array
    {
        if ($condition->kind === Node::UNARY && $condition->value === '!') {
            return self::nonEmptyIf($condition->children[0], !$true);
        }
        if ($condition->kind === Node::BINARY && $condition->value === '&&') {
            return $true ? array_merge(self::nonEmptyIf($condition->children[0], true), self::nonEmptyIf($condition->children[1], true)) : [];
        }
        if ($condition->kind === Node::BINARY && $condition->value === '||') {
            return $true ? [] : array_merge(self::nonEmptyIf($condition->children[0], false), self::nonEmptyIf($condition->children[1], false));
        }
        $list = self::counted($condition);
        if ($list !== null) {
            return $true ? [$list] : [];
        }
        if ($condition->kind !== Node::BINARY) {
            return [];
        }
        [$left, $right] = $condition->children;
        $operator = $condition->value;
        if (self::counted($left) === null) {
            // 0 < count(L) is count(L) > 0.
            [$left, $right] = [$right, $left];
            $operator = ['<' => '>', '<=' => '>=', '>' => '<', '>=' => '<='][$operator] ?? $operator;
        }
        $list = self::counted($left);
        if ($list === null || $right->kind !== Node::LITERAL || !is_int($right->value) && !is_float($right->value)) {
            return [];
        }
        $n = $right->value;
        if (($operator === '===' || $operator === '!==') && !is_int($n)) {
            return [];  // count() is an int: count(L) !== 0.0 is always true
        }
        // A count is 0, 1, 2...: "count(L) > n" with n >= 0 means at least one.
        $whenTrue = match ($operator) {
            '>' => $n >= 0,
            '>=' => $n > 0,
            '!=', '!==', '<>' => $n == 0,
            default => false,
        };
        $whenFalse = match ($operator) {
            '==', '===' => $n == 0,
            '<=' => $n >= 0,
            '<' => $n > 0,
            default => false,
        };
        return ($true ? $whenTrue : $whenFalse) ? [$list] : [];
    }

    /** L, when $node is count(L). */
    private static function counted(Node $node): ?Node
    {
        return $node->kind === Node::CALL && $node->value === 'count' && count($node->children) === 1 ? $node->children[0] : null;
    }

    /** @param list<Node> $lists */
    private static function among(Node $list, array $lists): bool
    {
        foreach ($lists as $other) {
            if (self::sameTree($list, $other)) {
                return true;
            }
        }
        return false;
    }

    /** Whether two pieces of formula are written the same (where they are, and brackets, aside). */
    private static function sameTree(mixed $a, mixed $b): bool
    {
        if ($a instanceof Node && $b instanceof Node) {
            return $a->kind === $b->kind && $a->value === $b->value && self::sameTree($a->children, $b->children);
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::sameTree($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    /**
     * When the list $node gives can be empty, said in words ("when ... "),
     * or null when it never is.
     */
    private static function emptyWhen(Node $node): ?string
    {
        switch ($node->kind) {
            case Node::ARRAY:
                return $node->children === [] ? 'always' : null;
            case Node::TERNARY:
                [, $then, $else] = $node->children;
                return self::emptyWhen($then ?? $node->children[0]) ?? self::emptyWhen($else);
            case Node::BINARY:
                return $node->value === '??' ? self::emptyWhen($node->children[0]) ?? self::emptyWhen($node->children[1]) : null;
            case Node::CALL:
                if ($node->value === 'array_diff' && $node->children !== []) {
                    return self::arrayDiff($node->children[0], array_slice($node->children, 1));
                }
                if ($node->value === 'array_filter' && count($node->children) === 1) {
                    return self::arrayFilter($node->children[0]);
                }
                return null;
            default:
                return null;
        }
    }

    /** @param list<Node> $removed the lists array_diff() takes out */
    private static function arrayDiff(Node $from, array $removed): ?string
    {
        if ($from->kind !== Node::ARRAY) {
            return 'when everything in it is taken out';
        }
        // array_diff() compares the values as text: (string)null is "", so
        // [null] takes out null, "" and false.
        $out = [];
        $known = true;
        foreach ($removed as $list) {
            if ($list->kind !== Node::ARRAY) {
                $known = false;
                continue;
            }
            foreach ($list->children as [, $value]) {
                if ($value->kind === Node::LITERAL && (is_scalar($value->value) || $value->value === null)) {
                    $out[(string)$value->value] = $value->value;
                } else {
                    $known = false;
                }
            }
        }
        if ($from->children === []) {
            return 'always';
        }
        $variables = [];
        foreach ($from->children as [, $value]) {
            if ($value->kind === Node::LITERAL && (is_scalar($value->value) || $value->value === null)) {
                if ($known && !array_key_exists((string)$value->value, $out)) {
                    return null;  // a written value nothing takes out: never empty
                }
                continue;
            }
            if ($known && $out === []) {
                return null;  // nothing is taken out
            }
            $variables[] = $value->kind === Node::VARIABLE ? '$' . $value->value : 'each item';
        }
        $items = $variables === [] ? 'every item' : self::andList(array_values(array_unique($variables)));
        $values = $known ? self::orList(array_map([self::class, 'literal'], array_values($out))) : 'one of the values taken out';
        return 'for a team whose ' . $items . (count($variables) === 1 ? ' is ' : ' are all ') . $values;
    }

    private static function arrayFilter(Node $from): ?string
    {
        if ($from->kind === Node::ARRAY) {
            foreach ($from->children as [, $value]) {
                if ($value->kind === Node::LITERAL && $value->value) {
                    return null;  // a truthy value written in it always stays
                }
            }
        }
        return 'when no item in it is truthy (all null, 0, "" or false)';
    }

    /** @return list<Node> */
    private static function childNodes(Node $node): array
    {
        $children = [];
        foreach ($node->children as $child) {
            if ($child instanceof Node) {
                $children[] = $child;
            } elseif (is_array($child)) {
                // ARRAY items are [key or null, value]; DATETIME parts are lists.
                foreach ($child as $part) {
                    if ($part instanceof Node) {
                        $children[] = $part;
                    }
                }
            }
        }
        return $children;
    }

    private static function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'" . $value . "'",
            default => (string)$value,
        };
    }

    /** @param non-empty-list<string> $items */
    private static function andList(array $items): string
    {
        $last = array_pop($items);
        return $items === [] ? $last : implode(', ', $items) . ' and ' . $last;
    }

    /** @param list<string> $items */
    private static function orList(array $items): string
    {
        if ($items === []) {
            return 'taken out';
        }
        $last = array_pop($items);
        return $items === [] ? $last : implode(', ', $items) . ' or ' . $last;
    }
}
