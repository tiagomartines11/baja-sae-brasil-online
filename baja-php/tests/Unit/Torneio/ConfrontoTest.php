<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Torneio;

use Baja\Torneio\Confronto;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Confronto: who won and who lost a match of a tournament, and
 * which car the public bracket marks as the winner (BAJAAPP-37).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Some provas are knockout tournaments. The cars race two at a time; the
 * winner goes on to the next round, the loser is out. Drawn as a tree, that
 * is a "bracket":
 *
 *     semifinal A:  car 6 against car 8  ──┐
 *                                          ├── final: winner of A against winner of B
 *     semifinal B:  car 5 against car 7  ──┘
 *
 *     match for third place: loser of A against loser of B
 *
 * The match for third place is how the podium gets its bronze: the two cars
 * that lost a semifinal race each other.
 *
 * Each match is a row of the table "tournament" with two "slots". A slot
 * holds a car number ("6"), "WO" (nobody there: the other car goes through
 * without racing) or a placeholder for a car not known yet: "win97" is
 * whoever WINS match 97, "los97" whoever LOSES it. When a judge records a
 * winner, the page writes the winner's number into the "win" slots and the
 * loser's into the "los" slots.
 *
 * A match is a single race, or "best of N": the cars race up to N times and
 * the first to win more than half of them wins the match. Best of 3: two
 * wins. The race wins so far are kept in the row as win1 (slot 1) and win2
 * (slot 2).
 *
 * THE BUG
 *
 * For a single race, the page worked out the "loser" as the winner again,
 * so the match for third place got a semifinal's WINNER, a car that was
 * also in the final. Best-of matches were right. Both now use
 * Confronto::outraEquipe(): the loser is the slot that is not the winner.
 *
 * The page juiz/tournament_entry.php calls registrarVitoria() with the two
 * slots and the car the judge clicked; that is what these tests call too.
 *
 * Run only this file with:
 *     vendor/bin/phpunit tests/Unit/Torneio/ConfrontoTest.php
 */
final class ConfrontoTest extends TestCase
{
    // --- A single race ---------------------------------------------------

    /** The judge clicks car 6 (slot 1): 6 wins, 8 loses. */
    public function testSingleRaceWonBySlot1(): void
    {
        $this->assertSame(['6', '8'], Confronto::registrarVitoria('6', '8', null, '6'));
    }

    /** The judge clicks car 8 (slot 2): 8 wins, 6 loses. */
    public function testSingleRaceWonBySlot2(): void
    {
        $this->assertSame(['8', '6'], Confronto::registrarVitoria('6', '8', null, '8'));
    }

    /**
     * The bug itself: the loser was the winner again, so the match for third
     * place got the car that had just won. The loser must never be the winner.
     */
    public function testTheLoserIsNeverTheWinner(): void
    {
        foreach (['6', '8'] as $clicado) {
            [$vencedor, $perdedor] = Confronto::registrarVitoria('6', '8', null, $clicado);
            $this->assertNotSame($vencedor, $perdedor);
        }
    }

    /** A click on a car that is not in the match decides nothing. */
    public function testSingleRaceIgnoresACarNotInTheMatch(): void
    {
        $this->assertNull(Confronto::registrarVitoria('6', '8', null, '5'));
    }

    /**
     * Rows of single races often have dados "null" or {"old1": "win97"}
     * (which match filled a slot): without "bestof" it is still one race.
     */
    public function testDadosWithoutBestofIsASingleRace(): void
    {
        $this->assertSame(['8', '6'], Confronto::registrarVitoria('6', '8', (object)['old1' => 'win97'], '8'));
    }

    // --- Best of 3 -------------------------------------------------------

    private static function melhorDe3(int $win1 = 0, int $win2 = 0): object
    {
        return (object)['bestof' => 3, 'win1' => $win1, 'win2' => $win2];
    }

    /** One race won is not the match: nobody is decided, the win is counted. */
    public function testBestOf3OneWinDecidesNothing(): void
    {
        $dados = self::melhorDe3();

        $this->assertNull(Confronto::registrarVitoria('6', '8', $dados, '6'));
        $this->assertSame(1, $dados->win1);
        $this->assertSame(0, $dados->win2);
    }

    /** Slot 1 wins its second race: slot 1 wins the match, slot 2 loses. */
    public function testBestOf3WonBySlot1(): void
    {
        $dados = self::melhorDe3(1, 1);

        $this->assertSame(['6', '8'], Confronto::registrarVitoria('6', '8', $dados, '6'));
        $this->assertSame(2, $dados->win1);
    }

    /** Slot 2 wins its second race: slot 2 wins the match, slot 1 loses. */
    public function testBestOf3WonBySlot2(): void
    {
        $dados = self::melhorDe3(0, 1);

        $this->assertSame(['8', '6'], Confronto::registrarVitoria('6', '8', $dados, '8'));
        $this->assertSame(2, $dados->win2);
    }

    /** A config may leave win1/win2 out; they start at zero. */
    public function testBestOfWithoutCountsStartsAtZero(): void
    {
        $dados = (object)['bestof' => 1];

        $this->assertSame(['8', '6'], Confronto::registrarVitoria('6', '8', $dados, '8'));
        $this->assertSame(1, $dados->win2);
    }

    // --- outraEquipe -----------------------------------------------------

    public function testOutraEquipeIsTheOtherSlot(): void
    {
        $this->assertSame('8', Confronto::outraEquipe('6', '8', '6'));
        $this->assertSame('6', Confronto::outraEquipe('6', '8', '8'));
        $this->assertNull(Confronto::outraEquipe('6', '8', '5'));
    }

    // --- The winner's mark on the public bracket -------------------------

    /**
     * resultados/torneio.php draws each match as two rows, one per slot, and
     * marks the winner's row in green. The second row used to ask whether
     * the FIRST slot held a car number; so when slot 1 was "WO" (nobody),
     * the car in slot 2 that went through was not marked.
     */
    public function testTheWinnerInSlot2IsMarkedWhenSlot1IsAWalkover(): void
    {
        $this->assertTrue(Confronto::venceu(8, '8'));    // slot 2: car 8, the winner
        $this->assertFalse(Confronto::venceu(8, 'WO'));  // slot 1: nobody
    }

    public function testEachSlotIsMarkedOnlyWhenItsCarWon(): void
    {
        // Car 6 (slot 1) won against car 8 (slot 2).
        $this->assertTrue(Confronto::venceu(6, '6'));
        $this->assertFalse(Confronto::venceu(6, '8'));
        // Car 8 (slot 2) won.
        $this->assertFalse(Confronto::venceu(8, '6'));
        $this->assertTrue(Confronto::venceu(8, '8'));
    }

    /** Undecided, or a slot that is not a car yet ("win97"): nobody is marked. */
    public function testNothingIsMarkedBeforeTheMatchIsDecided(): void
    {
        $this->assertFalse(Confronto::venceu(null, '6'));
        $this->assertFalse(Confronto::venceu(null, 'win97'));
        $this->assertFalse(Confronto::venceu(null, null));
    }
}
