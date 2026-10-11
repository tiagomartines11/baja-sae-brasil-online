<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Which laps may be announced as a "best lap".
 *
 * A best lap is read out on the loudspeakers and can decide a prize, so a
 * lap only qualifies when nothing at all is doubtful about it. Each of the
 * following disqualifies a lap ON ITS OWN:
 *
 *   - one of its two ends is not well timed          (LapTimeTest)
 *   - it overlaps a red flag or a pit stay           (RaceControlTest, PitAndLapChecksTest)
 *   - it is measured from the start time             (PassZeroTest)
 *   - one of its two ends has an open review flag    (here)
 *   - it is too short to be a lap of the track       (here)
 */
final class BestLapTest extends EngineTestCase
{
    /**
     * SITUATION: five cars cross for the first time around 00:30 and lap
     * every 180 seconds. Car 9's first recorded pass is at 03:35, a whole
     * lap after everybody's, so that pass carries an open flag ("primeira
     * passagem tardia"). Car 9 then laps in 170 s and 180 s, perfectly
     * recorded by all three counters.
     *
     * EXPECTED: lap 1 of car 9 STARTS at the flagged pass, and that alone
     * disqualifies it, although nothing else is wrong with it (well timed,
     * not interrupted, not short). Lap 2 touches no flagged pass and
     * qualifies, so the car's best lap is 180 s, not 170 s.
     *
     * Once the reviewer confirms the flagged pass, lap 1 qualifies and the
     * best lap becomes 170 s.
     */
    public function testAnOpenFlagOnEitherEndDisqualifiesALapOnItsOwn(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5], 180, 3, '00:30', 2);
        $flaggedPass = $race->pass(9, '03:35');
        $race->pass(9, '06:25');
        $race->pass(9, '09:25');

        $before = $race->run('10:00');
        $this->assertSingleOpenFlag($before, Flag::PRIMEIRA_PASSAGEM_TARDIA);
        [$lapOne, $lapTwo] = $this->countedLapsOf($before->laps, 9);
        $this->assertSame(170000, $lapOne->tempoVoltaMs);
        $this->assertFalse($lapOne->interrompida);
        $this->assertSame(0, $lapOne->dispersaoMs);
        $this->assertFalse($lapOne->elegivelMelhorVolta, 'It starts at a pass with an open flag.');
        $this->assertTrue($lapTwo->elegivelMelhorVolta);
        $this->assertSame(180000, $before->cars[9]->melhorVoltaMs);

        $race->confirm($flaggedPass[1]);
        $after = $race->run('10:00');
        $this->assertTrue($this->countedLapsOf($after->laps, 9)[0]->elegivelMelhorVolta);
        $this->assertSame(170000, $after->cars[9]->melhorVoltaMs);
    }

    /**
     * SITUATION: a number that is no team at this event, 77, is recorded by
     * all three counters three times, 180 seconds apart. Each pass carries
     * an open "carro_desconhecido" flag, except the first, which a reviewer
     * confirmed.
     *
     * EXPECTED: the laps count (three counters agree) but none qualifies.
     * Lap 1 starts at the confirmed pass, which is fine, but ENDS at a
     * flagged one, and that alone is enough.
     */
    public function testALapEndingAtAFlaggedPassDoesNotQualifyEither(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([7]);
        $race->start('00:00');
        $firstPass = $race->pass(77, '00:20');
        $race->confirm($firstPass[1]);
        $race->pass(77, '03:20');
        $race->pass(77, '06:20');

        $result = $race->run('07:00');

        $this->assertLapCounts([77 => 2], $result);
        $this->assertCount(2, $this->openFlags($result, Flag::CARRO_DESCONHECIDO));
        foreach ($this->countedLapsOf($result->laps, 77) as $lap) {
            $this->assertFalse($lap->elegivelMelhorVolta);
        }
        $this->assertNull($result->cars[77]->melhorVoltaMs);
    }

    /**
     * SITUATION: a steady race where everybody laps in 180 seconds. All
     * three counters record car 5 again 42 seconds after a pass, and a
     * reviewer CONFIRMS that pass (the car cut the track and will be
     * penalised elsewhere, say). So the 42-second lap is counted and has no
     * open flag.
     *
     * EXPECTED: it still cannot be the best lap. Being too short to be a
     * lap of the track (under half the expected lap) disqualifies it on
     * its own. Car 5's best lap is not 42 s.
     */
    public function testAConfirmedLapThatIsTooShortForThePaceIsStillNotABestLap(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        $shortcut = $race->pass(5, '13:10');
        $race->confirm($shortcut[1]);

        $result = $race->run('20:00');

        $this->assertNoOpenFlags($result);
        $shortLap = $result->groupOfEntry($shortcut[1]);
        $lapsOfCar5 = $this->countedLapsOf($result->laps, 5);
        $theShortLap = array_values(array_filter($lapsOfCar5, fn(Lap $lap) => $lap->grupo === $shortLap))[0];
        $this->assertSame(42000, $theShortLap->tempoVoltaMs);
        $this->assertFalse($theShortLap->elegivelMelhorVolta);
        $this->assertGreaterThan(42000, $result->cars[5]->melhorVoltaMs);
    }

    /**
     * SITUATION: the first minutes of a race; the pace is not known yet.
     * Car 7 crosses at 00:20 and 03:20, and all three counters record it
     * again at 03:50, thirty seconds later. A reviewer confirms that pass,
     * which silences its "volta_curta" flag. The car then crosses at 06:50.
     *
     * EXPECTED: a 30-second "lap" must never become the best lap of the
     * race just because nobody knows the pace yet. Under two matching
     * windows (40 s) a lap does not qualify, whatever the pace. The best
     * lap of car 7 is 180 s.
     */
    public function testAConfirmedLapShorterThanTwoMatchingWindowsIsNotABestLapEvenWithoutAPace(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->pass(7, '03:20');
        $tooSoon = $race->pass(7, '03:50');
        $race->confirm($tooSoon[1]);
        $race->pass(7, '06:50');

        $result = $race->run('08:00');

        $this->assertNoOpenFlags($result);
        $this->assertNull($result->cars[7]->voltaEsperadaMs);
        $this->assertSame([180000, 30000, 180000], array_map(fn(Lap $lap) => $lap->tempoVoltaMs, $this->countedLapsOf($result->laps, 7)));
        $this->assertSame([true, false, true], array_map(fn(Lap $lap) => $lap->elegivelMelhorVolta, $this->countedLapsOf($result->laps, 7)));
        $this->assertSame(180000, $result->cars[7]->melhorVoltaMs);
    }

    /**
     * @param Lap[] $laps
     * @return Lap[] the counted laps of one car, in order (pass zero is not a lap)
     */
    private function countedLapsOf(array $laps, int $car): array
    {
        return array_values(array_filter($laps, fn(Lap $lap) => $lap->carro === $car && $lap->isCounted()));
    }

    /**
     * SITUATION: car 7 crosses four times, 180 seconds apart. The third
     * crossing (06:20) was recorded by counter 1 only; after a protest the
     * reviewer adds a pass for car 7 at that same moment. That pass group
     * now holds two entries: counter 1's and the reviewer's.
     *
     * EXPECTED: it counts, of course. But "two entries" is not "two
     * counters agree on the time": the reviewer was not at the line with a
     * finger on Enter. The crossing rests on one counter's timing, so the
     * laps ending and starting there do not qualify; lap 1 does.
     */
    public function testAReviewersAddedPassDoesNotMakeALoneEntryWellTimed(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '00:20');
        $race->pass(7, '03:20');
        $lone = $race->entry(1, 7, '06:20');
        $race->addPass(7, '06:20');
        $race->pass(7, '09:20');

        $result = $race->run('10:00');

        $this->assertNoOpenFlags($result);
        $this->assertCount(2, $result->groupOfEntry($lone)->entries);
        $this->assertLapCounts([7 => 3], $result);
        $this->assertSame([true, false, false], array_map(fn(Lap $lap) => $lap->elegivelMelhorVolta, $this->countedLapsOf($result->laps, 7)));
    }
}
