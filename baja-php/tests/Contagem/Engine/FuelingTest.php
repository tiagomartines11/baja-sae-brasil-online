<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\CarStatus;
use Baja\Contagem\Engine\Data\CarState;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The fueling station ("abastecimento").
 *
 * It is a second area beside the pit lane ("box"), logged the same way:
 * somebody records each car going in and each car coming out. For the
 * engine it means exactly what the pit lane means, with a status of its
 * own:
 *
 * - while a car is logged inside, its status is "abastecendo";
 * - a lap during which the car was inside is counted, but it says nothing
 *   about the car's speed: it feeds neither the pace nor "best lap";
 * - a pass at the counting line while the car is logged inside is flagged
 *   ("no_abastecimento"): one of the two records is wrong;
 * - once the car is out, the clock behind "atrasado"/"parado" restarts.
 *
 * The two areas are independent. A car can be logged in both at once.
 */
final class FuelingTest extends EngineTestCase
{
    private const FIELD = [11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    /** Ten cars lapping in 180 seconds, and car 1 crossing every three minutes up to 21:00. */
    private function fieldLappingInThreeMinutes(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField(self::FIELD, 180, 12);
        for ($crossing = 1; $crossing <= 7; $crossing++) {
            $race->pass(1, RaceScenario::clock($crossing * 180));
        }
        return $race;
    }

    /** @return array{0: string, 1: ?int} status of car 1 and since when */
    private function statusOfCar1(RaceScenario $race, string $now): array
    {
        $car = $race->run($now)->cars[1];
        return [$car->status, $car->statusDesdeMs];
    }

    /**
     * SITUATION: the field laps in 180 seconds. Car 1 last crossed at
     * 21:00, went into the fueling station at 22:00 and left it at 30:00.
     *
     * EXPECTED, exactly as for a pit stop:
     *   29:00  abastecendo (since 22:00), however long it stays
     *   33:00  correndo: only 180 s since it left
     *   35:00  atrasado since 34:30 (exit + 1.5 laps)
     *   36:30  parado since 36:00 (exit + 2 laps)
     * Measured from its last pass instead, the car would be "parado" the
     * moment it rejoined.
     */
    public function testWhileFuelingTheCarIsAbastecendoAndTheClockRestartsAtTheExit(): void
    {
        $race = $this->fieldLappingInThreeMinutes();
        $race->fuelIn(1, '22:00');
        $race->fuelOut(1, '30:00');

        $this->assertSame([CarState::ABASTECENDO, RaceScenario::ms('22:00')], $this->statusOfCar1($race, '29:00'));
        $this->assertSame([CarState::CORRENDO, null], $this->statusOfCar1($race, '33:00'));
        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('34:30')], $this->statusOfCar1($race, '35:00'));
        $this->assertSame([CarState::PARADO, RaceScenario::ms('36:00')], $this->statusOfCar1($race, '36:30'));
        $this->assertSame('abastecendo', CarState::ABASTECENDO);
    }

    /**
     * SITUATION: car 1 enters the pit lane at 22:00 and, without being
     * logged out of it, the fueling station at 24:00. It is logged out of
     * fueling at 26:00 and out of the pit lane at 30:00.
     *
     * EXPECTED: the areas are independent, and a car in both is shown in
     * the one it entered LAST:
     *   23:00  no_box since 22:00
     *   25:00  abastecendo since 24:00 (in both; fueling was entered last)
     *   27:00  no_box since 22:00 again (out of fueling, still in the pits)
     *   33:00  correndo (the clock restarted at the pit exit, 30:00)
     *   35:00  atrasado since 34:30
     */
    public function testACarInBothAreasIsShownInTheOneItEnteredLast(): void
    {
        $race = $this->fieldLappingInThreeMinutes();
        $race->pitIn(1, '22:00');
        $race->fuelIn(1, '24:00');
        $race->fuelOut(1, '26:00');
        $race->pitOut(1, '30:00');

        $this->assertSame([CarState::NO_BOX, RaceScenario::ms('22:00')], $this->statusOfCar1($race, '23:00'));
        $this->assertSame([CarState::ABASTECENDO, RaceScenario::ms('24:00')], $this->statusOfCar1($race, '25:00'));
        $this->assertSame([CarState::NO_BOX, RaceScenario::ms('22:00')], $this->statusOfCar1($race, '27:00'));
        $this->assertSame([CarState::CORRENDO, null], $this->statusOfCar1($race, '33:00'));
        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('34:30')], $this->statusOfCar1($race, '35:00'));
    }

    /**
     * SITUATION: car 1 leaves the pit lane at 28:00, goes into the fueling
     * station at 28:30 and leaves it at 31:00. Its last pass was at 21:00.
     *
     * EXPECTED: the wait is measured from the LATEST of: last pass, last
     * pit exit, last fueling exit. That is 31:00, so the car is "correndo"
     * at 35:00 and "atrasado" only from 35:30. (From the pit exit it would
     * have been "atrasado" since 32:30.)
     */
    public function testTheClockRestartsAtTheLatestOfPassPitExitAndFuelingExit(): void
    {
        $race = $this->fieldLappingInThreeMinutes();
        $race->pitIn(1, '22:00');
        $race->pitOut(1, '28:00');
        $race->fuelIn(1, '28:30');
        $race->fuelOut(1, '31:00');

        $this->assertSame([CarState::CORRENDO, null], $this->statusOfCar1($race, '35:00'));
        $this->assertSame([CarState::ATRASADO, RaceScenario::ms('35:30')], $this->statusOfCar1($race, '35:45'));
    }

    /**
     * The status rule on its own (`CarStatus::statusAt`, which the results
     * page uses on a stored snapshot), for the cases a race rarely shows.
     *
     * EXPECTED:
     * - fueling entered at 22:00, pit lane at 24:00, both open: "no_box"
     *   since 24:00 (the pit lane was entered last);
     * - both entered at the very same moment: "no_box" (a tie goes to the
     *   pit lane, deterministically);
     * - a fueling exit OLDER than the last pass plays no part.
     */
    public function testTheStatusRuleWithBothAreas(): void
    {
        $settings = new Settings();
        $everybodySynced = fn(int $momentMs) => true;
        $timelineOf = function (array $events): RaceTimeline {
            $ocorrencias = [new Ocorrencia(1, Ocorrencia::LARGADA, RaceScenario::ms('00:00'))];
            foreach ($events as $index => [$tipo, $at]) {
                $ocorrencias[] = new Ocorrencia($index + 2, $tipo, RaceScenario::ms($at), 7);
            }
            return RaceTimeline::fromOcorrencias($ocorrencias);
        };
        $lastPassMs = RaceScenario::ms('21:00');

        $pitLast = $timelineOf([[Ocorrencia::ABASTECIMENTO_ENTRADA, '22:00'], [Ocorrencia::BOX_ENTRADA, '24:00']]);
        $this->assertSame(
            [CarState::NO_BOX, RaceScenario::ms('24:00')],
            CarStatus::statusAt(RaceScenario::ms('25:00'), 7, $lastPassMs, 180000, $pitLast, $settings, $everybodySynced)
        );

        $sameMoment = $timelineOf([[Ocorrencia::ABASTECIMENTO_ENTRADA, '22:00'], [Ocorrencia::BOX_ENTRADA, '22:00']]);
        $this->assertSame(
            [CarState::NO_BOX, RaceScenario::ms('22:00')],
            CarStatus::statusAt(RaceScenario::ms('25:00'), 7, $lastPassMs, 180000, $sameMoment, $settings, $everybodySynced)
        );

        $oldFuelStop = $timelineOf([[Ocorrencia::ABASTECIMENTO_ENTRADA, '10:00'], [Ocorrencia::ABASTECIMENTO_SAIDA, '11:00']]);
        $this->assertSame(
            [CarState::ATRASADO, RaceScenario::ms('25:30')],
            CarStatus::statusAt(RaceScenario::ms('25:40'), 7, $lastPassMs, 180000, $oldFuelStop, $settings, $everybodySynced)
        );
    }

    /**
     * SITUATION: car 6 makes its first crossing at 03:00, refuels from
     * 04:00 to 06:00 and crosses again at 08:00. All counters record both
     * crossings.
     *
     * EXPECTED: nothing is wrong, so there is no flag, and the lap counts.
     * But those five minutes include two minutes standing still: the lap is
     * marked "interrompida" and cannot be a best lap. Without the fueling
     * stop the very same lap could be one.
     */
    public function testALapThatIncludesAFuelingStopCountsButIsNotABestLap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->pass(6, '08:00');
        $withoutStop = $race->run('09:00');
        $this->assertFalse($withoutStop->laps[1]->interrompida);
        $this->assertTrue($withoutStop->laps[1]->elegivelMelhorVolta);

        $race->fuelIn(6, '04:00');
        $race->fuelOut(6, '06:00');
        $result = $race->run('09:00');

        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([6 => 1], $result);
        $this->assertTrue($result->laps[1]->interrompida);
        $this->assertFalse($result->laps[1]->elegivelMelhorVolta);
        $this->assertNull($result->cars[6]->melhorVoltaMs);
    }

    /**
     * SITUATION: eight cars have each completed exactly one lap of 100
     * seconds: just enough (eight laps) for the engine to say what a normal
     * lap is. Then we learn that car 8 was in the fueling station during
     * its lap.
     *
     * EXPECTED: a lap with a fueling stop in it says nothing about the pace
     * of the race and is not used for it. Seven usable laps are one too
     * few, so the pace is unknown again.
     */
    public function testALapWithAFuelingStopDoesNotFeedThePace(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8], 100, 2, '00:40', 3);
        $this->assertSame(100000, $race->run('03:00')->cars[1]->voltaEsperadaMs);

        $race->fuelIn(8, '01:10');
        $race->fuelOut(8, '01:30');

        $this->assertNull($race->run('03:00')->cars[1]->voltaEsperadaMs);
    }

    /**
     * SITUATION: car 6 made its first crossing at 03:00. The fueling log
     * says it went in at 05:00 and nobody logs it out. All three counters
     * record car 6 at the counting line at 07:00 and again at 10:00.
     *
     * WHAT WENT WRONG: one of the two records is wrong. Either the counters
     * took another car for car 6, or somebody missed the car leaving.
     *
     * EXPECTED: the engine does not guess. Both passes are HELD (reason
     * "no_abastecimento"): not counted, pending, each with a
     * "no_abastecimento" flag that has NO suggestion, says the count may be
     * a lap short and that confirming adds a pass.
     *
     * The reviewer who knows the car did leave records the exit nobody
     * logged, one second before the first pass ("marcar saída", an
     * ordinary exit event marked as inferred). From then on the car is
     * outside: BOTH passes count by the ordinary rules, the flags are gone,
     * and the lanes can show the exit as inferred.
     */
    public function testAPassWhileTheCarIsLoggedInTheFuelingStationIsHeldUntilItsExitIsRecorded(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->fuelIn(6, '05:00');
        $first = $race->pass(6, '07:00');
        $second = $race->pass(6, '10:00');

        $result = $race->run('11:00');

        $this->assertLapCounts([6 => 0], $result);
        $held = $result->groupOfEntry($first[1]);
        $this->assertTrue($held->retida);
        $this->assertSame(PassGroup::RETIDA_NO_ABASTECIMENTO, $held->motivoRetencao);
        $flags = $this->openFlags($result, Flag::NO_ABASTECIMENTO);
        $this->assertCount(2, $flags);
        $this->assertCount(2, $result->openFlags());
        $flag = $flags[0];
        $this->assertSame([6], $flag->carros);
        $this->assertSame(array_values($first), $flag->passagemIds);
        $this->assertSame(RaceScenario::ms('07:00'), $flag->tsMs);
        $this->assertNull($flag->sugestao, 'No default action: the reviewer chooses between the exit and discarding.');
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);
        $this->assertStringContainsString('abastecimento', $flag->motivo);
        $this->assertStringContainsString('ficou retida', $flag->motivo);
        $this->assertSame(0, $race->countCars('11:00', [6])->lapCount(6), 'The fast path holds it too.');

        $inferredExit = $race->inferredExit('abastecimento', 6, RaceScenario::ms('06:59'));
        $fixed = $race->run('11:00');
        $this->assertNoOpenFlags($fixed);
        $this->assertLapCounts([6 => 2], $fixed);
        $this->assertFalse($fixed->groupOfEntry($first[1])->retida);
        $this->assertFalse($fixed->groupOfEntry($second[1])->retida);
        $this->assertSame(2, $race->countCars('11:00', [6])->lapCount(6));
        $this->assertSame([[RaceScenario::ms('05:00'), RaceScenario::ms('06:59')]], $fixed->timeline->fuelIntervals[6]);
        $exits = array_values(array_filter($fixed->timeline->stopEvents, fn(array $event) => $event['ocorrencia_id'] === $inferredExit));
        $this->assertTrue($exits[0]['inferida'], 'The lanes can show it as an inferred exit.');
        $this->assertFalse($fixed->timeline->stopEvents[0]['inferida']);
    }

    /**
     * SITUATION: the same stay, but only counter 2 records car 6 at the
     * line at 07:00; the other two are online and have nothing.
     *
     * EXPECTED: the flag is "no_abastecimento" (the log says more than
     * "only one counter saw it"), names the counter, and has no
     * suggestion. One counter's pass did not count anyway, so it is not
     * "held" and the count is not in doubt on its account; confirming it
     * would add a pass.
     */
    public function testAPassOnlyOneCounterSawWhileTheCarIsLoggedInsideIsFlaggedWithoutASuggestion(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->fuelIn(6, '05:00');
        $lone = $race->entry(2, 6, '07:00');

        $result = $race->run('10:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::NO_ABASTECIMENTO);
        $this->assertNull($flag->sugestao);
        $this->assertSame(2, $flag->fonteId);
        $this->assertStringContainsString('Só uma contagem', $flag->motivo);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertNull($flag->contagemEmDuvida);
        $this->assertFalse($result->groupOfEntry($lone)->retida);
        $this->assertLapCounts([6 => 0], $result);
    }

    /**
     * SITUATION: cars 1, 2 and 3 each have the same fueling stay on the
     * log, 05:00 to 09:00, and each is recorded at the line once during the
     * race: car 1 exactly at the entry (05:00), car 2 a millisecond before
     * the exit, car 3 exactly at the exit (09:00).
     *
     * EXPECTED: a stay includes the instant of entry and ends at the
     * instant of exit: the first two passes are "inside" and flagged, the
     * third is not.
     */
    public function testAFuelingStayIncludesItsEntryInstantAndEndsAtItsExit(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        foreach ([1 => '05:00', 2 => '08:59.999', 3 => '09:00'] as $car => $passAt) {
            $race->pass($car, '00:20');
            $race->fuelIn($car, '05:00');
            $race->fuelOut($car, '09:00');
            $race->pass($car, $passAt);
        }

        $flagged = array_map(fn(Flag $flag) => $flag->carros[0], $this->openFlags($race->run('10:00'), Flag::NO_ABASTECIMENTO));

        sort($flagged);
        $this->assertSame([1, 2], $flagged);
    }

    /**
     * SITUATION: car 6 is logged in the pit lane AND in the fueling station
     * when all three counters record it at the line.
     *
     * EXPECTED: one flag, not two, for the one doubtful pass: "no_box".
     */
    public function testAPassWhileInBothAreasGetsOneFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->pitIn(6, '05:00');
        $race->fuelIn(6, '05:30');
        $race->pass(6, '07:00');

        $this->assertSingleOpenFlag($race->run('08:00'), Flag::NO_BOX);
    }

    /**
     * SITUATION: the fueling log, read through the questions the rest of
     * the system asks of the race timeline. Car 7 fuels 10:00-12:00 and
     * again from 20:00 (still inside); car 3 fuels from 11:00 (still
     * inside); car 5 is in the PIT LANE from 11:00.
     *
     * EXPECTED: each question answers for the fueling station only, and the
     * pit lane questions do not see fueling stays (and the other way
     * round).
     */
    public function testTheQuestionsTheTimelineAnswersAboutFueling(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->fuelIn(7, '10:00');
        $race->fuelOut(7, '12:00');
        $race->fuelIn(7, '20:00');
        $race->fuelIn(3, '11:00');
        $race->pitIn(5, '11:00');
        // Car 9 has two completed stays, logged in a jumbled order.
        $race->fuelIn(9, '08:00');
        $race->fuelOut(9, '09:00');
        $race->fuelIn(9, '05:00');
        $race->fuelOut(9, '06:00');
        $timeline = $race->run('30:00')->timeline;
        $ms = fn(string $clock) => RaceScenario::ms($clock);

        $this->assertSame(
            [3 => [[$ms('11:00'), null]], 7 => [[$ms('10:00'), $ms('12:00')], [$ms('20:00'), null]]],
            [3 => $timeline->fuelIntervals[3], 7 => $timeline->fuelIntervals[7]]
        );
        $this->assertCount(3, $timeline->fuelIntervals, 'Only the three cars that fueled.');
        $this->assertSame([[$ms('05:00'), $ms('06:00')], [$ms('08:00'), $ms('09:00')]], $timeline->fuelIntervals[9], 'Stays are in time order.');
        $this->assertSame($ms('06:00'), $timeline->lastFuelExitBefore(9, $ms('07:00')));
        $this->assertSame($ms('09:00'), $timeline->lastFuelExitBefore(9, $ms('25:00')), 'The LAST exit, not the first.');
        $this->assertSame([5 => [[$ms('11:00'), null]]], $timeline->pitIntervals);

        $this->assertFalse($timeline->isFuelingAt(7, $ms('09:59.999')));
        $this->assertTrue($timeline->isFuelingAt(7, $ms('10:00')));
        $this->assertTrue($timeline->isFuelingAt(7, $ms('11:59.999')));
        $this->assertFalse($timeline->isFuelingAt(7, $ms('12:00')));
        $this->assertFalse($timeline->isFuelingAt(5, $ms('11:30')), 'Car 5 is in the pit lane, not fueling.');
        $this->assertFalse($timeline->isInPitAt(7, $ms('11:00')), 'Car 7 is fueling, not in the pit lane.');

        $this->assertSame($ms('10:00'), $timeline->fuelEntryBefore(7, $ms('11:00')));
        $this->assertSame($ms('20:00'), $timeline->fuelEntryBefore(7, $ms('25:00')));
        $this->assertNull($timeline->fuelEntryBefore(7, $ms('15:00')));

        $this->assertNull($timeline->lastFuelExitBefore(7, $ms('11:59.999')));
        $this->assertSame($ms('12:00'), $timeline->lastFuelExitBefore(7, $ms('12:00')));
        $this->assertSame($ms('12:00'), $timeline->lastFuelExitBefore(7, $ms('25:00')));
        $this->assertNull($timeline->lastFuelExitBefore(3, $ms('25:00')), 'Car 3 has not come out yet.');
        $this->assertNull($timeline->lastPitExitBefore(7, $ms('25:00')));

        $this->assertSame([], $timeline->carsFuelingAt($ms('09:00')));
        $this->assertSame([3, 7], $timeline->carsFuelingAt($ms('11:30')));
        $this->assertSame([9], $timeline->carsFuelingAt($ms('05:30')));
        $this->assertSame([3], $timeline->carsFuelingAt($ms('15:00')));
        $this->assertSame([5], $timeline->carsInPitAt($ms('11:30')));

        $this->assertTrue($timeline->overlapsFuelStay(7, $ms('09:00'), $ms('10:30')));
        $this->assertFalse($timeline->overlapsFuelStay(7, $ms('12:00'), $ms('20:00')), 'Between the two stays.');
        $this->assertTrue($timeline->overlapsFuelStay(7, $ms('19:00'), $ms('20:00.001')));
        $this->assertFalse($timeline->overlapsFuelStay(5, $ms('00:00'), $ms('30:00')));
        $this->assertFalse($timeline->overlapsPitStay(7, $ms('00:00'), $ms('30:00')));
    }

    /**
     * SITUATION: the logs of both areas, kept by people who sometimes miss
     * a car:
     * - car 5 is logged OUT of the pit lane at 04:00, with no entry;
     * - car 6 is logged INTO fueling at 05:00 and again at 05:30, out at
     *   06:00, and out again at 06:30.
     *
     * EXPECTED: nothing is refused and nothing blocks. The events that have
     * no partner are listed in `unpairedStops`, in time order, each with
     * its area, type, car, time and row id, for the review screen to show:
     * the pit exit of car 5, the FIRST fueling entry of car 6 (a car that
     * enters again must have left in between, so the older entry is the
     * one whose exit was missed), and its second exit. Car 6 has one
     * fueling stay, 05:30-06:00. Car 5 has no pit stay and is never "in the
     * pit lane".
     */
    public function testEventsWithNoPartnerAreListedAndBlockNothing(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $exitWithNoEntry = $race->pitOut(5, '04:00');
        $firstEntry = $race->fuelIn(6, '05:00');
        $race->fuelIn(6, '05:30');
        $race->fuelOut(6, '06:00');
        $secondExit = $race->fuelOut(6, '06:30');

        $timeline = $race->run('10:00')->timeline;

        $this->assertSame(
            [
                ['area' => 'box', 'tipo' => Ocorrencia::BOX_SAIDA, 'carro' => 5, 'ts_ms' => RaceScenario::ms('04:00'), 'ocorrencia_id' => $exitWithNoEntry, 'inferida' => false],
                ['area' => 'abastecimento', 'tipo' => Ocorrencia::ABASTECIMENTO_ENTRADA, 'carro' => 6, 'ts_ms' => RaceScenario::ms('05:00'), 'ocorrencia_id' => $firstEntry, 'inferida' => false],
                ['area' => 'abastecimento', 'tipo' => Ocorrencia::ABASTECIMENTO_SAIDA, 'carro' => 6, 'ts_ms' => RaceScenario::ms('06:30'), 'ocorrencia_id' => $secondExit, 'inferida' => false],
            ],
            $timeline->unpairedStops
        );
        $this->assertSame([6 => [[RaceScenario::ms('05:30'), RaceScenario::ms('06:00')]]], $timeline->fuelIntervals);
        $this->assertSame([], $timeline->pitIntervals);
        $this->assertFalse($timeline->isInPitAt(5, RaceScenario::ms('03:00')));
        $this->assertFalse($timeline->isInPitAt(5, RaceScenario::ms('05:00')));
        $this->assertCount(5, $timeline->stopEvents, 'Paired or not, every event is kept.');
    }

    /**
     * SITUATION: a well-kept log: car 6 in and out of both areas, once
     * each.
     *
     * EXPECTED: nothing is unpaired.
     */
    public function testAWellKeptLogHasNoUnpairedEvents(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitIn(6, '04:00');
        $race->pitOut(6, '05:00');
        $race->fuelIn(6, '05:10');
        $race->fuelOut(6, '06:00');

        $this->assertSame([], $race->run('10:00')->timeline->unpairedStops);
    }

    /**
     * SITUATION: car 6 was logged into the fueling station at 05:00 by
     * mistake (it was car 9). Race control cancels that row.
     *
     * EXPECTED: fueling events are corrected and cancelled like any other
     * event: car 6 is not fueling, and its pass at 07:00 raises no flag.
     */
    public function testACancelledFuelingEntryNoLongerCounts(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $wrongCar = $race->fuelIn(6, '05:00');
        $race->pass(6, '07:00');
        $this->assertSingleOpenFlag($race->run('08:00'), Flag::NO_ABASTECIMENTO);

        $race->cancel($wrongCar);
        $result = $race->run('08:00');

        $this->assertNoOpenFlags($result);
        $this->assertSame([], $result->timeline->fuelIntervals);
        $this->assertSame(CarState::CORRENDO, $result->cars[6]->status);
    }

    /**
     * EXPECTED: the names of the event types and their groupings, which the
     * upload endpoint and the pit pages rely on. The pit-lane list still
     * holds only the two pit-lane types.
     */
    public function testTheTypesOfStopEvent(): void
    {
        $this->assertSame('abastecimento_entrada', Ocorrencia::ABASTECIMENTO_ENTRADA);
        $this->assertSame('abastecimento_saida', Ocorrencia::ABASTECIMENTO_SAIDA);
        $this->assertSame(['box_entrada', 'box_saida'], Ocorrencia::TIPOS_BOX);
        $this->assertSame(['abastecimento_entrada', 'abastecimento_saida'], Ocorrencia::TIPOS_ABASTECIMENTO);
        $this->assertSame(['box_entrada', 'box_saida', 'abastecimento_entrada', 'abastecimento_saida'], Ocorrencia::TIPOS_DE_PARADA);
        foreach (Ocorrencia::TIPOS_DE_PARADA as $tipo) {
            $this->assertContains($tipo, Ocorrencia::TIPOS);
        }
    }
}
