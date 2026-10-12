<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * How the engine decides that entries typed by different counters are the
 * same car crossing the line (a "pass group"), and when such a group
 * becomes an official lap.
 *
 * The default rule ("confirmadas"): a pass is official when at least two
 * counters recorded it.
 *
 * ONE THING TO KNOW BEFORE READING ANY TEST: the counting line is after the
 * start line, so the first time a car crosses it the car has not completed
 * a lap yet. That first official pass is the car's "pass zero". Laps are
 * counted from there: laps = official passes - 1. Most tests therefore
 * begin with `firstCrossing(...)`, which is every car's pass zero.
 */
final class MatchingTest extends EngineTestCase
{
    /**
     * SITUATION: four cars cross the line almost together (a "pack"). Each
     * of the three counters types the four numbers, but each in a different
     * order, because nobody can type four numbers at once.
     *
     * NOTHING WENT WRONG here. This is the normal case, and it is the reason
     * entries are matched by car and time rather than by typing order.
     *
     * EXPECTED: every car gets exactly one lap (each had crossed once
     * before, its pass zero) and the review queue is empty.
     */
    public function testAPackTypedInDifferentOrdersByEachCounterCountsEveryCarOnce(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([7, 12, 41, 3]);

        // Counter 1 types them in the order 7, 12, 41, 3, a second apart.
        $race->entry(1, 7, '03:00');
        $race->entry(1, 12, '03:01');
        $race->entry(1, 41, '03:02');
        $race->entry(1, 3, '03:03');

        // Counter 2 types 12, 7, 3, 41.
        $race->entry(2, 12, '03:00.5');
        $race->entry(2, 7, '03:01.5');
        $race->entry(2, 3, '03:02.5');
        $race->entry(2, 41, '03:03.5');

        // Counter 3 types 41, 3, 7, 12.
        $race->entry(3, 41, '03:01');
        $race->entry(3, 3, '03:02');
        $race->entry(3, 7, '03:03');
        $race->entry(3, 12, '03:04');

        $result = $race->run('04:00');

        $this->assertLapCounts([7 => 1, 12 => 1, 41 => 1, 3 => 1], $result);
        $this->assertNoOpenFlags($result);
        foreach ([1, 2, 3] as $counter) {
            $this->assertSame(0, $result->sourceStats[$counter]->faltas, "Counter $counter missed nothing.");
        }
    }

    /**
     * SITUATION: the same pack, but counter 3 is slow: it types car 12 six
     * seconds after the other two did.
     *
     * WHY IT MATTERS: counters who saw the same crossing type it within a
     * few seconds of each other (`dispersao_alerta_ms`, 4 s). An entry
     * further away than that may be the same crossing typed late, or
     * something else entirely (a typo for this car, a repeat).
     *
     * EXPECTED: the lap counts on the two counters who agree, and its time
     * is theirs. Counter 3's entry is set apart as a lone entry and shown
     * to the reviewer ("dispersao_alta", naming counter 3) with the
     * suggestion to void it. Accepting changes no count and empties the
     * queue.
     */
    public function testAnEntryFarFromTheTwoThatAgreeIsSetApartAndFlagged(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([12]);
        $race->entry(1, 12, '03:01');
        $race->entry(2, 12, '03:00.5');
        $late = $race->entry(3, 12, '03:07');

        $result = $race->run('04:00');

        $this->assertLapCounts([12 => 1], $result);
        $this->assertSame(RaceScenario::ms('03:00.75'), $result->cars[12]->ultimaPassagemMs, 'The pass time is the two agreeing counters\'.');
        $flag = $this->assertSingleOpenFlag($result, Flag::DISPERSAO_ALTA);
        $this->assertSame([$late], $flag->passagemIds);
        $this->assertSame(3, $flag->fonteId);
        $this->assertSame(['tipo' => 'anular', 'passagem_id' => $late], $flag->sugestao);
        $this->assertStringContainsString('Contagem 3', $flag->motivo);
        $this->assertStringContainsString('6 s depois', $flag->motivo);
        $this->assertNull($flag->contagemEmDuvida, 'The count is not in doubt: two counters agree.');
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar, 'Confirming the lone entry would count it as another pass.');

        $race->accept($flag);
        $fixed = $race->run('04:00');
        $this->assertLapCounts([12 => 1], $fixed);
        $this->assertNoOpenFlags($fixed);
    }

    /**
     * SITUATION: counter 1 types 23 by mistake when car 22 crosses at
     * 12:00. Car 23 itself crosses twenty seconds later, and all three
     * counters record it: counter 2 at 12:19.8, counter 1 at 12:20.1 and
     * counter 3 at 12:20.6.
     *
     * WHAT WENT WRONG (in the software, found by simulating whole races):
     * entries used to join "the first group still open". Counter 2's real
     * entry (19.5 s after the stray one, inside the 20 s window) joined the
     * STRAY, and the other two real entries formed a second group. Two
     * groups of two counters each: car 23 got two passes for one crossing.
     *
     * EXPECTED: entries closest in time go together, so the three real
     * entries are one pass and car 23 has one lap. Counter 1's stray entry
     * stands alone. It is the earlier half of a "repetida" (counter 1 has
     * the car twice within the window), and the suggestion is to void THE
     * STRAY, not the later entry, which is the good one.
     */
    public function testAStrayEntryOneWindowBeforeTheRealPassDoesNotSplitItInTwo(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([22, 23]);
        $race->pass(22, '12:00', [2, 3]);
        $stray = $race->entry(1, 23, '12:00.3');
        $race->entry(2, 23, '12:19.8');
        $real = $race->entry(1, 23, '12:20.1');
        $race->entry(3, 23, '12:20.6');

        $result = $race->run('13:00');

        $this->assertLapCounts([22 => 1, 23 => 1], $result);
        $this->assertCount(3, $result->groupOfEntry($real)->entries, 'The three real entries are one pass.');
        $this->assertCount(1, $result->groupOfEntry($stray)->entries);
        $flag = $this->assertSingleOpenFlag($result, Flag::REPETIDA);
        $this->assertSame(['tipo' => 'anular', 'passagem_id' => $stray], $flag->sugestao);

        $race->accept($flag);
        $fixed = $race->run('13:00');
        $this->assertLapCounts([22 => 1, 23 => 1], $fixed);
        $this->assertNoOpenFlags($fixed);
    }

    /**
     * SITUATION: the same entries in every order they could have been
     * uploaded in (a phone that was offline uploads late; the database
     * returns rows as it pleases).
     *
     * EXPECTED: the same pass groups every time. Matching is decided by
     * the times typed, never by the order of arrival.
     */
    public function testMatchingDoesNotDependOnTheOrderEntriesArriveIn(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([23]);
        $race->entry(1, 23, '12:00.3');
        $race->entry(2, 23, '12:19.8');
        $race->entry(1, 23, '12:20.1');
        $race->entry(3, 23, '12:20.6');
        $race->entry(3, 23, '12:31');
        $input = $race->input('13:00');
        $describe = fn($result) => array_map(fn($group) => implode('+', $group->entryIds()) . ($group->contado ? ' counted' : ''), $result->groups);
        $expected = $describe(\Baja\Contagem\Engine\Engine::run($input));

        $orders = [[4, 3, 2, 1, 0, 7, 6, 5], [7, 0, 6, 1, 5, 2, 4, 3], [3, 7, 4, 6, 5, 0, 1, 2]];
        foreach ($orders as $order) {
            $shuffled = clone $input;
            $shuffled->entries = array_map(fn(int $index) => $input->entries[$index], $order);
            $this->assertSame($expected, $describe(\Baja\Contagem\Engine\Engine::run($shuffled)));
        }
    }

    /**
     * SITUATION: car 41 crosses the line (for the second time; everybody
     * recorded its first crossing). Counters 1 and 2 record it. Counter 3
     * was looking at their phone and did not.
     *
     * WHAT WENT WRONG: one counter missed a car.
     *
     * EXPECTED: the lap counts (two counters agree, that is enough). Nobody
     * is asked to review anything, because there is nothing to decide. The
     * miss is simply added to counter 3's statistics, so that after the race
     * one can see how reliable each counter was.
     */
    public function testALapMissedByOneCounterStillCountsAndIsRecordedAsThatCountersMiss(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([41]);
        $race->pass(41, '03:00', [1, 2]);

        $result = $race->run('04:00');

        $this->assertLapCounts([41 => 1], $result);
        $this->assertNoOpenFlags($result);
        $this->assertSame(0, $result->sourceStats[1]->faltas);
        $this->assertSame(0, $result->sourceStats[2]->faltas);
        $this->assertSame(1, $result->sourceStats[3]->faltas, 'The miss is charged to counter 3.');
    }

    /**
     * SITUATION: car 8 crosses the counting line four times, three minutes
     * apart, and everybody records every crossing.
     *
     * NOTHING WENT WRONG.
     *
     * EXPECTED: four official passes, but THREE laps. The first crossing is
     * pass zero (number 0, state "inicial"): the car came from the start
     * line, which is not a full lap. The next three complete laps 1, 2
     * and 3. Entries more than the matching window (20 seconds) apart are
     * different crossings.
     */
    public function testTheFirstCrossingIsPassZeroAndEachLaterCrossingCompletesALap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(8, '00:40');
        $race->pass(8, '03:40');
        $race->pass(8, '06:40');
        $race->pass(8, '09:40');

        $result = $race->run('10:00');

        $this->assertLapCounts([8 => 3], $result);
        $this->assertSame([0, 1, 2, 3], array_map(fn(Lap $lap) => $lap->numeroVolta, $result->laps));
        $this->assertSame(
            [Lap::INICIAL, Lap::CONTADA, Lap::CONTADA, Lap::CONTADA],
            array_map(fn(Lap $lap) => $lap->estado, $result->laps)
        );
        $this->assertSame([0, 1, 2, 3], array_map(fn($group) => $group->numeroVolta, $result->groups));
        $this->assertSame(RaceScenario::ms('09:40'), $result->cars[8]->ultimaPassagemMs);
    }

    /**
     * SITUATION: car 8 has crossed the line once, 40 seconds after the start.
     *
     * EXPECTED: zero laps, but the car is not invisible: it has an official
     * pass (its pass zero), and that is its "last pass" for now.
     */
    public function testACarThatCrossedOnlyOnceHasZeroLapsButAKnownLastPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(8, '00:40');

        $result = $race->run('02:00');

        $this->assertLapCounts([8 => 0], $result);
        $this->assertSame(RaceScenario::ms('00:40'), $result->cars[8]->ultimaPassagemMs);
        $this->assertNull($result->cars[8]->posicao, 'A car with no laps has no position in the standings.');
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: the counters start typing during the formation lap, before
     * race control has started the race. Car 5 is recorded by everybody
     * thirty seconds before the start, then twenty seconds after it, then
     * three minutes after it.
     *
     * EXPECTED: what was typed before the start does not count. It is not
     * even the car's pass zero: pass zero is the crossing at 00:20, and the
     * crossing at 03:00 completes lap 1.
     *
     * The entries are not thrown away, though: they stay visible as a pass
     * group marked "antes da largada" (so that a protest can be answered),
     * with no number, no row in the lap table and, being a full half minute
     * before the start, no flag. (Entries only SECONDS before the start are
     * another matter; see BeforeTheStartTest.)
     */
    public function testEntriesTypedBeforeTheStartAreNeitherLapsNorPassZero(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $beforeTheStart = $race->pass(5, '-00:30');
        $race->pass(5, '00:20');
        $race->pass(5, '03:00');

        $result = $race->run('04:00');

        $this->assertLapCounts([5 => 1], $result);
        $this->assertSame(RaceScenario::ms('00:20'), $result->laps[0]->tsMs, 'Pass zero is the first crossing AFTER the start.');
        $this->assertCount(2, $result->laps, 'The group before the start is not a row of the lap table.');
        $warmUp = $result->groupOfEntry($beforeTheStart[1]);
        $this->assertTrue($warmUp->antesDaLargada);
        $this->assertFalse($warmUp->contado);
        $this->assertNull($warmUp->numeroVolta);
        $this->assertNoOpenFlags($result);
        $this->assertSame(2, $this->sourceCount($result, 5, 1), 'Warm-up entries are not among a counter\'s passes of the race.');
    }

    /**
     * SITUATION: the counting line is right at the start line and car 5 is
     * typed by everybody at the very millisecond of the start; a second
     * car, 6, one millisecond before it.
     *
     * EXPECTED: "after the start" includes the start instant itself. Car
     * 5's entry is its pass zero; car 6's is before the start and is not an
     * official pass (it is kept, marked, and a reviewer is asked about it).
     */
    public function testAnEntryAtTheExactInstantOfTheStartBelongsToTheRace(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('01:00');
        $atTheStart = $race->pass(5, '01:00');
        $justBefore = $race->pass(6, '00:59.999');

        $result = $race->run('02:00');

        $this->assertSame(0, $result->groupOfEntry($atTheStart[1])->numeroVolta);
        $this->assertFalse($result->groupOfEntry($atTheStart[1])->antesDaLargada);
        $this->assertTrue($result->groupOfEntry($justBefore[1])->antesDaLargada);
        $this->assertNull($result->groupOfEntry($justBefore[1])->numeroVolta);
        $this->assertNull($result->cars[6]->ultimaPassagemMs ?? null, 'Car 6 has no official pass.');
    }

    /**
     * SITUATION: everybody is typing, but race control has not pressed
     * "Largar agora" yet.
     *
     * EXPECTED: nothing is counted and nothing is flagged. Without a start
     * there is no race to count.
     */
    public function testNothingIsCountedWhileTheRaceHasNoStart(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->pass(5, '03:00');

        $result = $race->run('04:00');

        $this->assertSame(0, $result->lapCount(5));
        $this->assertSame([], $result->laps);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: car 9 made its first crossing normally. At minute 3 only
     * counter 2 records it again. Counters 1 and 3 are online and recorded
     * nothing for it.
     *
     * WHAT WENT WRONG: either counter 2 typed something that did not happen,
     * or two counters missed the same car. The engine cannot know.
     *
     * EXPECTED: the pass does not count yet. It is kept as a "pendente" row
     * (waiting for a person), and a flag asks the reviewer to decide.
     */
    public function testAPassSeenByOnlyOneCounterWaitsForAReviewer(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->entry(2, 9, '03:00');

        $result = $race->run('04:00');

        $this->assertSame(0, $result->lapCount(9));
        $this->assertCount(2, $result->laps, 'Pass zero, and the pass that is waiting.');
        $this->assertSame(Lap::PENDENTE, $result->laps[1]->estado);
        $this->assertSame(RaceScenario::ms('00:20'), $result->cars[9]->ultimaPassagemMs, 'A waiting pass is not the car\'s last pass.');
        $this->assertCount(1, $result->openFlags());
    }

    /**
     * SITUATION: car 9 crosses and counter 1 presses Enter at 03:00.
     * Counter 2 is slow. In the first race counter 2 presses Enter exactly
     * 20 seconds later; in the second race, one millisecond after that.
     *
     * WHY 20 SECONDS: that is the "matching window" (`janela_ms`). Entries
     * for the same car inside it may describe the same crossing.
     *
     * EXPECTED: at exactly 20.000 s the two entries are put together as ONE
     * doubtful pass. Twenty seconds is far more than counters who saw the
     * same crossing differ by, so the pass is held, not counted, and one
     * flag ("dispersao_alta") names both counters; the reviewer who says
     * "it is one pass" gives the car its lap with one decision. At 20.001 s
     * they are two unrelated lone entries, nothing counts, and each is
     * shown to the reviewer on its own.
     */
    public function testTheMatchingWindowIsTwentySecondsInclusive(): void
    {
        $insideTheWindow = RaceScenario::withCounters(2);
        $insideTheWindow->start('00:00');
        $insideTheWindow->firstCrossing([9]);
        $first = $insideTheWindow->entry(1, 9, '03:00');
        $second = $insideTheWindow->entry(2, 9, '03:20');
        $inside = $insideTheWindow->run('05:00');
        $this->assertCount(2, $inside->groups);
        $this->assertLapCounts([9 => 0], $inside);
        $held = $inside->groupOfEntry($first);
        $this->assertTrue($held->retida);
        $this->assertSame(\Baja\Contagem\Engine\Data\PassGroup::RETIDA_DISPERSAO, $held->motivoRetencao);
        $flag = $this->assertSingleOpenFlag($inside, Flag::DISPERSAO_ALTA);
        $this->assertSame([$first, $second], $flag->passagemIds);
        $this->assertNull($flag->sugestao);
        $this->assertSame(Flag::DUVIDA_A_MENOS, $flag->contagemEmDuvida);
        $this->assertSame(Flag::EFEITO_MAIS_UMA_PASSAGEM, $flag->efeitoDeConfirmar);
        $this->assertStringContainsString('Contagem 1 e Contagem 2', $flag->motivo);
        $this->assertStringContainsString('20 s', $flag->motivo);

        $insideTheWindow->confirm($first);
        $confirmed = $insideTheWindow->run('05:00');
        $this->assertLapCounts([9 => 1], $confirmed);
        $this->assertNoOpenFlags($confirmed);

        $justOutside = RaceScenario::withCounters(2);
        $justOutside->start('00:00');
        $justOutside->firstCrossing([9]);
        $justOutside->entry(1, 9, '03:00');
        $justOutside->entry(2, 9, '03:20.001');
        $outside = $justOutside->run('05:00');
        $this->assertLapCounts([9 => 0], $outside);
        $this->assertCount(3, $outside->groups);
        $this->assertCount(2, $this->openFlags($outside, Flag::SOLITARIA));
    }

    /**
     * SITUATION: the organisers set a narrower matching window of 5 seconds
     * (`janela_ms` = 5000). Counter 1 records car 9 at 03:00 and counter 2
     * at 03:06.
     *
     * EXPECTED: with the default 20 seconds these are put together as one
     * (doubtful) pass; with 5 seconds they are two lone entries. The window
     * is the setting, not a number buried in the code.
     */
    public function testTheMatchingWindowFollowsTheSetting(): void
    {
        foreach ([20000 => 2, 5000 => 3] as $janelaMs => $expectedGroups) {
            $race = RaceScenario::withCounters(2);
            $race->settings->janelaMs = $janelaMs;
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->entry(1, 9, '03:00');
            $race->entry(2, 9, '03:06');

            $this->assertCount($expectedGroups, $race->run('05:00')->groups, "With a window of $janelaMs ms.");
        }
    }

    /**
     * SITUATION: two counters record car 9 at 03:00 and at 03:04 (in a
     * second race: 03:04.001), and the third missed it. Then the organisers
     * widen `dispersao_alerta_ms` from its default 4 s to 8 s.
     *
     * EXPECTED: entries exactly 4.000 s apart are two counters agreeing,
     * and the lap counts with no flag. One millisecond more and they are
     * not: the pass is held and flagged. With the setting at 8 s the same
     * entries agree again. Like the window, it is a setting.
     */
    public function testHowFarApartAgreeingCountersMayBeIsASetting(): void
    {
        foreach ([['03:04', 4000, 1], ['03:04.001', 4000, 0], ['03:04.001', 8000, 1]] as [$secondEntryAt, $alertaMs, $expectedLaps]) {
            $race = RaceScenario::withCounters(3);
            $race->settings->dispersaoAlertaMs = $alertaMs;
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->entry(1, 9, '03:00');
            $race->entry(2, 9, $secondEntryAt);

            $result = $race->run('05:00');

            $context = "Second entry at $secondEntryAt, dispersao_alerta_ms = $alertaMs.";
            $this->assertLapCounts([9 => $expectedLaps], $result, $context);
            $this->assertCount(1 - $expectedLaps, $this->openFlags($result, Flag::DISPERSAO_ALTA), $context);
        }
    }

    /**
     * SITUATION: whoever shows a result asks "which pass group is this
     * entry in?" (`groupOfEntry`). The answer is looked up in an index
     * that is only built when first asked for. Here a result is asked,
     * then given a different set of groups, then asked again.
     *
     * EXPECTED: the second answer is about the second set of groups, not a
     * leftover of the first.
     */
    public function testTheGroupOfAnEntryFollowsTheGroupsTheResultHolds(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $car5 = $race->pass(5, '00:20');
        $car6 = $race->pass(6, '00:25');
        $result = $race->run('01:00');
        $groupOfCar5 = $result->groupOfEntry($car5[1]);
        $groupOfCar6 = $result->groupOfEntry($car6[1]);
        $this->assertSame(5, $groupOfCar5->carro);
        $this->assertNull($result->groupOfEntry(999), 'An entry the result does not hold.');

        $result->setGroups([$groupOfCar6]);

        $this->assertNull($result->groupOfEntry($car5[1]));
        $this->assertSame($groupOfCar6, $result->groupOfEntry($car6[1]));
    }

    /**
     * SITUATION: three counters record car 9, each five seconds after the
     * previous one: 03:00, 03:05 and 03:10. No two of them are within the
     * 4 s that counters who saw the same crossing differ by.
     *
     * EXPECTED: all three are within the matching window, so they are put
     * together as one doubtful pass; nobody agrees closely, so it is held.
     * One flag ("dispersao_alta") about the three entries. Confirming it
     * counts ONE pass, not three.
     */
    public function testThreeCountersWhoAllDisagreeAreOneHeldPass(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $entries = [$race->entry(1, 9, '03:00'), $race->entry(2, 9, '03:05'), $race->entry(3, 9, '03:10')];

        $result = $race->run('04:00');

        $this->assertLapCounts([9 => 0], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::DISPERSAO_ALTA);
        $this->assertSame($entries, $flag->passagemIds);
        $this->assertStringContainsString('espalhados por 10 s', $flag->motivo);

        $race->confirm($entries[1]);
        $confirmed = $race->run('04:00');
        $this->assertLapCounts([9 => 1], $confirmed);
        $this->assertNoOpenFlags($confirmed);
    }

    /**
     * SITUATION: counter 1 has a stray entry for car 9 at 03:00. The car
     * really crosses at 03:12, recorded by counters 2 and 3. A reviewer,
     * seeing the car on the video, adds a pass at 03:10.
     *
     * EXPECTED: the added pass joins the group NEAREST to it (the real
     * crossing, two seconds away), not the first group around (the stray,
     * ten seconds away): the car gets one lap for that crossing, not two.
     */
    public function testAPassAReviewerAddsJoinsThePassNearestToIt(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $stray = $race->entry(1, 9, '03:00');
        $real = $race->pass(9, '03:12', [2, 3]);
        $race->addPass(9, '03:10');

        $result = $race->run('04:00');

        $this->assertLapCounts([9 => 1], $result);
        $this->assertCount(3, $result->groupOfEntry($real[2])->entries);
        $this->assertTrue($result->groupOfEntry($real[2])->adicionadoPorRevisor);
        $this->assertCount(1, $result->groupOfEntry($stray)->entries);
    }

    /**
     * SITUATION: three counters record car 9 twelve seconds apart each:
     * 03:00, 03:12 and 03:24. Each is within the matching window of the
     * next, but the first and the last are 24 s apart.
     *
     * EXPECTED: a group never spans more than the matching window. The
     * first two are put together (a doubtful, held pass); the third stands
     * alone. Two flags, nothing counted.
     */
    public function testAGroupNeverSpansMoreThanTheMatchingWindow(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $first = $race->entry(1, 9, '03:00');
        $second = $race->entry(2, 9, '03:12');
        $third = $race->entry(3, 9, '03:24');

        $result = $race->run('05:00');

        $this->assertSame([$first, $second], $result->groupOfEntry($first)->entryIds());
        $this->assertSame([$third], $result->groupOfEntry($third)->entryIds());
        $this->assertLapCounts([9 => 0], $result);
    }

    /**
     * SITUATION: counters 1 and 2 record car 9 exactly 4.000 s apart, and
     * counter 3 twelve seconds later (so this is not the ordinary case of
     * three entries close together).
     *
     * EXPECTED: exactly `dispersao_alerta_ms` apart still agrees: the lap
     * counts on counters 1 and 2, and counter 3's entry is set apart.
     */
    public function testExactlyTheAgreementSpreadStillAgreesWhenAThirdEntryIsFarAway(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->entry(1, 9, '03:00');
        $race->entry(2, 9, '03:04');
        $far = $race->entry(3, 9, '03:16');

        $result = $race->run('05:00');

        $this->assertLapCounts([9 => 1], $result);
        $this->assertSame([$far], $this->assertSingleOpenFlag($result, Flag::DISPERSAO_ALTA)->passagemIds);
    }
}
