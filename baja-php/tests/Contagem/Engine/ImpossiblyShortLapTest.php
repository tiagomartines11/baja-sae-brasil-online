<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * "Too short to be a lap."
 *
 * Two passes of the same car can be too close together for the car to have
 * driven round the track in between. Three things follow from that:
 *
 * 1. THE HOLD RULE. A pass the counters agree on, but which comes sooner
 *    after the car's previous official pass than the car's minimum
 *    plausible lap, is NOT counted. It is held (a pending row) with the flag
 *    "passagem_proxima_demais", and counts once a reviewer confirms it. The
 *    minimum plausible lap is two matching windows (40 s), or half the
 *    car's own normal lap once it has three laps, whichever is longer.
 * 2. "volta_curta". Where the hold rule does not reach (the car has too few
 *    laps of its own for its normal lap to be known, or a reviewer's word
 *    made the pass count), a counted lap shorter than half the lap the PACE
 *    MODEL expects of the car is flagged. Both passes are named: either
 *    may be the wrong one.
 * 3. A lone entry that WOULD create an impossibly short lap gets the
 *    suggestion to void it.
 */
final class ImpossiblyShortLapTest extends EngineTestCase
{
    /**
     * SITUATION: four counters, second minute of the race, pace still
     * unknown. Car 9 crosses the line once. Counters 1 and 2 press Enter at
     * 01:30; counters 3 and 4 were looking elsewhere and press it at 01:51,
     * 21 seconds later, just outside the 20-second matching window.
     *
     * WHAT WENT WRONG: one crossing became two groups of two counters each.
     * Both groups are "agreed". Counting both, as the engine once did,
     * gives the car a lap it never drove.
     *
     * EXPECTED: agreement is not enough. The second group is HELD: it is
     * not an official pass, the car has one lap, and a flag
     * ("passagem_proxima_demais") says which pass is held and why: it is
     * 21 s after the previous one. The flag says the count may be one lap
     * short, and that confirming would add a pass. There is no suggestion.
     */
    public function testTwoAgreedGroupsForOneCrossingAreNotBothCountedEvenBeforeThePaceIsKnown(): void
    {
        $race = RaceScenario::withCounters(4);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '01:30', [1, 2]);
        $late = $race->pass(9, '01:51', [3, 4]);

        $result = $race->run('03:00');

        $this->assertNull($result->cars[9]->voltaEsperadaMs, 'The pace is not known yet.');
        $this->assertLapCounts([9 => 1], $result);
        $held = $result->groupOfEntry($late[3]);
        $this->assertTrue($held->retida);
        $this->assertFalse($held->contado);
        $this->assertSame(PassGroup::RETIDA_PROXIMA_DEMAIS, $held->motivoRetencao);
        $this->assertSame(Lap::PENDENTE, $result->laps[2]->estado, 'The held pass is a pending row.');
        $flag = $this->assertSingleOpenFlag($result, Flag::PASSAGEM_PROXIMA_DEMAIS);
        $this->assertSame([9], $flag->carros);
        $this->assertNull($flag->sugestao);
        $this->assertSame(array_values($late), $flag->passagemIds);
        $this->assertStringContainsString('Contagem 3 e Contagem 4', $flag->motivo);
        $this->assertStringContainsString('a 21 s da passagem anterior', $flag->motivo);
        $this->assertStringContainsString('40 s', $flag->motivo);
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertSame(21000, $flag->voltaResultanteMs);

        // The reviewer voids the two late entries: one lap, nothing to review.
        $race->void($late[3]);
        $race->void($late[4]);
        $fixed = $race->run('03:00');
        $this->assertLapCounts([9 => 1], $fixed);
        $this->assertNoOpenFlags($fixed);
    }

    /**
     * SITUATION: the same four counters and the same held pass. This time
     * the reviewer has the video: the car did cross twice. They press
     * "Contar esta passagem (+1 volta)", which is a "confirmar".
     *
     * EXPECTED: a reviewer's word always counts. The car has two laps and
     * the queue is empty: nobody is asked again about what a person
     * already decided.
     */
    public function testAHeldPassCountsOnceAReviewerConfirmsIt(): void
    {
        $race = RaceScenario::withCounters(4);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '01:30', [1, 2]);
        $late = $race->pass(9, '01:51', [3, 4]);
        $race->confirm($late[3]);

        $result = $race->run('03:00');

        $this->assertLapCounts([9 => 2], $result);
        $this->assertFalse($result->groupOfEntry($late[3])->retida);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: three passes the counters agree on, 21 s apart each: at
     * 01:30, 01:51 and 02:12.
     *
     * EXPECTED: each is judged against the car's previous OFFICIAL pass.
     * The second is held (21 s after the first). The third is 42 s after
     * the first, which is the last official one, so it counts. A held pass
     * does not hold the next one.
     */
    public function testAHeldPassIsNotWhatTheNextOneIsMeasuredFrom(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '01:30');
        $second = $race->pass(9, '01:51');
        $third = $race->pass(9, '02:12');

        $result = $race->run('03:00');

        $this->assertLapCounts([9 => 2], $result);
        $this->assertTrue($result->groupOfEntry($second[1])->retida);
        $this->assertFalse($result->groupOfEntry($third[1])->retida);
        $this->assertSame(2, $result->groupOfEntry($third[1])->numeroVolta);
    }

    /**
     * SITUATION: pace unknown. Car 9 is recorded by everybody at 01:30 and
     * again exactly 40 seconds later; in a second race, 39.999 s later.
     *
     * EXPECTED: "less than two matching windows" means less than 40.000 s.
     * At exactly 40 s the pass counts and there is no flag; one millisecond
     * less, it is held.
     */
    public function testTheLimitWithoutAPaceIsTwoMatchingWindows(): void
    {
        foreach (['02:10' => [2, 0], '02:09.999' => [1, 1]] as $secondPassAt => [$expectedLaps, $expectedFlags]) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->pass(9, '01:30');
            $race->pass(9, $secondPassAt);

            $result = $race->run('03:00');

            $this->assertLapCounts([9 => $expectedLaps], $result, "Second pass at $secondPassAt.");
            $this->assertCount($expectedFlags, $this->openFlags($result, Flag::PASSAGEM_PROXIMA_DEMAIS), "Second pass at $secondPassAt.");
            $this->assertCount($expectedFlags, $result->openFlags(), "Second pass at $secondPassAt.");
        }
    }

    /**
     * SITUATION: pace unknown. Car 9 is recorded by everybody at 01:30.
     * Counter 2 alone types it again 30 seconds later. In a second race the
     * lone entry comes 60 seconds later.
     *
     * EXPECTED: 30 s after a real pass cannot be a lap, so the lone entry
     * comes with the suggestion to void it, and the reason gives the 30 s
     * (and no "expected" lap, which nobody knows yet). At 60 s the engine
     * has no yardstick: the lone entry is flagged without a suggestion.
     */
    public function testALoneEntryRightAfterARealPassIsSuggestedForVoidingEvenBeforeThePaceIsKnown(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '01:30');
        $stray = $race->entry(2, 9, '02:00');

        $flag = $this->assertSingleOpenFlag($race->run('03:00'), Flag::SOLITARIA);
        $this->assertSame(['tipo' => Decision::ANULAR, 'passagem_id' => $stray], $flag->sugestao);
        $this->assertStringContainsString('30 s', $flag->motivo);
        $this->assertStringNotContainsString('esperado', $flag->motivo);

        $later = RaceScenario::withCounters(3);
        $later->start('00:00');
        $later->firstCrossing([9]);
        $later->pass(9, '01:30');
        $later->entry(2, 9, '02:30');
        $this->assertNull($this->assertSingleOpenFlag($later->run('03:00'), Flag::SOLITARIA)->sugestao);
    }

    /**
     * SITUATION: a steady race, ten cars lapping every 180 seconds. Car 5
     * has five laps of 180 s behind it, so its minimum plausible lap is
     * half of that: 90 s. It crossed at 18:28; everybody records it again
     * exactly 90 s later (and in a second race, 89.999 s later).
     *
     * EXPECTED: a pass exactly half a normal lap after the previous one
     * counts; one millisecond sooner it is held, and the reason gives the
     * 90 s it was measured against.
     */
    public function testTheLimitWithLapsOfItsOwnIsTheConfiguredFractionOfTheCarsNormalLap(): void
    {
        $onTheLimit = $this->steadyRace();
        $onTheLimit->pass(5, '19:58');
        $result = $onTheLimit->run('20:00');
        $this->assertNoOpenFlags($result);
        $this->assertLapCounts([5 => 6], $result);

        $justUnder = $this->steadyRace();
        $justUnder->pass(5, '19:57.999');
        $result = $justUnder->run('20:00');
        $flag = $this->assertSingleOpenFlag($result, Flag::PASSAGEM_PROXIMA_DEMAIS);
        $this->assertStringContainsString('é de 90 s', $flag->motivo);
        $this->assertLapCounts([5 => 5], $result);
    }

    /**
     * SITUATION: the same steady race with a pass of car 5 only 42 seconds
     * after its previous one, under different values of the setting
     * `fracao_volta_minima`.
     *
     * EXPECTED: with the default 0.5 the pass is held (42 < 90). With 0.2
     * it is not (42 >= 36), and since 42 s is also more than two matching
     * windows, it counts and there is no flag.
     */
    public function testTheFractionIsASetting(): void
    {
        foreach ([[0.5, 1, 5], [0.2, 0, 6]] as [$fraction, $expectedFlags, $expectedLaps]) {
            $race = $this->steadyRace();
            $race->settings->fracaoVoltaMinima = $fraction;
            $race->pass(5, '19:10');

            $result = $race->run('20:00');

            $this->assertCount($expectedFlags, $result->openFlags(), "With fracao_volta_minima = $fraction.");
            $this->assertLapCounts([5 => $expectedLaps], $result, "With fracao_volta_minima = $fraction.");
        }
    }

    /**
     * SITUATION: car 5 laps in 180 s, except that it broke down twice: of
     * its last five laps, three took 180 s and two took 600 s. Then it
     * comes round in 170 s.
     *
     * WHY IT MATTERS: the car's "normal lap" must not be thrown off by a
     * couple of long laps, or the next ordinary lap would be "too soon",
     * would be held, and the lap after it would look twice as long, and so
     * on. (With the median of the last three laps, two long ones are enough
     * for that; it happened in a generated race.) The normal lap is the
     * SECOND SHORTEST of the last five.
     *
     * EXPECTED: the normal lap is 180 s, the minimum 90 s, and the 170 s
     * lap counts with no flag. The same for a car whose only four laps
     * include three long ones... is NOT promised: see the next test for
     * what happens then.
     */
    public function testACoupleOfLongLapsDoNotMakeTheNextOrdinaryLapTooShort(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(5, '00:30');
        $race->pass(5, '10:30');
        $race->pass(5, '13:30');
        $race->pass(5, '23:30');
        $race->pass(5, '26:30');
        $race->pass(5, '29:30');
        $race->pass(5, '32:20');

        $result = $race->run('33:00');

        $this->assertLapCounts([5 => 6], $result);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: car 5 limps round in 600 s four times, is repaired, and
     * then laps in 180 s. Its normal lap, as far as its own history says,
     * is 600 s; the first 180 s lap is under half of that and is held.
     *
     * EXPECTED: the held pass still teaches the engine what a lap of this
     * car looks like. The NEXT 180 s lap counts (it is 360 s after the
     * car's last official pass), and from then on the car's normal lap is
     * 180 s again and nothing more is held. One pass waits for the
     * reviewer; the car does not sink into a hole.
     */
    public function testAfterAHeldPassTheCarsNormalLapStillFollowsWhatTheCountersSee(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(5, '00:30');
        foreach (['10:30', '20:30', '30:30', '40:30'] as $slowLap) {
            $race->pass(5, $slowLap);
        }
        $held = $race->pass(5, '43:30');
        foreach (['46:30', '49:30', '52:30', '55:30'] as $normalLap) {
            $race->pass(5, $normalLap);
        }

        $result = $race->run('56:00');

        $this->assertTrue($result->groupOfEntry($held[1])->retida);
        $this->assertCount(1, $result->openFlags());
        $this->assertLapCounts([5 => 8], $result, 'Four slow laps and four of the five fast ones.');
    }

    /**
     * SITUATION: a rehearsal on a table, not a race: ten "cars" typed round
     * and round every 28 seconds. Two passes of one car less than 40 s
     * apart (two matching windows) are taken for one crossing typed twice,
     * so every second pass of each car is held.
     *
     * WHAT WENT WRONG (in the software, found in a real rehearsal): the
     * reason shown to the reviewer quoted the pace, and read "28 s de
     * intervalo, quando o esperado para este carro é cerca de 28 s", which
     * explains nothing.
     *
     * EXPECTED: the reason names the rule that actually applied: the pass
     * is 28 s after the previous one, and the minimum is 40 s.
     */
    public function testTheReasonNamesTheRuleThatActuallyApplied(): void
    {
        $rehearsal = RaceScenario::withCounters(3);
        $rehearsal->start('00:00');
        $rehearsal->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 28, 8, '01:00', 2);

        $result = $rehearsal->run('05:00');

        $flagsOfCar5 = array_values(array_filter($this->openFlags($result, Flag::PASSAGEM_PROXIMA_DEMAIS), fn(Flag $flag) => $flag->carros === [5]));
        $this->assertNotEmpty($flagsOfCar5);
        foreach ($flagsOfCar5 as $flag) {
            $this->assertStringContainsString('a 28 s da passagem anterior', $flag->motivo);
            $this->assertStringContainsString('é de 40 s', $flag->motivo);
            $this->assertStringNotContainsString('esperado', $flag->motivo);
        }
    }

    /**
     * SITUATION: a steady race in which the pace is known (180 s). Car 77
     * joined late: it has crossed three times, so it has two laps of its
     * own, too few for its normal lap to be known. The hold rule can only
     * apply its 40 s floor to it. Everybody then records it again 60 s
     * after its last pass.
     *
     * EXPECTED: the pass counts (60 s is more than two matching windows),
     * but a 60 s lap is under half of what the pace model expects of the
     * car, so it gets a "volta_curta" flag. The flag is about BOTH passes,
     * the earlier one first: either may be the one that did not happen, and
     * the reviewer chooses which to discard. It says the car may have a lap
     * too many, and has no suggestion.
     */
    public function testACountedLapUnderHalfTheExpectedLapIsFlaggedNamingBothPasses(): void
    {
        $race = $this->steadyRace();
        $this->lateCarJoins($race);
        $earlier = $race->pass(77, '16:00');
        $later = $race->pass(77, '17:00');

        $result = $race->run('20:00');

        $this->assertLapCounts([77 => 3], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::VOLTA_CURTA);
        $this->assertSame([77], $flag->carros);
        $this->assertNull($flag->sugestao);
        $this->assertSame(Flag::DUVIDA_A_MAIS, $flag->contagemEmDuvida);
        $this->assertSame(Flag::EFEITO_NENHUM, $flag->efeitoDeConfirmar);
        $this->assertSame(
            [
                ['passagem_ids' => array_values($earlier), 'ts_ms' => RaceScenario::ms('16:00')],
                ['passagem_ids' => array_values($later), 'ts_ms' => RaceScenario::ms('17:00')],
            ],
            $flag->passagens
        );
        $this->assertSame(array_merge(array_values($later), array_values($earlier)), $flag->passagemIds, 'A decision may be about an entry of either pass.');
        $this->assertSame($flag->passagens, $flag->toArray()['passagens']);
        $this->assertStringContainsString('60 s de intervalo', $flag->motivo);
        $this->assertStringContainsString('cerca de 180 s', $flag->motivo);
        $this->assertStringContainsString('pode ser qualquer uma', $flag->motivo);

        // "Descartar" on the EARLIER pass is as good as on the later one.
        foreach ($earlier as $entryId) {
            $race->void($entryId);
        }
        $fixed = $race->run('20:00');
        $this->assertLapCounts([77 => 2], $fixed);
        $this->assertNoOpenFlags($fixed);
    }

    /**
     * SITUATION: the same late car, with its fourth pass exactly 90 s
     * after the third (and in a second race, 89.999 s after).
     *
     * EXPECTED: a lap of exactly half the expected lap is not flagged; one
     * millisecond shorter is.
     */
    public function testTheLimitOfTheShortLapFlagIsTheConfiguredFractionOfTheExpectedLap(): void
    {
        foreach (['17:30' => 0, '17:29.999' => 1] as $fourthPassAt => $expectedFlags) {
            $race = $this->steadyRace();
            $this->lateCarJoins($race);
            $race->pass(77, '16:00');
            $race->pass(77, $fourthPassAt);

            $this->assertCount($expectedFlags, $this->openFlags($race->run('20:00'), Flag::VOLTA_CURTA), "Fourth pass at $fourthPassAt.");
        }
    }

    /**
     * Car 77 joins the steady race late: its first crossing is at 10:00 and
     * its second at 13:00. A reviewer has already said "it really started
     * late" about the first one, so that is not what these tests are about.
     */
    private function lateCarJoins(RaceScenario $race): void
    {
        $first = $race->pass(77, '10:00');
        $race->confirm($first[1]);
        $race->pass(77, '13:00');
    }

    /** Ten cars lapping every 180 s; car 5 crosses at 03:28, 06:28, ..., 18:28. */
    private function steadyRace(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        return $race;
    }

    /**
     * SITUATION: the late car 77 again, with a 60 s lap, and the pit log
     * says it entered the pit lane ten seconds before that last pass.
     *
     * WHAT COULD GO WRONG: the last pass has two things wrong with it: the
     * car was logged in the pit lane, and it makes a 60-second lap. Two
     * flags for one doubtful pass would have the reviewer deal with it
     * twice.
     *
     * EXPECTED: one flag. The pass is flagged as "no_box", and the
     * short-lap check leaves a pass that is already flagged alone.
     */
    public function testAPassThatIsAlreadyFlaggedGetsNoShortLapFlagOnTop(): void
    {
        $race = $this->steadyRace();
        $this->lateCarJoins($race);
        $race->pass(77, '16:00');
        $race->pitIn(77, '16:50');
        $race->pass(77, '17:00');

        $this->assertSingleOpenFlag($race->run('20:00'), Flag::NO_BOX);
    }

    /**
     * SITUATION: car 6 is recorded by everybody at 03:00 and again at
     * 03:30, and the pit log says it entered the pit lane at 03:10.
     *
     * EXPECTED: one flag for the one doubtful pass, saying both things.
     * The pass is held for being logged inside the pit lane (flag
     * "no_box"), and the reason also says it is only 30 s after the car's
     * previous pass. Recording an exit would not be enough to make it
     * count: it would then be held for being too close.
     */
    public function testAHeldPassInsideThePitLaneGetsOneFlagThatSaysBoth(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->pitIn(6, '03:10');
        $race->pass(6, '03:30');

        $flag = $this->assertSingleOpenFlag($race->run('04:00'), Flag::NO_BOX);
        $this->assertStringContainsString('dentro do box', $flag->motivo);
        $this->assertStringContainsString('a 30 s da passagem anterior', $flag->motivo);

        $race->inferredExit('box', 6, RaceScenario::ms('03:29'));
        $this->assertSingleOpenFlag($race->run('04:00'), Flag::PASSAGEM_PROXIMA_DEMAIS);
    }

    /**
     * SITUATION: car 6 is recorded at 04:50 and next at 10:20. The race was
     * stopped by a red flag from 05:00 to 10:00.
     *
     * EXPECTED: the time since the previous pass is RACING time. Five and a
     * half minutes passed on the clock, but the car only raced for 10 s
     * before the red flag and 20 s after it: 30 seconds, less than the
     * minimum, so the pass is held (two passes that close in racing time
     * are one crossing typed twice, or a pass typed while the cars were
     * standing). The reason quotes the 30 seconds.
     */
    public function testTheTimeSinceThePreviousPassIsMeasuredInRacingTime(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '00:20');
        $race->pass(6, '04:50');
        $race->redFlag('05:00');
        $race->restart('10:00');
        $race->pass(6, '10:20');

        $result = $race->run('11:00');
        $flag = $this->assertSingleOpenFlag($result, Flag::PASSAGEM_PROXIMA_DEMAIS);

        $this->assertSame(RaceScenario::ms('10:20'), $flag->tsMs);
        $this->assertStringContainsString('a 30 s da passagem anterior', $flag->motivo);
        $this->assertLapCounts([6 => 1], $result);
    }

    /**
     * SITUATION: the late car 77 has a pass at 19:30, a red flag stops the
     * race from 19:40 to 24:40, and everybody records the car again at
     * 25:30: six minutes later on the clock, 60 s of racing.
     *
     * EXPECTED: the short-lap flag, too, judges the lap in racing time,
     * and quotes the 60 seconds.
     */
    public function testAShortLapIsMeasuredInRacingTime(): void
    {
        $race = $this->steadyRace();
        $this->lateCarJoins($race);
        $race->pass(77, '19:30');
        $race->redFlag('19:40');
        $race->restart('24:40');
        $race->pass(77, '25:30');

        $flag = $this->assertSingleOpenFlag($race->run('26:00'), Flag::VOLTA_CURTA);

        $this->assertSame(RaceScenario::ms('25:30'), $flag->tsMs);
        $this->assertStringContainsString('60 s de intervalo', $flag->motivo);
    }
}
