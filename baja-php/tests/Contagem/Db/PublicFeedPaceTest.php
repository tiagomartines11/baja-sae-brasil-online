<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\PublicFeed;
use Baja\Contagem\Service\RedisClient;

/**
 * The pace of the race in the public feed.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The engine keeps a "field pace": the median of the laps the whole field
 * finished in the last 15 minutes (`janela_ritmo_s`), unknown until there
 * are eight. Each car's "expected lap" is that pace times how the car
 * compares with the field. They decide when a lap is too short to be real,
 * which laps may be a best lap, and when a car is late or stopped.
 *
 * The broadcast can show them: each car in the feed has its
 * `volta_esperada_ms`, the feed has `ritmo` (the pace now and the laps
 * behind it), and `contagem_feed.php?…&ritmo=historico` gives the pace
 * once a minute since the start, for a chart. Like the rest of the feed,
 * all of it comes from the last recalculation's snapshot.
 */
final class PublicFeedPaceTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    /**
     * Each of the three cars crosses the line every 100 s, five times
     * (pass zero and four laps), 10 s apart: twelve laps of 100 s, all
     * finished by 0:08:10.
     */
    private function twelveLapsOfOneHundredSeconds(): void
    {
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $position => $car) {
            for ($crossing = 0; $crossing < 5; $crossing++) {
                $this->recordCountedPass($car, $this->at(30 + $position * 10 + $crossing * 100));
            }
        }
    }

    private function forgetFeedCaches(): void
    {
        $redis = RedisClient::get();
        if ($redis !== null) {
            $prefix = 'contagem:' . self::EVENTO_ID . ':' . self::PROVA_ID . ':';
            $redis->del($prefix . 'feed', $prefix . 'feed_ritmo');
        }
    }

    private function feed(): array
    {
        $this->forgetFeedCaches();
        return json_decode((string) PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);
    }

    private function paceHistory(): array
    {
        $this->forgetFeedCaches();
        return json_decode((string) PublicFeed::paceHistoryJsonFor(self::EVENTO_ID, self::PROVA_ID), true);
    }

    /**
     * SITUATION: twelve laps of 100 s, recalculated at 0:10:00.
     *
     * EXPECTED: the feed says the field pace is 100 s, taken from twelve
     * laps over a 900-second window, and every car is expected to lap in
     * 100 s. The long history is not in the main feed.
     */
    public function testTheFeedCarriesThePaceAndEachCarsExpectedLap(): void
    {
        $this->twelveLapsOfOneHundredSeconds();
        $this->recompute();

        $feed = $this->feed();

        $this->assertSame(['volta_mediana_ms' => 100000, 'voltas_na_janela' => 12, 'janela_s' => 900], $feed['ritmo']);
        $this->assertSame([100000, 100000, 100000], array_column($feed['carros'], 'volta_esperada_ms'));
        $this->assertArrayNotHasKey('ritmo_historico', $feed);
    }

    /**
     * SITUATION: the same race, before anybody finished a lap: only the
     * three cars' pass zero.
     *
     * EXPECTED: the pace is unknown (null) with no laps behind it, and so
     * is every car's expected lap. The keys are there all the same.
     */
    public function testBeforeEightLapsThePaceIsUnknown(): void
    {
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $car) {
            $this->recordCountedPass($car, $this->at(30));
        }
        $this->recompute();

        $feed = $this->feed();

        $this->assertSame(['volta_mediana_ms' => null, 'voltas_na_janela' => 0, 'janela_s' => 900], $feed['ritmo']);
        $this->assertSame([null, null, null], array_column($feed['carros'], 'volta_esperada_ms'));
    }

    /**
     * SITUATION: twelve laps of 100 s, recalculated at 0:10:00. The pace
     * chart asks for the history.
     *
     * EXPECTED: a point per minute from the start to 0:10:00, the last one
     * being the pace now; the pace is unknown until the eighth lap ended
     * (0:05:40) and 100 s from the first point after that.
     */
    public function testTheHistoryGoesFromTheStartToTheLastRecalculation(): void
    {
        $this->twelveLapsOfOneHundredSeconds();
        $this->recompute();

        $answer = $this->paceHistory();
        $history = $answer['ritmo_historico'];

        $this->assertSame($this->feed()['ritmo'], $answer['ritmo']);
        $this->assertSame($this->at(600), $answer['calculado_em_ms']);
        $this->assertGreaterThanOrEqual($this->at(0), $history[0]['ts_ms']);
        $this->assertLessThan($this->at(60), $history[0]['ts_ms']);
        $this->assertSame($this->at(600), $history[count($history) - 1]['ts_ms']);
        foreach ($history as $point) {
            $finishedLaps = 0;
            foreach ([0, 10, 20] as $offset) {
                for ($lapEnd = 130 + $offset; $lapEnd <= 430 + $offset; $lapEnd += 100) {
                    $finishedLaps += $this->at($lapEnd) <= $point['ts_ms'] ? 1 : 0;
                }
            }
            $this->assertSame($finishedLaps >= 8 ? 100000 : null, $point['volta_mediana_ms'], 'At ' . $point['ts_ms']);
        }
    }

    /**
     * SITUATION: the race is recalculated at 0:10:00 and again at 0:12:00
     * with nothing new on track (a phone's heartbeat, say, counted as a
     * change).
     *
     * EXPECTED: the history now reaches 0:12:00, and the snapshot's token
     * is the same: the pace moves with the clock, but no review screen
     * shows it, so the screens are not made to download the race again.
     */
    public function testAGrowingHistoryDoesNotChangeTheSnapshotToken(): void
    {
        $this->twelveLapsOfOneHundredSeconds();
        $this->recompute();
        $before = $this->service->lastStoredSnapshot();

        Clock::freezeAt($this->at(720));
        $this->recompute();
        $after = $this->service->lastStoredSnapshot();

        $history = $this->paceHistory()['ritmo_historico'];
        $this->assertSame($this->at(720), $history[count($history) - 1]['ts_ms']);
        $this->assertSame($before->token, $after->token);
    }
}
