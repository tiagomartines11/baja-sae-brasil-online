<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\SafeEngine;
use Baja\Formula\Syntax\FormulaSyntaxError;
use Baja\Formula\Syntax\Node;
use Baja\Formula\Syntax\Parser;
use PHPUnit\Framework\TestCase;

/**
 * What the parser says about a formula without running it: its
 * variables, its functions, and where a mistake is.
 *
 * HOW TO READ THESE TESTS
 *
 * Each method whose name starts with "test" is one small experiment. It
 * reads a formula with Parser::parse() (or SafeEngine::read(), the same
 * for a formula that may not be text) and "asserts" what must be true:
 * assertSame(expected, actual) fails, showing both, when they differ.
 *
 * BACKGROUND
 *
 * Reading a formula gives a tree (Syntax\Node) that SafeEngine works out.
 * The same reading tells which variables the formula needs, which the
 * config check uses to list unknown ones ($ACEmin where the prova has
 * ACE_MIN), and which a future editor will use for autocomplete.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/ParserTest.php
 */
final class ParserTest extends TestCase
{
    /** In the order they first appear, each once, without "$". */
    public function testItListsTheVariables(): void
    {
        $this->assertSame(['B', 'A'], Parser::parse('ROUND($B, 2) + $A + $B')->variables);
        $this->assertSame(['T', 'X'], Parser::parse('isset($T) ? "$X s" : 0')->variables, 'in isset() and inside "..." too');
        $this->assertSame(['ts'], Parser::parse("(new \\DateTime('now', new \\DateTimeZone('UTC')))->setTimestamp(\$ts)->format('H')")->variables);
        $this->assertSame([], Parser::parse("'DNF'")->variables);
    }

    /** Both sides of a ? : count: which one is read depends on the data. */
    public function testItListsTheVariablesOfEveryBranch(): void
    {
        $this->assertSame(['A', 'B', 'C'], Parser::parse('$A ? $B : $C')->variables);
    }

    /** The functions, in lower case. */
    public function testItListsTheFunctions(): void
    {
        $this->assertSame(['round', 'max', 'array_diff'], Parser::parse('ROUND(MAX(array_diff([$A], [null])), 2) + round($B)')->functions);
    }

    /** A number is a formula too; read as eval() would write it. */
    public function testReadTakesAnyFormula(): void
    {
        $this->assertSame(Node::LITERAL, SafeEngine::read(2.0)->root->kind);
        $this->assertSame(2, SafeEngine::read(2.0)->root->value, '2.0 is written "2"');
    }

    /** The tree follows PHP's order: * before +, and "." after both. */
    public function testItReadsInPhpsOrder(): void
    {
        $root = Parser::parse('1 + 2 * 3 . "x"')->root;
        $this->assertSame([Node::BINARY, '.'], [$root->kind, $root->value]);
        $plus = $root->children[0];
        $this->assertSame([Node::BINARY, '+'], [$plus->kind, $plus->value]);
        $this->assertSame([Node::BINARY, '*'], [$plus->children[1]->kind, $plus->children[1]->value]);
    }

    /** A mistake says what and where, counting characters (not bytes) from 1. */
    public function testMistakesSayWhere(): void
    {
        $cases = [
            'ROUND($X, 2' => 'expected "," or ")" but found end of the formula, at character 12',
            'ROUND($X,, 2)' => 'unexpected ",", at character 10',
            '$Segurança +* 2' => 'unexpected "*", at character 13',
            "'aberto" => "a text started with ' is never closed, at character 1",
            '$X ° 2' => 'unexpected name "°", at character 4',
        ];
        foreach ($cases as $formula => $message) {
            try {
                Parser::parse($formula);
                $this->fail('Should be refused: ' . $formula);
            } catch (FormulaSyntaxError $error) {
                $this->assertSame($message, $error->getMessage(), $formula);
            }
        }
    }
}
