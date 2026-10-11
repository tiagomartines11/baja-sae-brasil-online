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
     * and 06:00. Ten minutes in, somebody corrects the start time to 00:30.
     *
     * EXPECTED: everything measured from the start follows the correction:
     * - LAST (seconds from the start to the car's last pass) goes from 360
     *   to 330;
     * - lap 1 goes from 180 to 150 seconds;
     * - the race clock at 10:00 reads 9 min 30 s instead of 10 min.
     * The lap count does not change.
     */
    public function testEditingTheStartTimeMovesLastPassAndTheRaceClock(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(3, '03:00');
        $race->pass(3, '06:00');

        $before = $race->run('10:00');
        $this->assertSame(360, PublishedValues::forCar($before->cars[3], $before->timeline)['LAST']);
        $this->assertSame(180000, $before->laps[0]->tempoVoltaMs);
        $this->assertSame(600000, $before->timeline->elapsedMs(RaceScenario::ms('10:00')));

        $race->start('00:30');
        $after = $race->run('10:00');

        $this->assertSame(RaceScenario::ms('00:30'), $after->timeline->largadaMs);
        $this->assertSame(330, PublishedValues::forCar($after->cars[3], $after->timeline)['LAST']);
        $this->assertSame(150000, $after->laps[0]->tempoVoltaMs);
        $this->assertSame(570000, $after->timeline->elapsedMs(RaceScenario::ms('10:00')));
        $this->assertLapCounts([3 => 2], $after);
    }

    /**
     * SITUATION: the start was first recorded at 00:00. Car 3 was typed by
     * everybody at 00:10 (it was still on the formation lap) and at 03:00.
     * The start is then corrected to 00:30.
     *
     * EXPECTED: before the correction the 00:10 entries were lap 1. After
     * it they are from before the start and no longer count, so the car has
     * one lap instead of two.
     */
    public function testAfterMovingTheStartLaterEarlierEntriesStopCounting(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(3, '00:10');
        $race->pass(3, '03:00');
        $this->assertLapCounts([3 => 2], $race->run('05:00'));

        $race->start('00:30');
        $this->assertLapCounts([3 => 1], $race->run('05:00'));
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
     * SITUATION: car 3 crosses at 03:00 and next at 12:00, with a red flag
     * from 05:00 to 10:00 in between.
     *
     * EXPECTED: the lap is counted, but marked as interrupted: its nine
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

        $this->assertLapCounts([3 => 2], $result);
        $this->assertTrue($result->laps[1]->interrompida);
        $this->assertFalse($result->laps[1]->elegivelMelhorVolta);
    }
}
