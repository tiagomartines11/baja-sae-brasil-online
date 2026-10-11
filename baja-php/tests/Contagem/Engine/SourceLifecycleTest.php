<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\Data\Flag;
use Baja\Tests\Contagem\Engine\Support\EngineTestCase;
use Baja\Tests\Contagem\Engine\Support\RaceScenario;

/**
 * Counters come and go during a race: one is switched off on the settings
 * page, one is excluded by a reviewer for a while or for good, one closes
 * the page and goes home.
 *
 * Two questions are kept apart throughout:
 * - Was this counter SUPPOSED to be counting at that moment? That decides
 *   what is official (a pass needs two of the counters that were supposed
 *   to count, or stands alone when only one was).
 * - Do we KNOW everything it recorded around that moment? That only decides
 *   when a reviewer is shown a flag and when a miss is charged.
 *
 * (`firstCrossing` is each car's pass zero, seen by everybody.)
 */
final class SourceLifecycleTest extends EngineTestCase
{
    /**
     * SITUATION: two counters. At minute 10 the controller switches
     * counter 2 off (the person had to leave). Car 5 was recorded by both
     * at 05:00, and by counter 1 alone at 12:00. Counter 2's phone, still
     * open in a pocket, also uploads a stray "9" typed at 11:00.
     *
     * EXPECTED:
     * - Switching a counter off does not erase what it recorded while it
     *   was on: the pass at 05:00 still has two counters behind it.
     * - From minute 10 on, counter 1 is the only one supposed to count, so
     *   its entry at 12:00 stands on its own: it counts and is not flagged.
     * - What counter 2 typed after being switched off is ignored.
     */
    public function testACounterSwitchedOffKeepsItsPastAndIsNotExpectedAfterwards(): void
    {
        $race = RaceScenario::withCounters(2);
        $race->start('00:00');
        $race->firstCrossing([5]);
        $beforeSwitchOff = $race->pass(5, '05:00');
        $race->entry(1, 5, '12:00');
        $afterSwitchOff = $race->entry(2, 9, '11:00');
        $race->deactivateSource(2, '10:00');

        $result = $race->run('15:00');

        $this->assertLapCounts([5 => 2], $result);
        $this->assertCount(2, $result->groupOfEntry($beforeSwitchOff[2])->entries, 'Counter 2 is still part of the 05:00 pass.');
        $this->assertNull($result->groupOfEntry($afterSwitchOff));
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: three counters; counter 3 is switched off at minute 10.
     * Car 5 is recorded by counters 1 and 2 only at 08:00 (counter 3 was on
     * and missed it) and at 12:00 (counter 3 was off).
     *
     * EXPECTED: one miss is charged to counter 3, the one at 08:00. Nobody
     * can miss a car after going home. A moment before the switch-off
     * (09:59.999) still counts as "on"; the switch-off moment itself does
     * not.
     */
    public function testNoMissIsChargedToACounterAfterItWasSwitchedOff(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([5, 6, 7]);
        $race->pass(5, '08:00', [1, 2]);
        $race->pass(5, '12:00', [1, 2]);
        $race->deactivateSource(3, '10:00');

        $this->assertSame(1, $race->run('15:00')->sourceStats[3]->faltas);

        $race->pass(6, '09:59.999', [1, 2]);
        $this->assertSame(2, $race->run('15:00')->sourceStats[3]->faltas);
        $race->pass(7, '10:00', [1, 2]);
        $this->assertSame(2, $race->run('15:00')->sourceStats[3]->faltas);
    }

    /**
     * SITUATION: a source that exists on the settings page but was switched
     * off before the race and never took part. Somebody's phone, bound to
     * it, uploads entries anyway.
     *
     * EXPECTED: as if it did not exist. Its entries are ignored, and the
     * other two counters are not waiting for it.
     */
    public function testASourceThatNeverTookPartIsIgnoredCompletely(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->deactivateSource(3, null);
        $race->start('00:00');
        $race->firstCrossing([5], '00:20', [1, 2]);
        $ignored = $race->entry(3, 5, '03:00');
        $race->pass(5, '06:00', [1, 2]);

        $result = $race->run('10:00');

        $this->assertLapCounts([5 => 1], $result);
        $this->assertNull($result->groupOfEntry($ignored));
        $this->assertSame(0, $result->sourceStats[3]->faltas);
        $this->assertNoOpenFlags($result);
    }

    /**
     * SITUATION: counter 3's phone died at minute 10 and will not come back.
     * The reviewer excludes counter 3 "from 10:00 until further notice" (no
     * end time). Car 5 is recorded by counters 1 and 2 at 08:00, 12:00 and
     * 50:00; counter 3 recorded it at 08:00 only.
     *
     * EXPECTED: an exclusion with no end covers the rest of the race,
     * however long. Counter 3 is charged no miss after 10:00, and the
     * 08:00 pass, before the exclusion, keeps all three counters.
     */
    public function testAnExclusionWithNoEndLastsUntilFurtherNotice(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([5]);
        $before = $race->pass(5, '08:00');
        $race->pass(5, '12:00', [1, 2]);
        $race->pass(5, '50:00', [1, 2]);
        $ghost = $race->entry(3, 5, '50:01');
        $exclusion = $race->excludeSource(3, '10:00', null);

        $result = $race->run('55:00');

        $this->assertSame(0, $result->sourceStats[3]->faltas);
        $this->assertCount(3, $result->groupOfEntry($before[3])->entries);
        $this->assertNull($result->groupOfEntry($ghost), 'Whatever the excluded counter uploads later takes no part.');

        $race->undo($exclusion);
        $this->assertSame(1, $race->run('55:00')->sourceStats[3]->faltas, 'Without the exclusion the miss at 12:00 is charged.');
    }

    /**
     * SITUATION: counter 3 was at the wrong line from the start of the race
     * until minute 10. The reviewer excludes it with no START time.
     *
     * EXPECTED: "since always, until 10:00". The entry at 05:00 is dropped,
     * the one at 12:00 is kept.
     */
    public function testAnExclusionWithNoStartCoversEverythingBeforeItsEnd(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $early = $race->entry(3, 5, '05:00');
        $later = $race->entry(3, 5, '12:00');
        $race->excludeSource(3, null, '10:00');

        $result = $race->run('15:00');

        $this->assertNull($result->groupOfEntry($early));
        $this->assertNotNull($result->groupOfEntry($later));
    }

    /**
     * SITUATION: counter 3 is excluded from 10:00 to 20:00 and typed car 5
     * at four moments: a millisecond before 10:00, at 10:00 sharp, at 20:00
     * sharp, and a millisecond after 20:00.
     *
     * EXPECTED: both ends belong to the excluded period. The entries at
     * 10:00.000 and 20:00.000 are dropped; the two just outside are kept.
     */
    public function testBothEndsOfAnExclusionBelongToIt(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $justBefore = $race->entry(3, 5, '09:59.999');
        $atTheStart = $race->entry(3, 6, '10:00');
        $atTheEnd = $race->entry(3, 7, '20:00');
        $justAfter = $race->entry(3, 8, '20:00.001');
        $race->excludeSource(3, '10:00', '20:00');

        $result = $race->run('25:00');

        $this->assertNotNull($result->groupOfEntry($justBefore));
        $this->assertNull($result->groupOfEntry($atTheStart));
        $this->assertNull($result->groupOfEntry($atTheEnd));
        $this->assertNotNull($result->groupOfEntry($justAfter));
    }

    /**
     * SITUATION: car 5 is recorded by counters 1 and 2 at 14:00. Counter 3
     * has nothing for it, and counter 1 also typed a lone "9" at 14:05.
     * Three states of counter 3's phone are compared:
     *
     *   a) open, but it has never synced since the page was loaded;
     *   b) open, synced through 14:20 exactly, then through 14:20.001;
     *   c) closed: no device is open on counter 3 at all.
     *
     * EXPECTED:
     * a) Nothing is known about counter 3. No miss is charged and the lone
     *    entry is not flagged yet: the phone may be holding both cars.
     * b) A counter's silence about 14:00 is trusted only once it synced
     *    PAST 14:00 plus the 20-second window. At 14:20.000 not yet; one
     *    millisecond later, yes. (The lone 9 at 14:05 needs 14:25.)
     * c) With no device open, nothing can still arrive. The miss is charged
     *    and the lone entry flagged at once. Waiting would be waiting
     *    forever.
     */
    public function testAMissIsOnlyChargedAndALoneEntryOnlyFlaggedOnceNothingCanStillArrive(): void
    {
        $race = RaceScenario::withCounters(3);
        $race->start('00:00');
        $race->firstCrossing([5, 9]);
        $race->pass(5, '14:00', [1, 2]);
        $race->entry(1, 9, '14:05');

        $race->syncedThrough(3, null);
        $neverSynced = $race->run('30:00');
        $this->assertSame(0, $neverSynced->sourceStats[3]->faltas);
        $this->assertNoOpenFlags($neverSynced);
        $this->assertLapCounts([5 => 1], $neverSynced, 'The count itself never waits for a sync.');

        $race->syncedThrough(3, '14:20');
        $this->assertSame(0, $race->run('30:00')->sourceStats[3]->faltas);
        $race->syncedThrough(3, '14:20.001');
        $justPast = $race->run('30:00');
        $this->assertSame(1, $justPast->sourceStats[3]->faltas);
        $this->assertNoOpenFlags($justPast, 'The lone entry at 14:05 still waits for a sync past 14:25.');

        $race->noDeviceOpen(3);
        $nobodyThere = $race->run('30:00');
        $this->assertSame(1, $nobodyThere->sourceStats[3]->faltas);
        $this->assertSingleOpenFlag($nobodyThere, Flag::SOLITARIA);
    }
}
