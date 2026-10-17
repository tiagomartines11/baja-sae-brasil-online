<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Entries that only one counter has: typing mistakes and stray entries.
 *
 * Two kinds of flag appear here:
 *
 * - "troca_provavel" (probable swap): one counter typed car X alone, and at
 *   that very moment the others recorded a car Y that this counter does not
 *   have. Most likely the counter saw Y and typed X.
 * - "solitaria" (lone entry): one counter typed a car alone and there is no
 *   such explanation.
 */
final class TypoAndLoneEntryTest extends EngineTestCase
{
    /**
     * SITUATION: car 41 crosses the line for the second time (everybody
     * recorded its first crossing, the pass zero). Counters 2 and 3 type 41.
     * Counter 1 swaps the digits and types 14. Car 14 exists but is nowhere
     * near.
     *
     * WHAT WENT WRONG: a classic typo by counter 1.
     *
     * EXPECTED:
     * - Car 41's lap counts anyway (two counters have it).
     * - One flag, "troca_provavel", suggesting that counter 1's entry was
     *   really car 41, and saying why in one Portuguese sentence.
     * - When the reviewer accepts the suggestion the flag disappears, and
     *   counter 1's own numbers are fixed for BOTH cars: one more pass for
     *   car 41, one fewer for car 14.
     */
    public function testSwappedDigitsAreRecognisedAndAcceptingTheSuggestionFixesBothCars(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([14, 41]);
        $race->start('00:00');
        $race->firstCrossing([41]);
        $race->pass(41, '03:10', [2, 3]);
        $typo = $race->entry(1, 14, '03:10');

        $before = $race->run('04:00');

        $this->assertLapCounts([41 => 1, 14 => 0], $before);
        $flag = $this->assertSingleOpenFlag($before, Flag::TROCA_PROVAVEL);
        $this->assertSame(['tipo' => Decision::REATRIBUIR, 'passagem_id' => $typo, 'equipe_numero' => 41], $flag->sugestao);
        $this->assertSame(1, $flag->fonteId, 'The flag is about counter 1.');
        $this->assertStringContainsString('Contagem 1', $flag->motivo);
        $this->assertStringContainsString('dígitos trocados', $flag->motivo);
        $this->assertSame(1, $this->sourceCount($before, 41, 1), 'Before the fix counter 1 has only the first crossing of car 41...');
        $this->assertSame(1, $this->sourceCount($before, 14, 1), '...and one for car 14, which never passed.');

        $race->accept($flag);
        $after = $race->run('04:00');

        $this->assertNoOpenFlags($after);
        $this->assertLapCounts([41 => 1, 14 => 0], $after);
        $this->assertSame(2, $this->sourceCount($after, 41, 1));
        $this->assertSame(0, $this->sourceCount($after, 14, 1));
        $this->assertSame(1, $after->sourceStats[1]->trocas, 'The typo is remembered in counter 1\'s statistics.');
    }

    /**
     * SITUATION: two cars cross within the same few seconds: 41 and 7.
     * Counter 1 has neither, but typed a lone 47 two seconds after car 7 and
     * six seconds after car 41.
     *
     * EXPECTED: the suggestion points at the car closest in time to the
     * stray entry (car 7), because that is the car the counter was most
     * likely looking at.
     */
    public function testTheSuggestedCarIsTheOneClosestInTime(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(41, '03:00', [2, 3]);
        $race->pass(7, '03:04', [2, 3]);
        $race->entry(1, 47, '03:06');

        $result = $race->run('04:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::TROCA_PROVAVEL);
        $this->assertSame(7, $flag->sugestao['equipe_numero']);
    }

    /**
     * SITUATION: a normal race is going on, ten cars lapping every three
     * minutes. Car 5 crossed the line at 12:28 and everybody recorded it.
     * Forty-two seconds later counter 2 types "5" again, alone. Nothing else
     * explains it: no car is missing from counter 2 around that moment.
     *
     * WHAT WENT WRONG: counter 2 typed a pass that cannot have happened. A
     * car that laps in three minutes does not come back after 42 seconds.
     *
     * EXPECTED: a "solitaria" flag whose suggestion is to void the entry,
     * with the reason spelled out. Accepting it empties the queue.
     */
    public function testALoneEntryThatWouldCreateAnImpossiblyShortLapIsSuggestedForVoiding(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        // Car 5 is the fifth car of the field: it passes at 03:28, 06:28,
        // 09:28, 12:28, 15:28 and 18:28.
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        $stray = $race->entry(2, 5, '13:10');

        $result = $race->run('20:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame(['tipo' => Decision::ANULAR, 'passagem_id' => $stray], $flag->sugestao);
        $this->assertStringContainsString('42 s', $flag->motivo);
        $this->assertSame(5, $result->lapCount(5), 'Six crossings are five laps; the stray entry never counted.');

        $race->accept($flag);
        $this->assertNoOpenFlags($race->run('20:00'));
    }

    /**
     * SITUATION: the same steady race. This time car 5's sixth crossing
     * (at 18:28, exactly one lap after the previous one) is recorded by
     * counter 2 only. Counters 1 and 3 are online and have nothing.
     *
     * WHAT WENT WRONG: nobody can tell. The timing is perfectly plausible
     * for a real lap, so maybe two counters missed the car; or maybe
     * counter 2 invented it.
     *
     * EXPECTED: a "solitaria" flag with NO default suggestion. The engine
     * does not guess; the reviewer chooses. Until then the lap is not
     * counted.
     */
    public function testALoneEntryWithAPlausibleLapTimeIsFlaggedWithoutASuggestion(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 6, 7, 8, 9, 10], 180, 6);
        foreach (['03:28', '06:28', '09:28', '12:28', '15:28'] as $crossing) {
            $race->pass(5, $crossing);
        }
        $race->entry(2, 5, '18:28');

        $result = $race->run('20:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertNull($flag->sugestao, 'A plausible lone entry gets no default action.');
        $this->assertSame(4, $result->lapCount(5), 'Five agreed crossings are four laps; the lone one waits for a reviewer.');
        // The car's previous official pass, so the review screen can show
        // the lap the entry would make (it is three minutes back).
        $this->assertSame(RaceScenario::ms('15:28'), $flag->passagemAnterior['ts_ms']);
        $this->assertSame($result->groupOfEntry($flag->passagemAnterior['passagem_id'])->tsMs, RaceScenario::ms('15:28'));
    }

    /**
     * SITUATION: counter 1 types "77". There is no car 77 at this event and
     * nothing else happened around that moment that would explain it.
     *
     * EXPECTED: a "carro_desconhecido" flag (unknown car), with no
     * suggestion: the engine cannot know which car was meant.
     */
    public function testANumberThatIsNoTeamAtTheEventIsFlaggedAsUnknownCar(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1, 2, 3]);
        $race->start('00:00');
        $race->entry(1, 77, '03:00');

        $result = $race->run('04:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::CARRO_DESCONHECIDO);
        $this->assertNull($flag->sugestao);
        $this->assertStringContainsString('77', $flag->motivo);
    }
}
