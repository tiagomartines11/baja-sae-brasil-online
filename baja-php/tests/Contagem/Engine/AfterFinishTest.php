<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * What happens to passes after race control ends the race (setting
 * `apos_encerramento`).
 *
 * When the flag falls every car is somewhere on the track and crosses the
 * line once more. Counters keep typing, and a car may even come round a
 * second time on its way to the paddock.
 *
 * - "proxima_passagem" (the default): each car's first official pass after
 *   the finish counts; anything later is left out. Nobody has to check
 *   whether a car crossed twice.
 * - "nenhuma": nothing after the finish counts.
 *
 * A pass that is left out is not anybody's mistake: it raises no flag, and
 * it stays on the car's timeline for the record.
 */
final class AfterFinishTest extends EngineTestCase
{
    /**
     * Car 7 crosses at 00:20 (pass zero) and 03:00 (lap 1). The race ends
     * at 05:00. Car 7 then crosses at 06:00 and again at 09:00.
     *
     * @return array{0: RaceScenario, 1: int, 2: int} the race and one entry id of each crossing after the finish
     */
    private function carCrossingTwiceAfterTheFinish(string $aposEncerramento): array
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->aposEncerramento = $aposEncerramento;
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->pass(7, '03:00');
        $race->finish('05:00');
        $firstAfter = $race->pass(7, '06:00');
        $secondAfter = $race->pass(7, '09:00');
        return [$race, $firstAfter[1], $secondAfter[1]];
    }

    /**
     * EXPECTED with "proxima_passagem": the crossing at 06:00 completes
     * lap 2. The one at 09:00 is left out: no lap, no flag, not even a
     * "pendente" row, but still present among the pass groups.
     */
    public function testByDefaultTheFirstPassAfterTheFinishCountsAndLaterOnesAreLeftOut(): void
    {
        [$race, $firstAfter, $secondAfter] = $this->carCrossingTwiceAfterTheFinish(Settings::APOS_PROXIMA_PASSAGEM);

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 2], $result);
        $this->assertSame(RaceScenario::ms('06:00'), $result->cars[7]->ultimaPassagemMs);
        $this->assertNoOpenFlags($result);

        $this->assertFalse($result->groupOfEntry($firstAfter)->ignoradoAposEncerramento);
        $this->assertSame(2, $result->groupOfEntry($firstAfter)->numeroVolta);
        $leftOut = $result->groupOfEntry($secondAfter);
        $this->assertNotNull($leftOut, 'The pass that is left out is still shown.');
        $this->assertTrue($leftOut->ignoradoAposEncerramento);
        $this->assertFalse($leftOut->contado);
        $this->assertNull($leftOut->numeroVolta);
        $this->assertSame([Lap::INICIAL, Lap::CONTADA, Lap::CONTADA], array_map(fn(Lap $lap) => $lap->estado, $result->laps));
    }

    /**
     * EXPECTED with "nenhuma": the race ended at 05:00 and so did the
     * counting. Car 7 keeps its one lap; both later crossings are left out.
     */
    public function testWithNenhumaNothingAfterTheFinishCounts(): void
    {
        [$race, $firstAfter, $secondAfter] = $this->carCrossingTwiceAfterTheFinish(Settings::APOS_NENHUMA);

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 1], $result);
        $this->assertSame(RaceScenario::ms('03:00'), $result->cars[7]->ultimaPassagemMs);
        $this->assertTrue($result->groupOfEntry($firstAfter)->ignoradoAposEncerramento);
        $this->assertTrue($result->groupOfEntry($secondAfter)->ignoradoAposEncerramento);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: car 7 is on the line at the very moment the race ends
     * (05:00.000), and crosses again at 08:00.
     *
     * EXPECTED: "after the finish" means strictly after. The pass at 05:00
     * belongs to the race, so the one at 08:00 is the car's one pass after
     * the finish and counts too.
     */
    public function testAPassAtTheExactMomentOfTheFinishBelongsToTheRace(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->finish('05:00');
        $race->pass(7, '05:00');
        $race->pass(7, '08:00');

        $this->assertLapCounts([7 => 2], $race->run('10:00'));
    }

    /**
     * SITUATION: after the finish at 05:00, counter 1 alone types car 7 at
     * 05:30 (a slip: the car is still half a lap away). The car really
     * crosses at 06:00 and everybody records it.
     *
     * WHAT WENT WRONG: one stray entry, at the worst possible moment.
     *
     * EXPECTED: an entry that does not count (one counter only) does not use
     * up the car's "one pass after the finish". The real pass at 06:00
     * counts. The stray entry is flagged like any other lone entry, with
     * the suggestion to void it (30 s before the next pass is no lap).
     */
    public function testALoneEntryAfterTheFinishDoesNotUseUpTheOnePassAllowed(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->pass(7, '03:00');
        $race->finish('05:00');
        $stray = $race->entry(1, 7, '05:30');
        $race->pass(7, '06:00');

        $result = $race->run('10:00');

        $this->assertLapCounts([7 => 2], $result);
        $this->assertSame(RaceScenario::ms('06:00'), $result->cars[7]->ultimaPassagemMs);
        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame($stray, $flag->sugestao['passagem_id']);

        // If a reviewer says the 05:30 entry was the real crossing, IT is
        // the car's pass after the finish, and 06:00 is the one left out.
        $race->confirm($stray);
        $confirmed = $race->run('10:00');
        $this->assertLapCounts([7 => 2], $confirmed);
        $this->assertSame(RaceScenario::ms('05:30'), $confirmed->cars[7]->ultimaPassagemMs);
        $this->assertNoOpenFlags($confirmed);
    }

    /**
     * SITUATION: car 7 has used its one pass after the finish (06:00). At
     * 09:00 only counter 2 types it, and the number 99, which is no team at
     * this event, is typed by everybody at 09:30.
     *
     * EXPECTED: no flags. Whatever is typed for a car after its last
     * counted pass is outside the race; a reviewer has nothing to decide.
     * (Car 99 is different: it never had a pass, so its entries are the
     * "first pass after the finish" of an unknown car and ARE flagged.)
     */
    public function testPassesLeftOutAfterTheFinishRaiseNoFlags(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([7]);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->finish('05:00');
        $race->pass(7, '06:00');
        $race->entry(2, 7, '09:00');

        $this->assertNoOpenFlags($race->run('10:00'));

        $race->pass(99, '09:30');
        $this->assertSingleOpenFlag($race->run('10:00'), Flag::CARRO_DESCONHECIDO);
    }

    /**
     * SITUATION: the stray-entry race again (lone entry at 05:30, real pass
     * at 06:00), and the pit log says car 7 was in the pit lane at 06:00.
     * The race is looked at in all four publish modes.
     *
     * WHY THIS TEST EXISTS: in "todas" mode the lone entry counts, so IT is
     * the car's pass after the finish and the real pass at 06:00 is left
     * out of the count. The pit-lane problem of the 06:00 pass must still
     * be shown: what a reviewer sees never depends on the publish mode.
     *
     * EXPECTED: the same two flags in every mode: the lone entry, and
     * "no_box" for the pass at 06:00.
     */
    public function testFlagsAfterTheFinishAreTheSameInEveryPublishMode(): void
    {
        $flagIdsByMode = [];
        foreach (Settings::MODOS_PUBLICACAO as $mode) {
            $race = RaceScenario::withCounters(3);
            $race->settings->modoPublicacao = $mode;
            $race->settings->fontePrincipalId = 1;
            $race->start('00:00');
            $race->pass(7, '00:20');
            $race->pass(7, '03:00');
            $race->finish('05:00');
            $race->entry(1, 7, '05:30');
            $race->pitIn(7, '05:50');
            $race->pass(7, '06:00');

            $flagIds = array_map(fn(Flag $flag) => $flag->id, $race->run('10:00')->openFlags());
            sort($flagIds);
            $flagIdsByMode[$mode] = $flagIds;
        }

        $this->assertCount(2, $flagIdsByMode[Settings::MODO_CONFIRMADAS]);
        $this->assertStringStartsWith(Flag::NO_BOX, $flagIdsByMode[Settings::MODO_CONFIRMADAS][0]);
        $this->assertStringStartsWith(Flag::SOLITARIA, $flagIdsByMode[Settings::MODO_CONFIRMADAS][1]);
        foreach (Settings::MODOS_PUBLICACAO as $mode) {
            $this->assertSame($flagIdsByMode[Settings::MODO_CONFIRMADAS], $flagIdsByMode[$mode], "Mode $mode.");
        }
    }
}
