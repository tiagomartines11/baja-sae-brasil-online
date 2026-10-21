<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The hold rule, at its edges.
 *
 * The rule: a pass that would count, but comes sooner after the car's
 * previous official pass than the car's minimum plausible lap, is held: not
 * counted, shown as pending, flagged "passagem_proxima_demais", and counted
 * once a reviewer confirms it. It is what keeps the safe mode from ever
 * counting one crossing twice.
 *
 * ImpossiblyShortLapTest shows the rule itself and its two limits. Here:
 * how it behaves in each publish mode, around the start and the finish,
 * through the fast path, and where its flags go in the review queue.
 */
final class HoldRuleTest extends EngineTestCase
{
    /** Car 9's first crossing, then two passes all three counters agree on, 21 s apart. */
    private function twoAgreedPassesTwentyOneSecondsApart(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $race->pass(9, '03:21');
        return $race;
    }

    /**
     * SITUATION: the two passes 21 s apart, in "todas" mode, where every
     * entry that was not voided counts.
     *
     * EXPECTED: "todas" means what it says, so nothing is held in the count
     * in force: two laps. But flags are the same in every mode: the second
     * pass still gets its "passagem_proxima_demais" flag. Since the pass
     * counts here, the flag says the car may have a lap TOO MANY, and that
     * confirming changes nothing.
     */
    public function testInTodasModeNothingIsHeldButTheFlagIsStillThere(): void
    {
        $race = $this->twoAgreedPassesTwentyOneSecondsApart();
        $race->settings->modoPublicacao = Settings::MODO_TODAS;

        $result = $race->run('05:00');

        $this->assertLapCounts([9 => 2], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::PASSAGEM_PROXIMA_DEMAIS);
        $this->assertSame(Flag::DUVIDA_A_MAIS, $flag->contagemEmDuvida);
        $this->assertSame(Flag::EFEITO_NENHUM, $flag->efeitoDeConfirmar);
        $group = $result->groupOfEntry($flag->passagemIds[0]);
        $this->assertFalse($group->retida, 'Not held in the count in force.');
        $this->assertTrue($group->retidaNaReferencia, 'Held under the default rule, which the flags are judged by.');
    }

    /**
     * SITUATION: "fonte_principal" mode, counter 1 is the primary. Car 9
     * made its first crossing; then counter 1 alone has it at 03:00 and
     * again at 03:21.
     *
     * EXPECTED: in this mode whatever the primary has counts, and that is
     * exactly where one person's double entry would give a car a lap. The
     * hold rule applies to the primary's passes as well: the pass at 03:21
     * is held and car 9 has one lap, not two.
     */
    public function testInFontePrincipalModeThePrimarysPassesAreHeldToo(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->entry(1, 9, '03:00');
        $second = $race->entry(1, 9, '03:21');

        $result = $race->run('05:00');

        $this->assertLapCounts([9 => 1], $result);
        $held = $result->groupOfEntry($second);
        $this->assertTrue($held->retida);
        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao);
        $this->assertSame(1, $race->countCars('05:00', [9])->lapCount(9), 'The fast path agrees.');
    }

    /**
     * SITUATION: car 9's pass at 03:00, and a pass a reviewer ADDED at
     * 03:21 ("adicionar": the reviewer saw on the video a crossing nobody
     * typed).
     *
     * EXPECTED: what a reviewer said always counts; it is never held. Two
     * laps. Because the two passes are closer than a lap can be, the pair
     * still gets a "volta_curta" flag saying the car may have a lap too
     * many: the reviewer may have added a pass that was there already.
     */
    public function testAPassAReviewerAddedIsNeverHeld(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $race->addPass(9, '03:21');

        $result = $race->run('05:00');

        $this->assertLapCounts([9 => 2], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::VOLTA_CURTA);
        $this->assertSame(Flag::DUVIDA_A_MAIS, $flag->contagemEmDuvida);
    }

    /**
     * SITUATION: everybody types car 9 five seconds BEFORE the start
     * (warm-up, or a start recorded late) and again ten seconds after it.
     * Nobody has reviewed anything. Then a reviewer confirms the group
     * before the start as the car's initial pass.
     *
     * EXPECTED: a group before the start is not a pass, so the crossing at
     * 00:10 has nothing to be too close to: it is the car's pass zero, not
     * held. Once the reviewer confirms the earlier group, THAT is the
     * car's pass zero, and the crossing fifteen seconds after it is held.
     */
    public function testAGroupBeforeTheStartOnlyHoldsTheNextPassOnceItIsThePassZero(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $beforeTheStart = $race->pass(9, '-00:05');
        $afterTheStart = $race->pass(9, '00:10');
        $race->pass(9, '03:10');

        $unreviewed = $race->run('04:00');
        $this->assertFalse($unreviewed->groupOfEntry($afterTheStart[1])->retida);
        $this->assertSame(0, $unreviewed->groupOfEntry($afterTheStart[1])->numeroVolta);
        $this->assertLapCounts([9 => 1], $unreviewed);

        $race->confirm($beforeTheStart[1]);
        $confirmed = $race->run('04:00');
        $this->assertSame(0, $confirmed->groupOfEntry($beforeTheStart[1])->numeroVolta);
        $this->assertTrue($confirmed->groupOfEntry($afterTheStart[1])->retida);
        $this->assertLapCounts([9 => 1], $confirmed, 'Pass zero before the start, the lap at 03:10; the crossing at 00:10 is held.');
    }

    /**
     * SITUATION: the other pass-zero rule ("largada") with a start window
     * of 30 seconds: the first crossing is not supposed to be typed, and
     * what is typed in the first 30 s never counts. Everybody types car 9
     * at 00:10 anyway, and again at 00:45.
     *
     * EXPECTED: the crossing inside the start window is not a pass, so the
     * one at 00:45 is not "35 s after the previous pass": it is the car's
     * lap 1 and is not held.
     */
    public function testACrossingInsideTheStartWindowDoesNotHoldTheNextOne(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->passagemInicial = Settings::INICIAL_LARGADA;
        $race->settings->janelaLargadaS = 30;
        $race->start('00:00');
        $race->pass(9, '00:10');
        $lapOne = $race->pass(9, '00:45');

        $result = $race->run('02:00');

        $this->assertLapCounts([9 => 1], $result);
        $this->assertFalse($result->groupOfEntry($lapOne[1])->retida);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the race ends at 10:00 and each car's next pass still
     * counts. Car 9 passed at 09:50. Two counters record it again at 10:12
     * (twenty-two seconds later: a phantom, or one crossing typed twice), and
     * everybody records its real pass after the flag at 12:50.
     *
     * EXPECTED: the pass at 10:12 is held. A held pass is not a pass, so it
     * does not use up the one pass allowed after the finish: the real one
     * at 12:50 counts. Two laps (09:50 and 12:50).
     */
    public function testAHeldPassDoesNotUseUpTheOnePassAllowedAfterTheFinish(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '09:50');
        $race->finish('10:00');
        $phantom = $race->pass(9, '10:12', [1, 2]);
        $afterTheFlag = $race->pass(9, '12:50');

        $result = $race->run('14:00');

        $this->assertLapCounts([9 => 2], $result);
        $this->assertTrue($result->groupOfEntry($phantom[1])->retida);
        $this->assertTrue($result->groupOfEntry($afterTheFlag[1])->contado);
        $this->assertFalse($result->groupOfEntry($afterTheFlag[1])->ignoradoAposEncerramento);
        $this->assertSingleOpenFlag($result, Flag::PASSAGEM_PROXIMA_DEMAIS);
    }

    /**
     * SITUATION: car 9 has eight passes three minutes apart and, twice, a
     * second agreed pass 21 s after a real one. The server recounts car 9
     * alone after an upload (`countCars`), from car 9's entries only.
     *
     * EXPECTED: exactly the rows of the full run: the same laps with the
     * same numbers and times, and the same two pending rows for the held
     * passes. The hold rule uses nothing but the car's own passes.
     */
    public function testRecountingOneCarHoldsTheSamePasses(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 8);
        $race->pass(9, '10:17', [1, 2]);
        $race->pass(9, '19:17', [2, 3]);
        $rows = fn(array $laps) => array_values(array_map(
            fn(Lap $lap) => $lap->numeroVolta . '@' . $lap->tsMs . ' ' . $lap->estado,
            array_filter($laps, fn(Lap $lap) => $lap->carro === 9)
        ));

        $full = $race->run('30:00');
        $alone = $race->countCars('30:00', [9]);

        $this->assertSame($rows($full->laps), $rows($alone->lapsByCar[9]));
        $this->assertSame(7, $alone->lapCount(9));
        $this->assertCount(2, array_filter($alone->lapsByCar[9], fn(Lap $lap) => $lap->estado === Lap::PENDENTE));
    }

    /**
     * SITUATION: a steady race. Car 1 leads; it has an informative flag (a
     * lone entry waiting for a reviewer). Car 10, last, has a held pass, and car 9 has a pass a
     * reviewer added too close to another (two official passes within the
     * minimum lap).
     *
     * EXPECTED: the review queue starts with the flags that put a car's
     * lap count in doubt (car 9 may have a lap too many, car 10 one too
     * few), the better-placed car first; the informative flag about the
     * leader comes after them. Every flag says which it is in
     * `contagem_em_duvida`.
     */
    public function testFlagsThatPutACountInDoubtComeFirstInTheQueue(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        // Car 1 crosses at 03:00, ..., 18:00: counter 2 alone has it once more, a lap later.
        $race->entry(2, 1, '21:00');
        // Car 9 crosses at 03:56, ..., 18:56: a reviewer adds a pass 25 s after one of them.
        $race->addPass(9, '13:21');
        // Car 10 crosses at 04:03, ..., 19:03: two counters have it again 30 s after one of them.
        $race->pass(10, '13:33', [1, 2]);

        $flags = $race->run('22:00')->openFlags();

        $this->assertSame(
            [[Flag::VOLTA_CURTA, [9], 'a_mais'], [Flag::PASSAGEM_PROXIMA_DEMAIS, [10], 'a_menos'], [Flag::SOLITARIA, [1], null]],
            array_map(fn(Flag $flag) => [$flag->tipo, $flag->carros, $flag->toArray()['contagem_em_duvida']], $flags)
        );
    }

    /** @return array<string, array{0: int[], 1: int}> the car's recent laps in seconds, and the minimum expected in ms */
    public static function recentLapsAndMinimum(): array
    {
        return [
            'no laps yet: two matching windows' => [[], 40000],
            'two laps are too few to know the car' => [[180, 180], 40000],
            'three laps: half the shortest' => [[600, 180, 590], 90000],
            'four laps: half the second shortest' => [[180, 190, 200, 210], 95000],
            'two long laps among five do not count' => [[600, 180, 600, 190, 185], 92500],
            'one very short lap does not lower it' => [[180, 180, 60, 180, 180], 90000],
            'only the last five are looked at' => [[100, 100, 100, 180, 180, 180, 180, 180], 90000],
            'never under two matching windows' => [[50, 50, 50, 50, 50], 40000],
        ];
    }

    /**
     * SITUATION: the minimum plausible lap of a car, from its own recent
     * laps (Settings::minimumPlausibleLapMs), with the default settings: a
     * 20 s matching window and `fracao_volta_minima` = 0.5.
     *
     * EXPECTED: two matching windows (40 s) until the car has three laps;
     * with three, half the shortest of them; from four on, half the SECOND
     * SHORTEST of its last five laps (when that is more than 40 s). Long laps (a breakdown, a pit stop) do not raise it,
     * and one short lap (a phantom that was counted) does not lower it.
     *
     * @param int[] $recentLapsSeconds
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('recentLapsAndMinimum')]
    public function testTheMinimumPlausibleLapComesFromTheCarsOwnRecentLaps(array $recentLapsSeconds, int $expectedMs): void
    {
        $laps = array_map(fn(int $seconds) => $seconds * 1000, $recentLapsSeconds);

        $this->assertSame($expectedMs, (new Settings())->minimumPlausibleLapMs($laps));
    }

    /**
     * EXPECTED: the two settings behind the minimum are honoured, a car
     * with no laps (null) is like one with none, and the spread accepted
     * between agreeing counters is never wider than the matching window.
     */
    public function testTheMinimumFollowsItsSettings(): void
    {
        $settings = new Settings();
        $settings->janelaMs = 30000;
        $settings->fracaoVoltaMinima = 0.8;
        $this->assertSame(60000, $settings->minimumPlausibleLapMs(null));
        $this->assertSame(144000, $settings->minimumPlausibleLapMs([180000, 180000, 180000, 180000]));

        $this->assertSame(4000, $settings->agreementSpreadMs());
        $settings->janelaMs = 3000;
        $this->assertSame(3000, $settings->agreementSpreadMs());
    }

    /**
     * Two counters. Car 9 laps every four minutes. Twice only one counter
     * types it (20:20 and 28:20: a lap short each time, a lone entry
     * flagged for review), and then it stops on track twice for sixteen
     * minutes (52:20 and 72:20).
     */
    private function carNineWithTwoLoneMissesAndTwoLongStops(): RaceScenario
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        foreach (['04:20', '08:20', '12:20', '16:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->entry(1, 9, '20:20');
        $race->pass(9, '24:20');
        $race->entry(2, 9, '28:20');
        $race->pass(9, '32:20');
        $race->pass(9, '52:20');
        $race->pass(9, '72:20');
        return $race;
    }

    /**
     * SITUATION: the car above then runs an ordinary lap, 3:50 (a little
     * quicker than its usual four minutes), and both counters type it.
     *
     * WHAT WENT WRONG (found by the race simulator, two counters): the lone
     * crossings were not passes the hold rule measures laps between, so
     * each one made the car's lap look eight minutes long. Its last five
     * laps read 4:00, 8:00, 8:00, 20:00, 20:00, its normal lap (the second
     * shortest) became 8:00, the minimum plausible lap 4:00, and the real
     * lap of 3:50 that both counters typed was held as "próxima demais".
     *
     * EXPECTED: a lone crossing that sits where a lap of the car is
     * believable (at least three quarters of its normal lap after the
     * previous pass and before the next one) splits the gap in two laps
     * for the normal-lap estimate, the way a held pass does. The normal lap
     * stays at 4:00, the bar at 2:00, and the pass at 76:10 counts: 9 laps
     * (the two lone crossings still wait for a reviewer). The quick per-car
     * recount agrees.
     */
    public function testALoneCrossingDoesNotMakeTheCarsNormalLapLookTwiceAsLong(): void
    {
        $race = $this->carNineWithTwoLoneMissesAndTwoLongStops();
        $real = $race->pass(9, '76:10');

        $result = $race->run('77:00');

        $group = $result->groupOfEntry($real[1]);
        $this->assertTrue($group->contado, 'The real lap both counters typed counts.');
        $this->assertNull($group->motivoRetencao);
        $this->assertLapCounts([9 => 9], $result);
        $this->assertSame([], $this->openFlags($result, Flag::PASSAGEM_PROXIMA_DEMAIS));
        $this->assertSame(9, $race->countCars('77:00', [9])->lapCount(9), 'The fast path agrees.');
    }

    /**
     * SITUATION: the bar must not drop where it protects. Car 9 laps every
     * four minutes, with two counters. In two of its last laps a counter
     * types it alone in the MIDDLE of the lap (22:20 and 26:20: strays,
     * two minutes after a pass), and then it stops on track for six
     * minutes (28:20 to 38:20), with another stray halfway (33:20). At
     * 40:10, 1:50 after its pass at 38:20, both counters type car 9 by
     * mistake (a shared wrong number: a phantom).
     *
     * EXPECTED: a stray two minutes into a four-minute lap is not where a
     * lap can end, so it splits nothing (had it split, the car's laps would
     * read 2:00 and its bar 1:00, and the phantom would count). The stray
     * in the long lap may split it, but only into parts no shorter than
     * three quarters of a normal lap, which leaves the bar where it was:
     * the normal lap is still 4:00, the bar 2:00, and the phantom is held.
     * Eight laps, not nine; the quick recount agrees.
     */
    public function testALoneStrayInTheMiddleOfALapDoesNotLowerTheBar(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        foreach (['04:20', '08:20', '12:20', '16:20', '20:20', '24:20', '28:20', '38:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->entry(1, 9, '22:20');
        $race->entry(2, 9, '26:20');
        $race->entry(1, 9, '33:20');
        $phantom = $race->pass(9, '40:10');

        $result = $race->run('41:00');

        $held = $result->groupOfEntry($phantom[1]);
        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao);
        $this->assertSame(120000, $held->voltaMinimaMs);
        $this->assertLapCounts([9 => 8], $result);
        $this->assertSame(8, $race->countCars('41:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: car 9 laps every four minutes, with two counters, then
     * stands on the track for twenty minutes (24:20 to 44:20). While it
     * stands there each counter types it once by mistake, three minutes
     * apart (27:20 and 30:20: strays). At 46:00, 1:40 after its pass at
     * 44:20, both counters type car 9 by mistake (a phantom); its real pass
     * is at 48:20.
     *
     * WHAT WENT WRONG (review of the first version of the lone-crossing
     * split): each stray sat three quarters of a normal lap from the
     * previous end, so both split the stop, the car's laps read 4:00, 4:00,
     * 3:00, 3:00, 14:00, its normal lap became 3:00 and the bar 1:30, and
     * the phantom counted, with no flag: a lap the car never ran, in
     * "confirmadas" mode, without anyone looking.
     *
     * EXPECTED: a split never takes the normal lap below the car's counted
     * laps (four minutes here). The bar stays at 2:00, the phantom is held
     * and flagged, and car 9 has 8 laps; the quick recount agrees.
     */
    public function testStraysDuringAStopOnTrackDoNotLowerTheBar(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        foreach (['04:20', '08:20', '12:20', '16:20', '20:20', '24:20', '44:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->entry(1, 9, '27:20');
        $race->entry(2, 9, '30:20');
        $phantom = $race->pass(9, '46:00');
        $race->pass(9, '48:20');

        $result = $race->run('49:00');

        $held = $result->groupOfEntry($phantom[1]);
        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao);
        $this->assertSame(120000, $held->voltaMinimaMs);
        $this->assertLapCounts([9 => 8], $result);
        $this->assertCount(1, $this->openFlags($result, Flag::PASSAGEM_PROXIMA_DEMAIS));
        $this->assertSame(8, $race->countCars('49:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: as above, and then a second twenty-minute stop (44:20 to
     * 64:20) with two more strays, 2:15 apart (46:35 and 48:50). At 65:40,
     * 1:20 after the pass at 64:20, a phantom both counters typed; the
     * real pass is at 68:20.
     *
     * WHAT WENT WRONG (same review): each stop measured its split against
     * the normal lap the previous one had already lowered, so the bar kept
     * falling, 2:00, then 1:30, then 1:07, and the phantom counted.
     *
     * EXPECTED: the floor does not move with the splits. The bar is still
     * 2:00, the phantom is held, 9 laps.
     */
    public function testStopAfterStopTheBarDoesNotKeepFalling(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        foreach (['04:20', '08:20', '12:20', '16:20', '20:20', '24:20', '44:20', '64:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->entry(1, 9, '27:20');
        $race->entry(2, 9, '30:20');
        $race->entry(1, 9, '46:35');
        $race->entry(2, 9, '48:50');
        $phantom = $race->pass(9, '65:40');
        $race->pass(9, '68:20');

        $result = $race->run('69:00');

        $held = $result->groupOfEntry($phantom[1]);
        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao);
        $this->assertSame(120000, $held->voltaMinimaMs);
        $this->assertLapCounts([9 => 9], $result);
        $this->assertSame(9, $race->countCars('69:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: car 9 laps every four minutes, with two counters. Twice
     * counter 2 misses it (24:20 and 32:20, typed by counter 1 alone), and
     * each time counter 2 types it by mistake a minute before (23:20 and
     * 31:20). Then a phantom both counters typed at 38:00, 1:40 after the
     * pass at 36:20, and the real pass at 40:20.
     *
     * WHAT WENT WRONG (same review): the split took the first lone crossing
     * far enough from the previous pass, the stray, so the laps read 3:00
     * and 5:00 instead of 4:00 and 4:00, the bar fell to 1:30 and the
     * phantom counted; once a reviewer confirmed the two missed passes the
     * car would have been a lap above the truth.
     *
     * EXPECTED: the lone crossing that leaves the lap closest to a normal
     * one ends it (the missed pass, not the stray), and in any case the
     * floor keeps the bar at 2:00. The phantom is held: 8 laps, with the
     * two missed passes still pending (10 once confirmed, the truth).
     */
    public function testAStrayJustBeforeAMissedPassDoesNotTakeItsPlace(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        foreach (['04:20', '08:20', '12:20', '16:20', '20:20', '28:20', '36:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->entry(2, 9, '23:20');
        $race->entry(1, 9, '24:20');
        $race->entry(2, 9, '31:20');
        $race->entry(1, 9, '32:20');
        $phantom = $race->pass(9, '38:00');
        $race->pass(9, '40:20');

        $result = $race->run('41:00');

        $held = $result->groupOfEntry($phantom[1]);
        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao);
        $this->assertSame(120000, $held->voltaMinimaMs);
        $this->assertLapCounts([9 => 8], $result);
        $this->assertSame(8, $race->countCars('41:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: car 9 laps in four minutes, then slows down to nine (a
     * damaged car). One counter misses four of its slow laps (30:20,
     * 48:20, 66:20, 84:20: a lone crossing each), so the gaps between the
     * passes both counters typed read eighteen minutes. Then both type its
     * next lap, 8:40 after the previous pass.
     *
     * EXPECTED: the floor only keeps a split from going below the car's
     * counted laps; it does not stop the splits that are right. The lone
     * crossings split the eighteen-minute gaps into nine-minute laps, the
     * normal lap is nine minutes (not eighteen), the bar 4:30, and the lap
     * of 8:40 counts: 9 laps, nothing held.
     */
    public function testTheFloorDoesNotStopASplitThatIsRight(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        foreach (['04:20', '08:20', '12:20', '21:20', '39:20', '57:20', '75:20', '93:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->entry(1, 9, '30:20');
        $race->entry(2, 9, '48:20');
        $race->entry(1, 9, '66:20');
        $race->entry(2, 9, '84:20');
        $real = $race->pass(9, '102:00');

        $result = $race->run('103:00');

        $this->assertTrue($result->groupOfEntry($real[1])->contado, 'The real lap both counters typed counts.');
        $this->assertLapCounts([9 => 9], $result);
        $this->assertSame([], $this->openFlags($result, Flag::PASSAGEM_PROXIMA_DEMAIS));
        $this->assertSame(9, $race->countCars('103:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: car 9 laps in four minutes, with two counters. Its first
     * three laps each hold a five-minute pit stop the pit log recorded
     * (02:00-07:00, 11:00-16:00, 20:00-25:00), so they read nine minutes;
     * then it stands on the track for twenty minutes (27:20 to 47:20,
     * nobody logs that), and its next lap, at 51:20, is an ordinary four
     * minutes both counters typed. Three more laps follow, then a phantom
     * both counters typed at 65:00, 1:40 after the pass at 63:20, and the
     * real pass at 67:20.
     *
     * WHAT WENT WRONG (race simulator, seed 6, two counters): the laps
     * behind the normal lap counted the pit stays at full length, so they
     * read 9:00, 9:00, 9:00, 20:00, the normal lap was nine minutes, the
     * bar 4:30, and the real lap at 51:20 was held.
     *
     * EXPECTED: a lap the car spent part of logged inside the pit lane (or
     * the fueling station) says nothing of its pace and is left out of the
     * normal lap. With only the twenty-minute stop left the car has no
     * normal lap yet, the bar is two windows, and the lap at 51:20 counts.
     * The laps that follow give it its four-minute normal lap again, the
     * bar is back at 2:00 and the phantom is held: 9 laps, the quick
     * recount agrees.
     */
    public function testPitStopsInsideALapDoNotMakeTheCarsNormalLapLonger(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        foreach ([['02:00', '07:00', '09:20'], ['11:00', '16:00', '18:20'], ['20:00', '25:00', '27:20']] as [$in, $out, $crossing]) {
            $race->pitIn(9, $in);
            $race->pitOut(9, $out);
            $race->pass(9, $crossing);
        }
        $race->pass(9, '47:20');
        $afterTheStop = $race->pass(9, '51:20');
        foreach (['55:20', '59:20', '63:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $phantom = $race->pass(9, '65:00');
        $race->pass(9, '67:20');

        $result = $race->run('68:00');

        $this->assertTrue($result->groupOfEntry($afterTheStop[1])->contado, 'The real lap after the stop counts.');
        $held = $result->groupOfEntry($phantom[1]);
        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao);
        $this->assertSame(120000, $held->voltaMinimaMs);
        $this->assertLapCounts([9 => 9], $result);
        $this->assertCount(1, $this->openFlags($result, Flag::PASSAGEM_PROXIMA_DEMAIS));
        $this->assertSame(9, $race->countCars('68:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: car 9 laps in four minutes, with two counters: 00:20,
     * 04:20, then a six-minute pit stop the pit log recorded (06:00 to
     * 12:00), 14:20, 18:20. At 19:20, a minute after its pass, both
     * counters type 9 (a phantom), and the car really crosses at 22:20.
     *
     * WHAT WENT WRONG (review of the first version of the rule above): the
     * pit lap was simply left out, so the car had two laps and no normal
     * lap, the bar fell to two windows (40 s) and the phantom counted: five
     * laps against a truth of four, and no flag.
     *
     * EXPECTED: until the car has three laps without a stop, a lap with a
     * logged stay still counts towards its normal lap, with the stay taken
     * out (10:00 minus 6:00: four minutes). The normal lap is four minutes,
     * the bar 2:00, the phantom is held: 4 laps, the quick recount agrees.
     */
    public function testAStopInsideOneOfTheFirstLapsDoesNotLowerTheBar(): void
    {
        foreach (['box', 'abastecimento'] as $area) {
            $race = RaceScenario::withCounters(2);
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->pass(9, '04:20');
            if ($area === 'box') {
                $race->pitIn(9, '06:00');
                $race->pitOut(9, '12:00');
            } else {
                $race->fuelIn(9, '06:00');
                $race->fuelOut(9, '12:00');
            }
            $race->pass(9, '14:20');
            $race->pass(9, '18:20');
            $phantom = $race->pass(9, '19:20');
            $race->pass(9, '22:20');

            $result = $race->run('23:00');

            $held = $result->groupOfEntry($phantom[1]);
            $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao, $area);
            $this->assertSame(120000, $held->voltaMinimaMs, $area);
            $this->assertLapCounts([9 => 4], $result);
            $this->assertSame(4, $race->countCars('23:00', [9])->lapCount(9), $area);
        }
    }

    /**
     * SITUATION: car 9 laps in four minutes, with two counters, but the pit
     * log has it inside for a minute in the middle of each of its first
     * three laps (a stay typed for the wrong car). After its fourth lap,
     * both counters type 9 again 1:10 after its pass (a phantom).
     *
     * EXPECTED: the stays shorten those laps to three minutes, so the bar
     * is 1:30, never the two windows, and the phantom is held: 5 laps.
     */
    public function testStaysLoggedForTheWrongCarDoNotLetAPhantomCount(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $t = 20;
        for ($lap = 0; $lap < 4; $lap++) {
            if ($lap < 3) {
                $race->pitIn(9, RaceScenario::clock($t + 60));
                $race->pitOut(9, RaceScenario::clock($t + 120));
            }
            $t += 240;
            $race->pass(9, RaceScenario::clock($t));
        }
        $phantom = $race->pass(9, RaceScenario::clock($t + 70));
        $race->pass(9, RaceScenario::clock($t + 240));
        $now = RaceScenario::clock($t + 260);

        $result = $race->run($now);

        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $result->groupOfEntry($phantom[1])->motivoRetencao);
        $this->assertLapCounts([9 => 5], $result);
        $this->assertSame(5, $race->countCars($now, [9])->lapCount(9));
    }

    /**
     * SITUATION: car 9 passed at 03:00 and is logged into the pit lane at
     * 05:00. At 06:00 two counters record it at the line (a wrong number:
     * the car is standing in its pit). It is logged out at 06:10 and
     * really crosses at 06:30, recorded by everybody.
     *
     * EXPECTED: the pass at 06:00 is held because the car is logged inside
     * (it used to count, which put the car a lap ahead). And a pass held
     * that way is not what the next one is measured from: the real pass at
     * 06:30 is 210 s after the car's last OFFICIAL pass, not 30 s, so it
     * counts. One lap.
     */
    public function testAPassHeldInsideThePitLaneIsNotWhatTheNextOneIsMeasuredFrom(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $race->pitIn(9, '05:00');
        $inside = $race->pass(9, '06:00', [1, 2]);
        $race->pitOut(9, '06:10');
        $real = $race->pass(9, '06:30');

        $result = $race->run('08:00');

        $this->assertSame(PassGroup::RETIDA_NO_BOX, $result->groupOfEntry($inside[1])->motivoRetencao);
        $this->assertTrue($result->groupOfEntry($real[1])->contado);
        $this->assertLapCounts([9 => 2], $result);
        $this->assertSame(2, $race->countCars('08:00', [9])->lapCount(9));
    }

    /**
     * SITUATION: car 9 starts the race from the pit lane: logged in at
     * 00:05, out at 02:00. Everybody records it at the line at 00:20 (a
     * wrong number, or a wrong log), and then at 03:20 and 06:20.
     *
     * EXPECTED: the rule also applies to what would be the car's FIRST
     * pass: the one at 00:20 is held. Its pass zero is the crossing at
     * 03:20, and it has one lap, not two.
     */
    public function testWhatWouldBeTheCarsFirstPassIsHeldTooWhileItIsLoggedInside(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitIn(9, '00:05');
        $inside = $race->pass(9, '00:20');
        $race->pitOut(9, '02:00');
        $race->pass(9, '03:20');
        $race->pass(9, '06:20');

        $result = $race->run('07:00');

        $this->assertTrue($result->groupOfEntry($inside[1])->retida);
        $this->assertLapCounts([9 => 1], $result);
    }

    /**
     * Car 9 laps every three minutes (00:20, 03:20, 06:20, 09:20, 12:20),
     * then is logged INTO the pit lane at 13:00, and nobody ever logs it
     * out.
     */
    private function carNineGoesIntoThePitLaneAndIsNeverLoggedOut(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        foreach (['00:20', '03:20', '06:20', '09:20', '12:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->pitIn(9, '13:00');
        return $race;
    }

    /**
     * SITUATION: the car above leaves the pit lane, nobody logs the exit,
     * and it goes on lapping: everybody records it at 16:00, 19:00 and
     * 22:00.
     *
     * WHAT WENT WRONG (with the plain pit-lane hold): one missed exit held
     * EVERY later pass of the car until a reviewer noticed. In simulated
     * races that was twenty uncounted crossings per race.
     *
     * EXPECTED:
     * - at 17:00, with one pass inside the stay, that pass is held (a
     *   single agreed pass may be a wrong number typed for a car standing
     *   in its pit);
     * - at 20:00 there is a second agreed pass one lap after the first.
     *   The car is plainly lapping, so the engine assumes the exit nobody
     *   logged, one second before the first of the two: both passes count,
     *   and so does the one at 22:00. Seven laps in the end;
     * - the stay ends at 15:59 on the timeline (so the car's status and the
     *   stops report agree with the count), and the assumed exit is listed;
     * - one informational flag, "saida_presumida", on the first pass: no
     *   suggestion, nothing held, no count in doubt. Confirming that pass
     *   silences it;
     * - recounting car 9 alone gives the same laps at each step.
     */
    public function testASecondAgreedPassOneLapLaterMeansTheCarLeftAndBothCount(): void
    {
        $race = $this->carNineGoesIntoThePitLaneAndIsNeverLoggedOut();
        $first = $race->pass(9, '16:00');

        $oneInside = $race->run('17:00');
        $this->assertLapCounts([9 => 4], $oneInside);
        $this->assertSame(PassGroup::RETIDA_NO_BOX, $oneInside->groupOfEntry($first[1])->motivoRetencao);
        $this->assertSame(4, $race->countCars('17:00', [9])->lapCount(9));
        $this->assertSame([], $oneInside->timeline->assumedExits);

        $race->pass(9, '19:00');
        $twoInside = $race->run('20:00');
        $this->assertLapCounts([9 => 6], $twoInside);
        $this->assertSame(6, $race->countCars('20:00', [9])->lapCount(9));

        $race->pass(9, '22:00');
        $result = $race->run('23:00');
        $this->assertLapCounts([9 => 7], $result);
        $this->assertSame(7, $race->countCars('23:00', [9])->lapCount(9));
        $this->assertSame([[RaceScenario::ms('13:00'), RaceScenario::ms('15:59')]], $result->timeline->pitIntervals[9]);
        $this->assertSame(
            [['area' => 'box', 'carro' => 9, 'ts_ms' => RaceScenario::ms('15:59'), 'entrada_ms' => RaceScenario::ms('13:00'), 'saida_registrada_ms' => null]],
            $result->timeline->assumedExits
        );
        $this->assertFalse($result->timeline->isInPitAt(9, RaceScenario::ms('23:00')));
        $flag = $this->assertSingleOpenFlag($result, Flag::SAIDA_PRESUMIDA);
        $this->assertSame([9], $flag->carros);
        $this->assertSame(array_values($first), $flag->passagemIds);
        $this->assertNull($flag->sugestao);
        $this->assertNull($flag->contagemEmDuvida);
        $this->assertSame(Flag::EFEITO_NENHUM, $flag->efeitoDeConfirmar);
        $this->assertStringContainsString('presumiu', $flag->motivo);
        $this->assertFalse($result->groupOfEntry($first[1])->retida);

        $race->confirm($first[1]);
        $confirmed = $race->run('23:00');
        $this->assertNoOpenFlags($confirmed);
        $this->assertLapCounts([9 => 7], $confirmed);
    }

    /** @return array<string, array{0: string[], 1: string}> the agreed passes typed while the car stands in its pit, and why they must stay held */
    public static function phantomsForACarInItsPit(): array
    {
        return [
            'ten minutes apart' => [['16:00', '26:00'], 'far more than a lap apart: unrelated'],
            'four and a half minutes apart' => [['16:00', '20:31'], 'just over one and a half normal laps apart'],
            'one minute apart' => [['16:00', '17:00'], 'sooner than the car could have lapped'],
        ];
    }

    /**
     * SITUATION: the same car, but it really IS standing in its pit. Twice
     * two counters type its number by mistake for other cars (a shared
     * wrong number): two "agreed" passes inside the stay.
     *
     * WHY IT MATTERS: the pit-lane hold exists for exactly these phantoms.
     * If any second agreed pass released the first, two phantoms would
     * give the car two laps.
     *
     * EXPECTED: "one lap later" is strict: no sooner than the car's
     * minimum plausible lap (90 s here) and no later than one and a half
     * of its normal laps (270 s). Outside that, both passes stay held, no
     * exit is assumed, and the car keeps its four laps.
     *
     * @param string[] $phantomsAt
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('phantomsForACarInItsPit')]
    public function testTwoAgreedPassesThatAreNotALapApartDoNotReleaseEachOther(array $phantomsAt, string $why): void
    {
        $race = $this->carNineGoesIntoThePitLaneAndIsNeverLoggedOut();
        foreach ($phantomsAt as $at) {
            $race->pass(9, $at, [1, 2]);
        }

        $result = $race->run('30:00');

        $this->assertLapCounts([9 => 4], $result, $why);
        $this->assertSame([], $result->timeline->assumedExits, $why);
        $this->assertCount(2, $this->openFlags($result, Flag::NO_BOX), $why);
    }

    /**
     * SITUATION: three more ways in which the release must NOT happen, and
     * one in which it must:
     * a) the second pass is exactly at the limit (270 s after the first):
     *    released;
     * b) the second "pass" rests on ONE counter: not agreed, no release;
     * c) the two counters of the second pass are 8 s apart (a doubtful,
     *    wide-spread group): not agreed, no release;
     * d) the car has only two laps of its own before the stay, so nobody
     *    knows what a lap of this car is: no release.
     *
     * EXPECTED: as said. In (b), (c) and (d) the first pass stays held.
     */
    public function testTheReleaseNeedsTwoAgreedPassesAndAKnownNormalLap(): void
    {
        $atTheLimit = $this->carNineGoesIntoThePitLaneAndIsNeverLoggedOut();
        $atTheLimit->pass(9, '16:00');
        $atTheLimit->pass(9, '20:30');
        $this->assertLapCounts([9 => 6], $atTheLimit->run('21:00'));

        $oneCounter = $this->carNineGoesIntoThePitLaneAndIsNeverLoggedOut();
        $oneCounter->pass(9, '16:00');
        $oneCounter->entry(2, 9, '19:00');
        $this->assertLapCounts([9 => 4], $oneCounter->run('21:00'));

        $wideSpread = $this->carNineGoesIntoThePitLaneAndIsNeverLoggedOut();
        $wideSpread->pass(9, '16:00');
        $wideSpread->entry(1, 9, '19:00');
        $wideSpread->entry(2, 9, '19:08');
        $this->assertLapCounts([9 => 4], $wideSpread->run('21:00'));

        $newCar = RaceScenario::withCounters(3);
        $newCar->start('00:00');
        foreach (['00:20', '03:20', '06:20'] as $crossing) {
            $newCar->pass(9, $crossing);
        }
        $newCar->pitIn(9, '07:00');
        $newCar->pass(9, '10:00');
        $newCar->pass(9, '13:00');
        $this->assertLapCounts([9 => 2], $newCar->run('14:00'));
    }

    /**
     * SITUATION: the car stands in its pit; two counters type its number
     * by mistake at 16:00 (a phantom). Much later it does leave, unlogged,
     * and laps: everybody records it at 30:00 and 33:00.
     *
     * EXPECTED: the phantom is not what the real passes are measured from.
     * The pass at 30:00 is far too long after it to be "its next lap", so
     * it becomes the new candidate; 33:00 is one lap after THAT, and those
     * two are released with the exit assumed just before 30:00. The
     * phantom at 16:00 stays held, with its flag: 6 laps, not 7.
     */
    public function testAPhantomInsideTheStayIsNotReleasedByTheRealPassesThatFollowMuchLater(): void
    {
        $race = $this->carNineGoesIntoThePitLaneAndIsNeverLoggedOut();
        $phantom = $race->pass(9, '16:00', [1, 2]);
        $race->pass(9, '30:00');
        $race->pass(9, '33:00');

        $result = $race->run('34:00');

        $this->assertLapCounts([9 => 6], $result);
        $this->assertTrue($result->groupOfEntry($phantom[1])->retida);
        $this->assertSame(RaceScenario::ms('29:59'), $result->timeline->assumedExits[0]['ts_ms']);
        $types = array_map(fn(Flag $flag) => $flag->tipo, $result->openFlags());
        sort($types);
        $this->assertSame([Flag::NO_BOX, Flag::SAIDA_PRESUMIDA], $types);
    }

    /**
     * SITUATION: a car with no laps of its own yet is logged into the
     * fueling station at 05:00 and never out. All three counters record it
     * at 07:00, 10:00 and 13:00, and counter 2 alone at 16:00. (With no
     * known normal lap, nothing is released: four flags.)
     *
     * EXPECTED: each flag says how many held passes would start counting
     * if the exit were recorded just before ITS pass, for the button
     * "Marcar saída (+N voltas)": 3, 2 and 1 for the agreed passes (that
     * pass and the later ones held in the same stay), and 0 for the pass
     * only one counter saw. Recording the exit before the second pass does
     * exactly what its flag said: two more passes count.
     */
    public function testEachFlagOfAPassHeldInsideAnAreaSaysHowManyPassesAnExitWouldRelease(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->fuelIn(6, '05:00');
        $race->pass(6, '07:00');
        $race->pass(6, '10:00');
        $race->pass(6, '13:00');
        $race->entry(2, 6, '16:00');

        $result = $race->run('17:00');

        $flags = $this->openFlags($result, Flag::NO_ABASTECIMENTO);
        usort($flags, fn(Flag $a, Flag $b) => $a->tsMs <=> $b->tsMs);
        $this->assertSame([3, 2, 1, 0], array_map(fn(Flag $flag) => $flag->toArray()['voltas_ao_marcar_saida'], $flags));
        $before = $result->lapCount(6);

        $race->inferredExit('abastecimento', 6, RaceScenario::ms('09:59'));
        $this->assertSame($before + 2, $race->run('17:00')->lapCount(6));
    }

    /**
     * SITUATION: a race with ONE counter, whose word is all there is, so
     * its passes count on their own. Car 9 laps, is logged into the pit
     * lane and never out, and the counter records it twice more, a lap
     * apart.
     *
     * EXPECTED: the release is for passes two or more counters agree on.
     * One counter typing a number twice, a lap apart, is not that: both
     * passes stay held and no exit is assumed.
     */
    public function testOneCountersWordDoesNotReleaseAPassHeldInsideThePitLane(): void
    {
        $race = RaceScenario::withCounters(1);
        $race->start('00:00');
        foreach (['00:20', '03:20', '06:20', '09:20', '12:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->pitIn(9, '13:00');
        $race->pass(9, '16:00');
        $race->pass(9, '19:00');

        $result = $race->run('20:00');

        $this->assertLapCounts([9 => 4], $result);
        $this->assertSame([], $result->timeline->assumedExits);
    }
}
