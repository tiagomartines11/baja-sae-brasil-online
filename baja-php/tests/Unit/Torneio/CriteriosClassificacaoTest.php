<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Torneio;

use Baja\Torneio\CriteriosClassificacao;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CriteriosClassificacao: the order in which the qualified cars
 * enter a tournament, their "seeding" (BAJAAPP-37).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A tournament is a knockout: cars race two at a time and the loser is out
 * (ConfrontoTest draws one). Who races whom in the first round depends on
 * each car's "seed", its position in a ranking made before the tournament:
 * seed 1 is the best car. The bracket is built so that the best cars meet
 * as late as possible (seeds 1 and 2 only in the final).
 *
 * The ranking is set by the organisers in the prova's config, as a list of
 * criteria. Each criterion gives every car a score (from a formula) and has
 * an "order":
 *
 *   "desc"  bigger is better, e.g. points: 90 before 80
 *   "asc"   smaller is better, e.g. a time: 50 s before 60 s
 *
 * The first criterion decides. When two cars tie on it, the second decides
 * between them, and so on: a "tie-break".
 *
 * THE BUG
 *
 * The page juiz/torneio.php ignored "order": every criterion was sorted
 * bigger first, so a fastest-time criterion put the SLOWEST car first. The
 * help page even told organisers to negate the formula to get around it.
 *
 * HOW TO READ THESE TESTS
 *
 * Each car is a list of its scores, one per criterion. seeding() sorts the
 * cars with the comparator, as the page does with usort(), and returns their
 * names in seeding order: seed 1 first.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Torneio/CriteriosClassificacaoTest.php
 */
final class CriteriosClassificacaoTest extends TestCase
{
    /**
     * @param list<string|null> $ordens one per criterion
     * @param array<string, list<mixed>> $carros name => scores, in the order the page found them
     * @return list<string> the names, seed 1 first
     */
    private static function seeding(array $ordens, array $carros): array
    {
        $criterios = new CriteriosClassificacao($ordens);
        $linhas = [];
        foreach ($carros as $nome => $scores) {
            $linhas[] = ['nome' => $nome, 'scores' => $scores];
        }
        usort($linhas, fn($a, $b) => $criterios->compare($a['scores'], $b['scores']));
        return array_column($linhas, 'nome');
    }

    // --- One criterion ---------------------------------------------------

    /** Points: the most points is seed 1. */
    public function testDescPutsTheBiggestFirst(): void
    {
        $this->assertSame(['B', 'C', 'A'], self::seeding(['desc'], ['A' => [70], 'B' => [90], 'C' => [80]]));
    }

    /** A time: the fastest (smallest) is seed 1. This is what was broken. */
    public function testAscPutsTheSmallestFirst(): void
    {
        $this->assertSame(['A', 'C', 'B'], self::seeding(['asc'], ['A' => [50.2], 'B' => [61.0], 'C' => [55.9]]));
    }

    /** A criterion without "order" sorts bigger first, as every one did before. */
    public function testNoOrderMeansDesc(): void
    {
        $this->assertSame(['B', 'A'], self::seeding([null], ['A' => [1], 'B' => [2]]));
    }

    // --- Several criteria ------------------------------------------------

    /**
     * Points first (bigger is better), then time to break ties (smaller is
     * better). A and C tie on points; C is faster, so C goes before A. Each
     * criterion keeps its own order.
     */
    public function testMixedCriteriaEachKeepItsOwnOrder(): void
    {
        $carros = [
            'A' => [80, 62.0],
            'B' => [90, 70.0],
            'C' => [80, 58.0],
            'D' => [70, 40.0],
        ];

        $this->assertSame(['B', 'C', 'A', 'D'], self::seeding(['desc', 'asc'], $carros));
    }

    /** The same the other way round: time first, then points. */
    public function testMixedCriteriaAscThenDesc(): void
    {
        $carros = [
            'A' => [60.0, 10],
            'B' => [55.0, 5],
            'C' => [60.0, 30],
        ];

        $this->assertSame(['B', 'C', 'A'], self::seeding(['asc', 'desc'], $carros));
    }

    /** The second criterion only matters when the first ties. */
    public function testALaterCriterionNeverOverridesAnEarlierOne(): void
    {
        // A wins the first criterion by a little and loses the second by a lot.
        $this->assertSame(['A', 'B'], self::seeding(['desc', 'desc'], ['A' => [81, 0], 'B' => [80, 1000]]));
    }

    /** Tied on the first two criteria: the third decides. */
    public function testTiesFallThroughToTheNextCriterion(): void
    {
        $carros = [
            'A' => [80, 60.0, 3],
            'B' => [80, 60.0, 9],
        ];

        $this->assertSame(['B', 'A'], self::seeding(['desc', 'asc', 'desc'], $carros));
    }

    /** Tied on every criterion: they keep the order the page found them in. */
    public function testATieOnEveryCriterionKeepsTheOriginalOrder(): void
    {
        $this->assertSame(['A', 'B', 'C'], self::seeding(['desc', 'asc'], ['A' => [80, 60], 'B' => [80, 60], 'C' => [80, 60]]));
        $this->assertSame(0, (new CriteriosClassificacao(['desc', 'asc']))->compare([80, 60], [80, 60]));
    }

    // --- What the comparator returns -------------------------------------

    /**
     * usort() needs a number: below zero (A first), zero (tie), above zero
     * (B first). The old code returned true/false, and false means "tie".
     */
    public function testCompareReturnsMinusOneZeroOrOne(): void
    {
        $asc = new CriteriosClassificacao(['asc']);
        $this->assertSame(-1, $asc->compare([1], [2]));
        $this->assertSame(1, $asc->compare([2], [1]));
        $this->assertSame(0, $asc->compare([2], [2]));

        $desc = new CriteriosClassificacao(['desc']);
        $this->assertSame(1, $desc->compare([1], [2]));
        $this->assertSame(-1, $desc->compare([2], [1]));
    }

    // --- Missing scores --------------------------------------------------

    /**
     * A formula that fails gives null. That car goes last on the criterion
     * in either order: with "asc", null would otherwise count as the
     * smallest and win.
     */
    public function testANullScoreIsLastInEitherOrder(): void
    {
        $carros = ['A' => [null], 'B' => [60], 'C' => [50]];

        $this->assertSame(['C', 'B', 'A'], self::seeding(['asc'], $carros));
        $this->assertSame(['B', 'C', 'A'], self::seeding(['desc'], $carros));
    }

    /** Two cars without a score tie on that criterion, and the next one decides. */
    public function testTwoNullScoresTie(): void
    {
        $this->assertSame(['B', 'A'], self::seeding(['asc', 'desc'], ['A' => [null, 1], 'B' => [null, 2]]));
    }
}
