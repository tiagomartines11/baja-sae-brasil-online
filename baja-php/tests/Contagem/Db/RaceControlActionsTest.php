<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Review\RaceControl;
use Baja\Contagem\Review\RaceEvents;
use Baja\Contagem\Service\Clock;
use InvalidArgumentException;

/**
 * The race control page: "Largar agora", red flag, restart, "Encerrar",
 * correcting or cancelling any of them, and "Zerar corrida".
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Each button stores a row, an "ocorrência". Rows are never edited or
 * deleted. A wrong one is put right by a NEW row that points at it:
 *
 *   - a correction: a row of the same type with another time;
 *   - a cancellation: a row of type "cancelamento". The row it points at
 *     stops counting, as if the button had never been pressed.
 *
 * These tests press the buttons the way the page does: by calling
 * RaceControl::handle() with the fields of the form that was submitted.
 * A refused action throws InvalidArgumentException with the sentence the
 * page shows to the controller.
 *
 * "Now" is fixed at one hour after the made-up start, so the tests do not
 * depend on when they run.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class RaceControlActionsTest extends ReviewDbTestCase
{
    private const CONTROLLER = 'zzt_controle';

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(3600));
    }

    /**
     * SITUATION: during the warm-up somebody leans on the keyboard and
     * "Largar agora" is pressed. The race has not started.
     *
     * WHAT WENT WRONG: there was no way back. A start could be moved to
     * another time but never removed, so the race clock ran, cars were
     * shown as late, and the results page showed a race in progress.
     *
     * EXPECTED: every race event has a "Cancelar" button. Cancelling the
     * start stores a "cancelamento" row pointing at it, the race is back
     * to "aguardando", and the start time kept for the results pages
     * (prova.tempo) is cleared. Nothing was deleted: both rows are there.
     * (Cancelling the ONLY start asks for a confirmation of its own, which
     * the page sends along; see RaceControlStartTest.)
     */
    public function testAStartPressedByMistakeCanBeCancelled(): void
    {
        $this->press(['acao' => 'largar_agora']);
        $start = $this->eventsInForce()[0];
        $this->assertSame(RaceTimeline::CORRENDO, $this->raceState());

        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $start->id, 'confirmar_sem_largada' => '1']);

        $this->assertSame(RaceTimeline::AGUARDANDO, $this->raceState());
        $this->assertSame([], $this->eventsInForce());
        $this->assertNull($this->startTimeOnResultsPages());
        $this->assertSame(2, $this->countRows('contagem_ocorrencia', 'evento_id = ? AND prova_id = ?', [self::EVENTO_ID, self::PROVA_ID]));
        $this->assertSame(1, $this->countRows('contagem_ocorrencia', 'evento_id = ? AND prova_id = ? AND tipo = ? AND substitui_id = ?', [self::EVENTO_ID, self::PROVA_ID, Ocorrencia::CANCELAMENTO, $start->id]));
    }

    /**
     * SITUATION: the race started at the made-up start time. Forty minutes
     * later a controller opens the page on a second computer and presses
     * "Largar agora" by accident; or two controllers press it at the start,
     * a second apart.
     *
     * WHAT WENT WRONG: the second press silently moved the start to "now".
     * Forty minutes of laps were suddenly "before the start" and dropped.
     *
     * EXPECTED: when a start exists, "Largar agora" is refused unless the
     * request says the person was asked and confirmed. (The page asks
     * before sending; this protects against a page that was opened before
     * the race started and therefore did not ask.) With the confirmation
     * it is accepted, and cancelling that second start puts the FIRST one
     * back, so even a confirmed mistake can be undone.
     */
    public function testASecondStartNeedsConfirmationAndCanBeUndone(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);

        $refusal = $this->refusalOf(['acao' => 'largar_agora']);
        $this->assertStringContainsString('já tem largada', $refusal);
        $this->assertSame(self::RACE_START_MS, $this->timeline()->largadaMs, 'the start was not moved');

        $this->press(['acao' => 'largar_agora', 'confirmar' => '1']);
        $this->assertSame($this->at(3600), $this->timeline()->largadaMs);
        $secondStart = $this->eventsInForce()[0];

        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $secondStart->id]);
        $this->assertSame(self::RACE_START_MS, $this->timeline()->largadaMs, 'the first start counts again');
        $this->assertSame(intdiv(self::RACE_START_MS, 1000), $this->startTimeOnResultsPages());
    }

    /**
     * SITUATION: the race is running. "Encerrar corrida" is sent without
     * the confirmation (a stray request, or a page that did not ask).
     * Then it is pressed properly, and it turns out to have been too early.
     *
     * EXPECTED: without confirmation the finish is refused and the race
     * goes on. With it the race is "encerrada". Cancelling the finish puts
     * the race back to "correndo".
     */
    public function testTheFinishNeedsConfirmationAndCanBeCancelled(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);

        $refusal = $this->refusalOf(['acao' => 'registrar', 'tipo' => 'encerramento']);
        $this->assertStringContainsString('Confirme o encerramento', $refusal);
        $this->assertSame(RaceTimeline::CORRENDO, $this->raceState());

        $this->press(['acao' => 'registrar', 'tipo' => 'encerramento', 'confirmar' => '1']);
        $this->assertSame(RaceTimeline::ENCERRADA, $this->raceState());

        $finish = $this->eventOfType(Ocorrencia::ENCERRAMENTO);
        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $finish->id]);
        $this->assertSame(RaceTimeline::CORRENDO, $this->raceState());
    }

    /**
     * SITUATION: the race started at 0:00 and was stopped by a red flag at
     * 0:10. A controller wants to correct the start time and types 0:15
     * (a slip: they meant 0:00:15).
     *
     * WHAT WENT WRONG: the start was moved to after the red flag. A race
     * that is stopped before it starts makes no sense, and the race clock
     * and the lap times of every car went wrong.
     *
     * EXPECTED: refused, with a sentence that names the red flag. A
     * correction to a time before the red flag (0:05) is accepted. And the
     * start cannot be cancelled either while the red flag stands: that
     * would leave a stoppage in a race that never started.
     */
    public function testTheStartCannotBeMovedPastARedFlag(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->press(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(600)]);
        $start = $this->eventOfType(Ocorrencia::LARGADA);

        $refusal = $this->refusalOf(['acao' => 'corrigir', 'ocorrencia_id' => $start->id, 'horario' => $this->formTime(900)]);
        $this->assertStringContainsString('bandeira vermelha', $refusal);
        $this->assertSame(self::RACE_START_MS, $this->timeline()->largadaMs);

        $refusal = $this->refusalOf(['acao' => 'cancelar', 'ocorrencia_id' => $start->id]);
        $this->assertStringContainsString('sem largada', $refusal);

        $this->press(['acao' => 'corrigir', 'ocorrencia_id' => $start->id, 'horario' => $this->formTime(300)]);
        $this->assertSame($this->at(300), $this->timeline()->largadaMs);
    }

    /**
     * SITUATION: a controller corrects the start time. Their colleague's
     * page, loaded before that, still shows the OLD start row with its
     * "Corrigir" and "Cancelar" buttons, and the colleague presses them.
     *
     * WHAT WENT WRONG: the old row could be corrected again. Two rows then
     * claimed to replace the same start, and which one won depended on the
     * order they were stored in.
     *
     * EXPECTED: a row that was already corrected or cancelled can be
     * neither corrected nor cancelled. The page marks such rows and offers
     * no buttons on them; the server refuses if asked anyway.
     */
    public function testARowThatWasAlreadyCorrectedOffersNothingMore(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $oldStart = $this->eventOfType(Ocorrencia::LARGADA);
        $this->press(['acao' => 'corrigir', 'ocorrencia_id' => $oldStart->id, 'horario' => $this->formTime(30)]);

        $situations = RaceEvents::situations(RaceEvents::toOcorrencias(RaceControl::raceEvents($this->prova)));
        $this->assertSame(RaceEvents::CORRIGIDA, $situations[$oldStart->id]);

        $this->assertStringContainsString('já foi corrigida', $this->refusalOf(['acao' => 'corrigir', 'ocorrencia_id' => $oldStart->id, 'horario' => $this->formTime(60)]));
        $this->assertStringContainsString('já foi corrigida', $this->refusalOf(['acao' => 'cancelar', 'ocorrencia_id' => $oldStart->id]));
        $this->assertSame($this->at(30), $this->timeline()->largadaMs);
    }

    /**
     * SITUATION: the real race is under way: it was started and the
     * counters have recorded passes. The controller of the prova, who is
     * not an administrator, opens race control to fix something, scrolls
     * too far and uses "Zerar corrida", typing the prova's code as asked.
     *
     * WHAT WENT WRONG: that wiped the race. Any controller could delete
     * the only record of a race in progress.
     *
     * EXPECTED: once a race has had a start, only an administrator may
     * wipe it. The controller is refused and every entry is still there.
     * An administrator can do it; and then nothing is lost for good,
     * because every deleted row was first copied to the archive table.
     */
    public function testOnlyAnAdministratorMayWipeARaceThatWasStarted(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->allCountersSee(self::CAR_A, 60);
        $wipe = ['acao' => 'zerar', 'confirmacao' => self::PROVA_ID];

        $refusal = $this->refusalOf($wipe, false);
        $this->assertStringContainsString('só um administrador', $refusal);
        $this->assertSame(3, $this->countEntries(), 'nothing was deleted');

        $this->press($wipe, true);
        $this->assertSame(0, $this->countEntries());
        $this->assertSame(3, $this->countRows('contagem_arquivo', 'evento_id = ? AND prova_id = ? AND tabela = ?', [self::EVENTO_ID, self::PROVA_ID, 'contagem_passagem']));
    }

    /**
     * SITUATION: the day before the race the counters rehearse: they open
     * the count page and type a few numbers. The race is never started.
     * The controller then clears the rehearsal.
     *
     * EXPECTED: allowed without an administrator. A race that never had a
     * start cannot be the real one. (A wrong confirmation code is still
     * refused: nobody wipes anything by a stray click.)
     */
    public function testAControllerMayWipeARehearsalThatWasNeverStarted(): void
    {
        $this->allCountersSee(self::CAR_A, 60);

        $this->assertStringContainsString('Confirmação incorreta', $this->refusalOf(['acao' => 'zerar', 'confirmacao' => 'XXX'], false));
        $this->assertSame(3, $this->countEntries());

        $this->press(['acao' => 'zerar', 'confirmacao' => self::PROVA_ID], false);
        $this->assertSame(0, $this->countEntries());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Submits one form of the race control page.
     *
     * @param array<string, mixed> $form the fields of the form
     */
    private function press(array $form, bool $isAdmin = false): void
    {
        $this->newRequest();
        RaceControl::handle($this->service, $form, self::CONTROLLER, $isAdmin);
    }

    /**
     * Submits a form that is expected to be refused and returns the
     * sentence the page would show.
     *
     * @param array<string, mixed> $form
     */
    private function refusalOf(array $form, bool $isAdmin = false): string
    {
        try {
            $this->press($form, $isAdmin);
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }
        $this->fail('the action was accepted, but it should have been refused');
    }

    /** A moment so many seconds after the start, written as a browser's date-and-time field sends it. */
    private function formTime(int $secondsAfterStart): string
    {
        return date('Y-m-d\TH:i:s', intdiv($this->at($secondsAfterStart), 1000));
    }

    private function timeline(): RaceTimeline
    {
        $this->newRequest();
        return RaceControl::timelineOf(RaceControl::raceEvents($this->prova));
    }

    private function raceState(): string
    {
        return $this->timeline()->stateAt(Clock::nowMs());
    }

    /** @return Ocorrencia[] the race events that count right now */
    private function eventsInForce(): array
    {
        $this->newRequest();
        return RaceEvents::inForce(RaceEvents::toOcorrencias(RaceControl::raceEvents($this->prova)));
    }

    private function eventOfType(string $tipo): Ocorrencia
    {
        foreach ($this->eventsInForce() as $event) {
            if ($event->tipo === $tipo) {
                return $event;
            }
        }
        $this->fail('no event of type ' . $tipo . ' is in force');
    }

    /** The start time the public results pages use (prova.tempo, in seconds), or null. */
    private function startTimeOnResultsPages(): ?int
    {
        $this->newRequest();
        $tempo = $this->prova->getTempo();
        return $tempo === null ? null : (int) $tempo;
    }

    private function countEntries(): int
    {
        return $this->countRows(
            'contagem_passagem',
            'fonte_id IN (SELECT fonte_id FROM contagem_fonte WHERE evento_id = ? AND prova_id = ?)',
            [self::EVENTO_ID, self::PROVA_ID]
        );
    }
}
