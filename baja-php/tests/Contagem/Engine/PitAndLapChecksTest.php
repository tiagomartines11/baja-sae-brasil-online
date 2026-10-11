<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Source;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Flags that are about a counted lap looking wrong, rather than about
 * counters disagreeing: a pass while the car was in the pit lane, a lap far
 * too short, and paper totals that do not match.
 *
 * None of these has a suggested action. The engine cannot know which record
 * is wrong; it only points.
 */
final class PitAndLapChecksTest extends EngineTestCase
{
    /**
     * SITUATION: the pit log says car 6 entered the pit lane at minute 5 and
     * left at minute 9. At minute 7 all three counters record car 6 at the
     * counting line.
     *
     * WHAT WENT WRONG: one of the two records is wrong. Either the counters
     * mistook another car for car 6, or the pit log has the wrong car or
     * time.
     *
     * EXPECTED: the lap counts (three counters agree), but a "no_box" flag
     * marks it as unreliable, and the laps touching that pass cannot be a
     * "best lap".
     */
    public function testAPassWhileTheCarIsLoggedInThePitLaneIsFlaggedAsUnreliable(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->pitIn(6, '05:00');
        $race->pass(6, '07:00');
        $race->pitOut(6, '09:00');

        $result = $race->run('10:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::NO_BOX);
        $this->assertNull($flag->sugestao);
        $this->assertSame([6], $flag->carros);
        $this->assertLapCounts([6 => 2], $result);
        $this->assertFalse($result->laps[1]->elegivelMelhorVolta);
    }

    /**
     * SITUATION: the pit log shows car 6 in the pit lane from minute 5 to
     * minute 9, and the counters record it at minutes 3 and 12 only.
     *
     * NOTHING WENT WRONG: a normal pit stop.
     *
     * EXPECTED: no flag. The long lap that contains the stop is counted but
     * marked as interrupted, so it is not used to judge the pace of the race
     * and cannot be a best lap.
     */
    public function testAnOrdinaryPitStopRaisesNoFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(6, '03:00');
        $race->pitIn(6, '05:00');
        $race->pitOut(6, '09:00');
        $race->pass(6, '12:00');

        $result = $race->run('13:00');

        $this->assertNoOpenFlags($result);
        $this->assertTrue($result->laps[1]->interrompida);
    }

    /**
     * SITUATION: a steady race, ten cars lapping every three minutes. All
     * three counters record car 5 at 12:28 (correct) and all three record it
     * again at 13:10. In reality that second car was number 15, with a
     * muddy number plate.
     *
     * WHAT WENT WRONG: every counter read the same wrong number, so they
     * agree with each other and the pass counts.
     *
     * EXPECTED: agreement alone cannot hide it: a "volta_curta" flag says
     * that a 42-second lap is not believable for a car that laps in about
     * 180 seconds. No suggestion; the reviewer decides which entries to
     * reassign.
     */
    public function testACountedLapFarShorterThanTheCarsUsualLapIsFlagged(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        $race->pass(5, '13:10');

        $result = $race->run('20:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::VOLTA_CURTA);
        $this->assertNull($flag->sugestao);
        $this->assertSame([5], $flag->carros);
        $this->assertStringContainsString('42 s', $flag->motivo);
        $this->assertSame(7, $result->lapCount(5), 'It stays counted until a reviewer acts.');
    }

    /**
     * SITUATION: the same short lap, but the reviewer looked at it and
     * confirmed it is real (the car took a shortcut and will be penalised
     * elsewhere, say).
     *
     * EXPECTED: confirming the pass silences the flag for good.
     */
    public function testConfirmingAShortLapSilencesItsFlag(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->steadyField([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 180, 6);
        $entries = $race->pass(5, '13:10');
        $race->confirm($entries[1]);

        $this->assertNoOpenFlags($race->run('20:00'));
    }

    /**
     * SITUATION: after the race somebody types in the totals of a paper
     * count: car 1 with 3 laps, car 2 with 2 laps. They go into a source of
     * type "totais". The official count has 2 laps for each car.
     *
     * EXPECTED: a "divergencia_total" flag for car 1 (3 on paper, 2
     * official) and none for car 2. Imported totals never change the
     * official count; they are only compared with it.
     */
    public function testImportedPaperTotalsThatDisagreeWithTheOfficialCountAreFlagged(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->addSource(9, 'Planilha de papel', Source::TIPO_TOTAIS);
        $race->start('00:00');
        foreach ([1, 2] as $car) {
            $race->pass($car, '03:0' . $car);
            $race->pass($car, '06:0' . $car);
        }
        $race->importTotals(9, [1 => 3, 2 => 2]);

        $result = $race->run('10:00');

        $flag = $this->assertSingleOpenFlag($result, Flag::DIVERGENCIA_TOTAL);
        $this->assertSame([1], $flag->carros);
        $this->assertSame(9, $flag->fonteId);
        $this->assertStringContainsString('Planilha de papel', $flag->motivo);
        $this->assertLapCounts([1 => 2, 2 => 2], $result);
    }

    /**
     * SITUATION: two flags are open. One concerns the leader of the race,
     * the other a car at the back.
     *
     * EXPECTED: the leader's flag is first in the queue. A mistake near the
     * top of the standings changes the podium; one at the back rarely
     * changes anything.
     */
    public function testFlagsAboutCarsNearTheTopOfTheStandingsComeFirst(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        // Car 1 leads with three laps; car 2 has one.
        $race->pass(1, '03:00');
        $race->pass(1, '06:00');
        $race->pass(1, '09:00');
        $race->pass(2, '04:00');
        // A lone entry for the backmarker first, then one for the leader.
        $race->entry(3, 2, '07:00');
        $race->entry(3, 1, '12:00');

        $flags = $race->run('13:00')->openFlags();

        $this->assertCount(2, $flags);
        $this->assertSame([1], $flags[0]->carros, 'The leader\'s flag is on top although it is the newer one.');
        $this->assertSame([2], $flags[1]->carros);
    }
}
