<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Review\DecisionHistory;
use Baja\Contagem\Review\StateSnapshot;
use Baja\Contagem\Service\Clock;

/**
 * The decision history sent as a difference: a review screen that holds
 * the history up to decision #X gets only the decisions after it.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The state of the review screen carries the whole decision history, so
 * that its filters see every decision. The screen downloads the state again
 * whenever it changes, every few seconds during a race, although the
 * history only changes when somebody decides something. With 300 decisions
 * that was most of every download.
 *
 * Decisions are never edited, and their ids grow. But some fields of an
 * OLD row change all the same: an undo makes the decision it undid
 * "desfeita", and a reassignment changes the car every decision about that
 * entry counts for. Those travel for the older rows too. And after "Zerar
 * corrida" the screen's copy is of another race altogether.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewHistoryDeltaTest extends ReviewDbTestCase
{
    /**
     * SITUATION: a screen holds the history up to the second decision; a
     * third is made.
     *
     * EXPECTED: the answer carries the third alone, says it is a difference
     * after the second, how many decisions the whole list has, and nothing
     * about the older rows (nothing touched them). Merged, it is the whole
     * history the server would have sent.
     */
    public function testOnlyTheDecisionsAfterTheOneTheScreenHoldsTravel(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entry = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(60));
        Clock::freezeAt($this->at(3600));
        $this->store(Decision::confirmar(0, $entry));
        $second = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300)));
        $before = $this->freshScreen();

        $third = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(900)));
        $full = $this->freshScreen();
        $delta = StateSnapshot::withHistoryAfter($full, $second, $before['historico_epoca']);

        $this->assertSame([$third], array_column($delta['historico'], 'id'));
        $this->assertSame($second, $delta['historico_desde']);
        $this->assertSame(3, $delta['historico_quantas']);
        $this->assertSame([], $delta['historico_alteradas']);
        $this->assertSame($full['historico_em_vigor'], $delta['historico_em_vigor'], 'the decisions in force still go whole');
        $this->assertSame($full['historico'], self::merge($before['historico'], $delta));
    }

    /**
     * SITUATION: the screen holds the history; then a reviewer undoes the
     * OLDEST decision.
     *
     * EXPECTED: the undo travels, and so does the new state of the row it
     * undid: no longer in force, no longer undoable. Merged, the screen has
     * exactly what the whole history says.
     */
    public function testAnUndoOfAnOldDecisionSendsTheNewStateOfThatRow(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entry = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(60));
        Clock::freezeAt($this->at(3600));
        $void = $this->store(Decision::anular(0, $entry));
        $newest = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300)));
        $before = $this->freshScreen();

        $undo = $this->store(Decision::desfazer(0, $void));
        $full = $this->freshScreen();
        $delta = StateSnapshot::withHistoryAfter($full, $newest, $before['historico_epoca']);

        $this->assertSame([$undo], array_column($delta['historico'], 'id'));
        $this->assertSame([[$void, false, false, self::CAR_B]], $delta['historico_alteradas']);
        $this->assertSame($full['historico'], self::merge($before['historico'], $delta));
    }

    /**
     * SITUATION: car B's entry was confirmed long ago. Later a reviewer
     * moves it to car A; later still that move is undone.
     *
     * EXPECTED: after the move, the old confirmation counts for car A (the
     * filter "Carro A" must find it) and the answer says so. After the
     * undo, the old confirmation is back to car B as typed: nothing is
     * listed for it, and the merge puts it back all the same.
     */
    public function testAReassignmentChangesTheCarOfOlderRowsAndItsUndoPutsItBack(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entry = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(60));
        Clock::freezeAt($this->at(3600));
        $confirmation = $this->store(Decision::confirmar(0, $entry));
        $first = $this->freshScreen();

        $move = $this->store(Decision::reatribuir(0, $entry, self::CAR_A));
        $afterMove = $this->freshScreen();
        $delta = StateSnapshot::withHistoryAfter($afterMove, $confirmation, $first['historico_epoca']);
        $this->assertSame([[$confirmation, true, true, self::CAR_A]], $delta['historico_alteradas']);
        $this->assertSame($afterMove['historico'], self::merge($first['historico'], $delta));

        $this->store(Decision::desfazer(0, $move));
        $afterUndo = $this->freshScreen();
        $delta = StateSnapshot::withHistoryAfter($afterUndo, $move, $afterMove['historico_epoca']);
        // The move itself is undone; the confirmation is as typed again: not listed.
        $this->assertSame([[$move, false, false, self::CAR_B]], $delta['historico_alteradas']);
        $merged = self::merge($afterMove['historico'], $delta);
        $this->assertSame($afterUndo['historico'], $merged);
        $this->assertSame(self::CAR_B, array_column($merged, null, 'id')[$confirmation]['carro']);
    }

    /**
     * SITUATION: a counter is renamed after a decision about its entry.
     *
     * EXPECTED: the older rows' source names travel with the difference.
     */
    public function testARenamedCounterReachesTheOlderRows(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entry = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(60));
        Clock::freezeAt($this->at(3600));
        $void = $this->store(Decision::anular(0, $entry));
        $before = $this->freshScreen();

        $this->fontes[0]->setNome('Contagem renomeada')->save();
        $full = $this->freshScreen();
        $delta = StateSnapshot::withHistoryAfter($full, $void, $before['historico_epoca']);

        $this->assertSame('Contagem renomeada', $delta['historico_fontes']->{(string) $this->fontes[0]->getFonteId()});
        $this->assertSame($full['historico'], self::merge($before['historico'], $delta));
    }

    /**
     * SITUATION: the screen holds a rehearsal's history. Then "Zerar
     * corrida" deletes it, and the real race gets decisions of its own.
     *
     * EXPECTED: the prova has a new epoch, and a screen that sends the old
     * one gets the whole history, not a difference against decisions that
     * no longer exist. So does a screen that names a decision newer than
     * any (the ids went backwards) or one the history does not have.
     */
    public function testAfterAResetOrAnUnknownDecisionTheWholeHistoryIsSent(): void
    {
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(3600));
        $rehearsal = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300)));
        $before = $this->freshScreen();

        $this->newRequest();
        $this->service->resetRace(self::USER_PREFIX . 'controle');
        $this->startRace(self::RACE_START_MS);
        $real = $this->store(Decision::adicionar(0, self::CAR_B, $this->at(300)));
        $full = $this->freshScreen();

        $this->assertNotSame($before['historico_epoca'], $full['historico_epoca']);
        $whole = StateSnapshot::withHistoryAfter($full, $rehearsal, $before['historico_epoca']);
        $this->assertArrayNotHasKey('historico_desde', $whole);
        $this->assertSame([$real], array_column($whole['historico'], 'id'));

        $this->assertArrayNotHasKey('historico_desde', StateSnapshot::withHistoryAfter($full, $real + 5, $full['historico_epoca']), 'ids went backwards');
        $this->assertArrayNotHasKey('historico_desde', StateSnapshot::withHistoryAfter($full, $real, $full['historico_epoca'] . 'x'));
        $this->assertSame([], StateSnapshot::withHistoryAfter($full, $real, $full['historico_epoca'])['historico'], 'nothing new');
    }

    /**
     * SITUATION: a decision the screen holds is missing from the server's
     * list although older and newer ones are there.
     *
     * EXPECTED: no difference: whatever happened, the screen's copy is not
     * the server's.
     */
    public function testADecisionTheServerDoesNotHaveGetsTheWholeHistory(): void
    {
        $rows = [['id' => 9], ['id' => 7]];
        $this->assertNull(DecisionHistory::after($rows, 8));
        $this->assertNull(DecisionHistory::after([], 8), 'nothing to send against');
    }

    /**
     * SITUATION: the poll's query string, as contagem_estado.php gets it:
     * from a screen holding the history, from one holding none (or an older
     * page that sends neither), and garbage.
     *
     * EXPECTED: only digits for the decision and a non-empty epoch ask for a
     * difference; anything else gets the whole history.
     */
    public function testThePollAsksForADifferenceOnlyWithADecisionIdAndAnEpoch(): void
    {
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(3600));
        $first = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300)));
        $epoch = $this->freshScreen()['historico_epoca'];
        // A prova never reset has an epoch all the same: an empty one makes
        // the screen ask for the whole history every time.
        $this->assertSame('0', $epoch);
        $second = $this->store(Decision::adicionar(0, self::CAR_B, $this->at(300)));
        $full = $this->freshScreen();

        $delta = StateSnapshot::withHistoryAskedFor($full, ['p' => '5', 'historico_desde' => (string) $first, 'historico_epoca' => $epoch]);
        $this->assertSame($first, $delta['historico_desde']);
        $this->assertSame([$second], array_column($delta['historico'], 'id'));

        foreach ([
            'no history held' => ['p' => '5'],
            'no epoch' => ['historico_desde' => (string) $first],
            'empty epoch' => ['historico_desde' => (string) $first, 'historico_epoca' => ''],
            'not a number' => ['historico_desde' => '-1', 'historico_epoca' => $epoch],
            'too long' => ['historico_desde' => str_repeat('9', 19), 'historico_epoca' => $epoch],
            'an array' => ['historico_desde' => [(string) $first], 'historico_epoca' => $epoch],
        ] as $why => $query) {
            $this->assertSame($full, StateSnapshot::withHistoryAskedFor($full, $query), $why);
        }
    }

    /**
     * The screen's merge (juntarHistorico in revisao-historico.js), written
     * again here so that every field of a row is checked against the whole
     * history: a field worked out from other rows and forgotten in
     * DecisionHistory::DERIVED_FIELDS makes these tests fail.
     *
     * @param array<int, array<string, mixed>> $held the history the screen holds
     * @param array<string, mixed> $delta the answer
     * @return array<int, array<string, mixed>>
     */
    private static function merge(array $held, array $delta): array
    {
        $changed = [];
        foreach ($delta['historico_alteradas'] as [$id, $active, $undoable, $car]) {
            $changed[$id] = ['ativa' => $active, 'pode_desfazer' => $undoable, 'carro' => $car];
        }
        $names = (array) $delta['historico_fontes'];
        $older = [];
        foreach ($held as $row) {
            if ($row['id'] > $delta['historico_desde'] || $row['id'] < $delta['historico_mais_antiga_id']) {
                continue;
            }
            $row = array_merge($row, DecisionHistory::untouched($row), $changed[$row['id']] ?? []);
            foreach (['fonte_id' => 'fonte_nome', 'fonte_alvo_id' => 'fonte_alvo_nome'] as $idField => $nameField) {
                if ($row[$idField] !== null && array_key_exists($row[$idField], $names)) {
                    $row[$nameField] = $names[$row[$idField]];
                }
            }
            $older[] = $row;
        }
        $merged = array_merge($delta['historico'], $older);
        self::assertCount($delta['historico_quantas'], $merged);
        return $merged;
    }

    /** Stores a decision directly, skipping the checks. */
    private function store(Decision $decision): int
    {
        return $this->service->storeDecision($decision, 'zzt_revisor', null);
    }
}
