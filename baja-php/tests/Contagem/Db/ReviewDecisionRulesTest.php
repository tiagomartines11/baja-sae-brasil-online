<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\DecisionValidator;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemDecisaoQuery;

/**
 * Decisions the server must refuse, and decisions it must understand.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A reviewer's correction is never an edit: it is stored as a new record
 * (a "decisão") next to what the counters typed, and undoing it stores yet
 * another record. That makes everything traceable, but it has a catch:
 * decisions PILE UP. Two "void this entry" records on one entry look like
 * one on the screen, and undoing one of them changes nothing, because the
 * other is still there. Each test below is about one way such a pile, or a
 * decision about the wrong thing, used to be possible.
 *
 * In every test "now" is fixed with Clock::freezeAt() so that the test
 * gives the same result whenever it runs.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewDecisionRulesTest extends ReviewDbTestCase
{
    /**
     * SITUATION: the reviewer's screen shows entry X (car A, counter 1)
     * and sends its fingerprint. But the decision in the same request says
     * "void entry Y" (car B), an entry the request does not list as watched.
     *
     * WHAT WENT WRONG: the server checked only that the WATCHED entries
     * were unchanged. Entry Y was voided although nothing in the request
     * showed that the reviewer had seen it in its current state. A stale
     * or buggy screen could void any entry of the race that way.
     *
     * EXPECTED: refused as a bad request (HTTP 400). Nothing is stored.
     */
    public function testADecisionAboutAnEntryThatWasNotBeingWatchedIsRefused(): void
    {
        $this->startRace(self::RACE_START_MS);
        $watched = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        $notWatched = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(60));
        Clock::freezeAt($this->at(180));
        $this->recompute();

        $answer = $this->decide(
            ['tipo' => 'anular', 'passagem_id' => $notWatched],
            [$watched],
            $this->currentSignatureOf([$watched])
        );

        $this->assertSame(400, $answer['status']);
        $this->assertStringContainsString('não está entre as passagens observadas', $answer['body']['mensagem']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: Ana voids an entry. Beto, on another computer, voids the
     * same entry. His screen was loaded after hers, so the fingerprint he
     * sends is up to date and the "did it change?" check passes.
     *
     * WHAT WENT WRONG: two "void" records were stored for one entry. Later
     * somebody pressed "Desfazer" on the void, the screen said it was
     * undone, and the entry stayed voided: one undo removes one record.
     *
     * EXPECTED: Beto's void is refused with HTTP 409 and he is told Ana
     * already decided. One record exists. One undo then really restores
     * the entry.
     */
    public function testTheSameVoidCannotBeStoredTwiceSoOneUndoIsEnough(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entryId = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        Clock::freezeAt($this->at(180));
        $this->recompute();

        $ana = $this->decideAboutEntry(['tipo' => 'anular', 'passagem_id' => $entryId], $entryId, 'zzt_ana');
        $this->assertSame(200, $ana['status']);

        // Beto's request carries the fingerprint the entry has NOW, after Ana's void.
        $beto = $this->decideAboutEntry(['tipo' => 'anular', 'passagem_id' => $entryId], $entryId, 'zzt_beto');

        $this->assertSame(409, $beto['status']);
        $this->assertSame(DecisionValidator::MOTIVO_JA_DECIDIDO, $beto['body']['motivo']);
        $this->assertSame('zzt_ana', $beto['body']['por']);
        $this->assertSame(1, $this->countDecisions());

        $undo = $this->decide(['tipo' => 'desfazer', 'desfaz_decisao_id' => $ana['body']['decisao_id']]);
        $this->assertSame(200, $undo['status']);
        $this->assertFalse($this->entryOnTimeline(self::CAR_A, $entryId)['anulada'], 'one undo restores the entry');
    }

    /**
     * SITUATION: the same, with "confirmar" (the reviewer says "this pass
     * is real, count it") instead of "anular".
     *
     * EXPECTED: the second identical confirmation is refused too.
     */
    public function testTheSameConfirmationCannotBeStoredTwice(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entryId = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        Clock::freezeAt($this->at(180));
        $this->recompute();

        $this->assertSame(200, $this->decideAboutEntry(['tipo' => 'confirmar', 'passagem_id' => $entryId], $entryId, 'zzt_ana')['status']);
        $second = $this->decideAboutEntry(['tipo' => 'confirmar', 'passagem_id' => $entryId], $entryId, 'zzt_beto');

        $this->assertSame(409, $second['status']);
        $this->assertSame('zzt_ana', $second['body']['por']);
        $this->assertSame(1, $this->countDecisions());
    }

    /**
     * SITUATION: all three counters missed car A at minute 5. Ana adds the
     * pass. Her browser gets no answer (the network hiccups), so she
     * presses "Adicionar" again; and Beto, who noticed the same gap, adds
     * the pass too, four seconds apart.
     *
     * WHAT WENT WRONG: every one of those requests stored a pass. Passes
     * added by a reviewer always count, so the car gained three laps for
     * one crossing.
     *
     * EXPECTED: the first is stored. Any other added pass for the same car
     * within the matching window (20 s: two entries that close are the same
     * crossing) is refused with 409, naming who added the first. A pass for
     * the same car two minutes later is a different crossing and is fine.
     */
    public function testAPassCannotBeAddedTwiceForTheSameCrossing(): void
    {
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(600));
        $this->recompute();
        $pass = ['tipo' => 'adicionar', 'equipe_numero' => self::CAR_A];

        $first = $this->decide($pass + ['ts_ms' => $this->at(300)], [], null, 'zzt_ana');
        $retry = $this->decide($pass + ['ts_ms' => $this->at(300)], [], null, 'zzt_ana');
        $beto = $this->decide($pass + ['ts_ms' => $this->at(304)], [], null, 'zzt_beto');

        $this->assertSame(200, $first['status']);
        $this->assertSame(409, $retry['status']);
        $this->assertSame(409, $beto['status']);
        $this->assertSame('zzt_ana', $beto['body']['por']);
        $this->assertSame(1, $this->countDecisions());

        $nextLap = $this->decide($pass + ['ts_ms' => $this->at(420)], [], null, 'zzt_beto');
        $this->assertSame(200, $nextLap['status'], 'two minutes later is another crossing');
    }

    /**
     * SITUATION: ten minutes into the race a reviewer adds a pass. The form
     * asks for the time of race ("0:05:00"); the reviewer types the time of
     * day instead, "14:03:00", which the screen reads as 14 hours of race.
     * Another reviewer types a time from before the start.
     *
     * WHAT WENT WRONG: both were stored. The first gave the car a lap dated
     * 14 hours in the future, which then was the car's "last pass" on every
     * screen.
     *
     * EXPECTED: an added pass must lie between the start and now. Both are
     * refused as bad requests (HTTP 400) with a sentence that says why.
     */
    public function testAnAddedPassMustLieBetweenTheStartAndNow(): void
    {
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(600));
        $this->recompute();
        $pass = ['tipo' => 'adicionar', 'equipe_numero' => self::CAR_A];

        $inTheFuture = $this->decide($pass + ['ts_ms' => $this->at(14 * 3600 + 3 * 60)]);
        $beforeTheStart = $this->decide($pass + ['ts_ms' => self::RACE_START_MS - 60 * 1000]);

        $this->assertSame(400, $inTheFuture['status']);
        $this->assertStringContainsString('futuro', $inTheFuture['body']['mensagem']);
        $this->assertSame(400, $beforeTheStart['status']);
        $this->assertStringContainsString('anterior à largada', $beforeTheStart['body']['mensagem']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: the race has not been started. Somebody sends "add a pass".
     *
     * EXPECTED: refused. Without a start there is no race for the pass to
     * belong to (and no "time of race" the reviewer could have meant).
     */
    public function testAPassCannotBeAddedBeforeTheRaceHasAStart(): void
    {
        Clock::freezeAt($this->at(600));

        $answer = $this->decide(['tipo' => 'adicionar', 'equipe_numero' => self::CAR_A, 'ts_ms' => $this->at(300)]);

        $this->assertSame(400, $answer['status']);
        $this->assertStringContainsString('não tem largada', $answer['body']['mensagem']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: a reviewer added a pass for car A. It is not a row typed
     * by a counter, so on the timeline it carries a made-up id: minus the
     * id of the decision that added it (decision 7 gives entry -7). The
     * pass raises a flag, the reviewer decides it was a mistake, clicks it
     * and presses "V" (void).
     *
     * WHAT WENT WRONG: the server only accepted ids of stored entries and
     * answered "esta passagem não pertence a esta prova". The buttons A, K
     * and V simply did not work on any flag that involved an added pass.
     *
     * EXPECTED: voiding an added pass is understood as undoing the decision
     * that added it. The pass is gone from the timeline. Confirming it
     * ("K": keep it) is accepted as well, and moving it to another car is
     * refused with advice (remove it and add it on the right car).
     */
    public function testVoidingAnAddedPassUndoesTheDecisionThatAddedIt(): void
    {
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(600));
        $this->recompute();
        $added = $this->decide(['tipo' => 'adicionar', 'equipe_numero' => self::CAR_A, 'ts_ms' => $this->at(300)]);
        $additionId = $added['body']['decisao_id'];
        $addedEntryId = -$additionId;
        $this->assertTrue($this->entryOnTimeline(self::CAR_A, $addedEntryId)['adicionada_por_revisor']);

        $moved = $this->decideAboutEntry(
            ['tipo' => 'reatribuir', 'passagem_id' => $addedEntryId, 'equipe_numero' => self::CAR_B],
            $addedEntryId
        );
        $this->assertSame(400, $moved['status']);

        $kept = $this->decideAboutEntry(['tipo' => 'confirmar', 'passagem_id' => $addedEntryId], $addedEntryId);
        $this->assertSame(200, $kept['status'], '"manter" works on an added pass');

        $voided = $this->decideAboutEntry(['tipo' => 'anular', 'passagem_id' => $addedEntryId], $addedEntryId);
        $this->assertSame(200, $voided['status']);

        $stored = ContagemDecisaoQuery::create()->findPk($voided['body']['decisao_id']);
        $this->assertSame('desfazer', $stored->getTipo(), 'the void was stored as an undo');
        $this->assertSame($additionId, (int) $stored->getDesfazDecisaoId());

        $this->newRequest();
        $timeline = TimelineWindow::forCar($this->service, self::CAR_A);
        foreach ($timeline['raias'] as $lane) {
            $this->assertNotContains($addedEntryId, array_column($lane['entradas'], 'id'), 'the added pass is gone');
        }
    }

    /**
     * SITUATION: counter 2's phone falls into the mud two minutes into the
     * race and will not come back. From then on that source would be
     * charged with a miss for every pass, and passes seen by one other
     * counter would wait for it for ever. The reviewer excludes the source
     * "from 2:00 until further notice", because nobody knows yet whether a
     * replacement phone will turn up.
     *
     * WHAT WENT WRONG: an exclusion needed an end time. Reviewers typed a
     * far-away end, forgot it, and a replacement counter's entries were
     * silently ignored for the rest of the race.
     *
     * EXPECTED: an exclusion with no end is accepted. An entry of that
     * source after 2:00 is shown as out of use because the source is
     * excluded; one from before 2:00 still counts. The exclusion is listed
     * among the decisions in force (which the screen keeps in sight), and
     * undoing it brings the later entry back.
     */
    public function testASourceCanBeExcludedUntilFurtherNotice(): void
    {
        $counter2 = $this->fontes[1];
        $this->startRace(self::RACE_START_MS);
        $before = $this->addEntry($counter2, self::CAR_A, $this->at(60));
        $after = $this->addEntry($counter2, self::CAR_A, $this->at(300));
        Clock::freezeAt($this->at(600));
        $this->recompute();

        $exclusion = $this->decide([
            'tipo' => 'excluir_fonte',
            'fonte_id' => $counter2->getFonteId(),
            'ts_ms' => $this->at(120),
            'ts_fim_ms' => null,
            'motivo' => 'telefone caiu na lama',
        ]);

        $this->assertSame(200, $exclusion['status']);
        $inForce = $exclusion['body']['estado']['historico_em_vigor'];
        $this->assertCount(1, $inForce);
        $this->assertSame('excluir_fonte', $inForce[0]['tipo']);
        $this->assertNull($inForce[0]['ts_fim_ms']);
        $this->assertSame('telefone caiu na lama', $inForce[0]['motivo']);

        $this->assertFalse($this->entryOnTimeline(self::CAR_A, $before)['fora_de_uso']);
        $this->assertSame('fonte_excluida', $this->entryOnTimeline(self::CAR_A, $after)['motivo_fora_de_uso']);

        $undo = $this->decide(['tipo' => 'desfazer', 'desfaz_decisao_id' => $exclusion['body']['decisao_id']]);
        $this->assertSame(200, $undo['status']);
        $this->assertSame([], $undo['body']['estado']['historico_em_vigor']);
        $this->assertFalse($this->entryOnTimeline(self::CAR_A, $after)['fora_de_uso']);
    }

    /**
     * How one entry is shown on its car's timeline right now.
     *
     * @return array<string, mixed>
     */
    private function entryOnTimeline(int $car, int $entryId): array
    {
        $this->newRequest();
        foreach (TimelineWindow::forCar($this->service, $car)['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                if ($entry['id'] === $entryId) {
                    return $entry;
                }
            }
        }
        $this->fail('entry ' . $entryId . ' is not on the timeline of car ' . $car);
    }
}
