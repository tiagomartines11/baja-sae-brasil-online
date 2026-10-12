<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Passes typed shortly before the start (flag "antes_da_largada").
 *
 * WHY IT MATTERS. Laps = official passes - 1: every car needs its first
 * crossing of the counting line to be counted as "pass zero". A pass can
 * only follow the start. So if race control records the start a few seconds
 * late, or corrects it to a later time, the first crossing of the cars at
 * the front lands BEFORE the start and cannot be official. Each of those
 * cars is then one lap short for the whole race.
 *
 * Until round 3 such entries were dropped without a word. Now they are
 * kept, shown, marked "antes da largada", and a flag per car suggests
 * counting them as that car's initial pass.
 *
 * Times before the start are written with a minus sign: "-00:05" is five
 * seconds before the start.
 */
final class BeforeTheStartTest extends EngineTestCase
{
    /**
     * Five cars, all recorded by all three counters. Cars 1 and 2 are at
     * the front and reach the counting line at 00:05 and 00:08; cars 3, 4
     * and 5 at 00:40, 00:45 and 00:50. Everybody comes round again three
     * minutes later.
     */
    private function fiveCarsTwoCrossingsEach(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        foreach ([1 => 5, 2 => 8, 3 => 40, 4 => 45, 5 => 50] as $car => $firstCrossingSeconds) {
            $race->pass($car, RaceScenario::clock($firstCrossingSeconds));
            $race->pass($car, RaceScenario::clock($firstCrossingSeconds + 180));
        }
        return $race;
    }

    /**
     * SITUATION: the race above. Every car has one lap. Then race control
     * corrects the start to 00:10, ten seconds later than first recorded.
     *
     * WHAT WENT WRONG: the first crossings of cars 1 and 2 (00:05, 00:08)
     * are now before the start. Their second crossing becomes their pass
     * zero and they are down to no lap at all, behind cars 3 to 5.
     *
     * EXPECTED:
     * - the count does follow the rule (cars 1 and 2 have 0 laps): the
     *   engine never counts a pass before the start on its own;
     * - but nothing vanished: the two groups are still there, marked
     *   "antes da largada", and each car has ONE flag, "antes_da_largada",
     *   suggesting to confirm the group (any of its entries will do);
     * - the flag says what accepting it does: one more official pass,
     *   making a first lap of 180 seconds;
     * - the "first pass came late" flag is NOT raised as well for the same
     *   two cars: it would ask the same question twice;
     * - once both suggestions are accepted, cars 1 and 2 have their lap
     *   back: the confirmed group is their pass zero, the lap after it
     *   lasts 180 s, and the review queue is empty.
     */
    public function testWhenTheStartIsMovedLaterTheFrontCarsFirstCrossingIsKeptAndFlagged(): void
    {
        $race = $this->fiveCarsTwoCrossingsEach();
        $this->assertLapCounts([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], $race->run('06:00'));

        $race->start('00:10');
        $moved = $race->run('06:00');

        $this->assertLapCounts([1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 1], $moved);
        $flags = $moved->openFlags();
        $this->assertSame([Flag::ANTES_DA_LARGADA, Flag::ANTES_DA_LARGADA], array_map(fn(Flag $flag) => $flag->tipo, $flags));
        $this->assertSame([[1], [2]], array_map(fn(Flag $flag) => $flag->carros, $flags));

        $flagOfCar1 = $flags[0];
        $groupOfCar1 = $moved->groupOfEntry($flagOfCar1->passagemIds[0]);
        $this->assertTrue($groupOfCar1->antesDaLargada);
        $this->assertFalse($groupOfCar1->contado);
        $this->assertNull($groupOfCar1->numeroVolta);
        $this->assertSame([Flag::ANTES_DA_LARGADA], $groupOfCar1->alertasAbertos);
        $this->assertCount(3, $flagOfCar1->passagemIds, 'The flag lists the entries of all three counters.');
        $this->assertSame(RaceScenario::ms('00:05'), $flagOfCar1->tsMs);
        $this->assertSame(Decision::CONFIRMAR, $flagOfCar1->sugestao['tipo']);
        $this->assertContains($flagOfCar1->sugestao['passagem_id'], $flagOfCar1->passagemIds);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flagOfCar1->efeitoDeConfirmar);
        $this->assertSame(180000, $flagOfCar1->voltaResultanteMs);
        $this->assertStringContainsString('5 s antes da largada', $flagOfCar1->motivo);
        $this->assertSame(1, $this->sourceCount($moved, 1, 1), 'A pass before the start is not among the counter\'s passes of the race yet.');

        $race->accept($flags[0]);
        $race->accept($flags[1]);
        $accepted = $race->run('06:00');

        $this->assertLapCounts([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], $accepted);
        $this->assertNoOpenFlags($accepted);
        $passZeroOfCar1 = $accepted->groupOfEntry($flagOfCar1->passagemIds[0]);
        $this->assertTrue($passZeroOfCar1->antesDaLargada, 'It is still marked: it IS from before the recorded start.');
        $this->assertTrue($passZeroOfCar1->contado);
        $this->assertSame(0, $passZeroOfCar1->numeroVolta);
        $lapsOfCar1 = array_values(array_filter($accepted->laps, fn(Lap $lap) => $lap->carro === 1));
        $this->assertSame([Lap::INICIAL, Lap::CONTADA], array_map(fn(Lap $lap) => $lap->estado, $lapsOfCar1));
        $this->assertSame(180000, $lapsOfCar1[1]->tempoVoltaMs);
        $this->assertSame(2, $this->sourceCount($accepted, 1, 1));
    }

    /**
     * SITUATION: the same race with the start moved to 00:10, looked at
     * through the fast path an upload uses (`countCars`), which recounts
     * one car from that car's entries alone and knows nothing of the field.
     *
     * EXPECTED: the same answer as the full run, before and after the
     * reviewer confirms: whether a group before the start is official
     * depends only on the reviewer's word, never on other cars.
     */
    public function testRecountingOneCarAgreesAboutPassesBeforeTheStart(): void
    {
        $race = $this->fiveCarsTwoCrossingsEach();
        $race->start('00:10');

        $this->assertSame(0, $race->countCars('06:00', [1])->lapCount(1));
        $this->assertSame(RaceScenario::ms('03:05'), $race->countCars('06:00', [1])->lastPassMs(1));

        $race->accept($race->run('06:00')->openFlags()[0]);

        $this->assertSame(1, $race->countCars('06:00', [1])->lapCount(1));
        $this->assertSame(1, $race->run('06:00')->lapCount(1));
        $this->assertSame(Lap::INICIAL, $race->countCars('06:00', [1])->lapsByCar[1][0]->estado);
        $this->assertSame(RaceScenario::ms('00:05'), $race->countCars('06:00', [1])->lapsByCar[1][0]->tsMs);
    }

    /**
     * SITUATION: counters also type during the warm-up and the formation
     * lap, long before the start. Nobody wants a flag for each of those.
     * How long before the start is still "shortly before"? As long as the
     * field usually takes to reach the counting line after the start: here
     * cars 11, 12 and 13 all make their first pass 60 seconds after it.
     * Car 7 was typed exactly 60 seconds before the start, car 8 one
     * millisecond earlier than that.
     *
     * EXPECTED: car 7 is flagged, car 8 is not. Both groups are kept and
     * marked; neither counts.
     */
    public function testShortlyBeforeMeansWithinTheFieldsUsualDelayToTheLine(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([11, 12, 13], '01:00');
        $onTheLimit = $race->pass(7, '-01:00');
        $justBeyond = $race->pass(8, '-01:00.001');

        $result = $race->run('02:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::ANTES_DA_LARGADA);
        $this->assertSame([7], $flag->carros);
        $this->assertTrue($result->groupOfEntry($onTheLimit[1])->antesDaLargada);
        $this->assertTrue($result->groupOfEntry($justBeyond[1])->antesDaLargada);
        $this->assertSame([], $result->groupOfEntry($justBeyond[1])->alertasAbertos);
    }

    /**
     * SITUATION: the counting line is right after the start line, so the
     * field reaches it 5 seconds after the start. Or nobody has passed at
     * all yet. Either way "the field's usual delay" is tiny or unknown.
     *
     * EXPECTED: the window is never shorter than one matching window (20
     * seconds, setting `janela_ms`): a group 20 s before the start is
     * flagged, one a millisecond earlier is not. With `janela_ms` set to
     * 10 seconds, the limit is 10 seconds.
     */
    public function testTheWindowIsNeverShorterThanOneMatchingWindow(): void
    {
        foreach ([true, false] as $theFieldHasPassed) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            if ($theFieldHasPassed) {
                $race->firstCrossing([11, 12, 13], '00:05');
            }
            $race->pass(7, '-00:20');
            $race->pass(8, '-00:20.001');
            $race->pass(9, '-00:10');
            $race->pass(10, '-00:10.001');

            $flaggedCars = function () use ($race): array {
                $cars = array_map(fn(Flag $flag) => $flag->carros[0], $this->openFlags($race->run('01:00'), Flag::ANTES_DA_LARGADA));
                sort($cars);
                return $cars;
            };
            $this->assertSame([7, 9, 10], $flaggedCars(), 'With the default window of 20 seconds.');

            $race->settings->janelaMs = 10000;
            $this->assertSame([9], $flaggedCars(), 'With a window of 10 seconds.');
        }
    }

    /**
     * SITUATION: the field reaches the line a minute after the start. Car 7
     * was typed by everybody 50 seconds before the start (formation lap)
     * and again 5 seconds before it.
     *
     * EXPECTED: one flag per car, not one per group, about the LAST group
     * before the start: only that one can be the car's initial pass.
     */
    public function testACarGetsOneFlagAboutItsLastGroupBeforeTheStart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([11, 12, 13], '01:00');
        $formationLap = $race->pass(7, '-00:50');
        $justBefore = $race->pass(7, '-00:05');

        $result = $race->run('02:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::ANTES_DA_LARGADA);
        $this->assertSame(array_values($justBefore), $flag->passagemIds);
        $this->assertTrue($result->groupOfEntry($formationLap[1])->antesDaLargada);
        $this->assertSame([], $result->groupOfEntry($formationLap[1])->alertasAbertos);
    }

    /**
     * SITUATION: car 9 was typed by everybody 10 seconds before the start
     * (it was rolling to the grid) and then, properly, 15 seconds after it.
     *
     * WHAT COULD GO WRONG: suggesting "count the earlier one as the initial
     * pass" would give car 9 a lap of 25 seconds, which no car can do.
     *
     * EXPECTED: the flag is raised (the reviewer should know), but WITHOUT
     * a suggestion, and it says what the first lap would be: 25 s, under
     * two matching windows. Its reason mentions the warm-up.
     */
    public function testNoSuggestionWhenTheFirstLapWouldBeUnderTwoMatchingWindows(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(9, '-00:10');
        $race->pass(9, '00:15');

        $flag = $this->assertSingleOpenFlag($race->run('01:00'), Flag::ANTES_DA_LARGADA);

        $this->assertNull($flag->sugestao);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertSame(25000, $flag->voltaResultanteMs);
        $this->assertStringContainsString('aquecimento', $flag->motivo);
        $this->assertStringContainsString('25 s', $flag->motivo);
    }

    /**
     * SITUATION: ten cars lap in 180 seconds. Cars 8 and 9 were both typed
     * before the start and both made their real first crossing at 00:50.
     * Car 8 was typed 40 s before the start, car 9 only 30 s before.
     *
     * EXPECTED: "too short to be a lap" is judged against the field's
     * median first lap (180 s), with the usual fraction (half: 90 s).
     * - Car 8: the first lap would be 90 s, exactly the limit, so it is
     *   plausible and the suggestion to confirm is made.
     * - Car 9: the first lap would be 80 s, too short: no suggestion.
     */
    public function testNoSuggestionWhenTheFirstLapWouldBeUnderHalfTheFieldsFirstLap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([11, 12, 13, 14, 15, 16, 17, 18, 19, 20], 180, 3, '00:40');
        foreach ([8 => '-00:40', 9 => '-00:30'] as $car => $beforeTheStart) {
            $race->pass($car, $beforeTheStart);
            $race->pass($car, '00:50');
            $race->pass($car, '03:50');
        }

        $flags = $this->openFlags($race->run('08:00'), Flag::ANTES_DA_LARGADA);

        $this->assertSame([[8], [9]], array_map(fn(Flag $flag) => $flag->carros, $flags));
        $this->assertSame([90000, 80000], array_map(fn(Flag $flag) => $flag->voltaResultanteMs, $flags));
        $this->assertNotNull($flags[0]->sugestao);
        $this->assertNull($flags[1]->sugestao);
    }

    /**
     * SITUATION: car 7 has two groups before the start, 50 s and 5 s before
     * it, and a reviewer confirmed BOTH (by mistake, or to be thorough).
     * The car then crosses at 03:00.
     *
     * EXPECTED: only one group before the start can be the initial pass:
     * the later of the confirmed ones. Otherwise car 7 would have completed
     * a "lap" before the race began. It has one lap (the one ending at
     * 03:00), and nothing is flagged.
     */
    public function testOnlyOneGroupBeforeTheStartCanBeTheInitialPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $earlier = $race->pass(7, '-00:50');
        $later = $race->pass(7, '-00:05');
        $race->pass(7, '03:00');
        $race->confirm($earlier[1]);
        $race->confirm($later[2]);

        $result = $race->run('04:00');

        $this->assertLapCounts([7 => 1], $result);
        $this->assertSame(0, $result->groupOfEntry($later[1])->numeroVolta);
        $this->assertNull($result->groupOfEntry($earlier[1])->numeroVolta);
        $this->assertFalse($result->groupOfEntry($earlier[1])->contado);
        $this->assertSame(185000, $result->laps[1]->tempoVoltaMs);
        $this->assertNoOpenFlags($result);
        $this->assertSame(1, $race->countCars('04:00', [7])->lapCount(7), 'And the fast path agrees.');
    }

    /**
     * SITUATION: two cars that already have their initial pass from before
     * the start, each with ANOTHER group before the start as well:
     * - car 7 (takes part): typed 50 s before the start, not confirmed, and
     *   5 s before it, confirmed;
     * - car 50 (present, not classified): two groups, both confirmed by a
     *   reviewer who decided to count it anyway.
     * The field reaches the line a minute after the start, so all of these
     * are "shortly before" it.
     *
     * EXPECTED: no flag at all. A car that has its initial pass needs no
     * suggestion about another one, and a group a reviewer confirmed is
     * never flagged again, even when it is not the one that counts.
     */
    public function testACarThatHasItsInitialPassIsNotAskedAboutAgain(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([7, 11, 12, 13]);
        $race->teamsNotClassified([50]);
        $race->start('00:00');
        $race->firstCrossing([11, 12, 13], '01:00');
        $race->pass(7, '-00:50');
        $race->confirm($race->pass(7, '-00:05')[1]);
        $race->confirm($race->pass(50, '-00:40')[1]);
        $race->confirm($race->pass(50, '-00:10')[1]);

        $result = $race->run('02:00');

        $this->assertNoOpenFlags($result);
        $this->assertSame(RaceScenario::ms('-00:05'), $result->cars[7]->ultimaPassagemMs);
        $this->assertSame(RaceScenario::ms('-00:10'), $result->cars[50]->ultimaPassagemMs);
    }

    /**
     * SITUATION: the start was recorded late for nearly everybody: cars 1,
     * 2 and 3 crossed 5 s before it and a reviewer has already confirmed
     * those as their initial passes. Car 4 made its first pass 60 s after
     * the start. Car 9 was typed 40 s before the start.
     *
     * EXPECTED: "the field's usual delay to the line" is measured from
     * first passes AFTER the start only. That is car 4's 60 s, so car 9 at
     * 40 s before is within the window and is flagged. (Counting the three
     * initial passes from before the start as delays of "minus 5 s" would
     * shrink the window to its minimum of 20 s and hide car 9.)
     */
    public function testTheWindowIsMeasuredFromFirstPassesAfterTheStart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        foreach ([1, 2, 3] as $car) {
            $race->confirm($race->pass($car, '-00:05')[1]);
        }
        $race->pass(4, '01:00');
        $race->pass(9, '-00:40');

        $flag = $this->assertSingleOpenFlag($race->run('02:00'), Flag::ANTES_DA_LARGADA);

        $this->assertSame([9], $flag->carros);
    }

    /**
     * SITUATION: teams 7, 11, 12 and 13 take part and reach the line 5 s
     * after the start. Five numbers that are no team at the event (91 to
     * 95) were typed by everybody 200 s after the start. Car 7 was also
     * typed a full minute before the start (warm-up).
     *
     * EXPECTED: "the field" is the cars that take part: its usual delay is
     * 5 s, the window is the minimum 20 s, and car 7's warm-up entry a
     * minute before the start is not flagged. (Letting the five strangers
     * into the field would stretch the window to 200 s.) The strangers
     * themselves get their "carro_desconhecido" flags.
     */
    public function testTheWindowLooksOnlyAtCarsThatTakePart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([7, 11, 12, 13]);
        $race->start('00:00');
        $race->firstCrossing([7, 11, 12, 13], '00:05');
        foreach ([91, 92, 93, 94, 95] as $stranger) {
            $race->pass($stranger, '03:20');
        }
        $race->pass(7, '-01:00');

        $result = $race->run('04:00');

        $this->assertSame([], $this->openFlags($result, Flag::ANTES_DA_LARGADA));
        $this->assertCount(5, $this->openFlags($result, Flag::CARRO_DESCONHECIDO));
    }

    /**
     * SITUATION: only TWO cars have completed a first lap so far (180 s
     * each). Car 9 was typed by everybody 30 s before the start and made
     * its first pass at 01:10, so confirming would give it a first lap of
     * 100 s. Later a third car completes its first lap too.
     *
     * WHY IT MATTERS: accepting this suggestion ADDS A LAP. Early in the
     * race the engine cannot tell a first crossing from warm-up: that takes
     * the field's median first lap, and two cars are not a field (three are
     * needed, as for the late-first-pass flag).
     *
     * EXPECTED: while only two cars have a first lap, the flag is there,
     * says what confirming would do, but makes NO suggestion, and says
     * why. Once the third car has its first lap, the 100 s can be judged
     * (more than half of 180 s) and the suggestion to confirm appears.
     */
    public function testUntilTheFieldsFirstLapIsKnownThereIsNoSuggestionToCountAPassBeforeTheStart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([11, 12], 180, 2, '00:40');
        $race->pass(13, '00:54');
        $race->pass(9, '-00:30');
        $race->pass(9, '01:10');

        $flag = $this->assertSingleOpenFlag($race->run('05:00'), Flag::ANTES_DA_LARGADA);

        $this->assertSame(100000, $flag->voltaResultanteMs);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertNull($flag->sugestao);
        $this->assertStringContainsString('por enquanto não há sugestão', $flag->motivo);

        $race->pass(13, '03:54');
        $flag = $this->assertSingleOpenFlag($race->run('05:00'), Flag::ANTES_DA_LARGADA);
        $this->assertSame(Decision::CONFIRMAR, $flag->sugestao['tipo']);
    }

    /**
     * SITUATION: the race of the first test (five cars, so the field's
     * first lap is known: 180 s). Thirty seconds before the start, counter
     * 1 alone typed car 9; in a second race counters 1 and 2 both did. Car
     * 9 then crosses at 02:50 and 05:50, recorded by everybody.
     *
     * WHAT WENT WRONG (before): the stray entry of ONE counter came with
     * the suggestion "count it as the initial pass", so a single key press
     * gave car 9 a lap on one person's word.
     *
     * EXPECTED: one counter's entry before the start is flagged, with what
     * confirming would do, but with no suggestion; the reason says only one
     * counter has it. Two counters agreeing do get the suggestion.
     */
    public function testAPassBeforeTheStartIsOnlySuggestedOnTwoCountersWord(): void
    {
        foreach ([[1], [1, 2]] as $seenBy) {
            $race = $this->fiveCarsTwoCrossingsEach();
            $race->pass(9, '-00:30', $seenBy);
            $race->pass(9, '02:50');
            $race->pass(9, '05:50');

            $flag = $this->assertSingleOpenFlag($race->run('06:00'), Flag::ANTES_DA_LARGADA);

            $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
            $this->assertSame(200000, $flag->voltaResultanteMs);
            if (count($seenBy) === 1) {
                $this->assertNull($flag->sugestao, 'One counter alone: no suggestion.');
                $this->assertStringContainsString('Só Contagem 1 a registrou', $flag->motivo);
            } else {
                $this->assertSame(Decision::CONFIRMAR, $flag->sugestao['tipo'], 'Two counters agree: suggested.');
            }
        }
    }

    /**
     * SITUATION: a reviewer does not confirm anything but ADDS a pass for
     * car 7 five seconds before the recorded start ("adicionar"), having
     * seen on the video that the car crossed then. The car crosses again
     * at 03:00.
     *
     * EXPECTED: an added pass is a reviewer's word just like a confirmed
     * one: it is the car's pass zero and 03:00 completes lap 1.
     */
    public function testAPassAReviewerAddsBeforeTheStartIsTheInitialPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '03:00');
        $this->assertLapCounts([7 => 0], $race->run('04:00'));

        $race->addPass(7, '-00:05');
        $result = $race->run('04:00');

        $this->assertLapCounts([7 => 1], $result);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the other pass-zero rule, `passagem_inicial` = "largada":
     * the organisers decided the first crossing is not typed and the start
     * itself is everybody's pass zero. Car 5 was typed by everybody 5
     * seconds before the start anyway, and crosses at 03:00.
     *
     * EXPECTED: nobody can lose a lap this way under this rule, so there is
     * no flag. The group is kept and marked, and it never counts, not even
     * if a reviewer confirms it: car 5 has one lap, start to 03:00.
     */
    public function testUnderTheLargadaRuleNothingBeforeTheStartIsFlaggedOrCounted(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->passagemInicial = Settings::INICIAL_LARGADA;
        $race->start('00:00');
        $beforeTheStart = $race->pass(5, '-00:05');
        $race->pass(5, '03:00');

        $result = $race->run('04:00');
        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([5 => 1], $result);
        $this->assertTrue($result->groupOfEntry($beforeTheStart[1])->antesDaLargada);

        $race->confirm($beforeTheStart[1]);
        $confirmed = $race->run('04:00');
        $this->assertLapCounts([5 => 1], $confirmed);
        $this->assertCount(1, $confirmed->laps, 'One row: the lap. The group before the start is not a row.');
        $this->assertSame(180000, $confirmed->laps[0]->tempoVoltaMs);
    }

    /**
     * SITUATION: three cars cross the line at the very moment of the start.
     * Car 5: counter 1 pressed Enter one second BEFORE the start instant,
     * counters 2 and 3 one and two seconds after it. Car 6: counters 1 and
     * 2 two and one seconds before, counter 3 one second after. Car 4: one
     * second before, exactly at the start instant, one second after.
     *
     * EXPECTED: the three entries of each car are one pass (they are well
     * within the matching window). Which side of the start a pass is on is
     * decided by its pass time, the median: car 5's is one second after the
     * start, so it is an ordinary pass zero with no flag; car 6's is one
     * second before, so it is marked and flagged. Car 4's is the start
     * instant itself, which belongs to the race: an ordinary pass zero.
     */
    public function testAPassTypedOnBothSidesOfTheStartGoesByItsMedian(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('01:00');
        $car5 = [$race->entry(1, 5, '00:59'), $race->entry(2, 5, '01:01'), $race->entry(3, 5, '01:02')];
        $car6 = [$race->entry(1, 6, '00:58'), $race->entry(2, 6, '00:59'), $race->entry(3, 6, '01:01')];
        $car4 = [$race->entry(1, 4, '00:59'), $race->entry(2, 4, '01:00'), $race->entry(3, 4, '01:01')];

        $result = $race->run('02:00');

        $groupOfCar4 = $result->groupOfEntry($car4[0]);
        $this->assertSame(RaceScenario::ms('01:00'), $groupOfCar4->tsMs);
        $this->assertFalse($groupOfCar4->antesDaLargada, 'A pass exactly at the start instant belongs to the race.');
        $this->assertSame(0, $groupOfCar4->numeroVolta);

        $groupOfCar5 = $result->groupOfEntry($car5[0]);
        $this->assertCount(3, $groupOfCar5->entries);
        $this->assertFalse($groupOfCar5->antesDaLargada);
        $this->assertSame(0, $groupOfCar5->numeroVolta);
        $groupOfCar6 = $result->groupOfEntry($car6[2]);
        $this->assertCount(3, $groupOfCar6->entries);
        $this->assertTrue($groupOfCar6->antesDaLargada);
        $this->assertSame([6], $this->assertSingleOpenFlag($result, Flag::ANTES_DA_LARGADA)->carros);
    }

    /**
     * SITUATION: the field reaches the line a minute after the start.
     * Before the start, counter 1 double-tapped car 5 (two entries two
     * seconds apart) and typed car 6 twice, ten seconds apart; the other
     * counters typed each car once.
     *
     * EXPECTED: repeats before the start are dealt with as always (the
     * double tap is dropped, the ten-second repeat is kept in a group of
     * its own), but no "repetida" flag is raised for either: warm-up typing
     * is not something to review. Each car has its one "antes_da_largada"
     * flag, about the group all three counters agree on, not about counter
     * 1's stray second entry.
     */
    public function testRepeatsBeforeTheStartRaiseNoFlagOfTheirOwn(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([11, 12, 13], '01:00');
        $car5 = $race->pass(5, '-00:10');
        $doubleTap = $race->entry(1, 5, '-00:08');
        $car6 = $race->pass(6, '-00:15');
        $strayRepeat = $race->entry(1, 6, '-00:05');

        $result = $race->run('02:00');

        $this->assertSame(
            [Flag::ANTES_DA_LARGADA, Flag::ANTES_DA_LARGADA],
            array_map(fn(Flag $flag) => $flag->tipo, $result->flags),
            'No "repetida" flag, open or auto-resolved.'
        );
        $this->assertSame([$doubleTap], $result->autoVoidedEntryIds);
        $this->assertSame(0, $result->sourceStats[1]->repetidasAnuladas, 'Warm-up double taps are not held against the counter.');
        $flagOfCar6 = $this->openFlags($result)[0];
        $this->assertSame([6], $flagOfCar6->carros);
        $this->assertSame(array_values($car6), $flagOfCar6->passagemIds);
        $this->assertNotSame($result->groupOfEntry($car6[1]), $result->groupOfEntry($strayRepeat));
        $this->assertSame(array_values($car5), $this->openFlags($result)[1]->passagemIds);
    }

    /**
     * SITUATION: three teams are at the event: 7, 11 and 12, plus team 50
     * which is present but not classified. Three seconds before the start:
     * counter 1 alone types car 7; everybody types 99 (no such team) and
     * 50.
     *
     * EXPECTED:
     * - car 7's lone entry is flagged "antes_da_largada" like any other
     *   pass before the start, naming the counter it comes from, but with
     *   no suggestion to count it;
     * - 99 and 50 get the flag that says more about them ("carro
     *   desconhecido", "carro não classificado"), not "antes da largada".
     */
    public function testALoneEntryAndCarsThatDoNotTakePartBeforeTheStart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([7, 11, 12]);
        $race->teamsNotClassified([50]);
        $race->start('00:00');
        $lone = $race->entry(1, 7, '-00:03');
        $race->pass(99, '-00:03');
        $race->pass(50, '-00:03');

        $result = $race->run('01:00');

        $flagsByCar = [];
        foreach ($result->openFlags() as $flag) {
            $flagsByCar[$flag->carros[0]] = $flag;
        }
        ksort($flagsByCar);
        $this->assertSame(
            [7 => Flag::ANTES_DA_LARGADA, 50 => Flag::CARRO_NAO_CLASSIFICADO, 99 => Flag::CARRO_DESCONHECIDO],
            array_map(fn(Flag $flag) => $flag->tipo, $flagsByCar)
        );
        $this->assertSame(1, $flagsByCar[7]->fonteId);
        $this->assertSame([$lone], $flagsByCar[7]->passagemIds);
        $this->assertNull($flagsByCar[7]->sugestao, 'One counter\'s word is not enough to suggest adding a lap.');
        $this->assertNull($flagsByCar[7]->voltaResultanteMs, 'Car 7 has no other pass to measure a lap from.');
    }

    /**
     * SITUATION: the race of the first test, start moved to 00:10, looked
     * at through the statistics per counter.
     *
     * EXPECTED: entries typed before the start and not (yet) accepted as an
     * initial pass are not "entries in use": each counter has 8 (cars 1 and
     * 2 once, cars 3 to 5 twice). After the reviewer accepts both flags
     * they are in use: 10 each.
     */
    public function testEntriesBeforeTheStartAreNotInUseUntilAccepted(): void
    {
        $race = $this->fiveCarsTwoCrossingsEach();
        $race->start('00:10');
        $moved = $race->run('06:00');
        $this->assertSame(8, $moved->sourceStats[1]->entradas);
        $this->assertCount(8, $moved->laps, 'And they are not rows of the lap table.');

        foreach ($moved->openFlags() as $flag) {
            $race->accept($flag);
        }
        $accepted = $race->run('06:00');
        $this->assertSame(10, $accepted->sourceStats[1]->entradas);
        $this->assertCount(10, $accepted->laps);
    }
}
