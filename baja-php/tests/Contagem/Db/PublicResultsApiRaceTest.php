<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Api\Resultados\CorridaEndpoint;
use Baja\Api\Resultados\Endpoint;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\PublicFeed;
use Baja\Contagem\Service\RedisClient;

/**
 * A real race, as the public results API shows it (api/resultados/corrida.php).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * corrida.php does not count anything. It asks the lap counting's public
 * feed (PublicFeed, the same answer resultados/contagem_feed.php gives) and
 * renames its fields into the API's English ones (docs/results-api.md). The
 * unit test tests/Unit/Api/Resultados/CorridaShapeTest checks the renaming
 * on a feed written by hand; this one checks it on a feed the lap counting
 * really produced: a race with passes, run through the engine.
 *
 * It is here, in the lap counting's database suite, because only this
 * suite builds a race (the made-up event 00NE; see DbTestCase). The dev
 * seed has none.
 */
final class PublicResultsApiRaceTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    /** The feed keeps its answer for 2 s in Redis; a test wants this moment's. */
    private function forgetFeedCache(): void
    {
        RedisClient::get()?->del('contagem:' . self::EVENTO_ID . ':' . self::PROVA_ID . ':feed');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [the API's answer, the feed it came from] */
    private function apiAndFeed(): array
    {
        $this->forgetFeedCache();
        $api = json_decode(Endpoint::encode(CorridaEndpoint::handle(['evt' => self::EVENTO_ID, 'p' => self::PROVA_ID])->body), true);
        $feed = json_decode((string)PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);
        return [$api, $feed];
    }

    /**
     * SITUATION: ten minutes into a race. Car A has crossed the line four
     * times (three laps), car B three times (two laps), car C once (its
     * pass zero: no lap yet).
     *
     * EXPECTED: the API lists the cars in the feed's order, with the feed's
     * positions, laps and last passes; A leads. The clock says the race is
     * running, started at 0:00:00, and has run ten minutes.
     */
    public function testTheApiShowsTheRaceTheFeedShows(): void
    {
        foreach ([0, 1, 2, 3] as $crossing) {
            $this->recordCountedPass(self::CAR_A, $this->at(30 + $crossing * 120));
        }
        foreach ([0, 1, 2] as $crossing) {
            $this->recordCountedPass(self::CAR_B, $this->at(40 + $crossing * 130));
        }
        $this->recordCountedPass(self::CAR_C, $this->at(50));
        $this->recompute();

        [$api, $feed] = $this->apiAndFeed();

        $this->assertSame(array_column($feed['carros'], 'numero'), array_column($api['cars'], 'number'));
        $this->assertSame(array_column($feed['carros'], 'posicao'), array_column($api['cars'], 'position'));
        $this->assertSame(array_column($feed['carros'], 'voltas'), array_column($api['cars'], 'laps'));
        $this->assertSame(array_column($feed['carros'], 'ultima_passagem_ms'), array_column($api['cars'], 'last_pass_ms'));
        $this->assertSame(array_column($feed['carros'], 'melhor_volta_ms'), array_column($api['cars'], 'best_lap_ms'));

        $this->assertSame(self::CAR_A, $api['cars'][0]['number']);
        $this->assertSame([1, 3], [$api['cars'][0]['position'], $api['cars'][0]['laps']]);
        $this->assertSame($this->at(390), $api['cars'][0]['last_pass_ms']);

        $this->assertSame('running', $api['clock']['state']);
        $this->assertSame($this->at(0), $api['clock']['start_ms']);
        $this->assertSame(600000, $api['clock']['elapsed_ms']);
        $this->assertSame($this->at(600), $api['clock']['server_time_ms']);
        $this->assertSame($feed['calculado_em_ms'], $api['calculated_at_ms']);
    }

    /**
     * SITUATION: the same race, before anybody recalculated it.
     *
     * EXPECTED: no cars yet, the clock already running: the clock is worked
     * out when the feed is served, the laps only when the race is
     * recalculated.
     */
    public function testBeforeTheFirstRecalculationTheClockAlreadyRuns(): void
    {
        [$api] = $this->apiAndFeed();

        $this->assertSame('running', $api['clock']['state']);
        $this->assertSame(600000, $api['clock']['elapsed_ms']);
    }
}
