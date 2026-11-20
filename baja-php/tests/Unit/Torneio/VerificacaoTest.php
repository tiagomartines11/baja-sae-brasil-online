<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Torneio;

use Baja\Model\Tournament;
use Baja\Torneio\Verificacao;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Verificacao, what scripts/check-tournament-configs.php reports
 * after the BAJAAPP-37 fixes. It only lists; a person decides.
 *
 * The two things it looks for (ConfrontoTest and CriteriosClassificacaoTest
 * explain brackets, seeds and the match for third place):
 *
 * 1. A seeding criterion with order "asc" (smaller is better) and a formula
 *    negated as the old help page advised (-$TEMPO). While "order" was
 *    ignored, the two cancelled out by luck; now they put the slowest first.
 *
 * 2. A match for third place holding a car that WON its semifinal, which
 *    the old single-race bug put there instead of the loser.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Torneio/VerificacaoTest.php
 */
final class VerificacaoTest extends TestCase
{
    // --- 1. Criteria inverted twice --------------------------------------

    public function testNegatedFormulas(): void
    {
        foreach (['-$TEMPO', ' - $TEMPO', '-($A+$B)', '0-$X', '-1*$X', '$X*-1', '$X * (-1)', '$X*-1.0'] as $f) {
            $this->assertTrue(Verificacao::formulaNegada($f), $f);
        }
    }

    public function testFormulasThatAreNotNegated(): void
    {
        foreach (['$TEMPO', '$A-$B', '$X*-2', '10-$X', 'random', '$X*1'] as $f) {
            $this->assertFalse(Verificacao::formulaNegada($f), $f);
        }
    }

    public function testOnlyAscWithANegatedFormulaIsReported(): void
    {
        $params = (object)['seeding' => (object)['classification' => [
            (object)['order' => 'desc', 'title' => 'Pontos', 'formula' => '-$P'],     // desc + negated: still right
            (object)['order' => 'asc', 'title' => 'Tempo', 'formula' => '-$TEMPO'],   // inverted twice
            (object)['order' => 'asc', 'title' => 'Tempo 2', 'formula' => '$TEMPO'],  // right now
            (object)['title' => 'Aleatório', 'formula' => 'random'],
        ]]];

        $this->assertSame([['criterio' => 1, 'title' => 'Tempo', 'formula' => '-$TEMPO']], Verificacao::criteriosInvertidos($params));
    }

    public function testAConfigWithoutSeedingReportsNothing(): void
    {
        $this->assertSame([], Verificacao::criteriosInvertidos((object)['inputs' => []]));
    }

    // --- 2. Third place given a semifinal's winner -----------------------

    private static function match(int $id, int $round, string $slot1, string $slot2, ?int $winner = null): Tournament
    {
        return (new Tournament())->setMatchId($id)->setRound((string)$round)
            ->setEquipe1Id($slot1)->setEquipe2Id($slot2)->setWinner($winner);
    }

    /**
     * Semifinals 125 (6 beat 8) and 126 (5 beat 7), single races. The bug
     * sent the winners, 6 and 5, to match 128, the one for third place.
     */
    public function testThirdPlaceWithTheSemifinalWinnersIsReported(): void
    {
        $matches = [
            self::match(125, 1, '6', '8', 6),
            self::match(126, 1, '5', '7', 5),
            self::match(127, 0, '6', '5'),
            self::match(128, -1, '6', '5', 5),
        ];

        $this->assertSame([
            ['match' => 128, 'slot' => 1, 'carro' => 6, 'semifinal' => 125, 'decidido' => true],
            ['match' => 128, 'slot' => 2, 'carro' => 5, 'semifinal' => 126, 'decidido' => true],
        ], Verificacao::terceiroLugarComVencedor($matches));
    }

    /** Only one semifinal decided so far: the other slot still says "los126". */
    public function testAnUndecidedThirdPlaceIsReportedAsSuch(): void
    {
        $matches = [
            self::match(125, 1, '6', '8', 6),
            self::match(126, 1, '5', '7'),
            self::match(128, -1, '6', 'los126'),
        ];

        $this->assertSame(
            [['match' => 128, 'slot' => 1, 'carro' => 6, 'semifinal' => 125, 'decidido' => false]],
            Verificacao::terceiroLugarComVencedor($matches)
        );
    }

    /** The right losers (8 and 7) in the match for third place: nothing to report. */
    public function testACorrectThirdPlaceIsNotReported(): void
    {
        $matches = [
            self::match(125, 1, '6', '8', 6),
            self::match(126, 1, '5', '7', 5),
            self::match(127, 0, '6', '5', 6),
            self::match(128, -1, '8', '7', 7),
        ];

        $this->assertSame([], Verificacao::terceiroLugarComVencedor($matches));
    }
}
