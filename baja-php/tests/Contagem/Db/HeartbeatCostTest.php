<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\ChangeCounter;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\RaceContext;
use Baja\Contagem\Service\SnapshotStore;

/**
 * What the five-second heartbeat of every phone is allowed to cost.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A phone with nothing to send still contacts the server every five
 * seconds: "I am here, my queue is empty". Over a four-hour race that is
 * most of the traffic. The server keeps a "change counter" for each race;
 * whenever it moves, the next reviewer's poll triggers a recalculation of
 * the WHOLE race and every review screen downloads the result again.
 *
 * So a heartbeat must only move that counter when it really changes what
 * the engine would compute. The one thing a heartbeat can change is "how
 * far has this counter uploaded" (its "synced through" mark), which the
 * engine uses to decide that a counter MISSED a car. That decision does
 * not need second-by-second precision, so the mark only counts as changed
 * when it crosses into a new ten-second step.
 */
final class HeartbeatCostTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    private function changesNow(): ?int
    {
        return (new ChangeCounter(self::EVENTO_ID, self::PROVA_ID))->current();
    }

    /** A phone says "my queue is empty" at the given second of the race. */
    private function heartbeatAt(string $token, int $seconds): array
    {
        Clock::freezeAt($this->at($seconds));
        return $this->upload($token, [], ['queue_empty' => true]);
    }

    /**
     * SITUATION: one phone, nothing happening on track. It sends heartbeats
     * at 10:00, 10:03, 10:06 and 10:11 of the race.
     *
     * WHAT WENT WRONG BEFORE: every one of them moved the change counter.
     * With a review screen open, the whole race was recalculated every five
     * seconds for the entire race, for nothing.
     *
     * EXPECTED: the first one counts (the source had no mark before). The
     * next two stay inside the same ten-second step and change nothing.
     * The one at 10:11 is in the next step and counts once.
     */
    public function testHeartbeatsMoveTheChangeCounterOncePerTenSecondStep(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $before = $this->changesNow();
        if ($before === null) {
            $this->markTestSkipped('Redis is not available in this environment.');
        }

        $this->heartbeatAt($token, 600);
        $afterFirst = $this->changesNow();
        $this->heartbeatAt($token, 603);
        $this->heartbeatAt($token, 606);
        $afterSameStep = $this->changesNow();
        $this->heartbeatAt($token, 611);
        $afterNextStep = $this->changesNow();

        $this->assertSame($before + 1, $afterFirst);
        $this->assertSame($afterFirst, $afterSameStep);
        $this->assertSame($afterFirst + 1, $afterNextStep);
    }

    /**
     * SITUATION: two phones on the same counter position. Ana's last
     * reported at 10:00 and has been quiet since; Bruno's keeps sending
     * heartbeats at 10:11 and 10:22.
     *
     * EXPECTED: Bruno's heartbeats change nothing. The source is only
     * synced through the EARLIEST of its phones' marks, and that is still
     * Ana's 10:00, whatever Bruno's phone says.
     */
    public function testAHeartbeatThatDoesNotMoveItsSourcesEarliestMarkChangesNothing(): void
    {
        $anasPhone = $this->issueToken($this->fontes[0], 'zzt_ana');
        $brunosPhone = $this->issueToken($this->fontes[0], 'zzt_bruno');
        $this->heartbeatAt($anasPhone, 600);
        $this->heartbeatAt($brunosPhone, 601);
        $before = $this->changesNow();
        if ($before === null) {
            $this->markTestSkipped('Redis is not available in this environment.');
        }

        $this->heartbeatAt($brunosPhone, 611);
        $this->heartbeatAt($brunosPhone, 622);

        $this->assertSame($before, $this->changesNow());
    }

    /**
     * SITUATION: the race is running and nobody has the review screen open
     * (the reviewers are at lunch). Car 11 gets a new pass. Phones keep
     * sending heartbeats.
     *
     * WHAT WENT WRONG BEFORE: only an upload with entries, or a reviewer's
     * poll, could start the whole-race recalculation. A quiet stretch left
     * flags and best laps stale until somebody opened the review screen.
     *
     * EXPECTED: a heartbeat arriving once the snapshot is stale starts the
     * recalculation itself. It does so AFTER answering the phone (the
     * "after" step of the answer, which the endpoint runs once the phone
     * has its reply), so the phone never waits for it.
     */
    public function testAHeartbeatStartsTheRecalculationWhenTheSnapshotIsStale(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $this->newRequest();
        $first = $this->service->snapshot();
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->newRequest();
        $this->service->withLock(fn() => $this->service->recountCars([self::CAR_A]));

        Clock::freezeAt($this->at(620));
        $answer = $this->upload($token, [], ['queue_empty' => false]);
        $this->assertSame($first->geradoEmMs, (new SnapshotStore($this->prova))->get()->geradoEmMs, 'not yet: the phone is answered first');
        ($answer['after'])();

        $stored = (new SnapshotStore($this->prova))->get();
        $this->assertSame($this->at(620), $stored->geradoEmMs);
        $this->assertSame($this->at(60), $stored->dados['tempo_real']['carros'][self::CAR_A]['ultima_passagem_ms']);
    }

    /**
     * SITUATION: phones are waiting for the start; their heartbeats keep
     * reading the "race context" (start time, state of the race), which is
     * cached for half a minute to spare the database. Race control presses
     * "Largar".
     *
     * WHAT WENT WRONG BEFORE: starting the race DELETED the cached context.
     * A heartbeat that had read the database a moment before the start
     * could then write its old, pre-start copy back, and phones went on
     * showing "aguardando a largada" for up to thirty seconds into the race.
     *
     * EXPECTED: starting the race REPLACES the cached context. Here the
     * cache is given a pre-start copy by hand (as the unlucky heartbeat
     * would), the race is started, and the very next heartbeat already
     * reports the start.
     */
    public function testStartingTheRaceReplacesTheCachedContextInsteadOfDeletingIt(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $this->newRequest();
        $this->service->withLock(fn() => $this->service->resetRace(self::USER_PREFIX . 'controle'));
        $beforeTheStart = $this->upload($token)['body'];
        $this->assertNull($beforeTheStart['race']['largada_ms']);

        $this->startRace($this->at(590));
        $cachedContext = (new RaceContext(self::EVENTO_ID, self::PROVA_ID))->get();
        $afterTheStart = $this->upload($token)['body'];

        $this->assertSame($this->at(590), $cachedContext['largada_ms'], 'the cache already holds the start');
        $this->assertSame($this->at(590), $afterTheStart['race']['largada_ms']);
        $this->assertSame('correndo', $afterTheStart['race']['estado']);
    }
}
