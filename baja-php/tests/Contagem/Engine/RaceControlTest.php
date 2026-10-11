<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Race control: start, red flag, restart, finish.
 *
 * Like everything else, race-control events are never edited. Changing the
 * start time means recording a new start; the most recent one wins.
 */
final class RaceControlTest extends EngineTestCase
{
    /**
     * SITUATION: race control pressed "Largar agora" at 00:00, but the cars
     * actually left 30 seconds later. Car 3 has crossed the line at 03:00
     * (its pass zero) and at 06:00 (completing lap 1). Ten minutes in,
     * somebody corrects the start time to 00:30.
     *
     * EXPECTED: what is measured from the start follows the correction:
     * - LAST (seconds from the start to the car's last pass) goes from 360
     *   to 330;
     * - the race clock at 10:00 reads 9 min 30 s instead of 10 min.
     * What is NOT measured from the start stays put: the lap count, and the
     * lap time. Lap 1 runs from the car's first crossing to its second
     * (180 s), whatever the start time is.
     */
    public function testEditingTheStartTimeMovesLastPassAndTheRaceClockButNoLapTime(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(3, '03:00');
        $race->pass(3, '06:00');

        $before = $race->run('10:00');
        $this->assertSame(360, PublishedValues::forCar($before->cars[3], $before->timeline)['LAST']);
        $this->assertSame(180000, $before->laps[1]->tempoVoltaMs);
        $this->assertSame(600000, $before->timeline->elapsedMs(RaceScenario::ms('10:00')));

        $race->start('00:30');
        $after = $race->run('10:00');

        $this->assertSame(RaceScenario::ms('00:30'), $after->timeline->largadaMs);
        $this->assertSame(330, PublishedValues::forCar($after->cars[3], $after->timeline)['LAST']);
        $this->assertSame(180000, $after->laps[1]->tempoVoltaMs, 'Lap 1 is crossing to crossing; the start is not part of it.');
        $this->assertSame(570000, $after->timeline->elapsedMs(RaceScenario::ms('10:00')));
        $this->assertLapCounts([3 => 1], $after);
    }

    /**
     * SITUATION: the start was first recorded at 00:00. Car 3 was typed by
     * everybody at 00:10 (it was still on the formation lap) and at 03:00.
     * The start is then corrected to 00:30.
     *
     * EXPECTED: before the correction the 00:10 entries were the car's pass
     * zero and 03:00 completed lap 1. After it, 00:10 is from before the
     * start and does not exist for the count; 03:00 becomes pass zero and
     * the car has no lap yet.
     */
    public function testAfterMovingTheStartLaterEarlierEntriesStopCounting(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(3, '00:10');
        $race->pass(3, '03:00');
        $this->assertLapCounts([3 => 1], $race->run('05:00'));

        $race->start('00:30');
        $corrected = $race->run('05:00');
        $this->assertLapCounts([3 => 0], $corrected);
        $this->assertSame(RaceScenario::ms('03:00'), $corrected->cars[3]->ultimaPassagemMs);
    }

    /**
     * SITUATION: red flag at 05:00, restart at 08:00, finish at 20:00.
     *
     * EXPECTED: the race state reported at each moment is "aguardando"
     * (waiting) before the start, then "correndo", "bandeira_vermelha",
     * "correndo" again and finally "encerrada". The race clock stops at the
     * finish.
     */
    public function testTheRaceStateFollowsRaceControl(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('01:00');
        $race->ocorrencia(Ocorrencia::BANDEIRA_VERMELHA, '05:00');
        $race->ocorrencia(Ocorrencia::RELARGADA, '08:00');
        $race->ocorrencia(Ocorrencia::ENCERRAMENTO, '20:00');
        $timeline = $race->run('25:00')->timeline;

        $this->assertSame(RaceTimeline::AGUARDANDO, $timeline->stateAt(RaceScenario::ms('00:30')));
        $this->assertSame(RaceTimeline::CORRENDO, $timeline->stateAt(RaceScenario::ms('03:00')));
        $this->assertSame(RaceTimeline::BANDEIRA_VERMELHA, $timeline->stateAt(RaceScenario::ms('06:00')));
        $this->assertSame(RaceTimeline::CORRENDO, $timeline->stateAt(RaceScenario::ms('09:00')));
        $this->assertSame(RaceTimeline::CORRENDO, $timeline->stateAt(RaceScenario::ms('19:59.999')), 'Running until the last millisecond.');
        $this->assertSame(RaceTimeline::ENCERRADA, $timeline->stateAt(RaceScenario::ms('20:00')), 'Over from the finish instant on.');
        $this->assertSame(RaceTimeline::AGUARDANDO, $timeline->stateAt(RaceScenario::ms('00:59.999')));
        $this->assertSame(RaceTimeline::CORRENDO, $timeline->stateAt(RaceScenario::ms('01:00')), 'Running from the start instant on.');
        $this->assertSame(RaceTimeline::ENCERRADA, $timeline->stateAt(RaceScenario::ms('21:00')));
        $this->assertSame(19 * 60000, $timeline->elapsedMs(RaceScenario::ms('25:00')), 'The clock stopped at the finish.');
    }

    /**
     * SITUATION: the red flag was recorded at 05:00 by mistake (wrong
     * button). Race control records a correction that replaces it, saying
     * the stoppage really began at 06:00.
     *
     * EXPECTED: the replaced event is ignored. At 05:30 the race was running.
     */
    public function testACorrectedOcorrenciaReplacesTheOneItPointsAt(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $wrongFlag = $race->ocorrencia(Ocorrencia::BANDEIRA_VERMELHA, '05:00');
        $race->ocorrencia(Ocorrencia::BANDEIRA_VERMELHA, '06:00', null, $wrongFlag);
        $timeline = $race->run('10:00')->timeline;

        $this->assertSame(RaceTimeline::CORRENDO, $timeline->stateAt(RaceScenario::ms('05:30')));
        $this->assertSame(RaceTimeline::BANDEIRA_VERMELHA, $timeline->stateAt(RaceScenario::ms('06:30')));
    }

    /**
     * SITUATION: car 3 crosses at 03:00 (pass zero) and next at 12:00, with
     * a red flag from 05:00 to 10:00 in between.
     *
     * EXPECTED: the lap between the two crossings is counted, but marked as interrupted: its nine
     * minutes say nothing about the car's pace, so it does not feed the pace
     * model and cannot be a best lap.
     */
    public function testALapThatSpansARedFlagIsCountedButMarkedInterrupted(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(3, '03:00');
        $race->ocorrencia(Ocorrencia::BANDEIRA_VERMELHA, '05:00');
        $race->ocorrencia(Ocorrencia::RELARGADA, '10:00');
        $race->pass(3, '12:00');

        $result = $race->run('13:00');

        $this->assertLapCounts([3 => 1], $result);
        $this->assertTrue($result->laps[1]->interrompida);
        $this->assertFalse($result->laps[1]->elegivelMelhorVolta);
    }

    /**
     * SITUATION: somebody pressed "Encerrar" by mistake at 05:00 while the
     * race was still on. Race control cancels that finish (a
     * "cancelamento" row pointing at it; nothing is ever deleted).
     *
     * EXPECTED: the cancelled finish no longer exists and the cancellation
     * is not an event itself. The race is running again, the clock is not
     * frozen, and a pass at 08:00 and another at 11:00 both count (with the
     * finish in force, only the first pass after it would).
     */
    public function testCancellingAFinishPutsTheRaceBackOn(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(3, '02:00');
        $finishByMistake = $race->finish('05:00');
        $race->pass(3, '08:00');
        $race->pass(3, '11:00');

        $ended = $race->run('12:00');
        $this->assertSame(RaceTimeline::ENCERRADA, $ended->timeline->stateAt(RaceScenario::ms('12:00')));
        $this->assertLapCounts([3 => 1], $ended);

        $race->ocorrencia(Ocorrencia::CANCELAMENTO, '06:00', null, $finishByMistake);
        $resumed = $race->run('12:00');

        $this->assertNull($resumed->timeline->encerramentoMs);
        $this->assertSame(RaceTimeline::CORRENDO, $resumed->timeline->stateAt(RaceScenario::ms('12:00')));
        $this->assertSame(720000, $resumed->timeline->elapsedMs(RaceScenario::ms('12:00')));
        $this->assertLapCounts([3 => 2], $resumed);
    }

    /**
     * SITUATION: "Largar agora" was pressed during the warm-up, and the
     * counters were already typing. Race control cancels that start.
     *
     * EXPECTED: there is no race yet: state "aguardando", no clock, and
     * nothing that was typed counts. If an older start exists it is NOT
     * brought back by accident here, because there was none.
     */
    public function testCancellingTheOnlyStartMeansTheRaceHasNotStarted(): void
    {
        $race = RaceScenario::withCounters(3);
        $startByMistake = $race->start('00:00');
        $race->pass(3, '02:00');
        $race->pass(3, '05:00');
        $this->assertLapCounts([3 => 1], $race->run('06:00'));

        $race->ocorrencia(Ocorrencia::CANCELAMENTO, '05:30', null, $startByMistake);
        $result = $race->run('06:00');

        $this->assertNull($result->timeline->largadaMs);
        $this->assertSame(RaceTimeline::AGUARDANDO, $result->timeline->stateAt(RaceScenario::ms('06:00')));
        $this->assertNull($result->timeline->elapsedMs(RaceScenario::ms('06:00')));
        $this->assertSame([], $result->laps);
    }

    /**
     * SITUATION: a red flag was recorded at 05:00 by mistake and never
     * followed by a restart. Car 3 crossed at 03:00 and 06:00. Race control
     * cancels the red flag.
     *
     * EXPECTED: with the red flag in force the race is stopped and the lap
     * across it is "interrupted". Once it is cancelled there never was a
     * stoppage: the race is running and the lap is an ordinary lap that
     * may be a best lap.
     */
    public function testCancellingARedFlagRemovesTheStoppage(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(3, '03:00');
        $redFlagByMistake = $race->ocorrencia(Ocorrencia::BANDEIRA_VERMELHA, '05:00');
        $race->pass(3, '06:00');

        $stopped = $race->run('07:00');
        $this->assertSame(RaceTimeline::BANDEIRA_VERMELHA, $stopped->timeline->stateAt(RaceScenario::ms('07:00')));
        $this->assertTrue($stopped->laps[1]->interrompida);

        $race->ocorrencia(Ocorrencia::CANCELAMENTO, '06:30', null, $redFlagByMistake);
        $running = $race->run('07:00');

        $this->assertSame([], $running->timeline->redFlagIntervals);
        $this->assertSame(RaceTimeline::CORRENDO, $running->timeline->stateAt(RaceScenario::ms('07:00')));
        $this->assertFalse($running->laps[1]->interrompida);
        $this->assertTrue($running->laps[1]->elegivelMelhorVolta);
    }
}
