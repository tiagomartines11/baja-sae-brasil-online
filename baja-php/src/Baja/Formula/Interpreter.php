<?php
// No declare(strict_types=1) here, on purpose. This file calls PHP's
// functions for the formulas (round(), max()...), and PHP checks a call's
// arguments by the rules of the file the call is written in. Formulas ran
// under eval(), which never was strict: round("2.5") gave 3. In a strict
// file it would be a TypeError, and the formula would give null.
// InterpreterTest::testFunctionsAreCalledWithoutStrictTypes guards this.

namespace Baja\Formula;

use Baja\Formula\Syntax\Node;
use Closure;
use DateTime;
use DateTimeZone;
use Error;

/**
 * Turns a read formula (a tree of Nodes, see Syntax\Parser) into a PHP
 * function that works it out: compile() once, then call the result with a
 * Scope for every team.
 *
 * Each operator is PHP's own: "$A + $B" becomes a function that does
 * $a + $b on the two values. So every rule of PHP 8 comes with it, quirks
 * included, without being written out here:
 *
 *   "5" + 1 is 6; "5 pts" + 1 is 6 (with a warning); "abc" + 1 and
 *   "<b>" + 1.5 are TypeErrors; 1 / 0 is a DivisionByZeroError;
 *   "2 - Até..." == 2 is false (PHP 8 compares a non-numeric text with a
 *   number as text); null == 0 is true; [1] + [2, 3] is [1, 3]...
 *
 * Errors are not caught here: Input::solveFormula() turns them into null.
 *
 * What is decided here rather than by an operator, all as eval() did:
 *   - && || ?: ? : and ?? only work out the side they need, so an error
 *     on the other side does not count ($A ? SPOILERS : 1 is 1 when $A is
 *     empty);
 *   - an unknown variable is null; $vars and $formula are the variables
 *     and the formula as given (Scope); $this is an Error;
 *   - an unknown constant is an Error when it is reached, not before;
 *   - ?? and isset() read $A[...] without complaining when it is missing.
 */
final class Interpreter
{
    /** @return Closure(Scope): mixed */
    public static function compile(Node $node): Closure
    {
        return self::node($node, false);
    }

    /**
     * @param bool $quiet read as ?? and isset() read: a missing variable or
     *                    item is null, with no error for a text read with
     *                    a word ("abc"["x"])
     * @return Closure(Scope): mixed
     */
    private static function node(Node $node, bool $quiet): Closure
    {
        return match ($node->kind) {
            Node::LITERAL => self::literal($node->value),
            Node::VARIABLE => self::variable($node->value, $quiet),
            Node::CONSTANT => self::constant($node->value),
            Node::TEMPLATE => self::template($node->children),
            Node::ARRAY => self::arrayOf($node->children),
            Node::INDEX => self::index(self::node($node->children[0], $quiet), self::node($node->children[1], false), $quiet),
            Node::ISSET => self::isset($node->children),
            Node::CALL => self::call($node->value, self::all($node->children)),
            Node::UNARY => self::unary($node->value, self::node($node->children[0], false)),
            Node::BINARY => self::binary($node->value, $node->children[0], $node->children[1]),
            Node::TERNARY => self::ternary($node->children),
            Node::DATETIME => self::dateTime($node->children),
        };
    }

    private static function literal(mixed $value): Closure
    {
        return static fn() => $value;
    }

    private static function variable(string $name, bool $quiet): Closure
    {
        return match ($name) {
            'this' => $quiet
                ? static fn() => null
                : static fn() => throw new Error('Using $this when not in object context'),
            'vars' => static fn(Scope $scope) => array_key_exists('vars', $scope->vars) ? $scope->vars['vars'] : $scope->given,
            'formula' => static fn(Scope $scope) => array_key_exists('formula', $scope->vars) ? $scope->vars['formula'] : $scope->formula,
            default => static fn(Scope $scope) => $scope->vars[$name] ?? null,
        };
    }

    /** A constant on the Allowlist reads PHP's; any other name is PHP's "Undefined constant" error. */
    private static function constant(string $name): Closure
    {
        $undefined = 'Undefined constant "' . $name . '"';
        if (!Allowlist::isConstant($name)) {
            return static fn() => throw new Error($undefined);
        }
        // Looked up when reached: Pos, EquipeNum and Equipe only exist once
        // src/bootstrap.php has run, as under eval().
        return static fn() => defined($name) ? constant($name) : throw new Error($undefined);
    }

    /** "text $A text": each part as "." would join it. */
    private static function template(array $parts): Closure
    {
        $compiled = array_map(fn($part) => is_string($part) ? $part : self::node($part, false), $parts);
        return static function (Scope $scope) use ($compiled) {
            $text = '';
            foreach ($compiled as $part) {
                $text .= is_string($part) ? $part : $part($scope);
            }
            return $text;
        };
    }

    /** [a, k => b]: built by PHP, so keys follow PHP's rules ("1" is 1, null is ""...). */
    private static function arrayOf(array $items): Closure
    {
        $compiled = array_map(fn(array $item) => [$item[0] === null ? null : self::node($item[0], false), self::node($item[1], false)], $items);
        return static function (Scope $scope) use ($compiled) {
            $array = [];
            foreach ($compiled as [$key, $value]) {
                if ($key === null) {
                    $array[] = $value($scope);
                } else {
                    $array[$key($scope)] = $value($scope);
                }
            }
            return $array;
        };
    }

    private static function index(Closure $of, Closure $key, bool $quiet): Closure
    {
        if ($quiet) {
            return static function (Scope $scope) use ($of, $key) {
                $base = $of($scope);
                $k = $key($scope);
                return $base[$k] ?? null;
            };
        }
        return static function (Scope $scope) use ($of, $key) {
            $base = $of($scope);
            $k = $key($scope);
            return $base[$k];
        };
    }

    /** isset(a, b): each one in turn, stopping at the first that is not set, as PHP does. */
    private static function isset(array $arguments): Closure
    {
        $checks = array_map(static function (Node $argument): Closure {
            if ($argument->kind === Node::VARIABLE) {
                $read = self::node($argument, true);
                return static fn(Scope $scope) => $read($scope) !== null;
            }
            $of = self::node($argument->children[0], true);
            $key = self::node($argument->children[1], false);
            return static function (Scope $scope) use ($of, $key) {
                $base = $of($scope);
                $k = $key($scope);
                return isset($base[$k]);
            };
        }, $arguments);
        return static function (Scope $scope) use ($checks) {
            foreach ($checks as $check) {
                if (!$check($scope)) {
                    return false;
                }
            }
            return true;
        };
    }

    /**
     * A function of the Allowlist (the Parser lets no other through),
     * called as PHP's own, from this non-strict file. time() and date()
     * go through PinnedClock, which is the real clock unless the Scope has
     * a fixed now.
     *
     * @param list<Closure> $arguments
     */
    private static function call(string $name, array $arguments): Closure
    {
        if ($name === 'time') {
            return static fn(Scope $scope) => PinnedClock::time($scope->now, self::values($arguments, $scope));
        }
        if ($name === 'date') {
            return static fn(Scope $scope) => PinnedClock::date($scope->now, self::values($arguments, $scope));
        }
        // The usual cases spelled out: round() alone is in 5,000 formulas.
        return match (count($arguments)) {
            1 => static fn(Scope $scope) => $name($arguments[0]($scope)),
            2 => static function (Scope $scope) use ($name, $arguments) {
                $first = $arguments[0]($scope);
                return $name($first, $arguments[1]($scope));
            },
            default => static fn(Scope $scope) => $name(...self::values($arguments, $scope)),
        };
    }

    private static function unary(string $operator, Closure $operand): Closure
    {
        return match ($operator) {
            '!' => static fn(Scope $scope) => !$operand($scope),
            '-' => static fn(Scope $scope) => -$operand($scope),
            '+' => static fn(Scope $scope) => +$operand($scope),
        };
    }

    /**
     * Left first, then right, then PHP's operator. &&, || and ?? only work
     * the right side out when they need it.
     */
    private static function binary(string $operator, Node $leftNode, Node $rightNode): Closure
    {
        $left = self::node($leftNode, $operator === '??');
        $right = self::node($rightNode, false);
        if ($operator === '&&') {
            return static fn(Scope $scope) => $left($scope) && $right($scope);
        }
        if ($operator === '||') {
            return static fn(Scope $scope) => $left($scope) || $right($scope);
        }
        if ($operator === '??') {
            return static fn(Scope $scope) => $left($scope) ?? $right($scope);
        }
        $apply = match ($operator) {
            '+' => static fn($a, $b) => $a + $b,
            '-' => static fn($a, $b) => $a - $b,
            '*' => static fn($a, $b) => $a * $b,
            '/' => static fn($a, $b) => $a / $b,
            '%' => static fn($a, $b) => $a % $b,
            '**' => static fn($a, $b) => $a ** $b,
            '.' => static fn($a, $b) => $a . $b,
            '==' => static fn($a, $b) => $a == $b,
            '!=', '<>' => static fn($a, $b) => $a != $b,
            '===' => static fn($a, $b) => $a === $b,
            '!==' => static fn($a, $b) => $a !== $b,
            '<' => static fn($a, $b) => $a < $b,
            '<=' => static fn($a, $b) => $a <= $b,
            '>' => static fn($a, $b) => $a > $b,
            '>=' => static fn($a, $b) => $a >= $b,
        };
        return static function (Scope $scope) use ($left, $right, $apply) {
            $a = $left($scope);
            return $apply($a, $right($scope));
        };
    }

    /** [if, then or null for ?:, else] */
    private static function ternary(array $children): Closure
    {
        $if = self::node($children[0], false);
        $else = self::node($children[2], false);
        if ($children[1] === null) {
            return static fn(Scope $scope) => $if($scope) ?: $else($scope);
        }
        $then = self::node($children[1], false);
        return static fn(Scope $scope) => $if($scope) ? $then($scope) : $else($scope);
    }

    /**
     * (new \DateTime(a, new \DateTimeZone(z)))->setTimestamp(t)->format(f),
     * with exactly those calls, in PHP's order: a, then z, the time zone,
     * the DateTime, then t and setTimestamp(), then f and format().
     *
     * @param array{0: list<Node>, 1: list<Node>|null, 2: list<Node>|null, 3: list<Node>} $children
     */
    private static function dateTime(array $children): Closure
    {
        [$arguments, $zone, $timestamp, $format] = [
            self::all($children[0]),
            $children[1] === null ? null : self::all($children[1]),
            $children[2] === null ? null : self::all($children[2]),
            self::all($children[3]),
        ];
        return static function (Scope $scope) use ($arguments, $zone, $timestamp, $format) {
            $values = self::values($arguments, $scope);
            if ($zone !== null) {
                $values[] = new DateTimeZone(...self::values($zone, $scope));
            }
            $date = new DateTime(...$values);
            if ($timestamp !== null) {
                $date = $date->setTimestamp(...self::values($timestamp, $scope));
            }
            return $date->format(...self::values($format, $scope));
        };
    }

    /**
     * @param list<Node> $nodes
     * @return list<Closure>
     */
    private static function all(array $nodes): array
    {
        return array_map(fn(Node $node) => self::node($node, false), $nodes);
    }

    /**
     * @param list<Closure> $closures
     * @return list<mixed> their values, in order
     */
    private static function values(array $closures, Scope $scope): array
    {
        $values = [];
        foreach ($closures as $closure) {
            $values[] = $closure($scope);
        }
        return $values;
    }
}
