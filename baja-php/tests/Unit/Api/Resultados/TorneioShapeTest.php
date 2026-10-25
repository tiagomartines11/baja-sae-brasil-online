<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Api\Resultados;

use Baja\Api\Resultados\TorneioEndpoint;
use Baja\Model\Equipe;
use Baja\Model\Evento;
use Baja\Model\Prova;
use Baja\Model\Resultado;
use Baja\Model\Tournament;
use PHPUnit\Framework\TestCase;

/**
 * How torneio.php writes a tournament bracket.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Some provas are knockout tournaments ("Super Prime"): two cars race, the
 * winner goes on. The bracket is the table "tournament", one row per match.
 * A row has two slots, and a slot holds one of three things:
 *
 *   "6"        car 6
 *   "WO"       nobody: the car in the other slot goes through without
 *              racing (a "bye", from "walkover")
 *   "win97"    not known yet: whoever wins match 97 ("los125": whoever
 *              loses match 125, which is how the match for third place
 *              gets its cars)
 *
 * When a judge records a winner, the "win97" slots of later matches are
 * replaced by that car's number. Some matches are "best of 3": the row's
 * "dados" then has {"bestof": 3, "win1": 2, "win2": 0}.
 *
 * Rounds count down to the final: ... 2 quarterfinals, 1 semifinals,
 * 0 final, and -1 the match for third place.
 *
 * Seeds: before the bracket is made, the qualified cars are put in order
 * (by their dynamic scores, say) and that order is frozen in the prova's
 * params, status.classification. The first car is seed 1. The bracket is
 * built so that seeds 1 and 2 can only meet in the final.
 */
final class TorneioShapeTest extends TestCase
{
    private static function match(int $id, int $round, string $slot1, string $slot2, ?int $winner = null, ?array $dados = null): Tournament
    {
        return (new Tournament())->setProvaId('SPR')->setMatchId($id)->setround((string)$round)
            ->setEquipe1Id($slot1)->setEquipe2Id($slot2)->setWinner($winner)
            ->setDados($dados === null ? null : json_encode($dados));
    }

    private static function team(int $number): Equipe
    {
        return (new Equipe())->setEquipeId($number)->setEquipeCurto('Carro ' . $number)->setEscolaCurto('Escola ' . $number);
    }

    private static function prova(?array $status): Prova
    {
        return (new Prova())->setProvaId('SPR')->setParams((object)['type' => 'tournament', 'status' => $status === null ? null : (object)$status]);
    }

    /**
     * A bracket of four cars (5, 6, 7, 8), seeded 6, 5, 7, 8:
     * two semifinals, a final and a match for third place.
     *
     *   match 1, semifinal:   6 (seed 1) beat 8 (seed 4)
     *   match 2, semifinal:   5 (seed 2) against 7 (seed 3), best of 3, 1 to 1 so far
     *   match 3, final:       6 against the winner of match 2
     *   match 4, third place: 8 against the loser of match 2
     *
     * @param list<Tournament>|null $matches
     */
    private static function shape(?array $matches = null, ?array $status = ['frozen' => true, 'classification' => [6, 5, 7, 8]]): array
    {
        $matches ??= [
            self::match(1, 1, '6', '8', winner: 6),
            self::match(2, 1, '5', '7', dados: ['bestof' => 3, 'win1' => 1, 'win2' => 1]),
            self::match(3, 0, '6', 'win2', dados: ['old1' => 'win1']),
            self::match(4, -1, '8', 'los2', dados: ['old1' => 'los1']),
        ];
        return TorneioEndpoint::shape(
            (new Evento())->setEventoId('26BR'),
            (new Resultado())->setResultadoId('26BR_SPR')->setNome('Super Prime')->setInputs(['SPR']),
            $matches,
            [self::prova($status)],
            [self::team(5), self::team(6), self::team(7), self::team(8)]
        );
    }

    // --- Rounds ----------------------------------------------------------

    /** First round first, as the page draws it; the third-place match last. */
    public function testRoundsComeInThePagesOrderWithTheirNames(): void
    {
        $rounds = self::shape()['rounds'];

        $this->assertSame([1, 0, -1], array_column($rounds, 'round'));
        $this->assertSame(['Semifinais', 'Final', 'Disputa 3° lugar'], array_column($rounds, 'name'));
        $this->assertSame([[1, 2], [3], [4]], array_map(fn($r) => array_column($r['matches'], 'match_id'), $rounds));
    }

    // --- Slots -----------------------------------------------------------

    public function testATeamSlotSaysWhoAndItsSeed(): void
    {
        $match = self::shape()['rounds'][0]['matches'][0];

        $this->assertSame([
            'type' => 'team',
            'team' => ['number' => 6, 'short_name' => 'Carro 6', 'school_short' => 'Escola 6'],
            'seed' => 1,
            'wins' => null,
        ], $match['slots'][0]);
        $this->assertSame(4, $match['slots'][1]['seed']);
    }

    /** "win2" and "los2": not known until match 2 is decided. */
    public function testASlotNotKnownYetSaysWhichMatchFillsIt(): void
    {
        $rounds = self::shape()['rounds'];

        $this->assertSame(['type' => 'pending', 'from_match' => 2, 'takes' => 'winner'], $rounds[1]['matches'][0]['slots'][1]);
        $this->assertSame(['type' => 'pending', 'from_match' => 2, 'takes' => 'loser'], $rounds[2]['matches'][0]['slots'][1]);
    }

    public function testAWalkoverIsABye(): void
    {
        $json = self::shape([self::match(1, 1, '6', 'WO', winner: 6)]);

        $this->assertSame(['type' => 'bye'], $json['rounds'][0]['matches'][0]['slots'][1]);
        $this->assertSame(6, $json['rounds'][0]['matches'][0]['winner']);
    }

    /** Anything else (a car that is not a team of the event, say) is "unknown", not a guess. */
    public function testAnythingElseIsUnknown(): void
    {
        $slots = self::shape([self::match(1, 1, '99', '')])['rounds'][0]['matches'][0]['slots'];

        $this->assertSame([['type' => 'unknown'], ['type' => 'unknown']], $slots);
    }

    // --- Winner and best of N --------------------------------------------

    public function testAMatchSaysItsWinnerOnceDecided(): void
    {
        $matches = self::shape()['rounds'][0]['matches'];

        $this->assertSame(6, $matches[0]['winner']);
        $this->assertNull($matches[1]['winner']);
    }

    /** As on the page, a winner who is not one of the two cars is no winner. */
    public function testAWinnerWhoIsNotInTheMatchIsIgnored(): void
    {
        $this->assertNull(self::shape([self::match(1, 1, '6', '8', winner: 5)])['rounds'][0]['matches'][0]['winner']);
    }

    public function testABestOfThreeSaysHowManyEachHasWon(): void
    {
        $match = self::shape()['rounds'][0]['matches'][1];

        $this->assertSame(3, $match['best_of']);
        $this->assertSame([1, 1], array_column($match['slots'], 'wins'));
        // A single race: no best_of, no wins.
        $this->assertNull(self::shape()['rounds'][0]['matches'][0]['best_of']);
    }

    // --- Seeding ---------------------------------------------------------

    /** Before the classification is frozen there are no seeds. */
    public function testWithoutAFrozenClassificationThereAreNoSeeds(): void
    {
        foreach ([null, ['frozen' => false, 'classification' => false]] as $status) {
            $slots = self::shape(status: $status)['rounds'][0]['matches'][0]['slots'];
            $this->assertSame([null, null], array_column($slots, 'seed'));
        }
    }
}
