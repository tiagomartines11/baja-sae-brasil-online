<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * "Did this counter miss the car, or is the phone just offline?"
 *
 * Event internet is bad. A phone with no signal keeps counting and uploads
 * later. Until it does, the server sees the same thing it would see if the
 * counter had missed every car: nothing. The engine tells the two apart
 * with the "synced through" mark: each device regularly says "my queue is
 * empty as of this moment", and only then is its silence about earlier
 * moments taken as fact.
 *
 * (`firstCrossing` is each car's pass zero, seen by everybody shortly after
 * the start; the passes these tests are about come later.)
 */
final class SourceAvailabilityTest extends EngineTestCase
{
    /**
     * SITUATION: counter 3's phone loses its connection from minute 10 to
     * minute 20. During that time car 41 passes at 14:00; counter 2 types 41
     * and counter 1 makes a typo, 14. Counter 3 did type 41, but that entry
     * is still in the phone.
     *
     * At minute 18 the server has: a lone "14" from counter 1 and a "41"
     * from counter 2 only.
     *
     * EXPECTED AT MINUTE 18: no flags at all for that moment. Whether these
     * entries are a problem depends on what counter 3 recorded, and nobody
     * knows that yet. Neither pass counts yet either (one counter each).
     * Counter 3 is shown as offline on the review screen.
     *
     * EXPECTED AFTER THE PHONE RECONNECTS: car 41's lap counts (counters 2
     * and 3 agree) and the typo flag for counter 1 appears, as it would have
     * if the phone had been online all along.
     */
    public function testNoFlagsAreRaisedForAnIntervalWhileACounterIsOfflineAndTheRightOnesAppearAfterItSyncs(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->teamsPresent([14, 41]);
        $race->start('00:00');
        $race->firstCrossing([41]);
        $race->entry(2, 41, '14:00');
        $race->entry(1, 14, '14:00');

        // Counter 3's device last spoke to the server at minute 10.
        $race->syncedThrough(3, '10:00');
        $race->lastSeen(3, '10:00');

        $whileOffline = $race->run('18:00');

        $this->assertNoOpenFlags($whileOffline, 'Flags that depend on the offline counter must wait.');
        $this->assertLapCounts([41 => 0, 14 => 0], $whileOffline);
        $this->assertTrue($whileOffline->sourceStats[3]->offline, 'Counter 3 is shown as offline.');
        $this->assertFalse($whileOffline->sourceStats[1]->offline);

        // The phone reconnects at minute 20 and uploads what it was holding.
        $race->entry(3, 41, '14:00.5');
        $race->syncedThrough(3, '21:00');
        $race->lastSeen(3, '21:00');

        $afterSync = $race->run('21:00');

        $this->assertLapCounts([41 => 1, 14 => 0], $afterSync);
        $flag = $this->assertSingleOpenFlag($afterSync, Flag::TROCA_PROVAVEL);
        $this->assertSame(41, $flag->sugestao['equipe_numero']);
        $this->assertFalse($afterSync->sourceStats[3]->offline);
    }

    /**
     * SITUATION: the same offline period, and a car that only counter 1
     * recorded at 14:00.
     *
     * EXPECTED: while counter 3 is offline the pass is neither counted nor
     * flagged; it simply waits ("pendente"). Once counter 3 has uploaded and
     * still has nothing for that car, the lone-entry flag appears.
     */
    public function testALoneEntryWaitsQuietlyUntilTheOfflineCounterHasUploaded(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->entry(1, 7, '14:00');
        $race->syncedThrough(3, '10:00');

        $whileOffline = $race->run('18:00');
        $this->assertNoOpenFlags($whileOffline);
        $this->assertSame(Lap::PENDENTE, $whileOffline->laps[0]->estado);

        $race->syncedThrough(3, '21:00');
        $afterSync = $race->run('21:00');
        $this->assertSingleOpenFlag($afterSync, Flag::SOLITARIA);
    }

    /**
     * SITUATION: a counter is online and has uploaded up to 14:10, and a car
     * was recorded by another counter at 14:00.
     *
     * WHY THE EXTRA MARGIN: the counter might type that car a few seconds
     * late. The engine only trusts a counter's silence about a moment once
     * the counter has synced past that moment PLUS the matching window
     * (20 seconds).
     *
     * EXPECTED: at "synced through 14:10" no flag yet; at 14:21, the flag.
     */
    public function testACountersSilenceIsOnlyTrustedOnceItSyncedPastTheMatchingWindow(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->entry(1, 7, '14:00');

        $race->syncedThrough(3, '14:10');
        $this->assertNoOpenFlags($race->run('14:30'));

        $race->syncedThrough(3, '14:21');
        $this->assertSingleOpenFlag($race->run('14:30'), Flag::SOLITARIA);
    }

    /**
     * SITUATION: counter 3 got confused between minutes 10 and 20 (say,
     * they were counting cars at the wrong line). The reviewer excludes
     * counter 3 for that interval. In that interval:
     *
     *   12:00  car 5 recorded by counters 1 and 2
     *   13:00  car 6 recorded by counter 1 only
     *   14:00  car 7 recorded by counter 3 only
     *
     * and outside it, at 25:00, car 8 is recorded by counters 1 and 2.
     *
     * EXPECTED, inside the interval, the rules of a two-counter race:
     * - car 5 counts (both remaining counters agree), and counter 3 is NOT
     *   charged with a miss;
     * - car 6 does not count and is flagged (the other remaining counter is
     *   expected and has nothing);
     * - car 7's entry is ignored completely, as if never typed.
     *
     * EXPECTED outside the interval: back to three counters, so car 8
     * counts and counter 3 is charged with the miss.
     */
    public function testWhileASourceIsExcludedTheRemainingTwoCountersDecide(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([5, 6, 8]);
        $race->pass(5, '12:00', [1, 2]);
        $race->entry(1, 6, '13:00');
        $entryOfExcludedCounter = $race->entry(3, 7, '14:00');
        $race->pass(8, '25:00', [1, 2]);
        $race->excludeSource(3, '10:00', '20:00');

        $result = $race->run('30:00');

        $this->assertLapCounts([5 => 1, 6 => 0, 7 => 0, 8 => 1], $result);
        $flag = $this->assertSingleOpenFlag($result, Flag::SOLITARIA);
        $this->assertSame([6], $flag->carros);
        $this->assertNull($result->groupOfEntry($entryOfExcludedCounter), 'Counter 3\'s entry inside the excluded interval takes no part.');
        $this->assertSame(1, $result->sourceStats[3]->faltas, 'Only the miss at 25:00, outside the interval, is charged.');
    }

    /**
     * SITUATION: two of the three counters are excluded for an interval
     * (their devices failed and the reviewer took them out). Only counter 1
     * is left, and it records car 5.
     *
     * EXPECTED: the pass counts on counter 1's word alone, with no flag.
     * When only one source is expected, there is nobody to agree with.
     */
    public function testWhenOnlyOneSourceIsExpectedItsEntriesCountOnTheirOwn(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([5]);
        $race->entry(1, 5, '12:00');
        $race->excludeSource(2, '10:00', '20:00');
        $race->excludeSource(3, '10:00', '20:00');

        $result = $race->run('30:00');

        $this->assertLapCounts([5 => 1], $result);
        $this->assertNoOpenFlags($result);
    }
}
