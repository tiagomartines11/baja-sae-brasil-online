<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\ResultsCheck;

use Baja\ResultsCheck\FixProposal;
use Baja\ResultsCheck\ResultadoCheck;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * When the check proposes a fix for a resultado, and when it does not.
 *
 * A fix is only proposed when it is the mechanical "+" to "." rewrite
 * (FormulaFixTest) AND the rewritten config has been run for every team
 * without that formula raising an error. Anything else is left to a person.
 *
 * Each test writes a resultado's config and some teams (as in
 * ResultadoCheckTest), runs the check, and asks FixProposal::for() for a
 * fix. It gives null when there is nothing to propose; otherwise:
 *
 *   changes      the formulas it rewrites (where, old, new)
 *   notVerified  rewrites it tried but dropped, with the reason
 *   colunas      the whole config with the changes, as it would be saved
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/ResultsCheck/FixProposalTest.php
 */
final class FixProposalTest extends TestCase
{
    public function testTheMechanicalCaseGetsAFixThatWorks(): void
    {
        $teams = [Fixtures::team(1, ['PTS' => 70]), Fixtures::team(2, ['PTS' => 69.63])];
        $fix = self::propose('{"pos": "Pontos", "colunas": [
            {"header": "Pos", "val": "Pos"},
            {"header": "Pontos", "val": "\'<strong>\'+ROUND($PTS,2)+\'<strong>\'"}
        ]}', $teams);

        $this->assertSame([[
            'where' => 'column "Pontos" val',
            'old' => "'<strong>'+ROUND(\$PTS,2)+'<strong>'",
            'new' => "'<strong>'.ROUND(\$PTS,2).'</strong>'",
        ]], $fix->changes);
        $this->assertSame([], $fix->notVerified);

        // The new config is the old one with that one formula replaced...
        $this->assertSame("'<strong>'.ROUND(\$PTS,2).'</strong>'", $fix->colunas->colunas[1]->val);
        $this->assertSame('Pontos', $fix->colunas->pos);
        // ...and with it, the check finds nothing wrong any more: the cells
        // have values, so the teams are ranked again.
        $this->assertSame([], ResultadoCheck::run($fix->colunas, $teams)['findings']);
    }

    /** The config given is not changed: the fix is made on a copy. */
    public function testTheOriginalConfigIsNotChanged(): void
    {
        $colunas = json_decode('{"colunas": [{"header": "X", "val": "\'a\' + $P"}]}');
        $teams = [Fixtures::team(1, ['P' => 1])];

        FixProposal::for($colunas, $teams, ResultadoCheck::run($colunas, $teams)['findings']);

        $this->assertSame("'a' + \$P", $colunas->colunas[0]->val);
    }

    /**
     * The rewrite does not always make the formula work. Here (25SE_SKP in
     * the seed) a number is still added to a bracket that gives text, which
     * is not the mechanical case. Nothing is proposed; the report says what
     * was tried and why it was dropped.
     */
    public function testARewriteThatStillFailsIsNotProposed(): void
    {
        $fix = self::propose('{"colunas": [
            {"header": "Tempo", "val": "ROUND($T,2) + \' / \' + ROUND($A,2) + ($C ? \' (\'+$C+\')\' : \'\')"}
        ]}', [Fixtures::team(1, ['T' => 10.5, 'A' => 11.25, 'C' => 2])]);

        $this->assertSame([], $fix->changes, 'Nothing to write to the database.');
        $this->assertCount(1, $fix->notVerified);
        $this->assertSame('TypeError: Unsupported operand types: float + string', $fix->notVerified[0]['error']);
    }

    /**
     * A column's value is read by the columns after it (docs/results-rules.md,
     * R1). Before the fix, "Pontos" was empty and "Total" = empty + 1 = 1.
     * After it, "Pontos" is the text "<b>5</b>", and "Total" would then fail:
     * text + 1. The fix would break another column, so it is not proposed.
     */
    public function testAFixThatBreaksALaterColumnIsNotProposed(): void
    {
        $fix = self::propose('{"colunas": [
            {"header": "Pontos", "val": "\'<b>\' + $P + \'</b>\'"},
            {"header": "Total", "val": "$Pontos + 1"}
        ]}', [Fixtures::team(1, ['P' => 5])]);

        $this->assertSame([], $fix->changes);
        $this->assertSame(
            'column "Total" val would then fail: TypeError: Unsupported operand types: string + int',
            $fix->notVerified[0]['error'],
        );
    }

    /** Other errors (here a formula cut short) are not the mechanical case: no proposal at all. */
    public function testOtherErrorsGetNoProposal(): void
    {
        $this->assertNull(self::propose('{"colunas": [{"header": "X", "val": "ROUND($P, 2)+"}]}', [Fixtures::team(1, ['P' => 1])]));
    }

    /** A resultado with nothing wrong gets no proposal. */
    public function testNothingWrongGetsNoProposal(): void
    {
        $this->assertNull(self::propose('{"colunas": [{"header": "X", "val": "$P + 1"}]}', [Fixtures::team(1, ['P' => 1])]));
    }

    /**
     * Two broken formulas in one resultado: both are rewritten, in one
     * proposal (one UPDATE per resultado).
     */
    public function testAllFixesOfAResultadoGoTogether(): void
    {
        $fix = self::propose('{"colunas": [
            {"header": "A", "val": "\'<b>\' + $P"},
            {"header": "B", "val": ["$P + \' pts\'", "$P"]}
        ]}', [Fixtures::team(1, ['P' => 1])]);

        $this->assertSame(['column "A" val', 'column "B" val[0]'], array_column($fix->changes, 'where'));
        $this->assertSame("\$P . ' pts'", $fix->colunas->colunas[1]->val[0]);
    }

    /** @param list<\Baja\Results\TeamInput> $teams */
    private static function propose(string $json, array $teams): ?FixProposal
    {
        $colunas = json_decode($json);
        self::assertIsObject($colunas, 'The test config is valid JSON.');
        return FixProposal::for($colunas, $teams, ResultadoCheck::run($colunas, $teams)['findings']);
    }
}
