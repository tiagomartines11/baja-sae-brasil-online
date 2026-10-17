<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * A crossing typed late, and the reviewer saying whose time is right.
 *
 * Seen at a real race (26SE, prova END): car 1 crossed the line, Contagem 2
 * typed it at once, Contagem 1 picked the car on the tiles and pressed
 * "Passou" 23 s later. That is beyond the matching window (20 s), so the
 * two entries were two lone entries, each flagged "solitaria" with "o
 * tempo de volta seria plausível": the check only measures a lone entry
 * against the car's official passes, and neither of the two is one.
 * Confirming both gave car 1 a lap too many.
 *
 * Two things answer that:
 *
 * - "registro_tardio": ONE flag about both entries, raised when two lone
 *   entries of one car from two counters would make an impossibly short
 *   lap if both were passes.
 * - "usar_tempo" ("usar o tempo de Contagem 2"): the pass counts once,
 *   timed by the chosen entry; the other joins it as a witness that does
 *   not time it. The same decision picks the time of a held pass whose
 *   entries are too far apart, which confirming would time by the middle.
 */
final class LateEntryTest extends EngineTestCase
{
    /**
     * Nine cars lapping every three minutes, and car 1 doing the same at
     * 01:30, 04:30, ... with every counter recording it, except at 13:30.
     */
    private function raceWithCarOne(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        foreach (['01:30', '04:30', '07:30', '10:30', '16:30'] as $crossing) {
            $race->pass(1, $crossing);
        }
        return $race;
    }

    /**
     * SITUATION: car 1's crossing at 13:30. Contagem 2 typed it at 13:30,
     * Contagem 1 at 13:53.4, Contagem 3 missed it.
     *
     * EXPECTED: one "registro_tardio" flag about both entries, naming the
     * late counter and the gap; no "solitaria". It says the car may be a
     * lap short, offers both times (each with the other entry as the one
     * that joins), and names the car's previous official pass. Nothing
     * counts until the reviewer chooses.
     */
    public function testTwoLoneEntriesOfOneCrossingTypedFarApartAreOneFlag(): void
    {
        $race = $this->raceWithCarOne();
        $onTime = $race->entry(2, 1, '13:30');
        $late = $race->entry(1, 1, '13:53.4');

        $result = $race->run('20:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::REGISTRO_TARDIO);
        $this->assertSame([1], $flag->carros);
        $this->assertSame([$onTime, $late], $flag->passagemIds);
        $this->assertSame(1, $flag->fonteId, 'The flag is about the counter that typed late.');
        $this->assertNull($flag->sugestao, 'Which time is right is for the reviewer to say.');
        $this->assertStringContainsString('Contagem 1 registrou o carro 1 23 s depois de Contagem 2', $flag->motivo);
        $this->assertStringContainsString('digitada com atraso', $flag->motivo);
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);
        // Each time adds the pass (+1), and says the lap it would make there.
        $this->assertSame([
            ['passagem_id' => $onTime, 'fonte_id' => 2, 'ts_ms' => RaceScenario::ms('13:30'), 'passagem_alvo_id' => $late, 'voltas' => 1, 'volta_resultante_ms' => 180000],
            ['passagem_id' => $late, 'fonte_id' => 1, 'ts_ms' => RaceScenario::ms('13:53.4'), 'passagem_alvo_id' => $onTime, 'voltas' => 1, 'volta_resultante_ms' => 156600],
        ], $flag->temposPossiveis);
        $this->assertSame(RaceScenario::ms('10:30'), $flag->passagemAnterior['ts_ms'], 'The previous official pass, for the timeline.');
        $this->assertSame(4, $result->lapCount(1), 'Five agreed crossings are four laps; the late pair waits for the reviewer.');
    }

    /**
     * SITUATION: the late pair of the first test, in the other publish
     * modes. In "todas" both lone entries count already; in
     * "fonte_principal" with Contagem 2 as the primary, its entry does.
     *
     * WHAT WENT WRONG: the flag always said "a lap short" and every button
     * "+1 volta". In "todas" the car is a lap OVER, and choosing a time
     * takes that lap away; with the primary's entry counting, the number of
     * passes is right and choosing a time only fixes it.
     *
     * EXPECTED: "todas": a lap too many, each time -1, and choosing one
     * leaves the pass counted once. "fonte_principal": no doubt about the
     * count, each time 0.
     */
    public function testTheLatePairSaysWhatChoosingATimeDoesInTheModeInForce(): void
    {
        $race = $this->raceWithCarOne();
        $onTime = $race->entry(2, 1, '13:30');
        $late = $race->entry(1, 1, '13:53.4');
        $race->settings->modoPublicacao = Settings::MODO_TODAS;

        $todas = $race->run('20:00');

        [$flag] = $this->openFlags($todas, Flag::REGISTRO_TARDIO);
        $this->assertSame(Flag::DUVIDA_A_MAIS, $flag->contagemEmDuvida);
        $this->assertSame([-1, -1], array_column($flag->temposPossiveis, 'voltas'));
        $this->assertSame(6, $todas->lapCount(1));

        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 2;
        $principal = $race->run('20:00');

        [$flag] = $this->openFlags($principal, Flag::REGISTRO_TARDIO);
        $this->assertNull($flag->contagemEmDuvida);
        $this->assertSame([0, 0], array_column($flag->temposPossiveis, 'voltas'));

        $race->settings->modoPublicacao = Settings::MODO_TODAS;
        $race->useTimeOf($onTime, $late);
        $this->assertSame(5, $race->run('20:00')->lapCount(1), 'Chosen, the crossing counts once, in "todas" too.');
    }

    /**
     * SITUATION: car 6's crossing at 12:35 (it is the fifth car of the
     * field) is agreed by Contagem 1 and Contagem 3. Contagem 2 missed it
     * and typed a lone 6 23.6 s later (it was really car 16 at the race).
     *
     * EXPECTED: no "registro_tardio": the other entry is in a pass the
     * counters agree on, not a lone entry. The stray is an ordinary lone
     * entry, and voiding it is suggested (it would make a 24 s lap).
     */
    public function testAStrayNearAnAgreedPassIsNotALateEntry(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([2, 3, 4, 5, 7, 8, 9, 10], 180, 6);
        foreach (['03:35', '06:35', '09:35', '15:35', '18:35'] as $crossing) {
            $race->pass(6, $crossing);
        }
        $race->pass(6, '12:35', [1, 3]);
        $stray = $race->entry(2, 6, '12:58.6');

        $result = $race->run('20:00');

        $this->assertSame([], $this->openFlags($result, Flag::REGISTRO_TARDIO));
        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame($stray, $flag->sugestao['passagem_id']);
    }

    /**
     * SITUATION: the late pair of the first test. The reviewer presses
     * "Usar o tempo de Contagem 2".
     *
     * EXPECTED: the pass counts once (five laps), at Contagem 2's time, and
     * the late entry is in the same pass. Contagem 3, which missed the
     * crossing, gets a miss; Contagem 1, whose entry is there, does not,
     * and its 23 s does not become a typing habit. The quick recount of
     * an upload says the same. Undoing the decision brings the flag back.
     */
    public function testUsingTheOnTimeEntryCountsThePassOnceAtThatTime(): void
    {
        $race = $this->raceWithCarOne();
        $onTime = $race->entry(2, 1, '13:30');
        $late = $race->entry(1, 1, '13:53.4');

        $decision = $race->useTimeOf($onTime, $late);
        $result = $race->run('20:00');

        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([1 => 5], $result);
        $pass = $result->groupOfEntry($onTime);
        $this->assertSame($pass, $result->groupOfEntry($late), 'The late entry joined the pass.');
        $this->assertSame(RaceScenario::ms('13:30'), $pass->tsMs, 'Timed by Contagem 2, not by the middle of the two.');
        $this->assertTrue($pass->confirmadoPorRevisor);
        $this->assertSame(1, $result->sourceStats[3]->faltas, 'Contagem 3 missed the crossing.');
        $this->assertSame(0, $result->sourceStats[1]->faltas, 'Contagem 1 recorded it, late.');
        $this->assertSame(0, $result->sourceStats[1]->desvioMedianoMs, 'The late entry teaches no habit.');
        $this->assertSame(5, $race->countCars('20:00', [1])->lapCount(1), 'The fast path agrees.');

        $race->undo($decision);
        $undone = $race->run('20:00');

        $this->assertSingleOpenFlag($undone, Flag::REGISTRO_TARDIO);
        $this->assertLapCounts([1 => 4], $undone);
    }

    /**
     * SITUATION: the same late pair, and the reviewer knows Contagem 1 was
     * right after all (Contagem 2 typed early): "Usar o tempo de Contagem 1".
     *
     * EXPECTED: one pass, at Contagem 1's time.
     */
    public function testTheLaterEntryCanBeTheOneThatTimesThePass(): void
    {
        $race = $this->raceWithCarOne();
        $early = $race->entry(2, 1, '13:30');
        $chosen = $race->entry(1, 1, '13:53.4');

        $race->useTimeOf($chosen, $early);
        $result = $race->run('20:00');

        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([1 => 5], $result);
        $this->assertSame(RaceScenario::ms('13:53.4'), $result->groupOfEntry($early)->tsMs);
    }

    /**
     * SITUATION: car 1's crossing at 13:30 is typed by Contagem 1 at 13:30
     * and by Contagem 2 at 13:40, ten seconds apart; Contagem 3 missed it.
     * The pass is held ("dispersao_alta"): nobody agrees closely.
     *
     * WHAT WENT WRONG at the race: confirming such a pass times it by the
     * middle of the two entries (13:35), five seconds from either counter.
     *
     * EXPECTED: the flag offers both times. "Usar o tempo de Contagem 1"
     * counts the pass at 13:30, and Contagem 2's habit is not touched.
     */
    public function testUsingOneTimeOnAHeldWidelySpreadPassTimesItByThatEntry(): void
    {
        $race = $this->raceWithCarOne();
        $first = $race->entry(1, 1, '13:30');
        $second = $race->entry(2, 1, '13:40');

        $before = $race->run('20:00');

        $flag = $this->assertSingleOpenFlag($before, Flag::DISPERSAO_ALTA);
        $this->assertSame([
            ['passagem_id' => $first, 'fonte_id' => 1, 'ts_ms' => RaceScenario::ms('13:30'), 'passagem_alvo_id' => null, 'voltas' => 1, 'volta_resultante_ms' => 180000],
            ['passagem_id' => $second, 'fonte_id' => 2, 'ts_ms' => RaceScenario::ms('13:40'), 'passagem_alvo_id' => null, 'voltas' => 1, 'volta_resultante_ms' => 170000],
        ], $flag->temposPossiveis);
        $this->assertLapCounts([1 => 4], $before);

        $race->useTimeOf($first);
        $after = $race->run('20:00');

        $this->assertNoOpenFlags($after);
        $this->assertLapCounts([1 => 5], $after);
        $this->assertSame(RaceScenario::ms('13:30'), $after->groupOfEntry($second)->tsMs);
        $this->assertSame(0, $after->sourceStats[2]->desvioMedianoMs);
    }

    /**
     * SITUATION: a timing system (a "precisa" source) and one counter
     * record car 7 at 03:00 and 03:08. The reviewer chooses the timing
     * system's time; at another pass, the counter's.
     *
     * WHAT WENT WRONG: any chosen time was marked "not precise", so a pass
     * timed by the transponder's own time could never be a best lap.
     *
     * EXPECTED: the pass timed by the precise source's entry is precisely
     * timed; the one timed by the counter is not.
     */
    public function testChoosingThePreciseSourcesTimeKeepsThePassPrecise(): void
    {
        $race = RaceScenario::withCounters(1);
        $race->addSource(4, 'Cronometragem', \Baja\Contagem\Engine\Data\Source::TIPO_AUTOMATICA, \Baja\Contagem\Engine\Data\Source::TEMPO_PRECISA);
        $race->start('00:00');
        $race->entry(4, 7, '00:20');
        $race->entry(1, 7, '00:20');
        $precise = $race->entry(4, 7, '03:00');
        $race->entry(1, 7, '03:08');
        $race->entry(4, 7, '06:00');
        $counter = $race->entry(1, 7, '06:08');

        $race->useTimeOf($precise);
        $race->useTimeOf($counter);
        $result = $race->run('07:00');

        $this->assertSame(RaceScenario::ms('03:00'), $result->groupOfEntry($precise)->tsMs);
        $this->assertTrue($result->groupOfEntry($precise)->tempoPreciso);
        $this->assertSame(RaceScenario::ms('06:08'), $result->groupOfEntry($counter)->tsMs);
        $this->assertFalse($result->groupOfEntry($counter)->tempoPreciso);
    }

    /**
     * SITUATION: a steady race; car 5 crossed at 12:28 with everybody
     * recording it, and Contagem 2 typed a lone 5 at 13:10. The reviewer
     * confirms that entry anyway (in a hurry, at the race: three
     * confirmations in four seconds).
     *
     * EXPECTED: as before, a pass a reviewer confirmed is not flagged again
     * ("volta_curta" stays closed), but the 42 s lap it makes carries a
     * mark, so the mistake stays visible. Only that lap.
     */
    public function testAShortLapThatCountsOnAConfirmationIsMarked(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        $stray = $race->entry(2, 5, '13:10');

        $race->confirm($stray);
        $result = $race->run('20:00');

        $this->assertSame([], $this->openFlags($result, Flag::VOLTA_CURTA));
        $this->assertSame(6, $result->lapCount(5));
        $marked = [];
        foreach ($result->laps as $lap) {
            if ($lap->curtaConfirmada) {
                $marked[] = [$lap->carro, $lap->numeroVolta, $lap->tsMs];
            }
        }
        $this->assertSame([[5, 4, RaceScenario::ms('13:10')]], $marked);
    }
}
