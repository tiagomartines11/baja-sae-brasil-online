<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The same counter recording the same car twice in a row ("repetida").
 *
 * A car cannot cross the line twice within 20 seconds, so the second entry
 * is never a second lap. The only question is whether to bother a reviewer.
 *
 * In these tests car 9 first makes its "first crossing" (pass zero, which
 * completes no lap); the crossing at 03:00 then completes lap 1.
 */
final class RepeatedEntryTest extends EngineTestCase
{
    /**
     * SITUATION: car 9 crosses. Counter 1's finger bounces on the Enter key
     * and the number is recorded twice, two seconds apart.
     *
     * WHAT WENT WRONG: a double tap.
     *
     * EXPECTED: the engine ignores the second entry by itself (within
     * `duplicata_ms`, 5 seconds, it is obviously the same crossing). The
     * car has one lap, the review queue stays empty, and the event is only
     * listed among the auto-resolved items, for the record.
     */
    public function testADoubleTapIsVoidedAutomaticallyWithoutBotheringTheReviewer(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $secondTap = $race->entry(1, 9, '03:02');

        $result = $race->run('04:00');

        $this->assertLapCounts([9 => 1], $result);
        $this->assertNoOpenFlags($result);
        $this->assertSame([$secondTap], $result->autoVoidedEntryIds);
        $this->assertSame(2, $this->sourceCount($result, 9, 1), 'Counter 1 has two passes for car 9 (00:20 and 03:00), not three.');

        $autoResolved = $this->autoResolvedFlags($result);
        $this->assertCount(1, $autoResolved);
        $this->assertSame(Flag::REPETIDA, $autoResolved[0]->tipo);
        $this->assertStringContainsString('toque duplo', $autoResolved[0]->motivo);
        $this->assertSame(1, $result->sourceStats[1]->repetidasAnuladas);
    }

    /**
     * SITUATION: counter 1 records car 9 at 03:00 and again at 03:12, on the
     * same phone. Twelve seconds is too long for a bouncing finger and too
     * short for a lap.
     *
     * WHAT WENT WRONG: unclear. Perhaps the counter was unsure the first
     * entry had registered; perhaps the second one was meant for another car.
     *
     * EXPECTED: an open "repetida" flag suggesting to void the second entry.
     * The car still has exactly one lap meanwhile. Accepting the suggestion
     * clears the queue.
     */
    public function testARepeatOutsideTheDoubleTapWindowIsShownToTheReviewer(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $repeat = $race->entry(1, 9, '03:12');

        $result = $race->run('04:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::REPETIDA);
        $this->assertSame(['tipo' => Decision::ANULAR, 'passagem_id' => $repeat], $flag->sugestao);
        $this->assertStringContainsString('12 s', $flag->motivo);
        $this->assertLapCounts([9 => 1], $result);
        $this->assertSame([], $result->autoVoidedEntryIds, 'Nothing was voided automatically.');

        $race->accept($flag);
        $after = $race->run('04:00');
        $this->assertNoOpenFlags($after);
        $this->assertLapCounts([9 => 1], $after);
    }

    /**
     * SITUATION: the other two counters were slower than counter 1's repeat.
     * Counter 1 records car 9 at 03:00 and again at 03:10; counter 2 records
     * it at 03:09 and counter 3 at 03:11.
     *
     * WHY THIS TEST EXISTS: a careless matcher would pair counter 2 with the
     * first entry and counter 3 with the second, see "two counters agree"
     * twice, and give the car two laps for one crossing.
     *
     * EXPECTED: one lap. Entries from the other counters join the earliest
     * group of that crossing.
     */
    public function testAnUnresolvedRepeatNeverSplitsOneCrossingIntoTwoLaps(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->entry(1, 9, '03:00');
        $race->entry(2, 9, '03:09');
        $race->entry(1, 9, '03:10');
        $race->entry(3, 9, '03:11');

        $result = $race->run('04:00');

        $this->assertLapCounts([9 => 1], $result);
    }

    /**
     * SITUATION: a handover on "Contagem 1". The person leaving (phone A)
     * keeps counting while the person arriving (phone B) starts counting on
     * the same source. Five cars cross three times each during the overlap
     * (their first crossing and two laps), and both phones record every
     * crossing, a few seconds apart. Counters 2 and 3 work normally.
     *
     * NOTHING WENT WRONG: this overlap is exactly how a handover should be
     * done, so that no car is missed while people swap seats.
     *
     * EXPECTED:
     * - every crossing counts once, not twice;
     * - nothing reaches the review queue, even when the two phones are more
     *   than five seconds apart (different devices on one source is the
     *   signature of a handover);
     * - "Contagem 1" ends with the same number of passes per car as the
     *   other counters.
     */
    public function testTwoPeopleOnTheSameSourceDuringAHandoverCountEachPassOnce(): void
    {
        $phoneOfPersonLeaving = 10;
        $phoneOfPersonArriving = 11;

        $race = RaceScenario::withCounters(3);
        $race->start('00:00');

        $cars = [1, 2, 3, 4, 5];
        // The newcomer is still finding the rhythm: between 2 and 8 seconds
        // behind, depending on the car.
        $newcomerDelaySeconds = [2, 4, 5, 7, 8];
        for ($lap = 0; $lap < 3; $lap++) {
            foreach ($cars as $position => $car) {
                $crossingSeconds = 180 + $lap * 180 + $position * 25;
                $race->entry(1, $car, RaceScenario::clock($crossingSeconds), $phoneOfPersonLeaving);
                $race->entry(1, $car, RaceScenario::clock($crossingSeconds + $newcomerDelaySeconds[$position]), $phoneOfPersonArriving);
                $race->pass($car, RaceScenario::clock($crossingSeconds), [2, 3]);
            }
        }

        $result = $race->run('15:00');

        $this->assertLapCounts([1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2], $result, 'Three crossings each: pass zero and two laps.');
        $this->assertNoOpenFlags($result, 'A handover must not create work for the reviewer.');
        foreach ($cars as $car) {
            $this->assertSame(3, $this->sourceCount($result, $car, 1), "Contagem 1 has three passes for car $car.");
            $this->assertSame($this->sourceCount($result, $car, 2), $this->sourceCount($result, $car, 1));
        }
        $this->assertSame(0, $result->sourceStats[1]->faltas);
    }

    /**
     * SITUATION: the double tap of the first test (car 9 at 03:00 and again
     * at 03:02 on counter 1's phone). This time the reviewer looks at it
     * and presses "keep both" on the second entry: improbable as it is,
     * they are sure these were two crossings.
     *
     * EXPECTED: a person's decision overrules the automatic void. The
     * second entry is kept, it counts as a pass of its own (the reviewer
     * vouched for it), and nothing is left in the queue. Undoing the
     * decision gives the automatic void back.
     */
    public function testKeepBothOverrulesTheAutomaticVoidOfADoubleTap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $secondTap = $race->entry(1, 9, '03:02');
        $keepBoth = $race->confirm($secondTap);

        $result = $race->run('04:00');

        $this->assertSame([], $result->autoVoidedEntryIds);
        $this->assertSame([], $result->flags, 'Neither an open flag nor an auto-resolved one.');
        $this->assertNotNull($result->groupOfEntry($secondTap));
        $this->assertLapCounts([9 => 2], $result);
        $this->assertSame(3, $this->sourceCount($result, 9, 1));

        $race->undo($keepBoth);
        $undone = $race->run('04:00');
        $this->assertSame([$secondTap], $undone->autoVoidedEntryIds);
        $this->assertLapCounts([9 => 1], $undone);
    }

    /**
     * SITUATION: the repeat of the second test (car 9 at 03:00 and again at
     * 03:12 on the same phone), with its open "repetida" flag. Instead of
     * accepting the suggestion to void, the reviewer presses "keep both".
     *
     * EXPECTED: the flag is gone, both entries stay, and the second one is
     * a confirmed pass: the car has two laps.
     */
    public function testKeepBothOnARepeatClosesTheFlagAndCountsBothPasses(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $repeat = $race->entry(1, 9, '03:12');
        $this->assertSingleOpenFlag($race->run('04:00'), Flag::REPETIDA);

        $race->confirm($repeat);
        $result = $race->run('04:00');

        $this->assertNoOpenFlags($result);
        $this->assertTrue($result->groupOfEntry($repeat)->confirmadoPorRevisor);
        $this->assertLapCounts([9 => 2], $result);
    }

    /**
     * SITUATION: counter 1 records car 9 at 03:00 and once more, on the
     * same phone, after exactly 5.000 s / 5.001 s / 19.999 s / 20.000 s.
     *
     * EXPECTED: the two limits are the settings `duplicata_ms` (5 s) and
     * `janela_ms` (20 s):
     *   up to 5.000 s inclusive   a double tap, voided automatically
     *   5.001 s to 19.999 s       an open "repetida" flag
     *   from 20.000 s on          not a repeat at all, but a separate (lone)
     *                             entry, flagged as such
     */
    public function testTheLimitsOfADoubleTapAndOfARepeat(): void
    {
        $expectations = [
            '03:05' => ['autoVoided' => 1, 'flag' => null],
            '03:05.001' => ['autoVoided' => 0, 'flag' => Flag::REPETIDA],
            '03:19.999' => ['autoVoided' => 0, 'flag' => Flag::REPETIDA],
            '03:20' => ['autoVoided' => 0, 'flag' => Flag::SOLITARIA],
        ];
        foreach ($expectations as $secondEntryAt => $expected) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->pass(9, '03:00');
            $race->entry(1, 9, $secondEntryAt);

            $result = $race->run('04:00');

            $this->assertCount($expected['autoVoided'], $result->autoVoidedEntryIds, "Second entry at $secondEntryAt.");
            $openTypes = array_map(fn(Flag $flag) => $flag->tipo, $result->openFlags());
            $this->assertSame($expected['flag'] === null ? [] : [$expected['flag']], $openTypes, "Second entry at $secondEntryAt.");
            $this->assertLapCounts([9 => 1], $result);
        }
    }

    /**
     * SITUATION: during a handover, the newcomer's phone records car 9
     * fifteen seconds after the phone of the person leaving. Fifteen
     * seconds on ONE phone would be shown to a reviewer.
     *
     * EXPECTED: from two phones it is a handover: voided automatically and
     * listed, for the record, with the words "troca de contador". A double
     * tap wider than `duplicata_ms` is only auto-voided when the setting is
     * raised to cover it.
     */
    public function testAHandoverDuplicateIsRecognisedByItsTwoPhonesAndTheDoubleTapLimitIsASetting(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '03:00');
        $race->entry(1, 9, '03:15', 11);

        $handover = $race->run('04:00');
        $this->assertNoOpenFlags($handover);
        $this->assertStringContainsString('troca de contador', $this->autoResolvedFlags($handover)[0]->motivo);

        $samePhone = RaceScenario::withCounters(3);
        $samePhone->start('00:00');
        $samePhone->firstCrossing([9]);
        $samePhone->pass(9, '03:00');
        $samePhone->entry(1, 9, '03:15');
        $this->assertSingleOpenFlag($samePhone->run('04:00'), Flag::REPETIDA);
        $samePhone->settings->duplicataMs = 15000;
        $this->assertNoOpenFlags($samePhone->run('04:00'));
    }
}
