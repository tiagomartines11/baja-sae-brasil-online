<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Source;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Corrections made by people ("decisões").
 *
 * Nothing a counter typed is ever edited or deleted. A correction is a new
 * record laid on top of the raw data, and undoing a correction is yet
 * another record ("desfazer"). Each test below applies one kind of decision
 * and then undoes it, checking that the race returns to exactly where it was.
 */
final class DecisionTest extends EngineTestCase
{
    /**
     * "anular": ignore an entry.
     *
     * SITUATION: car 7 was recorded by counters 1 and 2 (counter 3 is online
     * and has nothing). The reviewer voids counter 1's entry.
     *
     * EXPECTED: with one counter left the lap no longer counts and the
     * remaining entry is flagged as lone. Undoing the void restores the lap.
     */
    public function testVoidingAnEntryRemovesItAndUndoBringsItBack(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $entries = $race->pass(7, '03:00', [1, 2]);
        $this->assertLapCounts([7 => 1], $race->run('04:00'));

        $void = $race->void($entries[1]);
        $voided = $race->run('04:00');
        $this->assertLapCounts([7 => 0], $voided);
        $this->assertSame(0, $this->sourceCount($voided, 7, 1));
        $this->assertSingleOpenFlag($voided, Flag::SOLITARIA);

        $race->undo($void);
        $restored = $race->run('04:00');
        $this->assertLapCounts([7 => 1], $restored);
        $this->assertNoOpenFlags($restored);
    }

    /**
     * "anular" by the counter: the "desfazer" button next to each of the
     * last ten entries on the count page.
     *
     * SITUATION: counter 1 types 99 by mistake and immediately presses
     * "desfazer" on their own phone.
     *
     * EXPECTED: the entry takes no part in anything, exactly like a
     * reviewer's void. (It is still stored; only its effect is cancelled.)
     */
    public function testACounterVoidingTheirOwnEntryWorksLikeAReviewersVoid(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $mistake = $race->entry(1, 99, '03:00');
        $race->void($mistake, Decision::ORIGEM_CONTADOR);

        $result = $race->run('04:00');

        $this->assertNull($result->groupOfEntry($mistake));
        $this->assertNoOpenFlags($result);
    }

    /**
     * "reatribuir": this entry was really car X.
     *
     * SITUATION: counter 1 typed 14 when car 41 passed; counters 2 and 3
     * typed 41. The reviewer reassigns counter 1's entry to car 41.
     *
     * EXPECTED: the entry joins car 41's pass (three counters now agree)
     * while still remembering what was typed. Undo puts it back on car 14.
     */
    public function testReassigningAnEntryMovesItToTheOtherCarAndUndoMovesItBack(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(41, '03:00', [2, 3]);
        $typo = $race->entry(1, 14, '03:00');

        $reassign = $race->reassign($typo, 41);
        $fixed = $race->run('04:00');
        $group = $fixed->groupOfEntry($typo);
        $this->assertSame(41, $group->carro);
        $this->assertCount(3, $group->entries);
        $this->assertSame(14, $fixed->effectiveEntries[$typo]->carroDigitado, 'What was typed is never lost.');
        $this->assertNoOpenFlags($fixed);

        $race->undo($reassign);
        $back = $race->run('04:00');
        $this->assertSame(14, $back->groupOfEntry($typo)->carro);
        $this->assertSingleOpenFlag($back, Flag::TROCA_PROVAVEL);
    }

    /**
     * "confirmar": count this pass even though only one counter has it.
     * It is also what the "keep both / it is fine" button stores.
     *
     * SITUATION: only counter 2 recorded car 5. The reviewer checks the
     * video and confirms the car did pass.
     *
     * EXPECTED: the lap counts and the flag is gone. Undo: not counted,
     * flagged again.
     */
    public function testConfirmingALoneEntryCountsItAndSilencesItsFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $lone = $race->entry(2, 5, '03:00');
        $this->assertLapCounts([5 => 0], $race->run('04:00'));

        $confirm = $race->confirm($lone);
        $confirmed = $race->run('04:00');
        $this->assertLapCounts([5 => 1], $confirmed);
        $this->assertNoOpenFlags($confirmed);

        $race->undo($confirm);
        $undone = $race->run('04:00');
        $this->assertLapCounts([5 => 0], $undone);
        $this->assertSingleOpenFlag($undone, Flag::SOLITARIA);
    }

    /**
     * "adicionar": add a pass nobody recorded.
     *
     * SITUATION: all three counters missed car 8 at minute 5 (it crossed
     * hidden behind another car). The team protests, the video agrees, and
     * the reviewer adds the pass by hand.
     *
     * EXPECTED: one lap for car 8, coming from the virtual "revisor" source,
     * with nothing to review. Undo removes it.
     */
    public function testAddingAPassNobodyRecordedCountsItAndUndoRemovesIt(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');

        $add = $race->addPass(8, '05:00');
        $added = $race->run('06:00');
        $this->assertLapCounts([8 => 1], $added);
        $this->assertSame(Source::REVISOR_ID, $added->groups[0]->entries[0]->fonteId);
        $this->assertSame(RaceScenario::ms('05:00'), $added->cars[8]->ultimaPassagemMs);
        $this->assertNoOpenFlags($added);

        $race->undo($add);
        $this->assertSame(0, $race->run('06:00')->lapCount(8));
    }

    /**
     * "excluir_fonte": ignore a source between two times.
     *
     * SITUATION: counter 3 typed nonsense between minutes 2 and 4 (three
     * lone entries). Instead of voiding them one by one, the reviewer
     * excludes counter 3 for that interval.
     *
     * EXPECTED: the three flags vanish at once. Undo brings them back.
     */
    public function testExcludingASourceForAnIntervalDropsItsEntriesThereAndUndoRestoresThem(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->entry(3, 21, '02:10');
        $race->entry(3, 22, '03:00');
        $race->entry(3, 23, '03:50');
        $this->assertCount(3, $race->run('05:00')->openFlags());

        $exclusion = $race->excludeSource(3, '02:00', '04:00');
        $excluded = $race->run('05:00');
        $this->assertNoOpenFlags($excluded);
        $this->assertSame(0, $excluded->sourceStats[3]->entradas);

        $race->undo($exclusion);
        $this->assertCount(3, $race->run('05:00')->openFlags());
    }

    /**
     * "desfazer" of a "desfazer": the reviewer undoes a void, then changes
     * their mind and undoes the undo.
     *
     * EXPECTED: the original void is in force again.
     */
    public function testUndoingAnUndoReappliesTheOriginalDecision(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $entries = $race->pass(7, '03:00', [1, 2]);

        $void = $race->void($entries[1]);
        $undo = $race->undo($void);
        $this->assertLapCounts([7 => 1], $race->run('04:00'), 'After the undo the lap is back.');

        $race->undo($undo);
        $this->assertLapCounts([7 => 0], $race->run('04:00'), 'After undoing the undo the void applies again.');
    }

    /**
     * SITUATION: an entry is reassigned twice (first to car 41, then the
     * reviewer realises it was car 47).
     *
     * EXPECTED: the most recent decision wins.
     */
    public function testWhenAnEntryIsReassignedTwiceTheLatestDecisionWins(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $typo = $race->entry(1, 14, '03:00');
        $race->reassign($typo, 41);
        $race->reassign($typo, 47);

        $this->assertSame(47, $race->run('04:00')->groupOfEntry($typo)->carro);
    }
}
