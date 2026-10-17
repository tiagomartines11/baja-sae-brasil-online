<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Review\DecisionHistory;
use Baja\Contagem\Service\Clock;

/**
 * The history panel of the review screen: the list of every decision, with
 * an "undo" button on the ones still in force.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Two kinds of decision are different from the rest: "excluir fonte"
 * (ignore a counter for a period) and "adicionar passagem" (a pass nobody
 * recorded). They change the count for as long as they stand, and they are
 * not attached to an entry on any timeline. The history panel is the ONLY
 * place where a reviewer can find them and undo them.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewHistoryTest extends ReviewDbTestCase
{
    /**
     * SITUATION: early in the race a reviewer excludes counter 3 "until
     * further notice" and adds a pass for car A. Then a long race follows:
     * another 120 decisions are made (here: one entry voided and restored,
     * sixty times over).
     *
     * WHAT WENT WRONG: the panel showed the newest 200 decisions and
     * nothing else. In a long race the early exclusion scrolled out of the
     * list. Counter 3 was then being ignored for the rest of the race with
     * no trace of it on the screen and no button to undo it.
     *
     * Later the panel showed the newest 50, with a button for older pages,
     * and the decisions in force in a list of their own. At 26SE the
     * reviewers asked for filters (by car, counter, person, type); a filter
     * over the pages loaded so far would quietly miss the rest.
     *
     * EXPECTED:
     *   - the state carries the WHOLE history, newest first, each decision
     *     once, and says it is complete;
     *   - the exclusion and the added pass are also in the list of
     *     decisions in force (the Fontes block and the status bar read the
     *     open exclusions from it), and they can be undone.
     */
    public function testTheStateCarriesTheWholeHistory(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entryId = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(60));
        Clock::freezeAt($this->at(3600));
        $this->recompute();

        $exclusionId = $this->store(Decision::excluirFonte(0, (int) $this->fontes[2]->getFonteId(), $this->at(120), null));
        $additionId = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300)));
        for ($round = 0; $round < 60; $round++) {
            $voidId = $this->store(Decision::anular(0, $entryId));
            $this->store(Decision::desfazer(0, $voidId));
        }
        $totalDecisions = 2 + 120;

        $state = $this->freshScreen();

        $ids = array_column($state['historico'], 'id');
        $this->assertCount($totalDecisions, $ids);
        $this->assertCount($totalDecisions, array_unique($ids), 'no decision is listed twice');
        $this->assertSame($exclusionId, $ids[$totalDecisions - 1], 'the oldest decision is last');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids, 'newest first');
        $this->assertTrue($state['historico_completo']);
        $this->assertArrayNotHasKey('historico_ha_mais', $state, 'there are no older pages any more');

        $this->assertSame([$additionId, $exclusionId], array_column($state['historico_em_vigor'], 'id'));
        foreach ($state['historico_em_vigor'] as $decision) {
            $this->assertTrue($decision['pode_desfazer']);
        }
    }

    /**
     * SITUATION: a history longer than any race has had.
     *
     * EXPECTED: only the newest decisions travel, the answer says that
     * older ones were left out, and the decisions in force are still all
     * in their own list (the screen merges them back in).
     */
    public function testPastTheLimitOnlyTheNewestTravelAndTheAnswerSaysSo(): void
    {
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(3600));
        $exclusionId = $this->store(Decision::excluirFonte(0, (int) $this->fontes[2]->getFonteId(), $this->at(120), null));
        $newest = [];
        for ($i = 0; $i < 4; $i++) {
            $newest[] = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300 + 200 * $i)));
        }
        $active = DecisionHistory::activeIdsFromDatabase($this->prova);

        $history = DecisionHistory::all($this->prova, $active, 3);
        $this->assertSame(array_reverse(array_slice($newest, 1)), array_column($history['linhas'], 'id'));
        $this->assertFalse($history['completo']);
        $this->assertContains($exclusionId, array_column(DecisionHistory::inForce($this->prova, $active), 'id'));

        $this->assertTrue(DecisionHistory::all($this->prova, $active, 5)['completo'], 'exactly the limit is complete');
    }

    /**
     * SITUATION: a reviewer confirms an entry of car B typed 100 s into
     * the race, and adds a pass for car A.
     *
     * EXPECTED: the confirmation's line carries the moment of the entry,
     * so a click on it can open car B's timeline right there. The added
     * pass has no entry: its own moment is in ts_ms.
     */
    public function testAnEntryDecisionSaysWhenTheEntryWasTyped(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entry = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(100));
        Clock::freezeAt($this->at(600));
        $confirmed = $this->store(Decision::confirmar(0, $entry));
        $added = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300)));

        $rows = array_column($this->freshScreen()['historico'], null, 'id');

        $this->assertSame($this->at(100), $rows[$confirmed]['passagem_ts_ms']);
        $this->assertNull($rows[$added]['passagem_ts_ms']);
        $this->assertSame($this->at(300), $rows[$added]['ts_ms']);
    }

    /**
     * SITUATION: a reviewer added a pass and then undid it; and excluded a
     * counter for a period that has an end.
     *
     * EXPECTED: the undone addition is NOT among the decisions in force.
     * The exclusion with an end still is: it goes on removing that
     * counter's entries of that period for as long as it stands.
     */
    public function testOnlyDecisionsStillInForceAreKeptInSight(): void
    {
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(3600));

        $additionId = $this->store(Decision::adicionar(0, self::CAR_A, $this->at(300)));
        $this->store(Decision::desfazer(0, $additionId));
        $exclusionId = $this->store(Decision::excluirFonte(0, (int) $this->fontes[2]->getFonteId(), $this->at(120), $this->at(240)));

        $state = $this->freshScreen();

        $this->assertSame([$exclusionId], array_column($state['historico_em_vigor'], 'id'));
        $additionInHistory = null;
        foreach ($state['historico'] as $decision) {
            if ($decision['id'] === $additionId) {
                $additionInHistory = $decision;
            }
        }
        $this->assertNotNull($additionInHistory, 'the undone addition is still part of the history');
        $this->assertFalse($additionInHistory['ativa']);
        $this->assertFalse($additionInHistory['pode_desfazer']);
    }

    /** Stores a decision directly, skipping the checks, to build a long history quickly. */
    private function store(Decision $decision): int
    {
        return $this->service->storeDecision($decision, 'zzt_revisor', null);
    }
}
