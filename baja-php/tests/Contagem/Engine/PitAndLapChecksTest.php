<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Engine\Data\Source;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Flags that are about a counted lap looking wrong, rather than about
 * counters disagreeing: a pass while the car was in the pit lane, a lap far
 * too short, and paper totals that do not match.
 *
 * None of these has a suggested action. The engine cannot know which record
 * is wrong; it only points.
 */
final class PitAndLapChecksTest extends EngineTestCase
{
    /**
     * SITUATION: car 6 made its first crossing at minute 3. The pit log
     * says it entered the pit lane at minute 5 and left at minute 9. At
     * minute 7 all three counters record car 6 at the counting line.
     *
     * WHAT WENT WRONG: one of the two records is wrong. Either the counters
     * mistook another car for car 6, or the pit log has the wrong car or
     * time. (The pass used to count, with a flag. Two counters typing the
     * number of a car that is standing in the pit lane then gave that car
     * a lap.)
     *
     * EXPECTED: the pass is HELD, reason "no_box": no lap, a pending row, a
     * "no_box" flag with no suggestion. Three ways out, each tested here:
     * - the reviewer confirms the pass ("contar esta passagem"): it counts;
     * - the reviewer records an exit just before the pass: the car is
     *   outside then, and the pass counts by the ordinary rules;
     * - the reviewer discards the pass: nothing counts, nothing is left.
     * In "todas" mode every entry counts, so nothing is held there, and
     * the flag says the car may have a lap too many.
     */
    public function testAPassWhileTheCarIsLoggedInThePitLaneIsHeld(): void
    {
        $build = function (): array {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->pass(6, '03:00');
            $race->pitIn(6, '05:00');
            $entries = $race->pass(6, '07:00');
            $race->pitOut(6, '09:00');
            return [$race, $entries];
        };

        [$race, $entries] = $build();
        $result = $race->run('10:00');
        $flag = $this->assertSingleOpenFlag($result, Flag::NO_BOX);
        $this->assertNull($flag->sugestao);
        $this->assertSame([6], $flag->carros);
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);
        $this->assertLapCounts([6 => 0], $result);
        $this->assertSame(PassGroup::RETIDA_NO_BOX, $result->groupOfEntry($entries[1])->motivoRetencao);
        $this->assertSame(Lap::PENDENTE, $result->laps[1]->estado);

        $race->confirm($entries[1]);
        $confirmed = $race->run('10:00');
        $this->assertLapCounts([6 => 1], $confirmed);
        $this->assertNoOpenFlags($confirmed);

        [$race, $entries] = $build();
        $race->inferredExit('box', 6, RaceScenario::ms('06:59'));
        $withExit = $race->run('10:00');
        $this->assertLapCounts([6 => 1], $withExit);
        $this->assertNoOpenFlags($withExit);
        $this->assertFalse($withExit->timeline->isInPitAt(6, RaceScenario::ms('07:00')));
        $this->assertTrue($withExit->timeline->isInPitAt(6, RaceScenario::ms('06:58.999')));

        [$race, $entries] = $build();
        foreach ($entries as $entryId) {
            $race->void($entryId);
        }
        $discarded = $race->run('10:00');
        $this->assertLapCounts([6 => 0], $discarded);
        $this->assertNoOpenFlags($discarded);

        [$race] = $build();
        $race->settings->modoPublicacao = Settings::MODO_TODAS;
        $everything = $race->run('10:00');
        $this->assertLapCounts([6 => 1], $everything);
        $this->assertSame(Flag::DUVIDA_A_MAIS, $this->assertSingleOpenFlag($everything, Flag::NO_BOX)->contagemEmDuvida);
    }

    /**
     * SITUATION: "fonte_principal" mode with counter 1 as the primary. Car
     * 6 is logged into the pit lane at minute 5; counter 1 alone records it
     * at the line at minute 7.
     *
     * EXPECTED: whatever the primary has counts in this mode, and the pit
     * hold applies to it too: the pass is held, no lap.
     */
    public function testInFontePrincipalModeThePrimarysPassInsideThePitLaneIsHeldToo(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->start('00:00');
        $race->pass(6, '03:00');
        $outside = $race->entry(1, 6, '04:00');
        $race->pitIn(6, '05:00');
        $inside = $race->entry(1, 6, '07:00');

        $result = $race->run('10:00');

        $this->assertLapCounts([6 => 1], $result);
        $this->assertTrue($result->groupOfEntry($outside)->contado);
        $this->assertTrue($result->groupOfEntry($inside)->retida);
        $this->assertSame(PassGroup::RETIDA_NO_BOX, $result->groupOfEntry($inside)->motivoRetencao);
    }

    /**
     * SITUATION: car 6 is logged into the pit lane at minute 5 and nobody
     * ever logs it out. Only counter 1 records it at the line at minute 7.
     *
     * EXPECTED: one counter against the pit log: the flag is "no_box",
     * names the counter, and has no suggestion.
     */
    public function testAPassOnlyOneCounterSawWhileInThePitLaneIsFlaggedWithoutASuggestion(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->pitIn(6, '05:00');
        $race->entry(1, 6, '07:00');

        $flag = $this->assertSingleOpenFlag($race->run('10:00'), Flag::NO_BOX);

        $this->assertNull($flag->sugestao);
        $this->assertSame(1, $flag->fonteId);
        $this->assertStringContainsString('Contagem 1', $flag->motivo);
    }

    /**
     * SITUATION: the pit log shows car 6 in the pit lane from minute 5 to
     * minute 9, and the counters record it at minutes 3 and 12 only.
     *
     * NOTHING WENT WRONG: a normal pit stop.
     *
     * EXPECTED: no flag. The long lap that contains the stop is counted but
     * marked as interrupted, so it is not used to judge the pace of the race
     * and cannot be a best lap.
     */
    public function testAnOrdinaryPitStopRaisesNoFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->pitIn(6, '05:00');
        $race->pitOut(6, '09:00');
        $race->pass(6, '12:00');

        $result = $race->run('13:00');

        $this->assertNoOpenFlags($result);
        $this->assertTrue($result->laps[1]->interrompida);
    }

    /**
     * SITUATION: a steady race, ten cars lapping every three minutes (six
     * crossings each, so five laps). All three counters record car 5 at
     * 12:28 (correct) and all three record it again at 13:10. In reality that second car was number 15, with a
     * muddy number plate.
     *
     * WHAT WENT WRONG: every counter read the same wrong number, so they
     * agree with each other. (The pass used to count, with a flag.)
     *
     * EXPECTED: agreement alone is not enough. Car 5 has three laps of 180
     * s behind it, so a pass 42 seconds after its previous one is not
     * believable: it is HELD, not counted, and a "passagem_proxima_demais"
     * flag says so. No suggestion; the reviewer decides which entries to
     * reassign. Car 5 keeps its five real laps.
     */
    public function testAPassFarTooSoonAfterTheCarsPreviousOneIsHeldHoweverManyCountersAgree(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        $race->pass(5, '13:10');

        $result = $race->run('20:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::PASSAGEM_PROXIMA_DEMAIS);
        $this->assertNull($flag->sugestao);
        $this->assertSame([5], $flag->carros);
        $this->assertStringContainsString('a 42 s da passagem anterior', $flag->motivo);
        $this->assertStringContainsString('é de 90 s', $flag->motivo);
        $this->assertSame(5, $result->lapCount(5), 'Five real laps; the held pass counts only if a reviewer says so.');
    }

    /**
     * SITUATION: the same held pass, but the reviewer looked at it and
     * confirmed it is real (the car took a shortcut and will be penalised
     * elsewhere, say).
     *
     * EXPECTED: confirming the pass makes it count (six laps) and silences
     * the flag for good: no "too short a lap" flag comes back for a pass a
     * person vouched for.
     */
    public function testConfirmingAHeldPassCountsItAndSilencesItsFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        $entries = $race->pass(5, '13:10');
        $race->confirm($entries[1]);

        $result = $race->run('20:00');

        $this->assertNoOpenFlags($result);
        $this->assertSame(6, $result->lapCount(5));
    }

    /**
     * SITUATION: after the race somebody types in the totals of a paper
     * count: car 1 with 3 laps, car 2 with 2 laps. They go into a source of
     * type "totais". The official count has 2 laps for each car (three
     * crossings: the first one and two more).
     *
     * EXPECTED: a "divergencia_total" flag for car 1 (3 on paper, 2
     * official) and none for car 2. Imported totals never change the
     * official count; they are only compared with it.
     */
    public function testImportedPaperTotalsThatDisagreeWithTheOfficialCountAreFlagged(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->addSource(9, 'Planilha de papel', Source::TIPO_TOTAIS);
        $race->start('00:00');
        $race->firstCrossing([1, 2]);
        foreach ([1, 2] as $car) {
            $race->pass($car, '03:0' . $car);
            $race->pass($car, '06:0' . $car);
        }
        $race->importTotals(9, [1 => 3, 2 => 2]);

        $result = $race->run('10:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::DIVERGENCIA_TOTAL);
        $this->assertSame([1], $flag->carros);
        $this->assertSame(9, $flag->fonteId);
        $this->assertStringContainsString('Planilha de papel', $flag->motivo);
        $this->assertLapCounts([1 => 2, 2 => 2], $result);
    }

    /**
     * SITUATION: two flags are open. One concerns the leader of the race,
     * the other a car at the back.
     *
     * EXPECTED: the leader's flag is first in the queue. A mistake near the
     * top of the standings changes the podium; one at the back rarely
     * changes anything.
     */
    public function testFlagsAboutCarsNearTheTopOfTheStandingsComeFirst(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([1, 2]);
        // Car 1 leads with three laps; car 2 has one.
        $race->pass(1, '03:00');
        $race->pass(1, '06:00');
        $race->pass(1, '09:00');
        $race->pass(2, '04:00');
        // A lone entry for the backmarker first, then one for the leader.
        $race->entry(3, 2, '07:00');
        $race->entry(3, 1, '12:00');

        $flags = $race->run('13:00')->openFlags();

        $this->assertCount(2, $flags);
        $this->assertSame([1], $flags[0]->carros, 'The leader\'s flag is on top although it is the newer one.');
        $this->assertSame(1, $flags[0]->prioridade, 'Its priority is the leader\'s position.');
        $this->assertSame([2], $flags[1]->carros);
        $this->assertSame(2, $flags[1]->prioridade);
    }

    /**
     * SITUATION: car 1 has three laps. Cars 2 and 3 have two laps each;
     * car 3 completed its second lap at 06:10, car 2 at 06:40. Car 4 has
     * crossed once and has no lap.
     *
     * EXPECTED: the standings: more laps first; with the same number of
     * laps, whoever completed them EARLIER is ahead (car 3 before car 2).
     * A car with no laps is listed last and has no position.
     */
    public function testTheStandingsAreByLapsThenByWhoGotThereFirst(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([1, 2, 3, 4]);
        foreach (['03:00', '06:00', '09:00'] as $at) {
            $race->pass(1, $at);
        }
        $race->pass(2, '03:40');
        $race->pass(2, '06:40');
        $race->pass(3, '03:50');
        $race->pass(3, '06:10');

        $result = $race->run('10:00');

        $this->assertSame([1, 3, 2, 4], array_keys($result->cars));
        $this->assertSame([1 => 1, 3 => 2, 2 => 3, 4 => null], array_map(fn($car) => $car->posicao, $result->cars));
    }

    /**
     * SITUATION: three things happened to car 9, in this order: a double
     * tap at 03:02 (voided automatically), a lone entry at 06:00 (an open
     * flag), and another double tap at 09:02 (voided automatically).
     *
     * EXPECTED: what still needs a person comes first. The open flag is on
     * top; the two auto-resolved ones follow, for the record, the most
     * recent first.
     */
    public function testOpenFlagsComeBeforeTheOnesTheEngineResolvedItself(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $race->entry(1, 9, '03:02');
        $race->entry(2, 9, '06:00');
        $race->pass(9, '09:00');
        $race->entry(1, 9, '09:02');

        $flags = $race->run('10:00')->flags;

        $this->assertSame([false, true, true], array_map(fn(Flag $flag) => $flag->autoResolvido, $flags));
        $this->assertSame(Flag::SOLITARIA, $flags[0]->tipo);
        $this->assertSame(RaceScenario::ms('09:02'), $flags[1]->tsMs, 'The newer auto-resolved flag first.');
        $this->assertSame(RaceScenario::ms('03:02'), $flags[2]->tsMs);
    }
}
