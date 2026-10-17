<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Review\ConfirmationNeeded;
use Baja\Contagem\Review\RaceControl;
use Baja\Contagem\Review\RaceEvents;
use InvalidArgumentException;

/**
 * What a test needs to press the buttons of the race control page.
 *
 * The page's forms are submitted by calling the function the page calls
 * (RaceControl::handle) with the fields a form sends. An action can end in
 * three ways, and there is a helper for each:
 *
 *   press()       it is carried out
 *   refusalOf()   it is refused, with a sentence
 *   questionOf()  nothing is stored and the person is asked first; the
 *                 page then shows a button that sends the same form again
 *                 plus the confirmation field
 */
trait RaceControlForms
{
    /** @param array<string, mixed> $form the fields of the form */
    private function press(array $form, string $username = 'zzt_controle'): void
    {
        $this->newRequest();
        RaceControl::handle($this->service, $form, $username, false);
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
    private function raceControlRows(): array
    {
        $this->newRequest();
        return RaceEvents::toOcorrencias(RaceControl::raceEvents($this->prova));
    }

    /** The start as the race control page shows it. */
    private function pageStartMs(): ?int
    {
        $this->newRequest();
        return RaceControl::timelineOf(RaceControl::raceEvents($this->prova))->largadaMs;
    }

    /** The race as the engine counts it (pit events and all). */
    private function engineTimeline(): \Baja\Contagem\Engine\RaceTimeline
    {
        $this->newRequest();
        return $this->service->compute()->timeline;
    }

    /** The event of this type that is in force (of two starts, the newest row). */
    private function eventInForce(string $tipo): Ocorrencia
    {
        $found = null;
        foreach (RaceEvents::inForce($this->raceControlRows()) as $event) {
            if ($event->tipo === $tipo) {
                $found = $event;
            }
        }
        return $found ?? $this->fail('no event of type ' . $tipo . ' is in force');
    }
}
