<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Contagem\Engine\Settings;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Which crossing is "pass zero" (setting `passagem_inicial`).
 *
 * The counting line is after the start line. The first time a car reaches
 * it, the car has not driven a full lap.
 *
 * - "primeira_passagem" (the default): each car's first official pass after
 *   the start is its pass zero. Laps = official passes - 1, and every lap is
 *   measured from one crossing to the next. MatchingTest shows this rule;
 *   here it is compared with the other one.
 * - "largada": the organisers decided that the first crossing is not typed.
 *   The start itself is everybody's pass zero and every official pass is a
 *   lap. Lap 1 is then measured from the start time, so it is not a full
 *   lap: it is never a best lap and says nothing about the pace.
 *   With it comes `janela_largada_s`: entries typed in the first N seconds
 *   after the start are the initial crossing and never count.
 */
final class PassZeroTest extends EngineTestCase
{
    /** Car 7 crosses at 02:00, 05:00 and 08:00, recorded by everybody. */
    private function carSevenCrossesThreeTimes(string $passagemInicial): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->passagemInicial = $passagemInicial;
        $race->start('00:00');
        $race->pass(7, '02:00');
        $race->pass(7, '05:00');
        $race->pass(7, '08:00');
        return $race;
    }

    /**
     * SITUATION: the same three crossings under the two rules.
     *
     * EXPECTED with "primeira_passagem": two laps. The pass at 02:00 is pass
     * zero; laps 1 and 2 last 180 s each and both may be a best lap.
     *
     * EXPECTED with "largada": three laps. Lap 1 runs from the start to
     * 02:00 (120 s) and is never a best lap, although it is the shortest:
     * it is not a full lap of the track. The best lap is 180 s.
     */
    public function testTheSameCrossingsCountDifferentlyUnderTheTwoRules(): void
    {
        $default = $this->carSevenCrossesThreeTimes(Settings::INICIAL_PRIMEIRA_PASSAGEM)->run('09:00');
        $this->assertLapCounts([7 => 2], $default);
        $this->assertSame([0, 1, 2], array_map(fn(Lap $lap) => $lap->numeroVolta, $default->laps));
        $this->assertSame([null, 180000, 180000], array_map(fn(Lap $lap) => $lap->tempoVoltaMs, $default->laps));
        $this->assertSame([false, true, true], array_map(fn(Lap $lap) => $lap->elegivelMelhorVolta, $default->laps));

        $fromTheStart = $this->carSevenCrossesThreeTimes(Settings::INICIAL_LARGADA)->run('09:00');
        $this->assertLapCounts([7 => 3], $fromTheStart);
        $this->assertSame([1, 2, 3], array_map(fn(Lap $lap) => $lap->numeroVolta, $fromTheStart->laps));
        $this->assertSame([120000, 180000, 180000], array_map(fn(Lap $lap) => $lap->tempoVoltaMs, $fromTheStart->laps));
        $this->assertSame([false, true, true], array_map(fn(Lap $lap) => $lap->elegivelMelhorVolta, $fromTheStart->laps));
        $this->assertSame(180000, $fromTheStart->cars[7]->melhorVoltaMs);
    }

    /**
     * SITUATION: ten cars; each has crossed the line twice, 100 seconds
     * apart. The engine only says what a normal lap is once it has seen
     * eight full laps.
     *
     * EXPECTED with "primeira_passagem": the ten laps between the first and
     * the second crossing are full laps, so the pace is known (100 s).
     *
     * EXPECTED with "largada": each car has two laps, but the ten "lap 1"
     * were measured from the start and are not full laps. Only the ten
     * "lap 2" teach the engine anything, and they say the same: 100 s. The
     * much shorter "lap 1" (40 to 67 s) must not drag the pace down.
     */
    public function testALapMeasuredFromTheStartDoesNotFeedThePace(): void
    {
        foreach ([Settings::INICIAL_PRIMEIRA_PASSAGEM => 1, Settings::INICIAL_LARGADA => 2] as $passagemInicial => $expectedLaps) {
            $race = RaceScenario::withCounters(3);
            $race->settings->passagemInicial = $passagemInicial;
            $race->start('00:00');
            $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 100, 2, '00:40', 3);

            $result = $race->run('02:50');

            $this->assertSame($expectedLaps, $result->lapCount(1), "With $passagemInicial.");
            $this->assertSame(100000, $result->cars[1]->voltaEsperadaMs, "With $passagemInicial.");
        }

        // With "largada" and one crossing each, there are ten laps but not
        // one full lap: the pace is still unknown.
        $race = RaceScenario::withCounters(3);
        $race->settings->passagemInicial = Settings::INICIAL_LARGADA;
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 100, 1, '00:40', 3);
        $result = $race->run('01:30');
        $this->assertSame(1, $result->lapCount(1));
        $this->assertNull($result->cars[1]->voltaEsperadaMs);
    }

    /**
     * SITUATION: "largada" rule. Race control pressed "Largar agora" at
     * 00:00 but the cars left at 00:30, and the start time is corrected.
     *
     * EXPECTED: here lap 1 IS measured from the start, so it follows the
     * correction: 120 s becomes 90 s. (Under the default rule no lap time
     * depends on the start; see RaceControlTest.)
     */
    public function testWithLargadaLapOneFollowsTheStartTime(): void
    {
        $race = $this->carSevenCrossesThreeTimes(Settings::INICIAL_LARGADA);
        $this->assertSame(120000, $race->run('09:00')->laps[0]->tempoVoltaMs);

        $race->start('00:30');
        $corrected = $race->run('09:00');

        $this->assertSame(90000, $corrected->laps[0]->tempoVoltaMs);
        $this->assertSame(450, PublishedValues::forCar($corrected->cars[7], $corrected->timeline)['LAST']);
    }

    /**
     * SITUATION: "largada" rule with `janela_largada_s` = 30. The organisers
     * said "do not type the first crossing", but counter 1 typed the pack
     * anyway, 10 seconds after the start: cars 7 and 8. For car 8 the other
     * counters typed it too. Later both cars cross normally at 03:00.
     *
     * WHAT WENT WRONG: nothing serious; somebody typed a crossing that the
     * rules of this race say is not a lap.
     *
     * EXPECTED: whatever is typed in the first 30 seconds is the initial
     * crossing. It never counts (not even car 8's, on which all three
     * agree), it raises no flag (not even car 7's lone entry), and it is
     * shown as the car's pass zero. Each car has one lap, from the start to
     * 03:00.
     */
    public function testEntriesInsideTheStartWindowNeverCountAndRaiseNoFlags(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->passagemInicial = Settings::INICIAL_LARGADA;
        $race->settings->janelaLargadaS = 30;
        $race->start('00:00');
        $loneInTheWindow = $race->entry(1, 7, '00:10');
        $race->pass(8, '00:10');
        $race->pass(7, '03:00');
        $race->pass(8, '03:00');

        $result = $race->run('04:00');

        $this->assertLapCounts([7 => 1, 8 => 1], $result);
        $this->assertNoOpenFlags($result);
        $this->assertTrue($result->groupOfEntry($loneInTheWindow)->dentroDaJanelaDeLargada);
        $this->assertSame(0, $result->groupOfEntry($loneInTheWindow)->numeroVolta);
        $lapsOfCar8 = array_values(array_filter($result->laps, fn(Lap $lap) => $lap->carro === 8));
        $this->assertSame([Lap::INICIAL, Lap::CONTADA], array_map(fn(Lap $lap) => $lap->estado, $lapsOfCar8));
        $this->assertSame(180000, $lapsOfCar8[1]->tempoVoltaMs, 'Lap 1 is still measured from the start.');

        // Without the window, car 8's agreed crossing at 00:10 is a lap.
        $race->settings->janelaLargadaS = 0;
        $this->assertLapCounts([7 => 1, 8 => 2], $race->run('04:00'));
    }

    /**
     * SITUATION: the same 30-second start window. Car 7 is typed by
     * everybody at 00:29.999 and car 8 at 00:30 sharp.
     *
     * EXPECTED: the window covers the first 30 seconds and not one
     * millisecond more. Car 7's entry is inside (no lap); car 8's is the
     * first moment outside (a lap).
     */
    public function testTheStartWindowEndsExactlyAfterItsSeconds(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->passagemInicial = Settings::INICIAL_LARGADA;
        $race->settings->janelaLargadaS = 30;
        $race->start('00:00');
        $race->pass(7, '00:29.999');
        $race->pass(8, '00:30');

        $this->assertLapCounts([7 => 0, 8 => 1], $race->run('01:00'));
    }

    /**
     * SITUATION: somebody left `janela_largada_s` = 30 in the settings but
     * the race uses the default rule, "primeira_passagem".
     *
     * EXPECTED: the window means nothing here. Under the default rule the
     * first crossing is SUPPOSED to be typed, however early: car 7's
     * crossing at 00:10 is its pass zero and 03:00 completes lap 1.
     */
    public function testTheStartWindowIsIgnoredUnderTheDefaultRule(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->settings->janelaLargadaS = 30;
        $race->start('00:00');
        $firstCrossing = $race->pass(7, '00:10');
        $race->pass(7, '03:00');

        $result = $race->run('04:00');

        $this->assertLapCounts([7 => 1], $result);
        $this->assertFalse($result->groupOfEntry($firstCrossing[1])->dentroDaJanelaDeLargada);
        $this->assertSame(170000, $result->laps[1]->tempoVoltaMs);
        $this->assertNotContains(Flag::PRIMEIRA_PASSAGEM_TARDIA, array_map(fn(Flag $flag) => $flag->tipo, $result->flags));
    }
}
