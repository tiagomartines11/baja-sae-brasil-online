<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * When does the engine dare to say "this lone entry was meant for car Y"
 * (the "troca_provavel" flag), and which car does it pick?
 *
 * The rule: Y must be a REAL pass at that moment, recorded by at least two
 * counters, that the counter of the lone entry does not have. Among several
 * such cars, the closest in time wins (in whole seconds: typing delays make
 * finer differences meaningless); between cars equally close, the number
 * that looks most like what was typed; and only then the milliseconds.
 *
 * TypoAndLoneEntryTest shows the ordinary cases. These are the edges.
 */
final class SwapSuggestionTest extends EngineTestCase
{
    /**
     * SITUATION: two stray entries a few seconds apart. Counter 1 types 14
     * alone, and counter 2 types 23 alone. Nobody else recorded either car,
     * and no other car crossed.
     *
     * WHAT WENT WRONG: two unrelated slips that happen to be neighbours.
     *
     * EXPECTED: they do NOT suggest each other. "Counter 1 meant 23" would
     * move the entry next to counter 2's, the two would then "agree", and
     * two mistakes would become one counted lap. Each is flagged as a plain
     * lone entry, and nothing counts.
     */
    public function testTwoUnrelatedLoneEntriesNextToEachOtherDoNotSuggestEachOther(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 23]);
        $race->entry(1, 14, '03:00');
        $race->entry(2, 23, '03:04');

        $result = $race->run('05:00');

        $this->assertCount(2, $this->openFlags($result, Flag::SOLITARIA));
        $this->assertSame([], $this->openFlags($result, Flag::TROCA_PROVAVEL));
        $this->assertLapCounts([14 => 0, 23 => 0], $result);
    }

    /**
     * SITUATION: car 41 crosses and only counter 2 records it. At the same
     * moment counter 1 types 14 alone.
     *
     * EXPECTED: tempting as it looks (swapped digits!), car 41's pass rests
     * on one counter, so it is not established that car 41 passed at all.
     * No swap is suggested. Two lone entries, for a person to judge.
     */
    public function testAPassSeenByOneCounterIsNotEnoughToExplainAStrayEntry(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 41]);
        $race->entry(2, 41, '03:00');
        $race->entry(1, 14, '03:01');

        $result = $race->run('05:00');

        $this->assertSame([], $this->openFlags($result, Flag::TROCA_PROVAVEL));
        $this->assertCount(2, $this->openFlags($result, Flag::SOLITARIA));
    }

    /**
     * SITUATION: car 41 crosses and all three counters record it, counter 1
     * included. A second later counter 1 also types 14, alone.
     *
     * EXPECTED: not a swap. Counter 1 HAS car 41, so the 14 cannot be "41
     * mistyped". It is a plain lone entry.
     */
    public function testNoSwapIsSuggestedTowardsACarTheCounterAlreadyHas(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 41]);
        $race->pass(41, '03:00');
        $race->entry(1, 14, '03:01');

        $this->assertSingleOpenFlag($race->run('05:00'), Flag::SOLITARIA);
    }

    /**
     * SITUATION: cars 41 and 7 cross within the same second, both recorded
     * by counters 2 and 3 only. Counter 1 types a lone 47. Car 7's pass is
     * 0.3 s from the stray entry and car 41's is 0.9 s.
     *
     * EXPECTED: both are "less than a second away", which is a tie as far
     * as hand timing goes. The numbers break it: 47 is 41 with one digit
     * wrong, while 7 is 47 with a digit missing, a rarer slip. The
     * suggestion is car 41, and the reason given to the reviewer says "um
     * dígito diferente".
     */
    public function testBetweenCarsEquallyCloseInTimeTheMoreSimilarNumberWins(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 41]);
        $race->pass(41, '03:00.1', [2, 3]);
        $race->pass(7, '03:00.7', [2, 3]);
        $race->entry(1, 47, '03:01');

        $flag = $this->assertSingleOpenFlag($race->run('05:00'), Flag::TROCA_PROVAVEL);

        $this->assertSame(41, $flag->sugestao['equipe_numero']);
        $this->assertSame([47, 41], $flag->carros);
        $this->assertStringContainsString('um dígito diferente', $flag->motivo);
    }

    /**
     * SITUATION: the same, but the two cars are 41 and 46: both are "47
     * with one digit wrong". Car 46 is 0.3 s from the stray entry, car 41
     * is 0.9 s.
     *
     * EXPECTED: same whole second, same resemblance; only now do the
     * milliseconds decide. The suggestion is car 46.
     */
    public function testWithTimeAndResemblanceEqualTheMillisecondsDecide(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([41, 46]);
        $race->pass(41, '03:00.1', [2, 3]);
        $race->pass(46, '03:00.7', [2, 3]);
        $race->entry(1, 47, '03:01');

        $flag = $this->assertSingleOpenFlag($race->run('05:00'), Flag::TROCA_PROVAVEL);

        $this->assertSame(46, $flag->sugestao['equipe_numero']);
    }

    /**
     * SITUATION: car 41 crosses, recorded by counters 2 and 3. Counter 1
     * types TWO stray numbers around that moment: 14 and then 17.
     *
     * EXPECTED: one real pass can explain only one stray entry. The first
     * one (14) gets the swap suggestion; the second (17) is a plain lone
     * entry. Otherwise accepting both suggestions would put counter 1 on
     * car 41 twice.
     */
    public function testOneRealPassExplainsOnlyOneStrayEntry(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 17, 41]);
        $race->pass(41, '03:00', [2, 3]);
        $first = $race->entry(1, 14, '03:00');
        $second = $race->entry(1, 17, '03:03');

        $result = $race->run('05:00');

        $swaps = $this->openFlags($result, Flag::TROCA_PROVAVEL);
        $this->assertCount(1, $swaps);
        $this->assertSame([$first], $swaps[0]->passagemIds);
        $lone = $this->openFlags($result, Flag::SOLITARIA);
        $this->assertCount(1, $lone);
        $this->assertSame([$second], $lone[0]->passagemIds);
    }

    /**
     * SITUATION: car 41 is recorded by counters 2 and 3 at 03:00. Counter 1
     * types a lone 14 exactly 20 seconds later in one race, and 20.001
     * seconds later in another.
     *
     * EXPECTED: a swap is only looked for inside the matching window. At
     * 20 s the two are still "the same moment" and the swap is suggested;
     * one millisecond later the 14 is just a lone entry. The same holds
     * when the stray entry comes BEFORE the real pass (02:40 and
     * 02:39.999).
     */
    public function testASwapIsOnlyLookedForInsideTheMatchingWindow(): void
    {
        $expectations = [
            '03:20' => Flag::TROCA_PROVAVEL,
            '03:20.001' => Flag::SOLITARIA,
            '02:40' => Flag::TROCA_PROVAVEL,
            '02:39.999' => Flag::SOLITARIA,
        ];
        foreach ($expectations as $strayAt => $expectedFlag) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->firstCrossing([14, 41]);
            $race->pass(41, '03:00', [2, 3]);
            $race->entry(1, 14, $strayAt);

            $this->assertSingleOpenFlag($race->run('05:00'), $expectedFlag);
        }
    }

    /**
     * SITUATION: counter 1 alone types 14 at 03:00. Around that moment car
     * 41 has a pass that rests on ONE counter's entry (counter 2) plus a
     * pass a reviewer added for it by hand.
     *
     * EXPECTED: to explain a stray entry as a typo, the other car's pass
     * must have been seen by two COUNTERS. A reviewer's added pass is not a
     * counter. So no swap is suggested towards car 41; the stray 14 is an
     * ordinary lone entry.
     */
    public function testAPassAReviewerAddedDoesNotCountAsASecondCounter(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([14, 41]);
        $race->entry(2, 41, '03:00');
        $race->addPass(41, '03:01');
        $stray = $race->entry(1, 14, '03:00');

        $result = $race->run('05:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame([$stray], $flag->passagemIds);
    }

    /**
     * A steady race: ten cars lapping every 180 s. Car 4 crosses at 03:21,
     * 06:21, ..., 18:21 and car 9 at 03:56, 06:56, ..., 18:56, except for
     * the crossings a test types by hand.
     *
     * @param string[] $withoutCrossingsOfCar9At crossings of car 9 the test types itself
     */
    private function steadyRace(array $withoutCrossingsOfCar9At = []): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 10], 180, 6);
        foreach (['03:56', '06:56', '09:56', '12:56', '15:56', '18:56'] as $crossing) {
            if (!in_array($crossing, $withoutCrossingsOfCar9At, true)) {
                $race->pass(9, $crossing);
            }
        }
        return $race;
    }

    /**
     * SITUATION: the shared typo. Car 9 crosses at 15:56. Counters 1 and 2
     * both type 4; counter 3 types 9. Car 4 itself crossed 35 seconds
     * earlier, at 15:21.
     *
     * WHAT WENT WRONG (before): two counters "agree" on car 4, so car 4 got
     * a pass it never made; the suggestion was to move counter 3's CORRECT
     * entry onto car 4 as well; accepting it left car 9 a lap short and car
     * 4 a lap over, with nothing left to review.
     *
     * EXPECTED:
     * - the phantom pass of car 4 is held (35 s after its real pass), so
     *   car 4 is not ahead; it has its own flag;
     * - the flag on counter 3's entry is still "troca_provavel", about
     *   both cars, but the suggestion is the REVERSE one: move the two
     *   entries to car 9 ("reatribuir_grupo"). Car 9 was due at the line
     *   (a lap after its previous pass) and car 4 was not;
     * - the reason tells both stories;
     * - the flag is about all three entries, so the screen can act on any;
     * - accepting it gives car 9 its lap, with three counters, and leaves
     *   nothing to review.
     */
    public function testWhenTwoCountersTypeTheSameWrongNumberTheSuggestionIsToMoveTheirEntries(): void
    {
        $race = $this->steadyRace(['15:56']);
        $wrong = $race->pass(4, '15:56', [1, 2]);
        $right = $race->entry(3, 9, '15:56');

        $result = $race->run('20:00');

        $this->assertLapCounts([4 => 5, 9 => 4], $result);
        $this->assertTrue($result->groupOfEntry($wrong[1])->retida);
        $types = array_map(fn(Flag $flag) => $flag->tipo, $result->openFlags());
        sort($types);
        $this->assertSame([Flag::PASSAGEM_PROXIMA_DEMAIS, Flag::TROCA_PROVAVEL], $types);
        $flag = $this->openFlags($result, Flag::TROCA_PROVAVEL)[0];
        $this->assertSame([9, 4], $flag->carros);
        $this->assertSame(['tipo' => 'reatribuir_grupo', 'passagem_ids' => [$wrong[1], $wrong[2]], 'equipe_numero' => 9], $flag->sugestao);
        $this->assertSame([$right, $wrong[1], $wrong[2]], $flag->passagemIds);
        $this->assertSame(3, $flag->fonteId);
        $this->assertStringContainsString('Ou Contagem 3 errou o número', $flag->motivo);
        $this->assertStringContainsString('ou as outras erraram', $flag->motivo);
        $this->assertStringContainsString('está retida', $flag->motivo);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);

        $race->accept($flag);
        $fixed = $race->run('20:00');
        $this->assertLapCounts([4 => 5, 9 => 5], $fixed);
        $this->assertNoOpenFlags($fixed);
        $this->assertCount(3, $fixed->groupOfEntry($right)->entries);
    }

    /**
     * SITUATION: the same picture (two counters type 4, one types 9), but
     * car 9 was NOT due: it crossed normally 40 seconds earlier, at 15:16
     * in this race. The two entries for car 4 are again held.
     *
     * EXPECTED: neither story helps. Moving counter 3's entry would feed a
     * pass that is held because it probably did not happen ("never into a
     * held group"), and car 9 cannot have passed again after 40 s. Both
     * cars are still flagged TOGETHER (a two-counter group next to the
     * third counter's entry for another car is always shown as such): a
     * "troca_provavel" about cars 9 and 4 and all three entries. The only
     * suggestion is the one a lone entry at an impossible moment always
     * gets: void it. Never "move it to car 4".
     */
    public function testNothingIsMovedIntoAHeldGroup(): void
    {
        $race = $this->steadyRace(['15:56']);
        $race->pass(9, '15:16');
        $others = $race->pass(4, '15:56', [1, 2]);
        $lone = $race->entry(3, 9, '15:56');

        $result = $race->run('20:00');

        $this->assertSame([], $this->openFlags($result, Flag::SOLITARIA));
        $flag = $this->openFlags($result, Flag::TROCA_PROVAVEL)[0];
        $this->assertSame([9, 4], $flag->carros);
        $this->assertSame([$lone, $others[1], $others[2]], $flag->passagemIds);
        $this->assertSame(['tipo' => 'anular', 'passagem_id' => $lone], $flag->sugestao);
    }

    /**
     * SITUATION: car 9 misses a lap: nobody has it at 15:56, where it was
     * due. At that moment counters 1 and 2 record car 10, a few seconds
     * before car 10's usual time (it normally crosses at 16:03), and
     * counter 3 records 9. Car 10's pass is perfectly ordinary.
     *
     * EXPECTED: both stories are believable: counter 3 may have typed 9
     * for 10, or the other two may have typed 10 for 9. The engine does not
     * guess: a "troca_provavel" flag with NO suggestion, telling both
     * stories, about all three entries. Nothing counts for car 9 until a
     * person decides.
     */
    public function testWhenBothStoriesAreBelievableThereIsNoSuggestion(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8], 180, 6);
        foreach (['03:56', '06:56', '09:56', '12:56', '18:56'] as $crossing) {
            $race->pass(9, $crossing);
        }
        foreach (['04:03', '07:03', '10:03', '13:03', '19:03'] as $crossing) {
            $race->pass(10, $crossing);
        }
        $others = $race->pass(10, '15:58', [1, 2]);
        $lone = $race->entry(3, 9, '15:58');

        $result = $race->run('20:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::TROCA_PROVAVEL);
        $this->assertNull($flag->sugestao);
        $this->assertSame([$lone, $others[1], $others[2]], $flag->passagemIds);
        $this->assertStringContainsString('As duas leituras são plausíveis', $flag->motivo);
        $this->assertLapCounts([9 => 4, 10 => 5], $result);
    }

    /**
     * SITUATION: counter 1 types a lone 4 at a moment when counters 2 and
     * 3 both typed 99, which is no team at this event; in a second race
     * they both typed 50, a team that is present but not classified.
     *
     * EXPECTED: an entry is never suggested to be moved onto a number that
     * is not a classified team. The lone 4 is an ordinary lone entry, and
     * the other two entries get the flag that says what THEY are.
     */
    public function testNothingIsMovedOntoAnUnknownOrUnclassifiedNumber(): void
    {
        foreach ([99 => Flag::CARRO_DESCONHECIDO, 50 => Flag::CARRO_NAO_CLASSIFICADO] as $number => $itsFlag) {
            $race = $this->steadyRace();
            $race->teamsNotClassified([50]);
            $race->pass($number, '14:00', [2, 3]);
            $race->entry(1, 4, '14:00');

            $result = $race->run('20:00');

            $types = array_map(fn(Flag $flag) => $flag->tipo, $result->openFlags());
            sort($types);
            $expected = [$itsFlag, Flag::SOLITARIA];
            sort($expected);
            $this->assertSame($expected, $types, "Two counters typed $number.");
        }
    }

    /**
     * SITUATION: the steady race. Car 4 crosses at 15:21, recorded by
     * counters 1 and 2; counter 3 types 9 instead. Car 9 last crossed at
     * 12:56, 145 s earlier (more than three quarters of a lap), but it
     * crosses again, recorded by everybody, at 15:56: only 35 s later.
     *
     * EXPECTED: car 9 was NOT due at 15:21. "Due" means the lone entry sits
     * where the car is missing a pass: far from the pass before it AND from
     * the pass after it. Here the car's own pass follows right away, so the
     * ordinary reading holds: counter 3 typed the wrong number, and the
     * suggestion is to move its entry to car 4.
     */
    public function testACarWithItsOwnPassRightAfterwardsWasNotDue(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 5, 6, 7, 8, 9, 10], 180, 6);
        foreach (['03:21', '06:21', '09:21', '12:21', '18:21'] as $crossing) {
            $race->pass(4, $crossing);
        }
        $race->pass(4, '15:21', [1, 2]);
        $typo = $race->entry(3, 9, '15:21');

        $flag = $this->assertSingleOpenFlag($race->run('20:00'), Flag::TROCA_PROVAVEL);

        $this->assertSame(['tipo' => 'reatribuir', 'passagem_id' => $typo, 'equipe_numero' => 4], $flag->sugestao);
    }

    /**
     * SITUATION: the shared typo with the phantom FIRST. Car 3 crosses at
     * 15:14 (recorded by everybody on its other laps); this time counters
     * 1 and 2 type 4 for it and counter 3 types 3. Car 4 really crosses
     * 48 seconds LATER, at 16:02, typed right by all three. A second race
     * is the same except that car 3 was not due: its next pass comes only
     * 110 s later, at 17:04.
     *
     * WHAT WENT WRONG (found by the simulation runner, seed 7): the phantom
     * pass of car 4 at 15:14 counts (a believable lap after car 4's
     * previous pass) and car 4's REAL pass is the one held, 48 s after it.
     * The phantom itself is not held, so the engine took it for a sound
     * pass and suggested moving counter 3's correct entry onto car 4.
     * Accepting every suggestion left car 3 a lap short with nothing open.
     *
     * EXPECTED: a pass within the minimum plausible lap of ANOTHER agreed
     * pass of its car, before or after, is in doubt, whichever of the two
     * is the one held. Car 3 due: the reverse suggestion (move the two
     * entries to car 3); accepting it puts both cars right and frees car
     * 4's real pass. Car 3 not due: no suggestion, both stories told. In
     * neither race is the plain "move the lone entry to car 4" suggested.
     */
    public function testAPhantomThatComesBeforeTheWrongCarsRealPassIsInDoubtToo(): void
    {
        foreach ([true, false] as $car3WasDue) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->steadyField([1, 2, 5, 6, 7, 8, 9, 10], 180, 6);
            foreach (['03:14', '06:14', '09:14', '12:14', $car3WasDue ? '18:14' : '17:04'] as $crossing) {
                $race->pass(3, $crossing);
            }
            foreach (['04:02', '07:02', '10:02', '13:02', '16:02', '19:02'] as $crossing) {
                $race->pass(4, $crossing);
            }
            $phantom = $race->pass(4, '15:14', [1, 2]);
            $right = $race->entry(3, 3, '15:14');

            $result = $race->run('20:00');

            $context = $car3WasDue ? 'Car 3 was due.' : 'Car 3 was not due.';
            $this->assertTrue($result->groupOfEntry($phantom[1])->contado, $context);
            $flag = $this->openFlags($result, Flag::TROCA_PROVAVEL)[0];
            $this->assertSame([$right, $phantom[1], $phantom[2]], $flag->passagemIds, $context);
            if (!$car3WasDue) {
                $this->assertNull($flag->sugestao, $context);
                $this->assertStringContainsString('está em dúvida', $flag->motivo);
                continue;
            }
            $this->assertSame(['tipo' => 'reatribuir_grupo', 'passagem_ids' => [$phantom[1], $phantom[2]], 'equipe_numero' => 3], $flag->sugestao, $context);
            $race->accept($flag);
            $fixed = $race->run('20:00');
            $this->assertLapCounts([3 => 5, 4 => 5], $fixed);
            $this->assertNoOpenFlags($fixed);
        }
    }

    /**
     * SITUATION: the same phantom-first shared typo, early in a race of two
     * cars: too few laps for the pace of the field to be known, but car 4
     * has three laps of its own, so the hold rule knows its minimum lap
     * (90 s) and holds its real pass, 48 s after the phantom.
     *
     * EXPECTED: "in doubt" is judged by the hold rule's own yardstick, not
     * only by the pace model's: the phantom is in doubt because the pass
     * held right after it is held on its account. With no pace nobody can
     * say car 3 was due, so there is no suggestion at all; above all, not
     * the one to move counter 3's entry onto car 4.
     */
    public function testAPassIsInDoubtWhenTheNextOneIsHeldBecauseOfItEvenWithoutAPace(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        foreach (['00:14', '03:14', '06:14', '09:14'] as $crossing) {
            $race->pass(3, $crossing);
        }
        foreach (['01:02', '04:02', '07:02', '10:02', '13:02'] as $crossing) {
            $race->pass(4, $crossing);
        }
        $race->pass(4, '12:14', [1, 2]);
        $right = $race->entry(3, 3, '12:14');

        $result = $race->run('14:00');

        $this->assertNull($result->cars[3]->voltaEsperadaMs, 'The pace is not known.');
        $this->assertCount(1, $this->openFlags($result, Flag::PASSAGEM_PROXIMA_DEMAIS), 'The real pass of car 4 at 13:02 is held.');
        $flag = $this->openFlags($result, Flag::TROCA_PROVAVEL)[0];
        $this->assertSame($right, $flag->passagemIds[0]);
        $this->assertNull($flag->sugestao);
    }

    /**
     * SITUATION (traced in a simulated race): cars 9 and 7 cross almost
     * together, at 15:56 and 15:58.7. Counter 1 types both correctly.
     * Counter 2 types 9, then "8" for car 7. Counter 3 types "8" for car
     * 9, then 7. Car 8 is nowhere near: it crossed at 15:49... in this
     * race, three minutes away from its next pass.
     *
     * WHAT WENT WRONG: car 9 counts (counters 1 and 2), car 7 counts
     * (counters 1 and 3), and car 8 gets a pass two counters "agree" on,
     * two seconds apart. Nobody is short, there is no lone entry, nothing
     * was flagged, and car 8 was one lap over for good.
     *
     * EXPECTED: the count is what it is (no rule that looks at car 8 alone
     * can know), but the pass is put in front of a person: EVERY counter
     * who recorded car 8 is missing from an agreed pass of another car at
     * that moment, so each may have typed 8 for the car it lacks. Seen from
     * the entries alone the same can be said of cars 9 and 7 (any one of
     * the three may be the one that did not pass), so there is ONE
     * "troca_provavel" flag for the whole knot: about cars 7, 8 and 9 and
     * all six entries, with no suggestion. Moving each "8" to the car its
     * counter lacks removes the phantom and the flag.
     *
     * In a second race counter 3 does have car 9 (only counter 2's "8" is
     * unexplained by a miss): one counter missing another car is an
     * ordinary miss next to an ordinary pass, and nothing is flagged.
     */
    public function testAPassWhoseEveryWitnessLacksAnotherCarIsFlaggedOnAllTheCars(): void
    {
        foreach ([false, true] as $counter3HasCar9) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->steadyField([1, 2, 3, 4, 5, 6, 10], 180, 6);
            foreach (['03:49', '06:49', '09:49', '12:49', '18:49'] as $crossing) {
                $race->pass(7, RaceScenario::clock((int) ((RaceScenario::ms($crossing) - RaceScenario::CLOCK_ZERO_MS) / 1000) + 10));
                $race->pass(8, $crossing);
                $race->pass(9, RaceScenario::clock((int) ((RaceScenario::ms($crossing) - RaceScenario::CLOCK_ZERO_MS) / 1000) + 7));
            }
            $race->pass(8, '15:20');
            $race->pass(9, '15:56', $counter3HasCar9 ? [1, 2, 3] : [1, 2]);
            $race->pass(7, '15:58.7', [1, 3]);
            $forCar9 = $race->entry(3, 8, '15:57.2');
            $forCar7 = $race->entry(2, 8, '15:59.2');

            $result = $race->run('20:00');

            $this->assertLapCounts([7 => 5, 9 => 5], $result);
            if ($counter3HasCar9) {
                $this->assertSame([], $this->openFlags($result, Flag::TROCA_PROVAVEL), 'One witness with a miss beside it is not enough.');
                continue;
            }
            $phantom = $result->groupOfEntry($forCar9);
            $this->assertSame([$forCar9, $forCar7], $phantom->entryIds());
            $this->assertCount(1, $this->openFlags($result, Flag::TROCA_PROVAVEL), 'One flag for the knot.');
            $flag = $this->openFlags($result, Flag::TROCA_PROVAVEL)[0];
            $cars = $flag->carros;
            sort($cars);
            $this->assertSame([7, 8, 9], $cars);
            $this->assertContains($forCar9, $flag->passagemIds);
            $this->assertContains($forCar7, $flag->passagemIds);
            $this->assertCount(6, $flag->passagemIds, 'Every entry of the three passes: a decision may be about any of them.');
            $this->assertNull($flag->sugestao);
            $this->assertNull($flag->contagemEmDuvida);
            $this->assertStringContainsString('Um desses carros pode não ter passado', $flag->motivo);
            $before = $result->lapCount(8);

            $race->reassign($forCar9, 9);
            $race->reassign($forCar7, 7);
            $fixed = $race->run('20:00');
            $this->assertSame([], $this->openFlags($fixed, Flag::TROCA_PROVAVEL));
            $this->assertCount(3, $fixed->groupOfEntry($forCar9)->entries);
            $this->assertLessThanOrEqual($before, $fixed->lapCount(8));
        }
    }
}
