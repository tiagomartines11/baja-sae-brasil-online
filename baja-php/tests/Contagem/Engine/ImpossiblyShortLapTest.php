<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * "Too short to be a lap."
 *
 * Two passes of the same car can be too close together for the car to have
 * driven round the track in between. There are two yardsticks:
 *
 * 1. The pace: less than `fracao_volta_minima` (half, by default) of the lap
 *    expected of that car. This needs the engine to know the pace of the
 *    race, which takes eight completed laps.
 * 2. The matching window: less than two windows (40 seconds), whatever the
 *    pace. Entries up to 20 seconds apart are "the same crossing", so two
 *    passes less than 40 seconds apart are far more likely one crossing
 *    recorded twice than two laps. This works from the first minute.
 *
 * A counted lap that is too short gets a "volta_curta" flag. A lone entry
 * that WOULD create one gets the suggestion to void it.
 */
final class ImpossiblyShortLapTest extends EngineTestCase
{
    /**
     * SITUATION: four counters, second minute of the race, pace still
     * unknown. Car 9 crosses the line once. Counters 1 and 2 press Enter at
     * 01:30; counters 3 and 4 were looking elsewhere and press it at 01:51,
     * 21 seconds later, just outside the 20-second matching window.
     *
     * WHAT WENT WRONG: one crossing became two groups of two counters each.
     * Both groups are "agreed", so both count: the car is given a lap it
     * never drove.
     *
     * EXPECTED: agreement cannot hide it. The second pass gets a
     * "volta_curta" flag saying the two passes are 21 s apart. There is no
     * suggestion (the engine cannot tell which pair to believe) and the lap
     * stays counted until a reviewer acts.
     */
    public function testTwoAgreedGroupsForOneCrossingAreFlaggedEvenBeforeThePaceIsKnown(): void
    {
        $race = RaceScenario::withCounters(4);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '01:30', [1, 2]);
        $late = $race->pass(9, '01:51', [3, 4]);

        $result = $race->run('03:00');

        $this->assertNull($result->cars[9]->voltaEsperadaMs, 'The pace is not known yet.');
        $flag = $this->assertSingleOpenFlag($result, Flag::VOLTA_CURTA);
        $this->assertSame([9], $flag->carros);
        $this->assertNull($flag->sugestao);
        $this->assertSame(array_values($late), $flag->passagemIds);
        $this->assertStringContainsString('21 s', $flag->motivo);
        $this->assertLapCounts([9 => 2], $result);

        // The reviewer voids the two late entries: one lap, nothing to review.
        $race->void($late[3]);
        $race->void($late[4]);
        $fixed = $race->run('03:00');
        $this->assertLapCounts([9 => 1], $fixed);
        $this->assertNoOpenFlags($fixed);
    }

    /**
     * SITUATION: pace unknown. Car 9 is recorded by everybody at 01:30 and
     * again exactly 40 seconds later; in a second race, 39.999 s later.
     *
     * EXPECTED: "less than two matching windows" means less than 40.000 s.
     * At exactly 40 s there is no flag; one millisecond less, there is.
     */
    public function testTheLimitWithoutAPaceIsTwoMatchingWindows(): void
    {
        foreach (['02:10' => 0, '02:09.999' => 1] as $secondPassAt => $expectedFlags) {
            $race = RaceScenario::withCounters(3);
            $race->start('00:00');
            $race->firstCrossing([9]);
            $race->pass(9, '01:30');
            $race->pass(9, $secondPassAt);

            $this->assertCount($expectedFlags, $this->openFlags($race->run('03:00'), Flag::VOLTA_CURTA), "Second pass at $secondPassAt.");
        }
    }

    /**
     * SITUATION: pace unknown. Car 9 is recorded by everybody at 01:30.
     * Counter 2 alone types it again 30 seconds later. In a second race the
     * lone entry comes 60 seconds later.
     *
     * EXPECTED: 30 s after a real pass cannot be a lap, so the lone entry
     * comes with the suggestion to void it, and the reason gives the 30 s
     * (and no "expected" lap, which nobody knows yet). At 60 s the engine
     * has no yardstick: the lone entry is flagged without a suggestion.
     */
    public function testALoneEntryRightAfterARealPassIsSuggestedForVoidingEvenBeforeThePaceIsKnown(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([9]);
        $race->pass(9, '01:30');
        $stray = $race->entry(2, 9, '02:00');

        $flag = $this->assertSingleOpenFlag($race->run('03:00'), Flag::SOLITARIA);
        $this->assertSame(['tipo' => Decision::ANULAR, 'passagem_id' => $stray], $flag->sugestao);
        $this->assertStringContainsString('30 s', $flag->motivo);
        $this->assertStringNotContainsString('esperado', $flag->motivo);

        $later = RaceScenario::withCounters(3);
        $later->start('00:00');
        $later->firstCrossing([9]);
        $later->pass(9, '01:30');
        $later->entry(2, 9, '02:30');
        $this->assertNull($this->assertSingleOpenFlag($later->run('03:00'), Flag::SOLITARIA)->sugestao);
    }

    /**
     * SITUATION: a steady race, ten cars lapping every 180 seconds, so the
     * pace is known and "too short" is under half of 180 s = 90 s. Car 5
     * crossed at 12:28; everybody records it again exactly 90 s later (and
     * in a second race, 89.999 s later).
     *
     * EXPECTED: a lap of exactly half the expected lap is not flagged; one
     * millisecond shorter is, and the reason tells the reviewer what was
     * expected (about 180 s).
     */
    public function testTheLimitWithAPaceIsTheConfiguredFractionOfTheExpectedLap(): void
    {
        $onTheLimit = $this->steadyRace();
        $onTheLimit->pass(5, '13:58');
        $this->assertNoOpenFlags($onTheLimit->run('20:00'));

        $justUnder = $this->steadyRace();
        $justUnder->pass(5, '13:57.999');
        $flag = $this->assertSingleOpenFlag($justUnder->run('20:00'), Flag::VOLTA_CURTA);
        $this->assertStringContainsString('cerca de 180 s', $flag->motivo);
    }

    /**
     * SITUATION: the same steady race with a 42-second lap for car 5, under
     * different values of the setting `fracao_volta_minima`.
     *
     * EXPECTED: with the default 0.5 the lap is too short (42 < 90). With
     * 0.2 it is not (42 >= 36), and since 42 s is also more than two
     * matching windows, there is no flag.
     */
    public function testTheFractionIsASetting(): void
    {
        foreach ([[0.5, 1], [0.2, 0]] as [$fraction, $expectedFlags]) {
            $race = $this->steadyRace();
            $race->settings->fracaoVoltaMinima = $fraction;
            $race->pass(5, '13:10');

            $this->assertCount($expectedFlags, $race->run('20:00')->openFlags(), "With fracao_volta_minima = $fraction.");
        }
    }

    /** Ten cars lapping every 180 s; car 5 crosses at 03:28, 06:28, ..., 18:28. */
    private function steadyRace(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        return $race;
    }
}
