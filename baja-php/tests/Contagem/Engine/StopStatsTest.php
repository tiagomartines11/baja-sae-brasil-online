<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Engine\StopStats;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * The numbers about pit lane and fueling station stops (`StopStats`).
 *
 * The public feed for the live stream and a CSV export are built on these,
 * so each number is pinned down here:
 *
 *   dentro     is the car inside the area right now?
 *   desde_ms   since when (null when it is not inside)
 *   paradas    how many stays it has COMPLETED (in and out again)
 *   total_ms   all the time it spent inside, including the stay under way
 *   ultima_ms  how long its most recent completed stay lasted
 *
 * The pit lane ("box") and the fueling station ("abastecimento") are
 * counted separately. Each has somebody logging entries and somebody
 * logging exits, usually two people on two phones, so the two halves of a
 * stay can arrive at different times, and one of them can be missing.
 */
final class StopStatsTest extends EngineTestCase
{
    /** What forCar() says about a car that never stopped. */
    private const NOTHING = ['dentro' => false, 'desde_ms' => null, 'paradas' => 0, 'total_ms' => 0, 'ultima_ms' => null];

    private function timelineOf(RaceScenario $race): RaceTimeline
    {
        return $race->run('59:00')->timeline;
    }

    /** @return array{dentro: bool, desde_ms: ?int, paradas: int, total_ms: int, ultima_ms: ?int} */
    private function pit(RaceTimeline $timeline, int $car, string $now): array
    {
        return StopStats::forCar($timeline, StopStats::BOX, $car, RaceScenario::ms($now));
    }

    /** @return array{dentro: bool, desde_ms: ?int, paradas: int, total_ms: int, ultima_ms: ?int} */
    private function fuel(RaceTimeline $timeline, int $car, string $now): array
    {
        return StopStats::forCar($timeline, StopStats::ABASTECIMENTO, $car, RaceScenario::ms($now));
    }

    /**
     * SITUATION: car 7 is in the pit lane from 10:00 to 12:00 (two
     * minutes). We ask at several moments.
     *
     * EXPECTED:
     * - before it went in: nothing, exactly as for a car that never stops
     *   (an event that has not happened yet at the moment asked about does
     *   not exist);
     * - at 10:00 sharp: inside since 10:00, no time spent yet;
     * - at 11:00: inside since 10:00, one minute spent so far, and still
     *   NO completed stay;
     * - at 12:00 sharp, the instant it leaves: no longer inside; one
     *   completed stay of 120 s;
     * - later: the same.
     */
    public function testOneStayBeforeDuringAndAfter(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitIn(7, '10:00');
        $race->pitOut(7, '12:00');
        $timeline = $this->timelineOf($race);
        $inside = fn(int $spentMs) => ['dentro' => true, 'desde_ms' => RaceScenario::ms('10:00'), 'paradas' => 0, 'total_ms' => $spentMs, 'ultima_ms' => null];
        $done = ['dentro' => false, 'desde_ms' => null, 'paradas' => 1, 'total_ms' => 120000, 'ultima_ms' => 120000];

        $this->assertSame(self::NOTHING, $this->pit($timeline, 7, '09:59.999'));
        $this->assertSame($inside(0), $this->pit($timeline, 7, '10:00'));
        $this->assertSame($inside(60000), $this->pit($timeline, 7, '11:00'));
        $this->assertSame($inside(119999), $this->pit($timeline, 7, '11:59.999'));
        $this->assertSame($done, $this->pit($timeline, 7, '12:00'));
        $this->assertSame($done, $this->pit($timeline, 7, '30:00'));
        $this->assertSame(self::NOTHING, $this->pit($timeline, 8, '30:00'), 'Another car is not affected.');
    }

    /**
     * SITUATION: car 7 stops three times in the pit lane: 10:00-12:00 (120
     * s), 20:00-21:30 (90 s), and from 30:00, still inside. We ask at 31:00.
     *
     * EXPECTED: inside since 30:00; two completed stays; total time 120 +
     * 90 + the 60 s of the stay under way = 270 s; the last COMPLETED stay
     * lasted 90 s (not 120, the first; not 60, the unfinished one).
     */
    public function testSeveralStaysAndOneUnderWay(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitIn(7, '10:00');
        $race->pitOut(7, '12:00');
        $race->pitIn(7, '20:00');
        $race->pitOut(7, '21:30');
        $race->pitIn(7, '30:00');
        $timeline = $this->timelineOf($race);

        $this->assertSame(
            ['dentro' => true, 'desde_ms' => RaceScenario::ms('30:00'), 'paradas' => 2, 'total_ms' => 270000, 'ultima_ms' => 90000],
            $this->pit($timeline, 7, '31:00')
        );
        $this->assertSame(
            ['dentro' => false, 'desde_ms' => null, 'paradas' => 2, 'total_ms' => 210000, 'ultima_ms' => 90000],
            $this->pit($timeline, 7, '25:00'),
            'Asked about minute 25, the third stay has not begun.'
        );
        $this->assertSame(
            ['dentro' => false, 'desde_ms' => null, 'paradas' => 1, 'total_ms' => 120000, 'ultima_ms' => 120000],
            $this->pit($timeline, 7, '15:00')
        );
    }

    /**
     * SITUATION: car 7 is in the pit lane 10:00-12:00 and in the fueling
     * station 11:00-11:30 and again from 20:00 on. We ask at 21:00.
     *
     * EXPECTED: each area has its own numbers and neither leaks into the
     * other. Pit lane: one stay of 120 s, not inside. Fueling: inside since
     * 20:00, one completed stay of 30 s, 30 + 60 = 90 s in total.
     */
    public function testThePitLaneAndTheFuelingStationAreCountedSeparately(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitIn(7, '10:00');
        $race->fuelIn(7, '11:00');
        $race->fuelOut(7, '11:30');
        $race->pitOut(7, '12:00');
        $race->fuelIn(7, '20:00');
        $timeline = $this->timelineOf($race);

        $this->assertSame(
            ['dentro' => false, 'desde_ms' => null, 'paradas' => 1, 'total_ms' => 120000, 'ultima_ms' => 120000],
            $this->pit($timeline, 7, '21:00')
        );
        $this->assertSame(
            ['dentro' => true, 'desde_ms' => RaceScenario::ms('20:00'), 'paradas' => 1, 'total_ms' => 90000, 'ultima_ms' => 30000],
            $this->fuel($timeline, 7, '21:00')
        );
        $this->assertSame(['box', 'abastecimento'], [StopStats::BOX, StopStats::ABASTECIMENTO]);
        $this->assertSame([StopStats::BOX, StopStats::ABASTECIMENTO], StopStats::AREAS);
    }

    /**
     * SITUATION: the person at the pit exit logs car 7 leaving at 12:00,
     * but nobody logged it going in (the phone at the entrance was offline).
     *
     * WHAT WENT WRONG: an exit with no entry. Half a stay.
     *
     * EXPECTED: an unpaired event is not a stay: no completed stay, no time
     * inside, the car is not "inside". Then the entrance phone reconnects
     * and uploads the entry it had recorded at 10:00: now both ends exist
     * and it is one completed stay of 120 s, as if nothing had gone wrong.
     */
    public function testAnExitWithNoEntryCountsOnlyOnceTheEntryArrives(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitOut(7, '12:00');
        $this->assertSame(self::NOTHING, $this->pit($this->timelineOf($race), 7, '15:00'));

        $race->pitIn(7, '10:00');

        $this->assertSame(
            ['dentro' => false, 'desde_ms' => null, 'paradas' => 1, 'total_ms' => 120000, 'ultima_ms' => 120000],
            $this->pit($this->timelineOf($race), 7, '15:00')
        );
    }

    /**
     * SITUATION: car 7 is logged INTO the fueling station at 10:00 and
     * again at 10:40 (somebody tapped twice, or the exit in between was
     * missed), and out at 12:00.
     *
     * WHAT WENT WRONG: a second entry with no exit before it. A car that
     * enters again must have left in between; if the FIRST entry kept the
     * stay open, one missed exit would make a stay that lasts for hours.
     *
     * EXPECTED: the OLDER entry is the unpaired event and adds nothing;
     * the stay runs from the second entry to the exit: one stay, 80 s. With
     * no exit at all the car is inside since the SECOND entry.
     */
    public function testWhenACarEntersTwiceTheOlderEntryIsTheOneLeftWithoutAnExit(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $first = $race->fuelIn(7, '10:00');
        $race->fuelIn(7, '10:40');
        $this->assertSame(
            ['dentro' => true, 'desde_ms' => RaceScenario::ms('10:40'), 'paradas' => 0, 'total_ms' => 20000, 'ultima_ms' => null],
            $this->fuel($this->timelineOf($race), 7, '11:00')
        );
        $this->assertSame([$first], array_column($this->timelineOf($race)->unpairedStops, 'ocorrencia_id'));

        $race->fuelOut(7, '12:00');
        $this->assertSame(
            ['dentro' => false, 'desde_ms' => null, 'paradas' => 1, 'total_ms' => 80000, 'ultima_ms' => 80000],
            $this->fuel($this->timelineOf($race), 7, '15:00')
        );
    }

    /**
     * SITUATION: car 7 enters the pit lane at 10:00 and nobody logs it
     * out. The race ends at 30:00. Car 8 enters the pit lane at 31:00,
     * after the finish.
     *
     * EXPECTED: a car still inside when the race ends is taken to have
     * left at the finish: car 7 has one completed stay of twenty minutes,
     * and its total no longer grows. Before the finish is recorded it is
     * simply inside. An entry AFTER the finish stays open (there is no
     * later moment to close it at).
     */
    public function testAStayStillOpenWhenTheRaceEndsIsClosedAtTheFinish(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitIn(7, '10:00');
        $this->assertSame(
            ['dentro' => true, 'desde_ms' => RaceScenario::ms('10:00'), 'paradas' => 0, 'total_ms' => 25 * 60000, 'ultima_ms' => null],
            $this->pit($this->timelineOf($race), 7, '35:00')
        );

        $race->finish('30:00');
        $race->pitIn(8, '31:00');
        $timeline = $this->timelineOf($race);

        $this->assertSame(
            ['dentro' => false, 'desde_ms' => null, 'paradas' => 1, 'total_ms' => 20 * 60000, 'ultima_ms' => 20 * 60000],
            $this->pit($timeline, 7, '35:00')
        );
        $this->assertSame([[RaceScenario::ms('10:00'), RaceScenario::ms('30:00')]], $timeline->pitIntervals[7]);
        $this->assertSame([[RaceScenario::ms('31:00'), null]], $timeline->pitIntervals[8]);
        $this->assertSame([], $timeline->unpairedStops);
    }

    /**
     * SITUATION: the phone at the pit exit could not take its time from
     * the server: car 7's exit is stored with whatever its own clock said
     * (here 10:30), marked as of unknown time. The entry at 10:00 is fine.
     *
     * WHAT COULD GO WRONG: pairing the two would invent a 30-second stay,
     * when nobody knows when the car left.
     *
     * EXPECTED: the event of unknown time pairs with nothing. It is listed
     * in `unknownTimeStops` for the review lanes, and is neither an
     * unpaired event nor one of the recent events the feed announces. Car
     * 7 is still inside, since 10:00.
     */
    public function testAStopEventOfUnknownTimeIsListedApartAndPairsWithNothing(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $entry = $race->pitIn(7, '10:00');
        $unknown = $race->stopEventOfUnknownTime(\Baja\Contagem\Engine\Data\Ocorrencia::BOX_SAIDA, 7, '10:30');
        $timeline = $this->timelineOf($race);

        $this->assertSame(
            [['area' => 'box', 'tipo' => 'box_saida', 'carro' => 7, 'ts_ms' => RaceScenario::ms('10:30'), 'ocorrencia_id' => $unknown, 'inferida' => false]],
            $timeline->unknownTimeStops
        );
        $this->assertSame([], $timeline->unpairedStops);
        $this->assertSame([$entry], array_column($timeline->stopEvents, 'ocorrencia_id'));
        $this->assertSame([[RaceScenario::ms('10:00'), null]], $timeline->pitIntervals[7]);
        $this->assertTrue($this->pit($timeline, 7, '15:00')['dentro']);
    }

    /**
     * SITUATION: an overlay wants to announce "car 12 enters the pit lane".
     * The log holds, in the order they happened: car 7 into the pit lane
     * (10:00), car 12 into fueling (10:30), car 7 out of the pit lane
     * (12:00), car 12 out of fueling (12:10), car 5 out of the pit lane
     * with no entry (12:20, unpaired), car 3 into the pit lane (13:00).
     *
     * EXPECTED: `recentEvents` gives the latest N across both areas, newest
     * first, each with exactly four fields: area, type, car, time. Unpaired
     * events are included: they did happen. Asking for more than there are
     * gives them all; asking for none gives none.
     */
    public function testTheLatestEventsAcrossBothAreasNewestFirst(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pitIn(7, '10:00');
        $race->fuelIn(12, '10:30');
        $race->pitOut(7, '12:00');
        $race->fuelOut(12, '12:10');
        $race->pitOut(5, '12:20');
        $race->pitIn(3, '13:00');
        $timeline = $this->timelineOf($race);

        $this->assertSame(
            [
                ['area' => 'box', 'tipo' => Ocorrencia::BOX_ENTRADA, 'carro' => 3, 'ts_ms' => RaceScenario::ms('13:00'), 'inferida' => false, 'presumida' => false],
                ['area' => 'box', 'tipo' => Ocorrencia::BOX_SAIDA, 'carro' => 5, 'ts_ms' => RaceScenario::ms('12:20'), 'inferida' => false, 'presumida' => false],
                ['area' => 'abastecimento', 'tipo' => Ocorrencia::ABASTECIMENTO_SAIDA, 'carro' => 12, 'ts_ms' => RaceScenario::ms('12:10'), 'inferida' => false, 'presumida' => false],
            ],
            StopStats::recentEvents($timeline, 3)
        );
        $all = StopStats::recentEvents($timeline, 10);
        $this->assertSame([3, 5, 12, 7, 12, 7], array_column($all, 'carro'));
        $this->assertSame(['box', 'box', 'abastecimento', 'box', 'abastecimento', 'box'], array_column($all, 'area'));
        $this->assertSame([Ocorrencia::BOX_ENTRADA], array_column(StopStats::recentEvents($timeline, 1), 'tipo'));
        $this->assertSame([], StopStats::recentEvents($timeline, 0));
        $this->assertSame([], StopStats::recentEvents($timeline, -1));
    }

    /**
     * SITUATION: two events carry the very same time (car 7 into the pit
     * lane and car 8 into fueling, both at 10:00.000), a pit entry of car 9
     * was cancelled by race control, and a pit entry of car 4 logged at
     * 11:00 was corrected to 10:30. The uploads arrived in a jumbled order.
     *
     * EXPECTED: events are ordered by their time, not by when they were
     * uploaded; of two at the same millisecond the one recorded later is
     * "newer"; a cancelled event is not announced; a corrected one appears
     * once, at its corrected time.
     */
    public function testRecentEventsFollowTimeAndIgnoreCancelledRows(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $wrongTime = $race->pitIn(4, '11:00');
        $cancelled = $race->pitIn(9, '10:45');
        $race->pitIn(7, '10:00');
        $race->fuelIn(8, '10:00');
        $race->correct($wrongTime, '10:30');
        $race->cancel($cancelled);

        $recent = StopStats::recentEvents($this->timelineOf($race), 10);

        $this->assertSame([4, 8, 7], array_column($recent, 'carro'));
        $this->assertSame(RaceScenario::ms('10:30'), $recent[0]['ts_ms']);
    }

    /**
     * SITUATION: a race with no pit or fueling log at all.
     *
     * EXPECTED: nothing for every car in both areas, and no events.
     */
    public function testARaceWithNoStops(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->pass(7, '03:00');
        $timeline = $this->timelineOf($race);

        $this->assertSame(self::NOTHING, $this->pit($timeline, 7, '10:00'));
        $this->assertSame(self::NOTHING, $this->fuel($timeline, 7, '10:00'));
        $this->assertSame([], StopStats::recentEvents($timeline, 10));
    }

    /**
     * SITUATION: car 7 enters the pit lane at 10:00; car 8 is logged OUT at
     * 10:30 with no entry; car 7 enters again at 11:00 (its exit was
     * missed, which is only noticed now).
     *
     * EXPECTED: the events without a partner are listed in time order: car
     * 7's first entry (10:00), then car 8's exit (10:30).
     */
    public function testEventsWithoutAPartnerAreListedInTimeOrder(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $firstEntry = $race->pitIn(7, '10:00');
        $exit = $race->pitOut(8, '10:30');
        $race->pitIn(7, '11:00');

        $this->assertSame([$firstEntry, $exit], array_column($this->timelineOf($race)->unpairedStops, 'ocorrencia_id'));
    }

    /**
     * Car 9 laps every three minutes, is logged INTO the pit lane at 13:00
     * and never out, then is recorded by everybody at 16:00 and 19:00: the
     * engine assumes it left at 15:59 (see HoldRuleTest). Car 4 enters the
     * pit lane at 17:00.
     */
    private function raceWithAnAssumedExit(): RaceScenario
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        foreach (['00:20', '03:20', '06:20', '09:20', '12:20'] as $crossing) {
            $race->pass(9, $crossing);
        }
        $race->pitIn(9, '13:00');
        $race->pass(9, '16:00');
        $race->pass(9, '19:00');
        $race->pitIn(4, '17:00');
        return $race;
    }

    /**
     * SITUATION: the race above. An overlay shows the latest entries and
     * exits ("últimas paradas").
     *
     * WHAT WENT WRONG (before): the exit the engine assumed is stored
     * nowhere, so the list showed car 9 going in and never coming out,
     * while its laps kept counting.
     *
     * EXPECTED: the assumed exit is listed as an exit from the pit lane at
     * its assumed time, 15:59, in time order with the logged events (after
     * car 9's entry, before car 4's), marked `presumida`. It is not
     * `inferida` (no reviewer recorded it). Every logged event says
     * `presumida` false. The log itself (`stopEvents`) is untouched: it
     * holds the two entries and nothing else.
     */
    public function testAnExitTheEngineAssumedIsListedAmongTheLatestEventsMarkedAsPresumed(): void
    {
        $result = $this->raceWithAnAssumedExit()->run('20:00');

        $this->assertSame(
            [
                ['area' => 'box', 'tipo' => Ocorrencia::BOX_ENTRADA, 'carro' => 4, 'ts_ms' => RaceScenario::ms('17:00'), 'inferida' => false, 'presumida' => false],
                ['area' => 'box', 'tipo' => Ocorrencia::BOX_SAIDA, 'carro' => 9, 'ts_ms' => RaceScenario::ms('15:59'), 'inferida' => false, 'presumida' => true],
                ['area' => 'box', 'tipo' => Ocorrencia::BOX_ENTRADA, 'carro' => 9, 'ts_ms' => RaceScenario::ms('13:00'), 'inferida' => false, 'presumida' => false],
            ],
            StopStats::recentEvents($result->timeline, 10)
        );
        $this->assertSame([4, 9], array_column(StopStats::recentEvents($result->timeline, 2), 'carro'), 'The limit counts the assumed exit like any event.');
        $this->assertSame([Ocorrencia::BOX_ENTRADA, Ocorrencia::BOX_ENTRADA], array_column($result->timeline->stopEvents, 'tipo'));
    }

    /**
     * SITUATION: the same race, after a reviewer pressed "Confirmar
     * saída": a real exit, attributed to the reviewer, is now in the log
     * at the assumed time (15:59).
     *
     * EXPECTED: the car is outside by the log from then on, so the engine
     * assumes nothing any more. The exit is listed ONCE, as `inferida` and
     * not `presumida`. The count is the same as before the confirmation.
     */
    public function testOnceTheExitIsConfirmedItIsListedOnceAsInferred(): void
    {
        $race = $this->raceWithAnAssumedExit();
        $lapsBefore = $race->run('20:00')->lapCount(9);
        $race->inferredExit('box', 9, RaceScenario::ms('15:59'));

        $result = $race->run('20:00');

        $this->assertSame([], $result->timeline->assumedExits);
        $exitsOfCar9 = array_values(array_filter(
            StopStats::recentEvents($result->timeline, 10),
            fn(array $event) => $event['carro'] === 9 && $event['tipo'] === Ocorrencia::BOX_SAIDA
        ));
        $this->assertSame(
            [['area' => 'box', 'tipo' => Ocorrencia::BOX_SAIDA, 'carro' => 9, 'ts_ms' => RaceScenario::ms('15:59'), 'inferida' => true, 'presumida' => false]],
            $exitsOfCar9
        );
        $this->assertSame($lapsBefore, $result->lapCount(9));
    }

    /**
     * SITUATION: the same race, but the person at the pit exit did log car
     * 9 leaving, late: at 20:30, after the two passes that made the engine
     * assume it had left at 15:59.
     *
     * EXPECTED: the count still goes by the assumed exit (the passes at
     * 16:00 and 19:00 count, and the stay ends at 15:59). But the list
     * shows ONE exit for that stay, the logged one: once the gate has
     * recorded the car leaving, that event is the record, and an overlay
     * must not announce the same car leaving twice.
     */
    public function testWhenTheLogHasALateExitForThatStayOnlyTheLoggedOneIsListed(): void
    {
        $race = $this->raceWithAnAssumedExit();
        $race->pitOut(9, '20:30');

        $result = $race->run('21:00');

        $this->assertSame(6, $result->lapCount(9));
        $this->assertSame(RaceScenario::ms('20:30'), $result->timeline->assumedExits[0]['saida_registrada_ms']);
        $exitsOfCar9 = array_values(array_filter(
            StopStats::recentEvents($result->timeline, 10),
            fn(array $event) => $event['carro'] === 9 && $event['tipo'] === Ocorrencia::BOX_SAIDA
        ));
        $this->assertSame([[RaceScenario::ms('20:30'), false]], array_map(fn(array $event) => [$event['ts_ms'], $event['presumida']], $exitsOfCar9));
    }
}
