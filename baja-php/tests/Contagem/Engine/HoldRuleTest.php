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
        $this->assertStringContainsString('está contada mesmo assim', $flag->motivo);
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
     * pass while the pit log says it is in the pit lane, which counts all
     * the same). Car 10, last, has a held pass, and car 9 has a pass a
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
        // Car 1 crosses at 03:00, ..., 18:00: logged into the pit lane just before its last pass.
        $race->pitIn(1, '17:50');
        // Car 9 crosses at 03:56, ..., 18:56: a reviewer adds a pass 25 s after one of them.
        $race->addPass(9, '13:21');
        // Car 10 crosses at 04:03, ..., 19:03: two counters have it again 30 s after one of them.
        $race->pass(10, '13:33', [1, 2]);

        $flags = $race->run('20:00')->openFlags();

        $this->assertSame(
            [[Flag::VOLTA_CURTA, [9], 'a_mais'], [Flag::PASSAGEM_PROXIMA_DEMAIS, [10], 'a_menos'], [Flag::NO_BOX, [1], null]],
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
}
