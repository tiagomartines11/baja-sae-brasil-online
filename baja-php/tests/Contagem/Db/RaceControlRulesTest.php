<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Review\RaceControl;
use Baja\Contagem\Review\RaceEvents;
use Baja\Contagem\Service\Clock;

/**
 * Race control, round 4: one rule for "which rows are in force", the
 * question about entries left before the start asked in EVERY path that can
 * move the start, and a race that is over staying over.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Every button of race control stores a row and no row is ever edited. A
 * row is put right by a newer row pointing at it: a row of the same type
 * CORRECTS it (a new time), a row of type "cancelamento" CANCELS it. See
 * RaceControlStartTest for more.
 *
 * An entry typed BEFORE the start does not count. So moving the start later
 * can silently take a lap from every car, and the server must ask first,
 * with the number of entries it counted itself at that moment.
 *
 * "Now" is fixed at one hour after the made-up start.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class RaceControlRulesTest extends ReviewDbTestCase
{
    use RaceControlForms;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(3600));
    }

    /**
     * SITUATION: a red flag is recorded at 0:10. The controller corrects it
     * to 0:11, looks at the video again and corrects it once more, to 0:12.
     *
     * WHAT WENT WRONG: the page and the engine each had their own rule for
     * which rows count. By the page's rule the second correction took the
     * first one out, and the ORIGINAL row (0:10) was "back in force" beside
     * the new one. The page then saw two red flags in a row and refused the
     * second correction. A red flag or a restart could be corrected once.
     *
     * EXPECTED: one rule, the engine's. After two corrections exactly one
     * red flag is in force, at 0:12, on the page and in the engine; the two
     * older rows are shown as corrected.
     */
    public function testARedFlagCanBeCorrectedTwice(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->press(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(600)]);
        $original = $this->eventInForce(Ocorrencia::BANDEIRA_VERMELHA);

        $this->press(['acao' => 'corrigir', 'ocorrencia_id' => $original->id, 'horario' => $this->formTime(660)]);
        $first = $this->eventInForce(Ocorrencia::BANDEIRA_VERMELHA);
        $this->press(['acao' => 'corrigir', 'ocorrencia_id' => $first->id, 'horario' => $this->formTime(720)]);

        $redFlags = array_values(array_filter(
            RaceEvents::inForce($this->raceControlRows()),
            fn(Ocorrencia $row) => $row->tipo === Ocorrencia::BANDEIRA_VERMELHA
        ));
        $this->assertCount(1, $redFlags, 'one red flag, not the original beside its second correction');
        $this->assertSame($this->at(720), $redFlags[0]->tsMs);
        $this->assertSame([[$this->at(720), null]], $this->engineTimeline()->redFlagIntervals, 'the engine agrees');

        $situations = RaceEvents::situations($this->raceControlRows());
        $this->assertSame(RaceEvents::CORRIGIDA, $situations[$original->id]);
        $this->assertSame(RaceEvents::CORRIGIDA, $situations[$first->id]);

        // And cancelling the second correction brings the first one back, not the original.
        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $redFlags[0]->id]);
        $this->assertSame($this->at(660), $this->eventInForce(Ocorrencia::BANDEIRA_VERMELHA)->tsMs);
    }

    /**
     * SITUATION: the race started at 0:00 and car A's first crossing was
     * typed by all three counters at 0:10. Forty minutes in, somebody
     * presses "Largar agora" on a page that already shows the start.
     *
     * WHAT WENT WRONG: that page sent the two confirmation fields along
     * with the click ("confirmar" and "confirmar_registros"), having asked
     * in the browser with a number it had worked out when it was drawn. The
     * server's own check was skipped entirely.
     *
     * EXPECTED: the click alone is answered with a question. The second
     * question, about the entries, has the number counted by the server at
     * that moment. And the start that is finally stored is the instant of
     * the FIRST click (carried through the questions), not of the last
     * confirmation half a minute later.
     */
    public function testLargarAgoraOnARunningRaceIsAskedByTheServerAndKeepsTheInstantOfTheClick(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->allCountersSee(self::CAR_A, 10);
        Clock::freezeAt($this->at(2400));

        $first = $this->questionOf(['acao' => 'largar_agora']);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR, $first->campo);
        $this->assertSame((string) $this->at(2400), $first->campos[RaceControl::CAMPO_INSTANTE], 'the click is remembered');

        // The person reads the question and answers 20 seconds later.
        Clock::freezeAt($this->at(2420));
        $answer = ['acao' => 'largar_agora', RaceControl::CAMPO_CONFIRMAR => '1'] + $first->campos;
        $second = $this->questionOf($answer);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_REGISTROS, $second->campo);
        $this->assertStringContainsString('3 registros de 1 carro ', $second->getMessage());
        $this->assertSame(self::RACE_START_MS, $this->pageStartMs(), 'nothing was stored yet');

        Clock::freezeAt($this->at(2440));
        $this->press($answer + [RaceControl::CAMPO_CONFIRMAR_REGISTROS => '1']);
        $this->assertSame($this->at(2400), $this->pageStartMs(), 'the start is the first click, not the last confirmation');
    }

    /**
     * SITUATION: no start yet. Counters warm up and type a few numbers.
     * Then the cars go, and the controller presses "Largar agora".
     *
     * EXPECTED: the very first "Largar agora" asks nothing, whatever was
     * typed before: every entry so far is before it by definition, and a
     * question would stand between the controller and the start. But a
     * first start TYPED as a time (somebody forgot the button and enters
     * 0:00:30 afterwards) is asked about when entries would be left before
     * it: those may be the start pack.
     */
    public function testTheFirstStartAsksOnlyWhenItsTimeIsTyped(): void
    {
        $this->allCountersSee(self::CAR_A, 10);

        $question = $this->questionOf(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(30)]);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_REGISTROS, $question->campo);
        $this->assertStringContainsString('3 registros de 1 carro ', $question->getMessage());
        $this->assertNull($this->pageStartMs());

        // A typed start before every entry leaves nothing behind: no question.
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(5)]);
        $this->assertSame($this->at(5), $this->pageStartMs());
    }

    public function testTheVeryFirstLargarAgoraAsksNothing(): void
    {
        $this->allCountersSee(self::CAR_A, 10);

        $this->press(['acao' => 'largar_agora']);

        $this->assertSame($this->at(3600), $this->pageStartMs());
    }

    /**
     * SITUATION: the start was recorded at 0:00:30 and then corrected to
     * 0:00:00, which is right. Car A's first crossing is at 0:00:10. Later
     * a controller cancels the correction by mistake.
     *
     * WHAT WENT WRONG: only "corrigir" and a second "Largar agora" asked
     * about entries. Cancelling a correction also moves the start (the
     * older time comes back, here 30 s LATER), and it was stored without a
     * word: car A's pass zero fell before the start and it lost a lap.
     * Restoring a cancelled start has the same effect.
     *
     * EXPECTED: both are asked about first, with the count made under the
     * lock, and store nothing until confirmed.
     */
    public function testCancellingOrRestoringAsksWhenItMovesTheStartPastEntries(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(30)]);
        $late = $this->eventInForce(Ocorrencia::LARGADA);
        $this->press(['acao' => 'corrigir', 'ocorrencia_id' => $late->id, 'horario' => $this->formTime(0)]);
        $correction = $this->eventInForce(Ocorrencia::LARGADA);
        $this->allCountersSee(self::CAR_A, 10);

        $cancel = ['acao' => 'cancelar', 'ocorrencia_id' => $correction->id];
        $question = $this->questionOf($cancel);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_REGISTROS, $question->campo);
        $this->assertStringContainsString('3 registros de 1 carro ', $question->getMessage());
        $this->assertSame(self::RACE_START_MS, $this->pageStartMs(), 'the start was not moved');

        $this->press($cancel + [RaceControl::CAMPO_CONFIRMAR_REGISTROS => '1']);
        $this->assertSame($this->at(30), $this->pageStartMs());

        // Restoring the correction moves the start EARLIER: nothing is left behind, nothing is asked.
        $this->press(['acao' => 'restaurar', 'ocorrencia_id' => $correction->id]);
        $this->assertSame(self::RACE_START_MS, $this->pageStartMs());

        // A second, later start, cancelled and then restored: the restore moves the start later again.
        Clock::freezeAt($this->at(40));
        $this->press(['acao' => 'largar_agora', RaceControl::CAMPO_CONFIRMAR => '1', RaceControl::CAMPO_CONFIRMAR_REGISTROS => '1']);
        $second = $this->eventInForce(Ocorrencia::LARGADA);
        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $second->id]);
        $this->assertSame(self::RACE_START_MS, $this->pageStartMs());

        $question = $this->questionOf(['acao' => 'restaurar', 'ocorrencia_id' => $second->id]);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_REGISTROS, $question->campo);
        $this->assertSame(self::RACE_START_MS, $this->pageStartMs());
    }

    /**
     * SITUATION: the race was finished at 0:30. Five minutes later somebody
     * presses "Bandeira vermelha" (meaning to press nothing, or on a page
     * left open), and then "Relargada".
     *
     * WHAT WENT WRONG: both were stored. The finished race was "stopped"
     * and "restarted": car statuses and the rule about passes after the
     * finish followed events that cannot have happened.
     *
     * EXPECTED: a red flag or a restart later than the finish is refused,
     * and the sentence says what to do if the race is in fact not over. A
     * red flag typed with a time BEFORE the finish (one that was forgotten)
     * is still accepted.
     */
    public function testARedFlagOrRestartAfterTheFinishIsRefused(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        $this->press(['acao' => 'registrar', 'tipo' => 'encerramento', 'horario' => $this->formTime(1800), 'confirmar' => '1']);

        $refusal = $this->refusalOf(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(2100)]);
        $this->assertStringContainsString('A corrida foi encerrada', $refusal);
        $this->assertStringContainsString('cancele o encerramento', $refusal);

        // Left empty, the time is "now", one hour in: after the finish too.
        $this->assertStringContainsString('A corrida foi encerrada', $this->refusalOf(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha']));

        // A stoppage that happened during the race and was not logged then.
        $this->press(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(600)]);
        $this->press(['acao' => 'registrar', 'tipo' => 'relargada', 'horario' => $this->formTime(900)]);
        $refusal = $this->refusalOf(['acao' => 'registrar', 'tipo' => 'bandeira_vermelha', 'horario' => $this->formTime(1900)]);
        $this->assertStringContainsString('A corrida foi encerrada', $refusal);
        $this->assertSame([[$this->at(600), $this->at(900)]], $this->engineTimeline()->redFlagIntervals);
    }

    /**
     * SITUATION: car A has completed two laps and they are published. A
     * controller cancels the only start, confirming the question that says
     * "the published results are set to zero".
     *
     * WHAT WENT WRONG: the question promised it and it did not happen: with
     * no start the recount published nothing at all, so the results page
     * kept showing 2 laps for a race that had no start.
     *
     * EXPECTED: what the question says is true. After the cancellation car
     * A is published with 0 laps; restoring the start brings the 2 back.
     */
    public function testCancellingTheOnlyStartReallyPublishesZeros(): void
    {
        $this->press(['acao' => 'registrar', 'tipo' => 'largada', 'horario' => $this->formTime(0)]);
        foreach ([60, 160, 260] as $seconds) {
            $this->allCountersSee(self::CAR_A, $seconds);
        }
        $this->syncAll($this->at(290));
        $this->recompute();
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $start = $this->eventInForce(Ocorrencia::LARGADA);

        $question = $this->questionOf(['acao' => 'cancelar', 'ocorrencia_id' => $start->id]);
        $this->assertStringContainsString('os resultados publicados são zerados', $question->getMessage());

        $this->press(['acao' => 'cancelar', 'ocorrencia_id' => $start->id, RaceControl::CAMPO_CONFIRMAR_SEM_LARGADA => '1']);
        $this->assertEquals(0, $this->publishedData(self::CAR_A)['VOLTAS'], 'the text of the question is true');

        $this->press(['acao' => 'restaurar', 'ocorrencia_id' => $start->id]);
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: the organisers rehearsed with the test preset (a matching
     * window of a few seconds) and forgot to restore the real settings. On
     * race day the controller presses "Largar agora".
     *
     * WHAT WENT WRONG: nothing stood in the way. The real race would have
     * been counted with a window far too short for people typing by hand.
     *
     * EXPECTED: while the preset is applied, a start is asked about first,
     * and the question says how to get the real settings back. The instant
     * of the click is kept through the question. With the settings restored
     * the start asks nothing.
     */
    public function testAStartIsAskedAboutWhileTheTestPresetIsApplied(): void
    {
        $this->newRequest();
        $this->service->applyTestPreset(60);

        $question = $this->questionOf(['acao' => 'largar_agora']);
        $this->assertSame(RaceControl::CAMPO_CONFIRMAR_PREDEFINICAO, $question->campo);
        $this->assertStringContainsString('PREDEFINIÇÃO DE TESTE', $question->getMessage());
        $this->assertStringContainsString('Restaurar valores salvos', $question->getMessage());
        $this->assertNull($this->pageStartMs());

        Clock::freezeAt($this->at(3630));
        $this->press(['acao' => 'largar_agora', RaceControl::CAMPO_CONFIRMAR_PREDEFINICAO => '1'] + $question->campos);
        $this->assertSame($this->at(3600), $this->pageStartMs(), 'a rehearsal starts at the click too');
    }

    public function testWithTheRealSettingsAStartIsNotAskedAboutThePreset(): void
    {
        $this->newRequest();
        $this->service->applyTestPreset(60);
        $this->newRequest();
        $this->assertTrue($this->service->restoreSavedSettings());

        $this->press(['acao' => 'largar_agora']);

        $this->assertSame($this->at(3600), $this->pageStartMs());
    }
}
