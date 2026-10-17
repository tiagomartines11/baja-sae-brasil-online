<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Data\PassGroup;
use Baja\Contagem\Engine\PaceModel;
use Baja\Contagem\Engine\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The pace model, by itself: "what lap time should we expect from this car
 * at this moment?"
 *
 *   expected lap = field pace x car factor
 *
 * - Field pace: the middle value (median) of the laps the whole field
 *   completed in the last `janela_ritmo_s` seconds (15 minutes). Unknown
 *   until there are 8 of them.
 * - Car factor: how this car compares with the field (0.8 = 20% faster),
 *   the median over its last 6 clean laps. 1.0 until it has 3.
 *
 * Here the model is fed laps directly, so each rule can be shown with round
 * numbers. Times are in seconds from an arbitrary zero. (The model looks at
 * the field once per minute, so the questions are asked on whole minutes.)
 */
final class PaceModelTest extends TestCase
{
    /** A counted, clean lap of $car that ended at $endSeconds and lasted $durationSeconds. */
    private function lap(int $car, int $endSeconds, float $durationSeconds): Lap
    {
        $lap = new Lap($car, 1, $endSeconds * 1000, 0, 3);
        $lap->tempoVoltaMs = (int) round($durationSeconds * 1000);
        $lap->grupoAnterior = new PassGroup(new Entry(1, 1, 10, $car, 0));
        return $lap;
    }

    /**
     * Eight field cars (numbers 1 to 8) lapping in exactly 100 seconds from
     * second 0 to $untilSeconds, one behind the other.
     *
     * @return array<int, Lap[]>
     */
    private function steadyField(int $untilSeconds): array
    {
        $lapsByCar = [];
        for ($car = 1; $car <= 8; $car++) {
            for ($end = 100 + $car; $end <= $untilSeconds; $end += 100) {
                $lapsByCar[$car][] = $this->lap($car, $end, 100);
            }
        }
        return $lapsByCar;
    }

    /**
     * SITUATION: seven laps of 100 s have been completed; then an eighth.
     *
     * EXPECTED: with seven laps the engine does not say what a normal lap
     * is (it would rather say nothing than guess from too little); with
     * eight, the pace is 100 s. Before the first lap of the race is
     * completed there is no pace either.
     */
    public function testThePaceIsUnknownUntilEightLapsWereCompleted(): void
    {
        $laps = [];
        for ($car = 1; $car <= 7; $car++) {
            $laps[$car][] = $this->lap($car, 100 + $car, 100);
        }
        $sevenLaps = new PaceModel($laps, new Settings());
        $this->assertNull($sevenLaps->fieldPaceAt(180000));
        $this->assertNull($sevenLaps->expectedLapMs(1, 180000));

        $laps[8][] = $this->lap(8, 108, 100);
        $eightLaps = new PaceModel($laps, new Settings());
        $this->assertSame(100000.0, $eightLaps->fieldPaceAt(180000));
        $this->assertSame(100000, $eightLaps->expectedLapMs(1, 180000));
        $this->assertNull($eightLaps->fieldPaceAt(60000), 'Before anybody completed a lap.');
    }

    /**
     * SITUATION: eight laps of 100 s, one of which is not a usable lap, for
     * each of these reasons in turn: it overlaps a red flag or a pit stay;
     * it was measured from the start time (no pass begins it); it is the
     * car's pass zero; it is a pass still waiting for confirmation; it has
     * no duration.
     *
     * EXPECTED: in every case only seven clean laps are left, so the pace
     * is unknown. None of those "laps" says anything about how fast the
     * track is.
     */
    public function testOnlyCleanFullLapsFeedThePace(): void
    {
        $spoilers = [
            'interrupted' => function (Lap $lap): void {
                $lap->interrompida = true;
            },
            'measured from the start' => function (Lap $lap): void {
                $lap->grupoAnterior = null;
            },
            'pass zero' => function (Lap $lap): void {
                $lap->estado = Lap::INICIAL;
            },
            'pending' => function (Lap $lap): void {
                $lap->estado = Lap::PENDENTE;
            },
            'no duration' => function (Lap $lap): void {
                $lap->tempoVoltaMs = null;
            },
            'zero duration' => function (Lap $lap): void {
                $lap->tempoVoltaMs = 0;
            },
        ];
        foreach ($spoilers as $reason => $spoil) {
            $laps = [];
            for ($car = 1; $car <= 8; $car++) {
                $laps[$car][] = $this->lap($car, 100 + $car, 100);
            }
            $spoil($laps[3][0]);

            $this->assertNull((new PaceModel($laps, new Settings()))->fieldPaceAt(180000), "A lap that is $reason must not count.");
        }
    }

    /**
     * SITUATION: eight laps: seven of 100 s and one of 1000 s (a car that
     * broke down and was pushed back to the track).
     *
     * EXPECTED: 100 s. The pace is the MIDDLE value, not the average, so
     * one absurd lap does not move it. (The average would be 212 s.)
     */
    public function testThePaceIsTheMedianSoOneAbsurdLapDoesNotMoveIt(): void
    {
        $laps = [];
        for ($car = 1; $car <= 7; $car++) {
            $laps[$car][] = $this->lap($car, 100 + $car, 100);
        }
        $laps[8][] = $this->lap(8, 108, 1000);

        $this->assertSame(100000.0, (new PaceModel($laps, new Settings()))->fieldPaceAt(180000));
    }

    /**
     * SITUATION: eight dry laps of 100 s: one ends at second 120, the other
     * seven between 121 and 127. Then it starts to rain: eight laps of
     * 200 s end between 1501 and 1508. The pace looks back 900 seconds
     * (`janela_ritmo_s`).
     *
     * EXPECTED:
     * - at second 960 the eight dry laps are inside the window: 100 s;
     * - at second 1020 the lap that ended at 120 is exactly 900 s old and
     *   has left the window; seven remain, too few: unknown. (Had it ended
     *   one second later, at 121, it would still be there.)
     * - at second 1560 only the wet laps are in the window: 200 s. The pace
     *   follows the track, it is not the average of the whole race;
     * - with a window of 1500 s the dry laps are still there at 1560, and
     *   the middle of 8 dry and 8 wet laps is 150 s.
     */
    public function testThePaceOnlyLooksBackOverTheConfiguredWindow(): void
    {
        $laps = [];
        for ($car = 1; $car <= 8; $car++) {
            $laps[$car][] = $this->lap($car, 119 + $car, 100);
            $laps[$car][] = $this->lap($car, 1500 + $car, 200);
        }

        $pace = new PaceModel($laps, new Settings());
        $this->assertSame(100000.0, $pace->fieldPaceAt(960000));
        $this->assertNull($pace->fieldPaceAt(1020000));
        $this->assertSame(200000.0, $pace->fieldPaceAt(1560000));

        $laps[1][0] = $this->lap(1, 121, 100);
        $this->assertSame(100000.0, (new PaceModel($laps, new Settings()))->fieldPaceAt(1020000));

        $longMemory = new Settings();
        $longMemory->janelaRitmoS = 1500;
        $this->assertSame(150000.0, (new PaceModel($laps, $longMemory))->fieldPaceAt(1560000));
    }

    /**
     * SITUATION: a field lapping in 100 s, and car 50, which laps in 80 s.
     *
     * EXPECTED: until car 50 has three clean laps of its own it is treated
     * as an average car (factor 1.0, expected lap 100 s). From its third
     * lap on, its factor is 0.8 and its expected lap 80 s. Laps the car has
     * not finished yet at the moment of the question do not count.
     */
    public function testACarIsAverageUntilItHasThreeLapsOfItsOwn(): void
    {
        $laps = $this->steadyField(2000);
        $laps[50] = [$this->lap(50, 1080, 80), $this->lap(50, 1160, 80), $this->lap(50, 1240, 80)];
        $pace = new PaceModel($laps, new Settings());

        $this->assertSame(1.0, $pace->carFactorAt(50, 1200000), 'Two laps finished so far.');
        $this->assertSame(100000, $pace->expectedLapMs(50, 1200000));
        $this->assertSame(0.8, $pace->carFactorAt(50, 1240000), 'The third lap has just ended.');
        $this->assertSame(80000, $pace->expectedLapMs(50, 1240000));
        $this->assertSame(1.0, $pace->carFactorAt(77, 1240000), 'A car with no laps at all is average.');
    }

    /**
     * SITUATION: car 50 did four laps of 140 s early on (a misfiring
     * engine) and then three laps of 80 s once it was fixed. The field laps
     * in 100 s throughout.
     *
     * EXPECTED: the factor is the median over the car's LAST SIX laps:
     * three of 1.4 and three of 0.8 give 1.1. (Over the last five it would
     * be 0.8; over all seven, 1.4.)
     */
    public function testTheCarFactorIsTheMedianOfItsLastSixLaps(): void
    {
        $laps = $this->steadyField(3000);
        $end = 1000;
        foreach ([140, 140, 140, 140, 80, 80, 80] as $duration) {
            $end += $duration;
            $laps[50][] = $this->lap(50, $end, $duration);
        }
        $pace = new PaceModel($laps, new Settings());

        $this->assertEqualsWithDelta(1.1, $pace->carFactorAt(50, 2000000), 0.0001);
        $this->assertSame(110000, $pace->expectedLapMs(50, 2000000));
    }

    /**
     * SITUATION: car 50 laps in 80 s twice and then has one lap of 160 s
     * (it stopped on track for a while). In a second race that third lap
     * takes 159 s.
     *
     * EXPECTED: a lap of 1.6 times the field pace or more says nothing
     * about the car's normal speed and is left out of its factor. With the
     * 160 s lap the car has only two usable laps: factor 1.0. A lap of
     * 159 s (1.59 times) is still used: three laps, factor 0.8.
     */
    public function testALapOfOnePointSixTimesThePaceOrMoreSaysNothingAboutTheCar(): void
    {
        foreach ([[160, 1.0], [159, 0.8]] as [$thirdLapSeconds, $expectedFactor]) {
            $laps = $this->steadyField(2000);
            $laps[50] = [
                $this->lap(50, 1080, 80),
                $this->lap(50, 1160, 80),
                $this->lap(50, 1160 + $thirdLapSeconds, $thirdLapSeconds),
            ];
            $pace = new PaceModel($laps, new Settings());

            $this->assertSame($expectedFactor, $pace->carFactorAt(50, 1500000), "Third lap of $thirdLapSeconds s.");
        }
    }

    /**
     * SITUATION: the same model is asked about minute 30 first and about
     * minute 5 afterwards (the engine asks in whatever order it meets the
     * cars).
     *
     * EXPECTED: the same answers as a fresh model asked in time order. The
     * model works its way through the race only as far as it is asked, and
     * must not forget the early answers once it has moved on.
     */
    public function testQuestionsCanBeAskedInAnyOrder(): void
    {
        $laps = [];
        for ($car = 1; $car <= 8; $car++) {
            $laps[$car][] = $this->lap($car, 100 + $car, 100);
            $laps[$car][] = $this->lap($car, 400 + $car, 300);
            $laps[$car][] = $this->lap($car, 1700 + $car, 130);
        }
        $inOrder = new PaceModel($laps, new Settings());
        $early = $inOrder->fieldPaceAt(300000);
        $middle = $inOrder->fieldPaceAt(450000);
        $late = $inOrder->fieldPaceAt(1800000);

        $backwards = new PaceModel($laps, new Settings());
        $this->assertSame($late, $backwards->fieldPaceAt(1800000));
        $this->assertSame($middle, $backwards->fieldPaceAt(450000));
        $this->assertSame($early, $backwards->fieldPaceAt(300000));
        $this->assertSame([100000.0, 200000.0, 130000.0], [$early, $middle, $late]);
    }

    /**
     * SITUATION: car 50 laps in 80 s among a field lapping in 100 s. The
     * model is asked about car 50 late in the race first (three laps
     * finished: factor 0.8), then about an EARLIER moment (two laps
     * finished), then about the late moment again.
     *
     * EXPECTED: each answer is about the moment asked, whatever was asked
     * before. Going back in time, the car is "average" again (factor 1.0):
     * the laps it had not finished yet at that moment do not count.
     */
    public function testQuestionsAboutOneCarCanGoBackInTime(): void
    {
        $laps = $this->steadyField(2000);
        $laps[50] = [$this->lap(50, 1080, 80), $this->lap(50, 1160, 80), $this->lap(50, 1240, 80)];
        $pace = new PaceModel($laps, new Settings());

        $this->assertSame(0.8, $pace->carFactorAt(50, 1500000));
        $this->assertSame(1.0, $pace->carFactorAt(50, 1200000), 'Back when only two laps were finished.');
        $this->assertSame(100000, $pace->expectedLapMs(50, 1200000));
        $this->assertSame(0.8, $pace->carFactorAt(50, 1500000));
        $this->assertSame(0.8, $pace->carFactorAt(50, 1240000), 'A lap that ended exactly at the moment asked counts.');
    }

    /**
     * SITUATION: early in a race (only three laps completed by the field)
     * the model is asked twice what lap to expect of car 1 at the same
     * moment, as the engine does (once for flags, once for best laps).
     *
     * EXPECTED: "unknown" both times. An answer that is remembered must
     * come back exactly as it was given the first time, unknown included.
     */
    public function testAnUnknownPaceIsStillUnknownWhenAskedAgain(): void
    {
        $laps = [];
        for ($car = 1; $car <= 3; $car++) {
            $laps[$car][] = $this->lap($car, 100 + $car, 100);
        }
        $pace = new PaceModel($laps, new Settings());

        $this->assertNull($pace->expectedLapMs(1, 180000));
        $this->assertNull($pace->expectedLapMs(1, 180000));
    }
}
