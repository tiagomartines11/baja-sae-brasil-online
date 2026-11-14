<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Formula;

use Baja\Formula\SafeEngine;
use Baja\Tests\Unit\Formula\Support\ComparesEngines;
use PHPUnit\Framework\TestCase;

/**
 * MENOR(), MAIOR() and CONTAR(): three helpers a formula can call on a
 * list of values (BAJAAPP-103).
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A prova like Tração lets a car try up to six times. Each attempt is a
 * field the judge fills in: T_1, T_2 ... T_6. A car that only ran twice
 * has values in T_1 and T_2 and nothing (null) in the rest. The formulas
 * get those values in a list too, $T_lista, with the empty ones left out:
 * for that car, a list of two numbers.
 *
 * The prova then wants "the best time", the smallest number in the list.
 * PHP has min() for that, but min() of an EMPTY list is an error, and an
 * error makes the whole formula give nothing. A car that ran no attempt
 * would break its own points formula. (That is the "max([])" problem of
 * BAJAAPP-56.) So the site has its own helpers:
 *
 *   MENOR($T_lista)   the smallest value; null (nothing) for an empty list
 *   MAIOR($T_lista)   the largest value;  null for an empty list
 *   CONTAR($T_lista)  how many values there are; 0 for an empty list
 *
 * HOW TO READ THESE TESTS
 *
 *     $this->assertGives(4.1, 'MENOR($L)', ['L' => [4.5, 4.1]]);
 *
 * means: when the formula's variable $L holds the list [4.5, 4.1], the
 * formula "MENOR($L)" gives 4.1. And it gives exactly that in BOTH ways
 * the site can run a formula: the old one (PHP's eval()) and the new safe
 * one (SafeEngine). assertFails('TypeError', ...) means both ways give an
 * error of that kind, which on the site is an empty cell.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Formula/ListFunctionsTest.php
 */
final class ListFunctionsTest extends TestCase
{
    use ComparesEngines;

    /** The ordinary case: a list of times, the best (smallest) and the worst (largest). */
    public function testMenorAndMaiorGiveTheSmallestAndLargestValue(): void
    {
        $times = ['T_lista' => [4.512, 4.208, 4.733]];
        $this->assertGives(4.208, 'MENOR($T_lista)', $times);
        $this->assertGives(4.733, 'MAIOR($T_lista)', $times);
        $this->assertGives(3, 'CONTAR($T_lista)', $times);
    }

    /**
     * THE POINT OF THE HELPERS. A car with no attempt at all has an empty
     * list. PHP's own min([]) is an error; MENOR([]) is simply null, and
     * the formula can go on (a null in a sum counts as 0, for instance).
     */
    public function testAnEmptyListGivesNullNotAnError(): void
    {
        $noAttempt = ['T_lista' => []];
        $this->assertGives(null, 'MENOR($T_lista)', $noAttempt);
        $this->assertGives(null, 'MAIOR($T_lista)', $noAttempt);
        $this->assertGives(0, 'CONTAR($T_lista)', $noAttempt);
        // What the helpers replace: PHP's own min() of an empty list fails.
        $this->assertFails('ValueError', 'min($T_lista)', $noAttempt);
        // And the formula can use the null: "no time" gives 0 points here.
        $this->assertGives(0, 'MENOR($T_lista) === null ? 0 : 50', $noAttempt);
    }

    /**
     * A variable nobody filled in is null, not a list. The helpers treat
     * it as an empty list rather than failing, so a typo-free formula never
     * breaks because a prova has no data yet.
     */
    public function testAMissingListIsAnEmptyList(): void
    {
        $this->assertGives(null, 'MENOR($NOT_THERE)');
        $this->assertGives(null, 'MAIOR($NOT_THERE)');
        $this->assertGives(0, 'CONTAR($NOT_THERE)');
    }

    /**
     * The helpers also work on a list written in the formula, as the old
     * configs do with [$T_1, $T_2]. There, an attempt not made is null:
     * empty values (null and "") are skipped, never taken as the smallest.
     */
    public function testEmptyValuesInTheListAreSkipped(): void
    {
        $twoOfThree = ['T_1' => 5.25, 'T_2' => null, 'T_3' => 4.75];
        $this->assertGives(4.75, 'MENOR([$T_1, $T_2, $T_3])', $twoOfThree);
        $this->assertGives(5.25, 'MAIOR([$T_1, $T_2, $T_3])', $twoOfThree);
        $this->assertGives(2, 'CONTAR([$T_1, $T_2, $T_3])', $twoOfThree);
        $this->assertGives(null, 'MENOR([null, ""])');
        // 0 is a real value, not an empty one.
        $this->assertGives(0, 'MENOR([3, 0, null])');
        $this->assertGives(2, 'CONTAR([3, 0, null])');
    }

    /** Like every function in a formula, the name can be written in any case. */
    public function testTheNamesIgnoreCase(): void
    {
        $list = ['L' => [2, 9, 4]];
        $this->assertGives(2, 'menor($L)', $list);
        $this->assertGives(2, 'Menor($L)', $list);
        $this->assertGives(9, 'mAiOr($L)', $list);
        $this->assertGives(3, 'contar($L)', $list);
    }

    /** The values are compared as PHP's min() and max() compare them: whole numbers stay whole. */
    public function testTheValueComesBackAsItWas(): void
    {
        $this->assertGives(3, 'MENOR($L)', ['L' => [7, 3, 5]]);
        $this->assertGives(7.5, 'MAIOR($L)', ['L' => [7, 7.5, 5]]);
        $this->assertGives('B', 'MAIOR($L)', ['L' => ['A', 'B']]);
    }

    /**
     * Misuse is an error (an empty cell), the same in both ways of running
     * the formula: one value that is not a list, no argument, or two.
     */
    public function testMisuseIsAnErrorInBothEngines(): void
    {
        $this->assertFails('TypeError: MENOR(): o argumento precisa ser uma lista', 'MENOR(5)');
        $this->assertFails('TypeError: MAIOR(): o argumento precisa ser uma lista', 'MAIOR("abc")');
        $this->assertFails('ArgumentCountError: MENOR() recebe exatamente 1 argumento', 'MENOR()');
        $this->assertFails('ArgumentCountError: CONTAR() recebe exatamente 1 argumento', 'CONTAR($A, $B)', ['A' => [1], 'B' => [2]]);
    }

    /**
     * The new engine reads a formula before running it and refuses any
     * function not on its list. The helpers are on it, so a formula using
     * them is read without complaint, and the config check can list the
     * variables it reads.
     */
    public function testTheSafeEngineAcceptsThemAndSeesTheirVariables(): void
    {
        $read = SafeEngine::read('MENOR($T_lista) + CONTAR($D_lista)');
        $this->assertSame(['T_lista', 'D_lista'], array_values($read->variables));
    }
}
