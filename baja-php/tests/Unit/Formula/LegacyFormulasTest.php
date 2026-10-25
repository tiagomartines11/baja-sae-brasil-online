<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Tests\Unit\Formula\Support\ComparesEngines;
use PHPUnit\Framework\TestCase;

/**
 * Formulas from real configs that are broken today, and the odd things
 * eval() did around a formula. The new engine must keep all of it: a
 * published result must not change because the engine did.
 *
 * HOW TO READ THESE TESTS
 *
 * assertGives(value, formula, variables) and assertFails(error, formula)
 * run the formula with both engines and check that they agree, as in
 * GrammarTest. assertEvalDiesButSafeEngineFails(formula) is for the few
 * formulas on which eval() kills PHP: there the new engine fails normally
 * instead (the deliberate differences, docs/formula-engine.md).
 *
 * BACKGROUND
 *
 * A formula that raises an error gives null: an empty cell. Several
 * production configs have such formulas, and their pages have shown
 * empty cells for years. Fixing them is a decision for whoever owns the
 * config (scripts/check-results-configs.php lists them), not something
 * the engine may do on its own by "understanding" the mistake.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/LegacyFormulasTest.php
 */
final class LegacyFormulasTest extends TestCase
{
    use ComparesEngines;

    /** 16SU_APR: a variable name cannot start with a digit, so this is not a variable. */
    public function testAVariableNameStartingWithADigit(): void
    {
        $this->assertFails('ParseError', 'ROUND($25PC * 0.25, 2)', ['25PC' => 80]);
    }

    /** 24SU_X97: SPOILERS without quotes is an undefined constant, not the text. */
    public function testBareSpoilers(): void
    {
        $this->assertFails('Error: Undefined constant "SPOILERS"', 'SPOILERS');
        $this->assertGives('SPOILERS', "'SPOILERS'", [], 'with quotes it works');
    }

    /** 24NE_SUS: Ncompletaram without "$" is an undefined constant, and the whole formula gives null. */
    public function testBareNameInAPointsFormula(): void
    {
        $this->assertFails('Error: Undefined constant "Ncompletaram"', '$ARROBA * (1 - Ncompletaram / $Nparticiparam)', ['ARROBA' => 50, 'Nparticiparam' => 10]);
    }

    /** 26BR_REL and others: "+" used to join text, which PHP 8 refuses. */
    public function testPlusUsedToJoinText(): void
    {
        $formula = "'<strong>'+ROUND(\$RELPTS,2)+'<strong>'";
        $this->assertFails('TypeError: Unsupported operand types: string + float', $formula, ['RELPTS' => 81.234]);
        $this->assertFails('TypeError: Unsupported operand types: string + float', "'<strong>'+\$X", ['X' => 1.5]);
        $this->assertGives('<strong>81.23</strong>', "'<strong>'.ROUND(\$RELPTS,2).'</strong>'", ['RELPTS' => 81.234], 'the fix the config check proposes');
    }

    /** 23SU_B44: a "+" with nothing after it. */
    public function testTrailingOperator(): void
    {
        $this->assertFails('ParseError', 'ROUND($ENDPTS/2, 2)+', ['ENDPTS' => 100]);
        $this->assertFails('ParseError', 'ROUND($X)+');
    }

    /**
     * A number, rather than a text, as a formula ("pontos": {"X": 0}). eval()
     * put it into "$formula = <it>;" as PHP writes it: 2.0 is written "2",
     * so it comes back as the whole number 2.
     */
    public function testANumberIsAFormula(): void
    {
        $this->assertGives(0, 0);
        $this->assertGives(15, 15);
        $this->assertGives(2, 2.0);
        $this->assertGives(0.3, 0.1 + 0.2, [], 'written with 14 digits: "0.3"');
        $this->assertGives(1.0E+20, 1e20);
        $this->assertGives(0, -0.0, [], '"-0" is minus the whole number 0');
        $this->assertGives(1, true, [], 'true is written "1"');
        $this->assertFails('ParseError', false, [], 'false is written "": no formula');
        $this->assertFails('ParseError', null, [], 'a missing formula: no formula');
    }

    /**
     * 6 SPR provas have a list where a formula should be (SPR_PTS). eval()
     * wrote an array as "Array", which PHP then reads as its word array(...)
     * missing its brackets; an object (a JSON {...}) could not be written
     * at all.
     */
    public function testAListOrAnObjectAsAFormula(): void
    {
        $this->assertFails('ParseError', [30, 20]);
        $this->assertFails('Error: Object of class stdClass could not be converted to string', (object)['0' => [30, 20], '-1' => [15, 10]]);
    }

    /**
     * eval() made each variable a PHP variable with extract(), and then
     * ran "$formula = <formula>;". So a variable called "formula" replaced
     * the formula itself, $formula and $vars could be read, and a variable
     * called "this" broke every formula. Nothing in production does this
     * on purpose, but a column header or an input code could.
     */
    public function testWhatExtractDid(): void
    {
        $this->assertGives(42, '1', ['formula' => '6 * 7'], 'the variable "formula" is run instead');
        $this->assertGives('$formula', '$formula', [], '$formula is the formula itself, as text');
        $this->assertGives(3, '$vars', ['vars' => 3], 'a variable called "vars"');
        $this->assertGives(2, 'count($vars)', ['A' => 1, 'B' => 2], '$vars is all the variables');
        $this->assertFails('Error: Cannot re-assign $this', '1', ['this' => 1]);
        $this->assertFails('Error: Using $this when not in object context', '$this');
        $this->assertGives(5, '$A', ['A' => 5, '25PC' => 1, 'Pontos Finais' => 2], 'names that are not variable names are left out');
    }

    /**
     * Formulas that do not just fail under eval(): PHP stops dead (a fatal
     * error), so the results page dies, or the judge's save. The new
     * engine gives null for them and the page goes on. The first one is in
     * production (18SU_APR, also listed by scripts/check-results-configs.php).
     */
    public function testFatalErrorsOfEvalAreNormalErrors(): void
    {
        $this->assertStringStartsWith('Unparenthesized', $this->assertEvalDiesButSafeEngineFails('($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))'));
        $this->assertStringStartsWith('Cannot use isset() on the result of an expression', $this->assertEvalDiesButSafeEngineFails('isset(1)'));
        $this->assertStringStartsWith('Cannot use empty array elements in arrays', $this->assertEvalDiesButSafeEngineFails('[1, , 2]'));
        $this->assertStringStartsWith('Illegal offset type', $this->assertEvalDiesButSafeEngineFails('[[] => 1]'));
        $this->assertStringStartsWith('Cannot use [] for reading', $this->assertEvalDiesButSafeEngineFails('$X[]'));
    }
}
