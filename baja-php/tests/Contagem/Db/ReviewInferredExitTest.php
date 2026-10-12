<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Review\InferredExits;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;

/**
 * "Marcar saída do box" and the decision that moves several entries at
 * once (round 4), through the decision endpoint and race control.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The pit lane is logged by one person at the way in and another at the way
 * out. When the one at the way out misses a car, the log says the car is
 * still inside, for the rest of the race: every pass it makes at the line
 * is flagged, its status says "no box" and its stop never ends.
 *
 * A reviewer who sees two counters record the car at the line knows it
 * left. "Marcar saída do box" stores an exit one second before that pass.
 * The exit is an ordinary race event, marked as coming from a reviewer
 * ("inferida"), and a decision row is stored with it so that the history
 * shows who did it and can undo it.
 *
 * The race: start at 0:00, car A's pass zero at 1:00, car A logged INTO the
 * pit lane at 2:00 and never out, then seen at the line at 5:00 by all
 * three counters.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewInferredExitTest extends ReviewDbTestCase
{
    use RaceControlForms;

    /** @var int[] the entries of the pass at 5:00 */
    private array $passAtTheLine = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->newRequest();
        $this->service->withLock(function (): void {
            $this->service->recordOcorrencia(Ocorrencia::BOX_ENTRADA, $this->at(120), self::CAR_A, 'zzt_box');
        });
        $this->passAtTheLine = $this->allCountersSee(self::CAR_A, 300);
        $this->syncAll($this->at(330));
        Clock::freezeAt($this->at(600));
        $this->recompute();
    }

    /**
     * SITUATION: as above. The reviewer presses "Marcar saída do box" on
     * the flag of the pass at 5:00.
     *
     * WHAT WENT WRONG (before): the flag offered only "Está correto, parar
     * de avisar". The stay never ended.
     *
     * EXPECTED: an exit is stored at 4:59 (one second before the pass),
     * attributed to the reviewer. The stay is closed: the engine has car A
     * in the pit lane from 2:00 to 4:59 and not after. The lap count does
     * not change. The timeline shows the exit as inferred, and the history
     * has a "marcar_saida" decision that can be undone.
     */
    public function testMarkingTheExitClosesTheStayAndChangesNoCount(): void
    {
        $lapsBefore = $this->carRow($this->freshScreen(), self::CAR_A)['voltas'];
        $this->assertTrue($this->engineTimeline()->isInPitAt(self::CAR_A, $this->at(500)), 'the log has the car inside');

        $answer = $this->markExit();

        $this->assertSame(200, $answer['status']);
        $timeline = $this->engineTimeline();
        $this->assertSame([[$this->at(120), $this->at(299)]], $timeline->pitIntervals[self::CAR_A]);
        $this->assertFalse($timeline->isInPitAt(self::CAR_A, $this->at(500)));
        $this->assertSame($lapsBefore, $this->carRow($answer['body']['estado'], self::CAR_A)['voltas'], 'marking an exit moves no count');

        $this->assertSame('revisor', $this->fetchValue(
            'SELECT origem FROM contagem_ocorrencia WHERE evento_id = ? AND prova_id = ? AND tipo = ? AND ts_ms = ?',
            [self::EVENTO_ID, self::PROVA_ID, Ocorrencia::BOX_SAIDA, $this->at(299)]
        ));

        // The lane of the review screen says the exit was inferred.
        $this->newRequest();
        $exitOnTheLane = null;
        foreach (TimelineWindow::forCar($this->service, self::CAR_A)['ocorrencias'] as $mark) {
            if ($mark['tipo'] === Ocorrencia::BOX_SAIDA) {
                $exitOnTheLane = $mark;
            }
        }
        $this->assertNotNull($exitOnTheLane);
        $this->assertTrue($exitOnTheLane['inferida']);
        $this->assertFalse($exitOnTheLane['sem_par']);

        // The history: who, which car, when; and it never scrolls away.
        $decision = $answer['body']['estado']['historico_em_vigor'][0];
        $this->assertSame(Decision::MARCAR_SAIDA, $decision['tipo']);
        $this->assertSame(self::CAR_A, $decision['equipe_numero']);
        $this->assertSame($this->at(299), $decision['ts_ms']);
        $this->assertSame('box', $decision['motivo']);
        $this->assertSame('zzt_revisor', $decision['usuario']);
        $this->assertTrue($decision['pode_desfazer']);
    }

    /**
     * SITUATION: the reviewer marked the exit and then learns the car was
     * in fact still in the pit lane (the pass at 5:00 was a typing mistake
     * of two counters). "Desfazer" in the history.
     *
     * EXPECTED: the exit is cancelled along with the decision, in one step:
     * the car is logged inside again. Nothing is deleted.
     */
    public function testUndoingTheDecisionTakesTheExitBack(): void
    {
        $marked = $this->markExit();

        $undone = $this->decide(['tipo' => 'desfazer', 'desfaz_decisao_id' => $marked['body']['decisao_id']]);

        $this->assertSame(200, $undone['status']);
        $this->assertSame([[$this->at(120), null]], $this->engineTimeline()->pitIntervals[self::CAR_A]);
        $this->assertSame([], $undone['body']['estado']['historico_em_vigor']);
        $this->assertSame(1, $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo = ?',
            [self::EVENTO_ID, self::PROVA_ID, Ocorrencia::BOX_SAIDA]
        ), 'the exit row is still there, cancelled');
    }

    /**
     * SITUATION: two reviewers have the same flag open and both press
     * "Marcar saída do box".
     *
     * EXPECTED: the second is answered with a conflict and the fresh state.
     * A second exit would be an event with no entry to pair with.
     */
    public function testMarkingTheSameExitTwiceIsAConflict(): void
    {
        $this->assertSame(200, $this->markExit()['status']);

        $second = $this->markExit('zzt_outro');

        $this->assertSame(409, $second['status']);
        $this->assertArrayHasKey('estado', $second['body']);
        $this->assertSame(1, $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo = ?',
            [self::EVENTO_ID, self::PROVA_ID, Ocorrencia::BOX_SAIDA]
        ));
    }

    public function testAnExitForAnAreaThatDoesNotExistOrACarThatIsNotInsideIsRefused(): void
    {
        $badArea = $this->decide(['tipo' => 'marcar_saida', 'area' => 'garagem', 'equipe_numero' => self::CAR_A, 'ts_ms' => $this->at(299)]);
        $this->assertSame(400, $badArea['status']);

        // Car B was never logged into the fueling station.
        $notInside = $this->decide(['tipo' => 'marcar_saida', 'area' => 'abastecimento', 'equipe_numero' => self::CAR_B, 'ts_ms' => $this->at(299)]);
        $this->assertSame(409, $notInside['status']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: the exit was marked at 4:59. Race control looks at the pit
     * lane camera: the car left at 4:30.
     *
     * EXPECTED: race control lists the marked exit like any event. Its
     * "corrigir" moves it to 4:30 (the history then shows the old mark
     * undone and a new one), and its "cancelar" takes it back, exactly as
     * "Desfazer" in the history does. A new time at which the car was not
     * inside (before it entered) is refused.
     */
    public function testRaceControlListsCorrectsAndCancelsAMarkedExit(): void
    {
        $this->markExit();
        $this->newRequest();
        $listed = InferredExits::inForce($this->prova);
        $this->assertCount(1, $listed);
        $this->assertSame(['box', self::CAR_A, $this->at(299)], [$listed[0]['area'], $listed[0]['carro'], $listed[0]['ts_ms']]);

        $refusal = $this->refusalOf(['acao' => 'corrigir_saida', 'decisao_id' => $listed[0]['decisao_id'], 'horario' => $this->formTime(100)]);
        $this->assertStringContainsString('não consta como dentro do box', $refusal);

        $this->press(['acao' => 'corrigir_saida', 'decisao_id' => $listed[0]['decisao_id'], 'horario' => $this->formTime(270)]);
        $this->assertSame([[$this->at(120), $this->at(270)]], $this->engineTimeline()->pitIntervals[self::CAR_A]);
        $this->newRequest();
        $corrected = InferredExits::inForce($this->prova);
        $this->assertCount(1, $corrected, 'one mark stands: the new one');
        $this->assertSame($this->at(270), $corrected[0]['ts_ms']);
        $this->assertStringContainsString('já foi corrigida', $this->refusalOf(
            ['acao' => 'cancelar_saida', 'decisao_id' => $listed[0]['decisao_id']]
        ), 'the old mark cannot be acted on again');

        $this->press(['acao' => 'cancelar_saida', 'decisao_id' => $corrected[0]['decisao_id']]);
        $this->assertSame([[$this->at(120), null]], $this->engineTimeline()->pitIntervals[self::CAR_A]);
        $this->newRequest();
        $this->assertSame([], InferredExits::inForce($this->prova));
    }

    /**
     * SITUATION: car B crosses at 10:00. Counters 1 and 2 both type 33
     * (car C) by mistake; counter 3 types 22 (car B).
     *
     * WHAT WENT WRONG: fixing it took one "reatribuir" per entry. Between
     * the two clicks car C still had half a phantom pass and car B half a
     * real one, and a reviewer interrupted after the first click left the
     * race that way.
     *
     * EXPECTED: one request, "reatribuir_grupo", moves both entries to car
     * B. Both reassignments are stored together; the three entries then
     * form one pass of car B. An entry that is not among those the
     * reviewer was looking at cannot be moved this way.
     */
    public function testMovingTwoEntriesToAnotherCarIsOneStep(): void
    {
        $wrongOne = $this->addEntry($this->fontes[0], self::CAR_C, $this->at(600));
        $wrongTwo = $this->addEntry($this->fontes[1], self::CAR_C, $this->at(601));
        $right = $this->addEntry($this->fontes[2], self::CAR_B, $this->at(602));
        $this->syncAll($this->at(630));
        Clock::freezeAt($this->at(700));
        $watched = [$wrongOne, $wrongTwo, $right];

        $unwatched = $this->decide(
            ['tipo' => 'reatribuir_grupo', 'passagem_ids' => [$wrongOne, $wrongTwo], 'equipe_numero' => self::CAR_B],
            [$right],
            $this->currentSignatureOf([$right])
        );
        $this->assertSame(400, $unwatched['status']);
        $this->assertSame(0, $this->countDecisions());

        $answer = $this->decide(
            ['tipo' => 'reatribuir_grupo', 'passagem_ids' => [$wrongOne, $wrongTwo], 'equipe_numero' => self::CAR_B],
            $watched,
            $this->currentSignatureOf($watched)
        );

        $this->assertSame(200, $answer['status']);
        $this->assertCount(2, $answer['body']['decisao_ids'], 'one reassignment per entry, stored together');
        $this->assertSame(2, $this->countDecisions());
        $this->newRequest();
        $entriesOfCarB = [];
        foreach (TimelineWindow::forCar($this->service, self::CAR_B)['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                $entriesOfCarB[$entry['id']] = $entry['grupo']['passagem_ids'] ?? null;
            }
        }
        sort($watched);
        $this->assertSame($watched, $entriesOfCarB[$right], 'the three entries are one pass of car B');
    }

    /** Presses "Marcar saída do box" on the flag of the pass at 5:00. */
    private function markExit(string $username = 'zzt_revisor'): array
    {
        return $this->decide(
            ['tipo' => 'marcar_saida', 'area' => 'box', 'equipe_numero' => self::CAR_A, 'ts_ms' => $this->at(299)],
            $this->passAtTheLine,
            $this->currentSignatureOf($this->passAtTheLine),
            $username
        );
    }
}
