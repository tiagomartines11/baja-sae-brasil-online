<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\PublicFeed;
use Baja\Contagem\Service\RedisClient;

/**
 * The slow path: the whole-race recalculation and the "snapshot" it leaves.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Review flags, lap times and best laps need every entry of every car. For
 * a real race that takes about a tenth of a second, which is too much to do
 * every time a phone or a browser asks. So the result of one such
 * recalculation is stored (the snapshot) and served again and again. A new
 * one is made:
 *
 *   - at most once every `recalculo_s` seconds (5 by default),
 *   - only if something changed since the last one,
 *   - by one request at a time; anybody else gets the previous snapshot
 *     instead of waiting.
 *
 * Each snapshot carries a "token", a fingerprint of its content. Two
 * snapshots with the same token show the same race; a review screen that
 * already has that token is told "nothing new" instead of being sent it all
 * again.
 */
final class SnapshotTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    /**
     * Asks for the snapshot as a reviewer's poll would, at the given second
     * of the race. Moving the clock matters: a snapshot younger than five
     * seconds is served as it is, whatever has changed.
     */
    private function snapshotAt(int $seconds): \Baja\Contagem\Service\RaceSnapshot
    {
        Clock::freezeAt($this->at($seconds));
        $this->newRequest();
        return $this->service->snapshot();
    }

    /** Car 11 gets one more official pass, through the same path an upload uses. */
    private function oneMorePassOfCarA(int $seconds): void
    {
        $this->recordCountedPass(self::CAR_A, $this->at($seconds));
        $this->newRequest();
        $this->service->withLock(fn() => $this->service->recountCars([self::CAR_A]));
    }

    /**
     * SITUATION: a reviewer's screen asks for the state of the race every
     * two seconds. Nothing is happening on track.
     *
     * EXPECTED: every answer is the same snapshot, however much time
     * passes. Without a change there is nothing to recalculate.
     */
    public function testNothingIsRecalculatedWhileNothingChanges(): void
    {
        $first = $this->service->snapshot();

        Clock::freezeAt($this->at(700));
        $this->newRequest();
        $later = $this->service->snapshot();

        $this->assertSame($first->token, $later->token);
    }

    /**
     * SITUATION: a snapshot was made at minute 10. Two seconds later a lap
     * arrives for car 11, and the reviewer's screen asks again at once, and
     * again four seconds after that.
     *
     * EXPECTED: the first poll after the change still gets the OLD
     * snapshot: it is less than five seconds old, and recalculating more
     * often than that is exactly what must not happen under load. The poll
     * at six seconds gets a new one, which knows the new pass.
     */
    public function testAChangeIsPickedUpButNotMoreOftenThanEveryFewSeconds(): void
    {
        $this->oneMorePassOfCarA(60);
        $first = $this->service->snapshot();

        Clock::freezeAt($this->at(602));
        $this->oneMorePassOfCarA(240);
        $tooSoon = $this->service->snapshot();
        $this->assertSame($first->token, $tooSoon->token, 'two seconds old: served as it is');

        Clock::freezeAt($this->at(606));
        $this->newRequest();
        $dueNow = $this->service->snapshot();
        $this->assertNotSame($first->token, $dueNow->token);
        $this->assertSame($this->at(240), $dueNow->dados['tempo_real']['carros'][self::CAR_A]['ultima_passagem_ms']);
    }

    /**
     * SITUATION: the snapshot is stale and one request has started
     * recalculating (it holds the "recalculation lock"). A second reviewer's
     * poll arrives while that is going on.
     *
     * EXPECTED: the second poll is answered immediately with the previous
     * snapshot. It does not wait and it does not start a second
     * recalculation. This is the rule that keeps a slow moment from piling
     * up requests until the server has no workers left.
     */
    public function testWhileSomebodyIsRecalculatingEverybodyElseGetsThePreviousSnapshot(): void
    {
        $first = $this->service->snapshot();
        Clock::freezeAt($this->at(610));
        $this->oneMorePassOfCarA(60);

        $recalculatingRequest = $this->holdLockFromAnotherRequest('recalculo');
        $startedAt = microtime(true);
        $served = $this->service->snapshot();
        $elapsedSeconds = microtime(true) - $startedAt;
        $recalculatingRequest = null;

        $this->assertSame($first->token, $served->token);
        $this->assertLessThan(1.0, $elapsedSeconds, 'it did not wait');
    }

    /**
     * SITUATION: thousands of people watch the broadcast, whose graphics
     * read the public feed. The snapshot is stale: car 11 has a new pass
     * that no recalculation has seen yet.
     *
     * EXPECTED: the feed answers from the snapshot it finds and does NOT
     * recalculate (afterwards the stored snapshot is still the old one).
     * The audience must never be able to make the server work harder.
     * Keeping the snapshot fresh is the job of uploads and reviewers.
     */
    public function testThePublicFeedNeverRecalculates(): void
    {
        $this->oneMorePassOfCarA(60);
        $this->oneMorePassOfCarA(240);
        $first = $this->snapshotAt(610);
        Clock::freezeAt($this->at(620));
        $this->oneMorePassOfCarA(420);

        $feed = json_decode((string) PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);

        $carA = array_values(array_filter($feed['carros'], fn($car) => $car['numero'] === self::CAR_A))[0];
        $this->assertSame(1, $carA['voltas'], 'the feed shows what the last recalculation knew');
        $this->assertSame($first->geradoEmMs, $feed['calculado_em_ms']);

        // The reviewer's next poll is what brings it up to date.
        $this->newRequest();
        $this->assertNotSame($first->token, $this->service->snapshot()->token);
    }

    /**
     * SITUATION: the feed is read twice, one minute apart, with no
     * recalculation in between.
     *
     * EXPECTED: the race clock in the feed has advanced by that minute. The
     * parts that depend on the time of day are worked out when the feed is
     * read, from the stored snapshot, not frozen into it.
     */
    public function testTheRaceClockInTheFeedRunsWithoutRecalculating(): void
    {
        $this->service->snapshot();
        $before = json_decode((string) PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);

        Clock::freezeAt($this->at(660));
        $this->forgetFeedCache();
        $after = json_decode((string) PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);

        $this->assertSame(600000, $before['corrida']['decorrido_ms']);
        $this->assertSame(660000, $after['corrida']['decorrido_ms']);
        $this->assertSame($before['calculado_em_ms'], $after['calculado_em_ms'], 'same snapshot both times');
    }

    /**
     * SITUATION: Redis (the fast in-memory store the snapshot is kept in)
     * is restarted in the middle of the race and comes back empty.
     *
     * EXPECTED: nothing goes blank. The snapshot is also kept in a local
     * file, so the feed still has an answer; and since "has anything
     * changed?" can no longer be answered, the next poll simply
     * recalculates, to be safe.
     */
    public function testAfterRedisLosesEverythingTheSnapshotIsStillServedAndThenRebuilt(): void
    {
        $this->oneMorePassOfCarA(60);
        $this->oneMorePassOfCarA(240);
        $first = $this->snapshotAt(610);

        $redis = RedisClient::get();
        if ($redis === null) {
            $this->markTestSkipped('Redis is not available in this environment.');
        }
        $prefix = 'contagem:' . self::EVENTO_ID . ':' . self::PROVA_ID . ':';
        $redis->del($prefix . 'snapshot', $prefix . 'mudancas', $prefix . 'feed', $prefix . 'contexto');

        $feed = json_decode((string) PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);
        $this->assertSame($first->geradoEmMs, $feed['calculado_em_ms'], 'served from the file copy');

        Clock::freezeAt($this->at(700));
        $this->newRequest();
        $rebuilt = $this->service->snapshot();
        $this->assertSame($this->at(700), $rebuilt->geradoEmMs, 'recalculated now');
        // The race itself did not change, so the content is the same and so
        // is the token: review screens are not made to download it again.
        $this->assertSame($first->token, $rebuilt->token);
    }

    /**
     * SITUATION: car 11 has done its pass zero and two laps, each crossing
     * recorded by two counters at the same instant: a 180-second lap and a
     * 170-second one. Uploads have published VOLTAS and LAST already; the
     * best lap needs lap times, which only the whole-race recalculation
     * works out.
     *
     * EXPECTED: after that recalculation (triggered here by a reviewer's
     * poll) the score row says BEST = 170, and the lap rows have their
     * durations filled in.
     */
    public function testTheBestLapIsPublishedByTheRecalculation(): void
    {
        $this->oneMorePassOfCarA(60);
        $this->oneMorePassOfCarA(240);
        $this->oneMorePassOfCarA(410);
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertEquals(0, $this->publishedData(self::CAR_A)['BEST'], 'not known yet');
        $this->syncAll($this->at(590));

        $this->snapshotAt(610);

        $this->assertEquals(170, $this->publishedData(self::CAR_A)['BEST']);
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS'], 'the count is not touched by this');
        $this->assertSame(1, $this->countRows(
            'contagem_volta',
            'evento_id = ? AND prova_id = ? AND equipe_numero = ? AND numero_volta = 2 AND tempo_volta_ms = 170000',
            [self::EVENTO_ID, self::PROVA_ID, self::CAR_A]
        ));
    }

    private function forgetFeedCache(): void
    {
        $redis = RedisClient::get();
        if ($redis !== null) {
            $redis->del('contagem:' . self::EVENTO_ID . ':' . self::PROVA_ID . ':feed');
        }
    }
}
