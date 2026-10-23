<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * What a reviewer's "confirmar" would do, flag by flag.
 *
 * On the review screen one key (K) confirms the pass a flag is about. For
 * some flags that only means "it is fine, stop warning me". For others it
 * ALSO gives the car one more official pass, on a single person's word.
 * Round 2 labelled the key "manter" for both, and reviewers added laps
 * without knowing.
 *
 * So every flag now says which it is, in two keys of `Flag::toArray()`:
 *
 *   efeito_de_confirmar   "nenhum" (only silences the flag) or
 *                         "mais_uma_passagem" (the car gets one more pass)
 *   volta_resultante_ms   for the second kind: the shortest lap the new
 *                         pass would create, so the screen can ask "are
 *                         you sure?" when no car could do it. Null when the
 *                         car has no other pass to measure from.
 *
 * (The flags of round 3 are checked in their own test classes:
 * antes_da_largada, no_encerramento, tempo_estimado, tempo_desconhecido,
 * carro_nao_classificado, no_abastecimento.)
 */
final class ConfirmEffectTest extends EngineTestCase
{
    /** The one flag of the given type, open or auto-resolved. */
    private function flagOfType(EngineResult $result, string $type): Flag
    {
        $found = array_values(array_filter($result->flags, fn(Flag $flag) => $flag->tipo === $type));
        $this->assertCount(1, $found, "Expected exactly one flag of type $type.");
        return $found[0];
    }

    /** @return array{0: string, 1: ?int} what the screen reads from the flag */
    private function effectOf(Flag $flag): array
    {
        $asSent = $flag->toArray();
        return [$asSent['efeito_de_confirmar'], $asSent['volta_resultante_ms']];
    }

    /**
     * SITUATION: car 9 crosses at 00:20, 03:00 and 09:00, recorded by
     * everybody. In between, counter 2 alone records it at 05:00.
     *
     * EXPECTED: the lone entry does not count, so confirming it WOULD add a
     * pass. It would split the lap 03:00-09:00 into 120 s and 240 s; the
     * flag reports the shorter, 120 s.
     */
    public function testALoneEntryWouldAddAPassAndTheFlagSaysTheShorterOfTheTwoLaps(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(9, '00:20');
        $race->pass(9, '03:00');
        $race->entry(2, 9, '05:00');
        $race->pass(9, '09:00');

        $flag = $this->flagOfType($race->run('10:00'), Flag::SOLITARIA);

        $this->assertSame(['mais_uma_passagem', 120000], $this->effectOf($flag));
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertSame(120000, $flag->voltaResultanteMs);
    }

    /**
     * SITUATION: the same lone entry of counter 2, in the other publish
     * modes.
     *
     * EXPECTED: the answer is about the count IN FORCE.
     * - "todas": every entry counts, the lone one included, so confirming
     *   adds nothing: "nenhum".
     * - "fonte_principal" with counter 2 as primary: it counts already
     *   (the primary has it): "nenhum".
     * - "fonte_principal" with counter 1 as primary: it does not count, so
     *   confirming adds a pass: 120 s again.
     * - "desligada" counts like the default.
     * (The flag itself is there in every mode; only this answer differs.)
     */
    public function testTheEffectIsAboutTheCountInForce(): void
    {
        $expected = [
            [Settings::MODO_TODAS, 1, ['nenhum', null]],
            [Settings::MODO_FONTE_PRINCIPAL, 2, ['nenhum', null]],
            [Settings::MODO_FONTE_PRINCIPAL, 1, ['mais_uma_passagem', 120000]],
            [Settings::MODO_DESLIGADA, 1, ['mais_uma_passagem', 120000]],
        ];
        foreach ($expected as [$mode, $primary, $effect]) {
            $race = RaceScenario::withCounters(3);
            $race->settings->modoPublicacao = $mode;
            $race->settings->fontePrincipalId = $primary;
            $race->start('00:00');
            $race->pass(9, '00:20');
            $race->pass(9, '03:00');
            $race->entry(2, 9, '05:00');
            $race->pass(9, '09:00');

            $flag = $this->flagOfType($race->run('10:00'), Flag::SOLITARIA);

            $this->assertSame($effect, $this->effectOf($flag), "Mode $mode, primary $primary.");
        }
    }

    /**
     * SITUATION: "fonte_principal" mode with counter 1 as the primary. Car
     * 9 made its first crossing at 00:20 (everybody). At 02:00 only the
     * primary recorded it: in this mode that IS an official pass. At 05:00
     * only counter 2 recorded it, which is not.
     *
     * EXPECTED: the lap that confirming counter 2's entry would create is
     * measured from the passes that count in the mode in force: from 02:00,
     * so 180 s. (Measured from the passes of the default rule it would be
     * 280 s, from 00:20, which is not the lap the car would really get.)
     */
    public function testTheResultingLapIsMeasuredBetweenThePassesInForce(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->modoPublicacao = Settings::MODO_FONTE_PRINCIPAL;
        $race->settings->fontePrincipalId = 1;
        $race->start('00:00');
        $race->pass(9, '00:20');
        $race->entry(1, 9, '02:00');
        $onlyCounter2 = $race->entry(2, 9, '05:00');

        $result = $race->run('06:00');
        $flags = array_values(array_filter($result->openFlags(), fn(Flag $flag) => $flag->passagemIds === [$onlyCounter2]));

        $this->assertLapCounts([9 => 1], $result);
        $this->assertCount(1, $flags);
        $this->assertSame(['mais_uma_passagem', 180000], $this->effectOf($flags[0]));
    }

    /**
     * SITUATION: car 9's only other pass is its first crossing at 00:20; a
     * red flag stopped the race from 05:00 to 10:00; counter 2 alone
     * records car 9 at 12:00.
     *
     * EXPECTED: laps are measured in RACING time. From 00:20 to 12:00 is 11
     * min 40 s on the clock, minus the five minutes stopped: 400 s.
     */
    public function testTheResultingLapIsMeasuredInRacingTime(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(9, '00:20');
        $race->redFlag('05:00');
        $race->restart('10:00');
        $race->entry(2, 9, '12:00');

        $flag = $this->flagOfType($race->run('13:00'), Flag::SOLITARIA);

        $this->assertSame(['mais_uma_passagem', 400000], $this->effectOf($flag));
    }

    /**
     * SITUATION: counter 2 alone records car 9 at 03:00, and car 9 has no
     * other pass at all. Under the default rule, and under the "largada"
     * rule (the start itself is pass zero).
     *
     * EXPECTED: no other crossing to measure a lap from, so no resulting
     * lap, under either rule. Under "largada" confirming would make this
     * lap 1, measured from the start, but that is not a full lap of the
     * track (the counting line is after the start line), so its length is
     * not something to be alarmed about.
     */
    public function testWithNoOtherCrossingThereIsNoResultingLap(): void
    {
        foreach (Settings::PASSAGENS_INICIAIS as $passagemInicial) {
            $race = RaceScenario::withCounters(3);
            $race->settings->passagemInicial = $passagemInicial;
            $race->start('00:00');
            $race->entry(2, 9, '03:00');

            $flag = $this->flagOfType($race->run('04:00'), Flag::SOLITARIA);

            $this->assertSame(['mais_uma_passagem', null], $this->effectOf($flag), "With $passagemInicial.");
        }
    }

    /**
     * SITUATION: the "largada" rule, with the counting line close to the
     * start line. Car 9 reaches it 30 seconds after the start and only
     * counter 2 records that; everybody records its next crossing at 03:30.
     *
     * WHAT WENT WRONG BEFORE: the 30 seconds from the start were treated as
     * a lap "too short to be real" (under two matching windows), and the
     * engine suggested voiding what was a real first crossing. A reviewer
     * accepting suggestions lost the car a lap.
     *
     * EXPECTED: the lone entry is flagged for the reviewer to decide, with
     * NO suggestion to void it. The lap it would create is measured to the
     * next crossing: 180 s.
     */
    public function testUnderLargadaAFirstCrossingSoonAfterTheStartIsNotSuggestedForVoiding(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->passagemInicial = Settings::INICIAL_LARGADA;
        $race->start('00:00');
        $race->entry(2, 9, '00:30');
        $race->pass(9, '03:30');

        $flag = $this->flagOfType($race->run('04:00'), Flag::SOLITARIA);

        $this->assertNull($flag->sugestao);
        $this->assertSame(['mais_uma_passagem', 180000], $this->effectOf($flag));
    }

    /**
     * SITUATION: car 41 crosses at 03:00. Counters 2 and 3 type 41, counter
     * 1 types 14. Car 14 itself made its first crossing at 00:20.
     *
     * EXPECTED: the flag is "troca_provavel", and its suggestion is to move
     * the entry to car 41. But the K key does not accept suggestions: it
     * CONFIRMS the entry as car 14, which would give car 14 a pass at
     * 03:00, 160 s after its first. The flag says so.
     */
    public function testConfirmingAProbableTypoWouldCountItForTheCarThatWasTyped(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 41]);
        $race->pass(41, '03:00', [2, 3]);
        $race->entry(1, 14, '03:00');

        $flag = $this->flagOfType($race->run('04:00'), Flag::TROCA_PROVAVEL);

        $this->assertSame(['mais_uma_passagem', 160000], $this->effectOf($flag));
    }

    /**
     * SITUATION: three kinds of repeated entry for car 9, which crossed at
     * 00:20 and at 03:00 (recorded by everybody):
     * a) counter 1 pressed Enter again two seconds later (a double tap,
     *    dropped automatically; the flag is only for the record);
     * b) counter 1 typed it again twelve seconds later (an open
     *    "repetida": the entry is kept, alone in a group of its own);
     * c) as (b), and counter 3 typed car 9 very late, 22 s after the
     *    crossing, landing in the same group as the repeat: two counters
     *    now seem to "agree" on a second pass. (This used to count, and
     *    gave the car a lap it never drove.)
     *
     * EXPECTED:
     * a) confirming the dropped entry brings it back as a pass of its own,
     *    2 s after the real one;
     * b) confirming the repeat makes it count: a 12-second lap;
     * c) the two entries are ten seconds apart, far more than counters who
     *    saw the same crossing differ by, so the group is held: the car
     *    keeps its one lap. There is no "repetida" flag for it, because
     *    its suggestion (void the repeat) is not one to accept blindly
     *    here; the one flag is "dispersao_alta", with no suggestion. It
     *    says the count may be a lap short, and
     *    confirming would add a pass 17 s after the real one. Only in
     *    "todas" mode, where every entry counts, does the group count
     *    already; the flag then says confirming adds nothing and that the
     *    car may have a lap too many. (The screen offers no "keep" for
     *    repeats; this is the truth it works from.)
     */
    public function testRepeatedEntries(): void
    {
        $build = function (): RaceScenario {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->pass(9, '00:20');
            return $race;
        };

        $doubleTap = $build();
        $doubleTap->pass(9, '03:00');
        $doubleTap->entry(1, 9, '03:02');
        $flag = $this->flagOfType($doubleTap->run('04:00'), Flag::REPETIDA);
        $this->assertTrue($flag->autoResolvido);
        $this->assertSame(['mais_uma_passagem', 2000], $this->effectOf($flag));

        $repeat = $build();
        $repeat->pass(9, '03:00');
        $repeat->entry(1, 9, '03:12');
        $flag = $this->flagOfType($repeat->run('04:00'), Flag::REPETIDA);
        $this->assertFalse($flag->autoResolvido);
        $this->assertSame(['mais_uma_passagem', 12000], $this->effectOf($flag));

        $repeatAndLatecomer = $build();
        $repeatAndLatecomer->pass(9, '03:00', [1, 2]);
        $repeatAndLatecomer->entry(1, 9, '03:12');
        $repeatAndLatecomer->entry(3, 9, '03:22');
        $result = $repeatAndLatecomer->run('04:00');
        $this->assertLapCounts([9 => 1], $result);
        $flag = $this->flagOfType($result, Flag::DISPERSAO_ALTA);
        $this->assertNull($flag->sugestao, 'Which of the two entries to void is for a person to say.');
        $this->assertSame(['mais_uma_passagem', 17000], $this->effectOf($flag));
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);
        $this->assertCount(1, $result->openFlags(), 'One doubtful pass, one flag.');

        $repeatAndLatecomer->settings->modoPublicacao = Settings::MODO_TODAS;
        $result = $repeatAndLatecomer->run('04:00');
        $this->assertLapCounts([9 => 2], $result);
        $flag = $this->flagOfType($result, Flag::DISPERSAO_ALTA);
        $this->assertSame(['nenhum', null], $this->effectOf($flag));
        $this->assertSame(Flag::DUVIDA_A_MAIS, $flag->contagemEmDuvida);
    }

    /**
     * SITUATION: only team 1 is at the event. Everybody types 99 at 03:00;
     * counter 1 alone types 98 at 04:00.
     *
     * EXPECTED: both are "carro_desconhecido". The pass of 99 counts
     * already (three counters agree), so confirming only silences the
     * flag. The lone 98 does not count; confirming would add a pass.
     */
    public function testAnUnknownCarDependsOnWhetherItsPassCountsAlready(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1]);
        $race->start('00:00');
        $race->pass(99, '03:00');
        $race->entry(1, 98, '04:00');

        $effectByCar = [];
        foreach ($this->openFlags($race->run('05:00'), Flag::CARRO_DESCONHECIDO) as $flag) {
            $effectByCar[$flag->carros[0]] = $this->effectOf($flag);
        }

        $this->assertSame([99 => ['nenhum', null], 98 => ['mais_uma_passagem', null]], $effectByCar);
    }

    /**
     * SITUATION: first a flag about a pass that does NOT count: "no_box",
     * recorded at the line while logged in the pit lane, is held, so
     * confirming it adds a pass (a 240 s lap here). Then flags about a pass
     * that counts and merely looks odd:
     * - "volta_curta": a pass a reviewer added 30 seconds after one the
     *   counters agree on (a reviewer's word always counts, so the hold
     *   rule does not apply to it);
     * - "primeira_passagem_tardia": a first pass a lap later than the
     *   field's;
     * - "divergencia_total": paper totals that disagree.
     *
     * EXPECTED: for all of them confirming changes no count: "nenhum", and
     * no resulting lap.
     */
    public function testFlagsAboutPassesThatAlreadyCountOnlyGetSilenced(): void
    {
        $inThePits = RaceScenario::withCounters(3);
        $inThePits->start('00:00');
        $inThePits->pass(6, '03:00');
        $inThePits->pitIn(6, '05:00');
        $inThePits->pass(6, '07:00');
        $this->assertSame(['mais_uma_passagem', 240000], $this->effectOf($this->flagOfType($inThePits->run('08:00'), Flag::NO_BOX)));

        $shortLap = RaceScenario::withCounters(3);
        $shortLap->start('00:00');
        $shortLap->pass(6, '00:20');
        $shortLap->pass(6, '03:00');
        $shortLap->addPass(6, '03:30');
        $this->assertSame(['nenhum', null], $this->effectOf($this->flagOfType($shortLap->run('04:00'), Flag::VOLTA_CURTA)));

        $lateFirstPass = RaceScenario::withCounters(3);
        $lateFirstPass->start('00:00');
        foreach ([1 => 20, 2 => 20, 3 => 20, 4 => 200] as $car => $firstPassSeconds) {
            $lateFirstPass->pass($car, RaceScenario::clock($firstPassSeconds));
            $lateFirstPass->pass($car, RaceScenario::clock($firstPassSeconds + 180));
        }
        $this->assertSame(['nenhum', null], $this->effectOf($this->flagOfType($lateFirstPass->run('08:00'), Flag::PRIMEIRA_PASSAGEM_TARDIA)));

        $paper = RaceScenario::withCounters(3);
        $paper->addSource(8, 'Planilha', \Baja\Contagem\Engine\Data\Source::TIPO_TOTAIS);
        $paper->start('00:00');
        $paper->pass(6, '00:20');
        $paper->pass(6, '03:00');
        $paper->importTotals(8, [6 => 5]);
        $this->assertSame(['nenhum', null], $this->effectOf($this->flagOfType($paper->run('04:00'), Flag::DIVERGENCIA_TOTAL)));
    }

    /**
     * EXPECTED: the two keys are part of what every flag sends to the
     * review screen, next to the keys it always had, and a flag that says
     * nothing about them defaults to the harmless answer. The same goes for
     * the two keys of round 4: whether the car's count is in doubt
     * ("contagem_em_duvida": null, "a_mais" or "a_menos") and, for a short
     * lap, the two passes it lies between ("passagens").
     */
    public function testTheTwoKeysAreAlwaysSent(): void
    {
        $flag = new Flag(Flag::SOLITARIA, [9], [5], '5');

        $this->assertSame(
            ['id', 'tipo', 'carros', 'passagem_ids', 'fonte_id', 'ts_ms', 'sugestao', 'motivo', 'auto_resolvido', 'prioridade', 'efeito_de_confirmar', 'volta_resultante_ms', 'contagem_em_duvida', 'passagens', 'voltas_ao_marcar_saida',
                'passagem_anterior', 'tempos_possiveis'],
            array_keys($flag->toArray())
        );
        // A lone entry's previous official pass and the times a reviewer may choose (round 5): null unless said.
        $this->assertNull($flag->toArray()['passagem_anterior']);
        $this->assertNull($flag->toArray()['tempos_possiveis']);
        $this->assertNull($flag->toArray()['contagem_em_duvida']);
        $this->assertNull($flag->toArray()['passagens']);
        $this->assertSame(['a_mais', 'a_menos'], [Flag::DUVIDA_A_MAIS, Flag::DUVIDA_A_MENOS]);
        $flag->contagemEmDuvida = Flag::DUVIDA_A_MENOS;
        $this->assertSame('a_menos', $flag->toArray()['contagem_em_duvida']);
        $this->assertSame('nenhum', $flag->toArray()['efeito_de_confirmar']);
        $this->assertNull($flag->toArray()['volta_resultante_ms']);
        $this->assertSame(['nenhum', 'mais_uma_passagem'], [Flag::EFEITO_NENHUM, Flag::EFEITO_MAIS_UMA_PASSAGEM]);

        $flag->efeitoDeConfirmar = Flag::EFEITO_MAIS_UMA_PASSAGEM;
        $flag->voltaResultanteMs = 31000;
        $this->assertSame('mais_uma_passagem', $flag->toArray()['efeito_de_confirmar']);
        $this->assertSame(31000, $flag->toArray()['volta_resultante_ms']);
    }
}
