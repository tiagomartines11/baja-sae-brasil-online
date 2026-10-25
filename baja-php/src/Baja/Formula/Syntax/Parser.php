<?php
declare(strict_types=1);

namespace Baja\Formula\Syntax;

use Baja\Formula\Allowlist;

/**
 * Reads a formula into a tree of Nodes, by PHP 8's rules for the part of
 * PHP that formulas use, or refuses it with a FormulaSyntaxError.
 *
 * Operators, from the loosest to the tightest (as in PHP 8):
 *
 *   ? :  ?:      if-then-else; two of them side by side need brackets
 *   ??           right to left: $A ?? $B ?? 0 is $A ?? ($B ?? 0)
 *   ||
 *   &&
 *   == != === !== <>     only one per level: $A == $B == $C is refused,
 *   < <= > >=            as PHP refuses it
 *   .            joins text; looser than + and - in PHP 8: 1 + 2 . 3 is "33"
 *   + -
 *   * / %
 *   !            !$A * 2 is (!$A) * 2
 *   - +          in front: -$A
 *   **           right to left, and tighter than the minus in front:
 *                -2 ** 2 is -4
 *   [ ]          $A[0], 'abc'[1], max($A)[0]
 *
 * Also read: numbers, texts, null/true/false (any case), variables,
 * [1, 2] and array(1, 2) (with optional "key =>"), isset(...), calls to
 * the functions of Allowlist, the constants of Allowlist (and any other
 * name, which then fails when the formula runs, as PHP's undefined
 * constants do), and the one object pattern found in the configs:
 *
 *   (new \DateTime(<a>, new \DateTimeZone(<z>)))->setTimestamp(<t>)->format(<f>)
 *
 * with or without the time zone, the setTimestamp() or the backslashes.
 *
 * Refused: anything else. In particular assignments, objects and "new"
 * (but the pattern above), static access, functions off the list,
 * variable variables, backticks, include/require/eval and the other
 * keywords, superglobals ($_SERVER, $GLOBALS...), and more than one
 * statement. A formula may end with ";" (eval() allowed that too).
 *
 * The parser also lists the variables and functions the formula names
 * (ParsedFormula), for the config checker and, later, the editor.
 */
final class Parser
{
    // How tightly each operator binds: the higher, the tighter.
    private const TERNARY = 10;
    private const COALESCE = 20;
    private const OR = 30;
    private const AND = 40;
    private const EQUALITY = 50;
    private const COMPARISON = 60;
    private const CONCAT = 70;
    private const ADD = 80;
    private const MULTIPLY = 90;
    private const NOT = 95;
    private const SIGN = 100;
    private const POWER = 110;

    /** operator => [how tightly it binds, 'left' | 'right' | 'none'] */
    private const BINARY = [
        '??' => [self::COALESCE, 'right'],
        '||' => [self::OR, 'left'],
        '&&' => [self::AND, 'left'],
        '==' => [self::EQUALITY, 'none'], '!=' => [self::EQUALITY, 'none'], '<>' => [self::EQUALITY, 'none'],
        '===' => [self::EQUALITY, 'none'], '!==' => [self::EQUALITY, 'none'],
        '<' => [self::COMPARISON, 'none'], '<=' => [self::COMPARISON, 'none'],
        '>' => [self::COMPARISON, 'none'], '>=' => [self::COMPARISON, 'none'],
        '.' => [self::CONCAT, 'left'],
        '+' => [self::ADD, 'left'], '-' => [self::ADD, 'left'],
        '*' => [self::MULTIPLY, 'left'], '/' => [self::MULTIPLY, 'left'], '%' => [self::MULTIPLY, 'left'],
        '**' => [self::POWER, 'right'],
    ];

    /** PHP keywords that do things; refused wherever they appear. */
    private const KEYWORDS = [
        'include', 'include_once', 'require', 'require_once', 'eval', 'exit', 'die', 'echo', 'print',
        'clone', 'function', 'fn', 'static', 'global', 'list', 'unset', 'yield', 'throw', 'match',
        'class', 'instanceof', 'and', 'or', 'xor', 'empty', '__halt_compiler', 'declare', 'goto',
    ];

    /** PHP's superglobals: they would let a formula read the server's settings and the visitor's request. */
    private const SUPERGLOBALS = ['GLOBALS', '_SERVER', '_GET', '_POST', '_COOKIE', '_FILES', '_ENV', '_REQUEST', '_SESSION'];

    private int $at = 0;

    /** The index of the END token, the last one. */
    private readonly int $last;

    /** @var array<string, true> */
    private array $variables = [];

    /** @var array<string, true> */
    private array $functions = [];

    /** @param list<Token> $tokens */
    private function __construct(private readonly string $source, private readonly array $tokens)
    {
        $this->last = count($tokens) - 1;
    }

    /** @throws FormulaSyntaxError */
    public static function parse(string $source): ParsedFormula
    {
        $parser = new self($source, Lexer::tokens($source));
        $root = $parser->expression(0);
        $semicolon = false;
        while ($parser->peek()->is(';')) {
            $parser->at++;
            $semicolon = true;
        }
        if (!$parser->peek()->is(Token::END)) {
            $token = $parser->peek();
            $parser->fail($semicolon ? 'only one formula is allowed, nothing after ";"' : 'unexpected ' . $token->describe(), $token);
        }
        return new ParsedFormula($root, array_keys($parser->variables), array_keys($parser->functions));
    }

    /**
     * An expression whose operators all bind tighter than $loosest. The
     * classic "precedence climbing": read one operand, then as long as the
     * next operator binds tightly enough, read its right side and combine.
     */
    private function expression(int $loosest): Node
    {
        $left = $this->prefix();
        // Set when the last operator of this level was "==" or "<" and so
        // on, which cannot be chained ($A == $B == $C).
        $lastNonAssociative = null;
        // Set when $left is an unbracketed "? :" or "?:" read in this loop.
        $lastTernary = null;

        while (true) {
            $token = $this->peek();
            if ($token->type === '?' && self::TERNARY > $loosest) {
                $left = $this->ternary($left, $token, $lastTernary);
                $lastTernary = $left->children[1] === null ? '?:' : '? :';
                continue;
            }
            if ($token->type === '=') {
                $this->fail('assignments ("=") are not allowed in a formula: to compare, write "=="', $token);
            }
            $operator = self::BINARY[$token->type] ?? null;
            if ($operator === null || $operator[0] <= $loosest) {
                return $left;
            }
            [$level, $associativity] = $operator;
            if ($associativity === 'none' && $lastNonAssociative === $level) {
                $this->fail('unexpected "' . $token->text . '": write brackets to compare more than two things', $token);
            }
            $this->at++;
            $right = $this->expression($associativity === 'right' ? $level - 1 : $level);
            $left = new Node(Node::BINARY, $token->type, [$left, $right], $left->offset);
            $lastNonAssociative = $associativity === 'none' ? $level : null;
            $lastTernary = null;
        }
    }

    /**
     * "<if> ? <then> : <else>" or "<if> ?: <else>", the "?" being next.
     *
     * PHP 8 refuses two of these side by side without brackets (a fatal
     * error that stops the whole page): "$A ? 1 : $B ? 2 : 3", and the same
     * with "?:" on either side. Only "$A ?: $B ?: 0" is allowed. Here it is
     * a FormulaSyntaxError, with PHP's own words: one formula gives null
     * and the page goes on. Brackets around either one make it fine.
     */
    private function ternary(Node $if, Token $question, ?string $previous): Node
    {
        $this->at++;
        $short = $this->peek()->is(':');
        $current = $short ? '?:' : '? :';
        if ($previous !== null && !($previous === '?:' && $short)) {
            $this->fail(self::nestedTernaryMessage($previous, $current), $question);
        }
        $then = null;
        if (!$short) {
            $then = $this->expression(0);
        }
        $this->expect(':');
        // Left to right: the else stops at the next "?", which comes back to
        // expression()'s loop as a ternary next to this one.
        $else = $this->expression(self::TERNARY);
        return new Node(Node::TERNARY, null, [$if, $then, $else], $if->offset);
    }

    /** PHP 8's message for two unbracketed ternaries side by side. */
    private static function nestedTernaryMessage(string $first, string $second): string
    {
        // The letters run on: "a ? b : c ? d : e", "a ?: b ? c : d".
        [$one, $last, $next] = $first === '?:' ? ['a ?: b', 'b', ['c', 'd']] : ['a ? b : c', 'c', ['d', 'e']];
        $two = $second === '?:' ? ' ?: ' . $next[0] : ' ? ' . $next[0] . ' : ' . $next[1];
        return 'Unparenthesized `' . $one . $two . '` is not supported. Use either `(' . $one . ')' . $two . '` or `'
            . substr($one, 0, -1) . '(' . $last . $two . ')`';
    }

    /** One operand: a literal, a variable, a call, a bracket..., then any "[...]" after it. */
    private function prefix(): Node
    {
        $token = $this->peek();
        switch ($token->type) {
            case '!':
                $this->at++;
                return new Node(Node::UNARY, '!', [$this->expression(self::NOT)], $token->offset);
            case '-':
            case '+':
                $this->at++;
                return new Node(Node::UNARY, $token->type, [$this->expression(self::SIGN)], $token->offset);
            case Token::NUMBER:
                $this->at++;
                // A number cannot be followed by [...] in PHP: 1[0] is refused.
                return new Node(Node::LITERAL, $token->value, [], $token->offset);
            case Token::STRING:
                $this->at++;
                return $this->postfix(new Node(Node::LITERAL, $token->value, [], $token->offset));
            case Token::TEMPLATE:
                $this->at++;
                return $this->postfix($this->template($token));
            case Token::VARIABLE:
                $this->at++;
                return $this->postfix($this->variable($token));
            case '[':
                $this->at++;
                return $this->postfix($this->arrayItems(']', $token));
            case '(':
                if ($this->peek(1)->isWord('new')) {
                    return $this->dateTime();
                }
                $this->at++;
                $inner = $this->expression(0);
                $this->expect(')');
                $inner->parenthesized = true;
                return $this->postfix($inner);
            case Token::NAME:
                return $this->postfix($this->name($token));
        }
        $this->fail('unexpected ' . $token->describe(), $token);
    }

    /** Any "[key]" after an operand, and the things refused there: "->", "(". */
    private function postfix(Node $node): Node
    {
        while (true) {
            $token = $this->peek();
            if ($token->type === '[') {
                $this->at++;
                if ($this->peek()->is(']')) {
                    $this->fail('"[]" adds to an array and is not allowed in a formula', $this->peek());
                }
                $key = $this->expression(0);
                $this->expect(']');
                $node = new Node(Node::INDEX, null, [$node, $key], $node->offset);
            } elseif ($token->type === '->') {
                $this->fail('objects ("->") are not allowed in a formula', $token);
            } elseif ($token->type === '(') {
                $this->fail('only the functions ' . self::functionList() . ' can be called in a formula', $token);
            } else {
                return $node;
            }
        }
    }

    private function variable(Token $token): Node
    {
        $name = $token->value;
        if (in_array($name, self::SUPERGLOBALS, true)) {
            $this->fail('$' . $name . ' is not allowed in a formula', $token);
        }
        $this->variables[$name] = true;
        return new Node(Node::VARIABLE, $name, [], $token->offset);
    }

    private function template(Token $token): Node
    {
        $parts = [];
        foreach ($token->value as $part) {
            if (is_array($part)) {
                $this->variables[$part['variable']] = true;
                $parts[] = new Node(Node::VARIABLE, $part['variable'], [], $token->offset);
            } else {
                $parts[] = $part;
            }
        }
        return new Node(Node::TEMPLATE, null, $parts, $token->offset);
    }

    /** A name: null/true/false, a keyword, a function call, or a constant. */
    private function name(Token $token): Node
    {
        $this->at++;
        $lower = strtolower($token->value);
        if (in_array($lower, self::KEYWORDS, true)) {
            $this->fail('"' . $token->value . '" is not allowed in a formula', $token);
        }
        if ($lower === 'new') {
            $this->fail('"new" is not allowed in a formula, except in (new \DateTime(...))->setTimestamp(...)->format(...)', $token);
        }
        if ($this->peek()->is('(')) {
            return match ($lower) {
                'isset' => $this->isset($token),
                'array' => $this->arrayItems(')', $token),
                default => $this->call($token, $lower),
            };
        }
        return match ($lower) {
            'null' => new Node(Node::LITERAL, null, [], $token->offset),
            'true' => new Node(Node::LITERAL, true, [], $token->offset),
            'false' => new Node(Node::LITERAL, false, [], $token->offset),
            'isset', 'array' => $this->fail('"' . $token->value . '" must be followed by "("', $this->peek()),
            default => new Node(Node::CONSTANT, $token->value, [], $token->offset),
        };
    }

    private function call(Token $name, string $lower): Node
    {
        if (!Allowlist::isFunction($lower)) {
            $this->fail('function ' . $name->value . '() is not allowed in a formula: only ' . self::functionList() . ' are', $name);
        }
        $this->functions[$lower] = true;
        $arguments = $this->arguments();
        $most = Allowlist::FUNCTIONS[$lower];
        if ($most !== null && count($arguments) > $most) {
            $this->fail($name->value . '() takes only ' . $most . ' argument' . ($most === 1 ? '' : 's') . ' in a formula', $name);
        }
        return new Node(Node::CALL, $lower, $arguments, $name->offset);
    }

    /**
     * "(a, b, c)" with an optional comma after the last, as PHP allows.
     *
     * @return list<Node>
     */
    private function arguments(): array
    {
        $this->expect('(');
        $arguments = [];
        while ($this->peek()->type !== ')') {
            $arguments[] = $this->expression(0);
            $this->commaOr(')');
        }
        $this->at++;
        return $arguments;
    }

    /** isset(a, b...): each must be a variable, or a variable followed by [...]. */
    private function isset(Token $name): Node
    {
        $arguments = $this->arguments();
        if ($arguments === []) {
            $this->fail('isset() needs at least one variable', $name);
        }
        foreach ($arguments as $argument) {
            $root = $argument;
            while ($root->kind === Node::INDEX && !$root->parenthesized) {
                $root = $root->children[0];
            }
            if ($root->kind !== Node::VARIABLE || $root->parenthesized || $argument->parenthesized) {
                $this->fail('isset() only takes variables, such as isset($A) or isset($A[0])', $name);
            }
        }
        return new Node(Node::ISSET, null, $arguments, $name->offset);
    }

    /** The items of [...] or array(...), up to $close, the opening one already read. */
    private function arrayItems(string $close, Token $open): Node
    {
        if ($close === ')') {
            $this->expect('(');
        }
        $items = [];
        while (!$this->peek()->is($close)) {
            if ($this->peek()->is(',')) {
                $this->fail('an array cannot have an empty item', $this->peek());
            }
            $value = $this->expression(0);
            $key = null;
            if ($this->peek()->is('=>')) {
                $this->at++;
                $key = $value;
                $value = $this->expression(0);
            }
            $items[] = [$key, $value];
            $this->commaOr($close);
        }
        $this->at++;
        return new Node(Node::ARRAY, null, $items, $open->offset);
    }

    /**
     * (new \DateTime(<a>, new \DateTimeZone(<z>)))->setTimestamp(<t>)->format(<f>)
     *
     * Found 32 times in production's configs, to show a time (enduro
     * penalties). The time zone and setTimestamp() are optional; format()
     * must end it. SafeEngine runs exactly these PHP calls (Interpreter).
     */
    private function dateTime(): Node
    {
        $start = $this->expect('(');
        $this->next(); // new
        $this->className('DateTime');
        $arguments = $this->peek()->is('(') ? $this->dateTimeArguments() : [[], null];
        $this->expect(')');

        $timestamp = null;
        $format = null;
        while ($format === null) {
            $arrow = $this->peek();
            if (!$arrow->is('->')) {
                $this->fail('(new \DateTime(...)) must be followed by ->format(...), optionally after ->setTimestamp(...)', $arrow);
            }
            $this->at++;
            $method = $this->next();
            $lower = $method->is(Token::NAME) ? strtolower($method->value) : '';
            if ($lower === 'settimestamp' && $timestamp === null) {
                $timestamp = $this->arguments();
            } elseif ($lower === 'format') {
                $format = $this->arguments();
            } else {
                $this->fail('only ->setTimestamp(...) and then ->format(...) are allowed after new \DateTime(...)', $method);
            }
        }
        if ($this->peek()->is('->')) {
            $this->fail('nothing is allowed after ->format(...)', $this->peek());
        }
        return new Node(Node::DATETIME, null, [$arguments[0], $arguments[1], $timestamp, $format], $start->offset);
    }

    /**
     * DateTime's "(<a>, new \DateTimeZone(<z>))", each part optional.
     *
     * @return array{0: list<Node>, 1: list<Node>|null}
     */
    private function dateTimeArguments(): array
    {
        $this->expect('(');
        $arguments = [];
        $zone = null;
        if (!$this->peek()->is(')')) {
            $arguments[] = $this->expression(0);
            if ($this->peek()->is(',') && !$this->peek(1)->is(')')) {
                $this->at++;
                $new = $this->next();
                if (!$new->isWord('new')) {
                    $this->fail('the second argument of new \DateTime(...) must be new \DateTimeZone(...)', $new);
                }
                $this->className('DateTimeZone');
                $zone = $this->arguments();
            }
            if ($this->peek()->is(',')) {
                $this->at++;
            }
        }
        $this->expect(')');
        return [$arguments, $zone];
    }

    private function className(string $expected): void
    {
        $name = $this->next();
        if (!$name->isWord($expected)) {
            $this->fail('"new" is not allowed in a formula, except in (new \DateTime(...))->setTimestamp(...)->format(...)', $name);
        }
    }

    private static function functionList(): string
    {
        return implode(', ', array_keys(Allowlist::FUNCTIONS));
    }

    private function peek(int $ahead = 0): Token
    {
        return $this->tokens[$this->at + $ahead] ?? $this->tokens[$this->last];
    }

    private function next(): Token
    {
        $token = $this->peek();
        if (!$token->is(Token::END)) {
            $this->at++;
        }
        return $token;
    }

    private function expect(string $type): Token
    {
        $token = $this->peek();
        if ($token->type !== $type) {
            $this->fail('expected "' . $type . '" but found ' . $token->describe(), $token);
        }
        $this->at++;
        return $token;
    }

    /** After an item of a list: a "," (read here), or the closing bracket (left for the caller). */
    private function commaOr(string $close): void
    {
        $token = $this->peek();
        if ($token->type === ',') {
            $this->at++;
        } elseif ($token->type !== $close) {
            $this->fail('expected "," or "' . $close . '" but found ' . $token->describe(), $token);
        }
    }

    private function fail(string $problem, Token $token): never
    {
        throw new FormulaSyntaxError($problem, Lexer::position($this->source, $token->offset));
    }
}
