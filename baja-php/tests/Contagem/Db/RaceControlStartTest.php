<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Review\ConfirmationNeeded;
use Baja\Contagem\Review\RaceControl;
use Baja\Contagem\Review\RaceEvents;
use Baja\Contagem\Service\Clock;
use InvalidArgumentException;

/**
 * Race control, round 3: moving the start, undoing an undo, and red flags
 * that come in pairs.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Every button of race control stores a row ("ocorrência") and no row is
 * ever edited or deleted. A row is put right by a newer row pointing at it:
 * a row of the same type CORRECTS it, a row of type "cancelamento" CANCELS
 * it. A row that was corrected or cancelled stops counting for as long as
 * the row that did it counts itself. So:
 *
 *   - cancelling a correction brings the original back;
 *   - cancelling a cancellation ("restaurar") brings the event back.
 *
 * Some actions are not refused but need a person's "yes" first. The server
 * then stores nothing and answers with a question (ConfirmationNeeded); the
 * page shows it with a button that sends the same form again plus one more
 * field. In these tests that field is simply added to the form.
 *
 * "Now" is fixed at one hour after the made-up start.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class RaceControlStartTest extends ReviewDbTestCase
{
    private const CONTROLLER = 'zzt_controle';

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(3600));
    }

    /**
     * SITUATION: the race started at 14:00. The controller wants to record
     * that, and in the time field picks tomorrow's date by mistake (or 02
     * for 14 on a 12-hour picker that was set to the afternoon).
     *
     * WHAT WENT WRONG: the start was stored in the future. Every entry the
     * counters typed from then on was "before the start": no car had a
     * single pass, the race clock stood at zero, and nothing said why.
     *
     * EXPECTED: a start later than now is refused with a sentence that says
     * so. A start one minute ago is fine.
     */
    public function testAStartInTheFutureIsRefused(): void
    {
        $refusal = $this->refusalOf(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(3600 + 7200)]);

        $this->assertStringContainsString('está no futuro', $refusal);
        $this->assertNull($this->startMs());

        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(3540)]);
        $this->assertSame($this->at(3540), $this->startMs());
    }

    /**
     * SITUATION: the start is recorded at 0:00. Ten seconds later the pack
     * crosses the counting line: car A is typed by all three counters, car
     * B by two. A controller then "corrects" the start to 0:00:30, thirty
     * seconds late, perhaps reading it off a wrong watch.
     *
     * WHAT WENT WRONG: the correction was accepted in silence. All five
     * entries were now before the start. Under the pass-zero rule each of
     * those cars lost a lap, the standings reordered, and no flag appeared.
     *
     * EXPECTED: nothing is stored and the controller is told what the new
     * time would do: 5 entries of 2 cars would be left before the start.
     * Only with the confirmation is the start moved. A correction that
     * leaves no entry behind (to 0:00:05) asks nothing.
     */
    public function testMovingTheStartPastEntriesSaysHowManyAndNeedsConfirmation(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->allCountersSee(self::CAR_A, 10);
        $this->recordCountedPass(self::CAR_B, $this->at(12));
        $start = $this->eventOfType(Ocorrencia::LARGADA);
        $correction = ['acao' => 'corrigir', 'ocorrencia_id' => $start->id, 'horario' => $this->formTime(30)];

        $question = $this->questionOf($correction);

        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_REGISTROS, $question->campo);
        $this->assertStringContainsString('5 registros de 2 carros', $question->getMessage());
        $this->assertSame(self::RACE_START_MS, $this->startMs(), 'the start was not moved');

        $this->press($correction + [RaceControl::CAMPO_CONFIRMAR_REGISTROS => '1']);
        $this->assertSame($this->at(30), $this->startMs());

        // Back by five seconds: an earlier start leaves nothing behind.
        $moved = $this->eventOfType(Ocorrencia::LARGADA);
        $this->press(['acao' => 'corrigir', 'ocorrencia_id' => $moved->id, 'horario' => $this->formTime(5)]);
        $this->assertSame($this->at(5), $this->startMs());
    }

    /**
     * SITUATION: the same race, forty minutes in. Somebody presses "Largar
     * agora" on a second computer and clicks through the browser's
     * question without reading it.
     *
     * EXPECTED: the server's own answer carries the numbers too, so a page
     * that never asked (opened before the start) cannot move the start
     * past forty minutes of entries by a single click.
     */
    public function testLargarAgoraOnARunningRaceSaysWhatItWouldLeaveBehind(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->allCountersSee(self::CAR_A, 10);

        $question = $this->questionOf(['acao' => 'largar_agora']);
        $this->assertStringContainsString('já tem largada', $question->getMessage());
        $this->assertStringContainsString('3 registros de 1 carro ', $question->getMessage());

        // "Yes, replace" alone is not enough while entries would be left behind.
        $question = $this->questionOf(['acao' => 'largar_agora', RaceControl::CAMPO_CONFIRMAR => '1']);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_REGISTROS, $question->campo);
        $this->assertSame(self::RACE_START_MS, $this->startMs());
    }

    /**
     * SITUATION: the start (row 1) is recorded at 0:00. A controller
     * corrects it to 0:05 (row 2), then sees that the correction was the
     * mistake and cancels row 2.
     *
     * WHAT WENT WRONG: cancelling the correction removed the EVENT. Row 1
     * stayed "corrected", row 2 was cancelled, the race had no start at
     * all, and every car's count went to zero.
     *
     * EXPECTED: with the correction gone, the row it corrected counts
     * again: the start is back at 0:00, on the page and in the engine.
     */
    public function testCancellingACorrectionBringsTheOriginalTimeBack(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $original = $this->eventOfType(Ocorrencia::LARGADA);
        $this->press(['acao' => 'corrigir', 'ocorrencia_id' => $original->id, 'horario' => $this->formTime(300)]);
        $correction = $this->eventOfType(Ocorrencia::LARGADA);
        $this->assertSame($this->at(300), $this->startMs());

        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $correction->id]);

        $this->assertSame(self::RACE_START_MS, $this->startMs(), 'the page shows the original start');
        $this->assertSame(self::RACE_START_MS, $this->engineStartMs(), 'and the engine counts from it');
        $situations = $this->situations();
        $this->assertSame(RaceEvents::EM_VIGOR, $situations[$original->id]);
        $this->assertSame(RaceEvents::CANCELADA, $situations[$correction->id]);
    }

    /**
     * SITUATION: the finish is recorded, then cancelled by mistake (the
     * controller meant to cancel a red flag in the row above).
     *
     * WHAT WENT WRONG: a cancelled row had no button. The finish had to be
     * recorded again by hand, with a time typed from memory.
     *
     * EXPECTED: a cancelled row offers "restaurar". It stores a
     * cancellation of the cancellation, and the finish is in force again
     * with the time it had. A row that is not cancelled cannot be restored.
     */
    public function testACancelledEventCanBeRestored(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->press(['acao' => 'registrar', 'tipo' => 'encerramento', 'horario' => $this->formTime(1800), 'confirmar' => '1']);
        $finish = $this->eventOfType(Ocorrencia::ENCERRAMENTO);
        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $finish->id]);
        $this->assertNull($this->timeline()->encerramentoMs);
        $this->assertSame(RaceEvents::CANCELADA, $this->situations()[$finish->id]);

        $this->press(['acao' => 'restaurar', 'ocorrencia_id' => $finish->id]);

        $this->assertSame($this->at(1800), $this->timeline()->encerramentoMs);
        $this->assertSame($this->at(1800), $this->engineTimeline()->encerramentoMs);
        $this->assertSame(RaceEvents::EM_VIGOR, $this->situations()[$finish->id]);
        $this->assertStringContainsString('já foi corrigida', $this->refusalOf(['acao' => 'restaurar', 'ocorrencia_id' => $finish->id]));
    }

    /**
     * SITUATION: the race has one start and cars are running. A controller
     * who wants to change the start time presses "Cancelar" on it instead
     * of "Corrigir".
     *
     * WHAT WENT WRONG: the generic question ("Cancelar esta ocorrência?")
     * said nothing about what cancelling the only start does: the race has
     * no start, so every car's count is zero and the published results are
     * wiped until somebody notices.
     *
     * EXPECTED: cancelling the only start needs its own confirmation, and
     * the question says what happens and points to "Corrigir". Cancelling a
     * second start (there is another one to fall back on) asks nothing more.
     */
    public function testCancellingTheOnlyStartNeedsItsOwnConfirmation(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $start = $this->eventOfType(Ocorrencia::LARGADA);

        $question = $this->questionOf(['acao' => 'cancelar', 'ocorrencia_id' => $start->id]);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_SEM_LARGADA, $question->campo);
        $this->assertStringContainsString('única largada', $question->getMessage());
        $this->assertStringContainsString('Corrigir', $question->getMessage());
        $this->assertSame(self::RACE_START_MS, $this->startMs());

        // A second start on top: cancelling THAT one falls back on the first.
        $this->press(['acao' => 'largar_agora', 'confirmar' => '1']);
        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $this->eventOfType(Ocorrencia::LARGADA)->id]);
        $this->assertSame(self::RACE_START_MS, $this->startMs());

        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $start->id, RaceControl::CAMPO_CONFIRMAR_SEM_LARGADA => '1']);
        $this->assertNull($this->startMs());
    }

    /**
     * SITUATION: a red flag at 0:10 and the restart at 0:20. Then:
     *   (a) "Relargada" is pressed again at 0:30, with the race running;
     *   (b) a second red flag at 0:40 is fine, and a THIRD at 0:45, with
     *       the race already stopped, is a double press;
     *   (c) somebody tries to cancel the first red flag while its restart
     *       stands.
     *
     * WHAT WENT WRONG: all three were stored. The engine paired the events
     * up as best it could, and which stretch of time the lap times skipped
     * depended on the order of the rows.
     *
     * EXPECTED: red flags and restarts come in pairs, in that order. Each
     * of the three is refused with a sentence that says what is missing.
     */
    public function testRedFlagsAndRestartsMustComeInPairs(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->press(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(600)]);
        $firstRedFlag = $this->eventOfType(Ocorrencia::BANDEIRA_VERMELHA);
        $this->press(['acao' => 'registrar', 'tipo' => 'relargada', 'horario' => $this->formTime(1200)]);

        $refusal = $this->refusalOf(['acao' => 'registrar', 'tipo' => 'relargada', 'horario' => $this->formTime(1800)]);
        $this->assertStringContainsString('sem bandeira vermelha antes', $refusal);

        $this->press(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(2400)]);
        $refusal = $this->refusalOf(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(2700)]);
        $this->assertStringContainsString('duas bandeiras vermelhas seguidas', $refusal);

        $refusal = $this->refusalOf(['acao' => 'cancelar', 'ocorrencia_id' => $firstRedFlag->id]);
        $this->assertStringContainsString('sem bandeira vermelha antes', $refusal);

        $this->assertSame([[$this->at(600), $this->at(1200)], [$this->at(2400), null]], $this->engineTimeline()->redFlagIntervals);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $form the fields of the form */
    private function press(array $form): void
    {
        $this->newRequest();
        RaceControl::handle($this->service, $form, self::CONTROLLER, false);
    }

    /** Submits a form that is expected to be refused outright and returns the sentence shown. */
    private function refusalOf(array $form): string
    {
        try {
            $this->press($form);
        } catch (ConfirmationNeeded $question) {
            $this->fail('the action only asked for confirmation, but it should have been refused: ' . $question->getMessage());
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }
        $this->fail('the action was accepted, but it should have been refused');
    }

    /** Submits a form that is expected to be answered with a question. */
    private function questionOf(array $form): ConfirmationNeeded
    {
        try {
            $this->press($form);
        } catch (ConfirmationNeeded $question) {
            return $question;
        }
        $this->fail('the action was carried out without asking');
    }

    /** A moment so many seconds after the start, written as a browser's date-and-time field sends it. */
    private function formTime(int $secondsAfterStart): string
    {
        return date('Y-m-d\TH:i:s', intdiv($this->at($secondsAfterStart), 1000));
    }

    /** @return Ocorrencia[] every stored race-control row */
    private function rows(): array
    {
        $this->newRequest();
        return RaceEvents::toOcorrencias(RaceControl::raceEvents($this->prova));
    }

    /** The race as the race control page shows it. */
    private function timeline(): \Baja\Contagem\Engine\RaceTimeline
    {
        $this->newRequest();
        return RaceControl::timelineOf(RaceControl::raceEvents($this->prova));
    }

    /** The race as the engine counts it (pit events and all). */
    private function engineTimeline(): \Baja\Contagem\Engine\RaceTimeline
    {
        $this->newRequest();
        return $this->service->compute()->timeline;
    }

    private function startMs(): ?int
    {
        return $this->timeline()->largadaMs;
    }

    private function engineStartMs(): ?int
    {
        return $this->engineTimeline()->largadaMs;
    }

    /** @return array<int, string> row id => what became of it */
    private function situations(): array
    {
        return RaceEvents::situations($this->rows());
    }

    /** @return Ocorrencia[] */
    private function eventsInForce(): array
    {
        return RaceEvents::inForce($this->rows());
    }

    private function eventOfType(string $tipo): Ocorrencia
    {
        $found = null;
        foreach ($this->eventsInForce() as $event) {
            if ($event->tipo === $tipo) {
                // Of two starts in force the newest row is the one that counts.
                $found = $event;
            }
        }
        return $found ?? $this->fail('no event of type ' . $tipo . ' is in force');
    }
}
