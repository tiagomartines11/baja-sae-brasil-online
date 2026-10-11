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
     * EXPECTED:
     *   - the state carries the newest page only (50 decisions) and says
     *     that there are more;
     *   - the exclusion and the added pass are ALWAYS listed, in their own
     *     list of decisions in force, although they are far older than the
     *     first page, and they can be undone from there;
     *   - asking for the page before the last decision shown returns the
     *     next 50, and so on until the very first decision, with nothing
     *     skipped and nothing twice.
     */
    public function testOldExclusionsAndAddedPassesStayListedAndTheRestIsPaged(): void
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

        $this->assertCount(DecisionHistory::PAGE_SIZE, $state['historico']);
        $this->assertTrue($state['historico_ha_mais']);
        $idsOnFirstPage = array_column($state['historico'], 'id');
        $this->assertNotContains($exclusionId, $idsOnFirstPage, 'the exclusion is far older than the first page');

        $this->assertSame([$additionId, $exclusionId], array_column($state['historico_em_vigor'], 'id'));
        foreach ($state['historico_em_vigor'] as $decision) {
            $this->assertTrue($decision['pode_desfazer']);
        }

        // Walk back through the older pages, as the "carregar mais" button does.
        $allIds = $idsOnFirstPage;
        $hasMore = $state['historico_ha_mais'];
        $pagesLoaded = 0;
        while ($hasMore) {
            $page = DecisionHistory::page($this->prova, DecisionHistory::activeIdsFromDatabase($this->prova), min($allIds));
            $allIds = array_merge($allIds, array_column($page['linhas'], 'id'));
            $hasMore = $page['ha_mais'];
            $pagesLoaded++;
            $this->assertLessThan(10, $pagesLoaded, 'paging must come to an end');
        }
        $this->assertCount($totalDecisions, $allIds);
        $this->assertCount($totalDecisions, array_unique($allIds), 'no decision is listed twice');
        $this->assertContains($exclusionId, $allIds);
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
