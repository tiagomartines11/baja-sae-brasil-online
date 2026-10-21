<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The field pace as the full run reports it, for the public feed and the
 * pace chart on Controle da corrida.
 *
 * The field pace is the median of the clean laps the whole field finished
 * in the last `janela_ritmo_s` seconds (15 minutes), unknown under eight
 * laps (see PaceModelTest). It decides when a lap is too short to be real,
 * which laps may be a best lap, and when a car is late or stopped. It does
 * NOT decide whether a pass counts: the hold rule looks at each car's own
 * laps.
 *
 * The run reports it twice: `ritmo`, the pace now with the number of laps
 * behind it, and `ritmoHistorico`, the pace once a minute since the start.
 */
final class RacePaceTest extends EngineTestCase
{
    private const FIELD = [11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

    /** Ten cars, 7 s apart, lapping in 180 s: first crossing at 03:00, last at 18:00 (five laps each). */
    private function steadyRace(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField(self::FIELD, 180, 6);
        return $race;
    }

    /**
     * SITUATION: the steady race, looked at at 25:00. The last laps ended
     * between 18:00 and 19:03; the earlier ones are more than 15 minutes
     * old by now, except the three most recent laps of every car.
     *
     * EXPECTED: the pace is 180 s, taken from those 30 laps, with the
     * 900-second window it looks back over.
     */
    public function testTheRunReportsThePaceNowAndHowManyLapsItIsTakenFrom(): void
    {
        $result = $this->steadyRace()->run('25:00');

        $this->assertSame(['volta_mediana_ms' => 180000, 'voltas_na_janela' => 30, 'janela_s' => 900], $result->ritmo);
    }

    /**
     * SITUATION: the same race at 25:00.
     *
     * EXPECTED: the history starts within a minute of the start, has one
     * point per minute and ends at 25:00 exactly. Its last point is the
     * pace now. Early on, before eight laps were finished, the pace is
     * unknown.
     */
    public function testTheHistoryRunsFromTheStartToNow(): void
    {
        $result = $this->steadyRace()->run('25:00');
        $history = $result->ritmoHistorico;

        $first = $history[0]['ts_ms'] - RaceScenario::ms('00:00');
        $this->assertGreaterThanOrEqual(0, $first);
        $this->assertLessThan(60000, $first);
        $last = $history[count($history) - 1];
        $this->assertSame(RaceScenario::ms('25:00'), $last['ts_ms']);
        $this->assertSame($result->ritmo['volta_mediana_ms'], $last['volta_mediana_ms']);
        $this->assertSame($result->ritmo['voltas_na_janela'], $last['voltas_na_janela']);
        for ($index = 1; $index < count($history) - 1; $index++) {
            $this->assertSame(60000, $history[$index]['ts_ms'] - $history[$index - 1]['ts_ms']);
        }
        $this->assertNull($history[3]['volta_mediana_ms'], 'Under four minutes in, no lap has been finished.');
        $this->assertSame(0, $history[3]['voltas_na_janela']);
    }

    /**
     * SITUATION: the steady race is finished at 20:00 and looked at an
     * hour after the start, when every lap is far more than 15 minutes old.
     *
     * EXPECTED: the pace is the one at the finish (180 s, all 50 laps),
     * not "unknown", and the history stops at the finish. After the race
     * nothing new happens; the pace would only fade away.
     */
    public function testAfterTheFinishThePaceStaysAsItWasAtTheFinish(): void
    {
        $race = $this->steadyRace();
        $race->finish('20:00');

        $result = $race->run('60:00');

        $this->assertSame(['volta_mediana_ms' => 180000, 'voltas_na_janela' => 50, 'janela_s' => 900], $result->ritmo);
        $this->assertSame(RaceScenario::ms('20:00'), $result->ritmoHistorico[count($result->ritmoHistorico) - 1]['ts_ms']);
    }

    /**
     * SITUATION: the counters are typing warm-up passes; race control has
     * not started the race.
     *
     * EXPECTED: no pace and no history, but the window is still said.
     */
    public function testBeforeTheStartThereIsNoPace(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->pass(11, '-02:00');

        $result = $race->run('-01:00');

        $this->assertSame(['volta_mediana_ms' => null, 'voltas_na_janela' => 0, 'janela_s' => 900], $result->ritmo);
        $this->assertSame([], $result->ritmoHistorico);
    }
}
