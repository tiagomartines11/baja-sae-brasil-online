<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\PublicFeed;
use Baja\Contagem\Service\RedisClient;
use Baja\Contagem\Service\StopReport;
use Baja\Contagem\Sync\DeviceTokens;

/**
 * The pit lane and the fueling station: logging stops and reporting them.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Besides the counters at the finish line, volunteers stand at the pit
 * lane ("box") and, when the prova uses it, at the fueling station
 * ("abastecimento"). On a phone they tap a car when it ENTERS the area and
 * when it LEAVES. Each tap is a "stop event" with one of four types:
 * box_entrada, box_saida, abastecimento_entrada, abastecimento_saida.
 *
 * These phones are "pit-log devices": they upload to the same address as
 * the counters' phones, with their own kind of token, and may only send
 * stop events (never passes).
 *
 * An entry followed by an exit is one "stay". The broadcast overlay reads
 * the public feed and shows, per car: is it inside now, how many stays it
 * has completed, how long in total, and how long the last one took.
 */
final class StopEventsTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    private function pitLogToken(): string
    {
        return DeviceTokens::issue(DeviceTokens::boxSourceFor($this->prova), self::USER_PREFIX . 'box', 'Telefone do box');
    }

    /** One tap on the pit-log page, as the phone sends it. */
    private function stopEvent(string $tipo, int $car, int $seconds): array
    {
        return [
            'uuid' => self::newUuid(),
            'car' => $car,
            'tipo' => $tipo,
            'ts_ms' => $this->at($seconds),
            'time_quality' => 'exato',
            'ts_device_ms' => $this->at($seconds),
        ];
    }

    /** @param array<int, array<string, mixed>> $events */
    private function uploadStops(string $token, array $events): array
    {
        return $this->upload($token, [], ['pit_events' => $events]);
    }

    /** The public feed as an overlay reads it right now, bypassing its two-second cache. */
    private function feedAt(int $seconds): array
    {
        Clock::freezeAt($this->at($seconds));
        $this->newRequest();
        $this->service->snapshot();
        $redis = RedisClient::get();
        if ($redis !== null) {
            $redis->del('contagem:' . self::EVENTO_ID . ':' . self::PROVA_ID . ':feed');
        }
        return json_decode((string) PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);
    }

    private static function carIn(array $feed, int $car): array
    {
        return array_values(array_filter($feed['carros'], fn(array $row): bool => $row['numero'] === $car))[0];
    }

    /**
     * SITUATION: the prova uses the fueling station. Car 11 enters the pit
     * lane at 2:00 and car 22 enters the fueling station at 2:10.
     *
     * EXPECTED: both events are stored with the type and the time the phone
     * sent. The answer tells the pit-log phone which cars are inside each
     * area right now, which is what lets its page list "cars inside" first
     * when the volunteer is logging exits.
     */
    public function testAPitLogPhoneRecordsStopsInBothAreas(): void
    {
        $this->changeSettings(['abastecimento' => true]);
        $token = $this->pitLogToken();
        $events = [
            $this->stopEvent('box_entrada', self::CAR_A, 120),
            $this->stopEvent('abastecimento_entrada', self::CAR_B, 130),
        ];

        $answer = $this->uploadStops($token, $events);

        $this->assertSame(200, $answer['status']);
        $this->assertEqualsCanonicalizing(array_column($events, 'uuid'), $answer['body']['stored_uuids']);
        $this->assertSame([self::CAR_A], $answer['body']['cars_in_pit']);
        $this->assertSame([self::CAR_B], $answer['body']['cars_in_fuel']);
        $this->assertTrue($answer['body']['abastecimento']);
        $this->assertSame(1, $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo = ? AND equipe_numero = ? AND ts_ms = ?',
            [self::EVENTO_ID, self::PROVA_ID, 'abastecimento_entrada', self::CAR_B, $this->at(130)]
        ));
    }

    /**
     * SITUATION: the same event is uploaded twice (the first answer was
     * lost), and the upload also carries a made-up event type.
     *
     * EXPECTED: the real event is stored once. The unknown type is not
     * stored at all.
     */
    public function testAResentStopEventIsStoredOnceAndUnknownTypesAreIgnored(): void
    {
        $token = $this->pitLogToken();
        $real = $this->stopEvent('box_entrada', self::CAR_A, 120);
        $madeUp = $this->stopEvent('lavagem_entrada', self::CAR_A, 125);

        $this->uploadStops($token, [$real, $madeUp]);
        $answer = $this->uploadStops($token, [$real, $madeUp]);

        $this->assertSame([$real['uuid']], $answer['body']['stored_uuids']);
        $this->assertSame(1, $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo LIKE ?',
            [self::EVENTO_ID, self::PROVA_ID, '%entrada']
        ));
    }

    /**
     * SITUATION: the race simulation is run on the pit-log page of a prova
     * that does not allow simulation (the setting is off, as for any real
     * race). Its events are marked with the mode "simulacao".
     *
     * EXPECTED: the event is not stored, and the answer lists it as
     * refused with the reason. A made-up pit stop in a real race would
     * take the car out of the pace and best-lap calculations.
     */
    public function testSimulatedStopEventsAreRefusedUnlessTheProvaAllowsSimulation(): void
    {
        $token = $this->pitLogToken();
        $simulated = ['mode' => 'simulacao'] + $this->stopEvent('box_entrada', self::CAR_A, 120);

        $refusedAnswer = $this->uploadStops($token, [$simulated]);
        $this->changeSettings(['permitir_simulacao' => true]);
        $allowedAnswer = $this->uploadStops($token, [$simulated]);

        $this->assertSame([], $refusedAnswer['body']['stored_uuids']);
        $this->assertSame([['uuid' => $simulated['uuid'], 'motivo' => 'simulacao_nao_permitida']], $refusedAnswer['body']['refused']);
        $this->assertSame([$simulated['uuid']], $allowedAnswer['body']['stored_uuids']);
    }

    /**
     * SITUATION: a counter's phone (bound to "Contagem 1", not to the pit
     * log) sends a stop event, as a tampered page might.
     *
     * EXPECTED: it is ignored. What a device may send depends on what it is
     * bound to: counters send passes, pit-log phones send stops.
     */
    public function testACountersPhoneCannotLogStops(): void
    {
        $token = $this->issueToken($this->fontes[0]);

        $answer = $this->uploadStops($token, [$this->stopEvent('box_entrada', self::CAR_A, 120)]);

        $this->assertSame([], $answer['body']['stored_uuids']);
        $this->assertSame(0, $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo = ?',
            [self::EVENTO_ID, self::PROVA_ID, 'box_entrada']
        ));
    }

    /**
     * SITUATION: car 11 made one complete pit stop (2:00 to 3:30, ninety
     * seconds) and went in again at 8:00. It is now 10:00 and it is still
     * there. Car 22 has an exit from the pit lane logged at 5:00 with no
     * entry before it (the volunteer at the entrance missed it).
     *
     * EXPECTED, in the public feed at 10:00:
     *   car 11, pit lane: inside since 8:00; 1 completed stay; total time
     *   90 s + the 120 s of the stay under way = 210 s; last stay 90 s.
     *   car 22, pit lane: not inside, 0 stays. An exit alone is not a stay;
     *   it becomes one only if somebody records the missing entry.
     *   The fueling part is absent (null): this prova does not use it.
     *   The list of latest stop events has all four, newest first.
     */
    public function testTheFeedReportsStaysPerCarAndTheLatestStopEvents(): void
    {
        $token = $this->pitLogToken();
        $this->uploadStops($token, [
            $this->stopEvent('box_entrada', self::CAR_A, 120),
            $this->stopEvent('box_saida', self::CAR_A, 210),
            $this->stopEvent('box_saida', self::CAR_B, 300),
            $this->stopEvent('box_entrada', self::CAR_A, 480),
        ]);
        // A car appears in the feed once it has an official pass.
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(self::CAR_B, $this->at(61));
        $this->recompute();

        $feed = $this->feedAt(600);

        $this->assertSame(
            ['dentro' => true, 'desde_ms' => $this->at(480), 'paradas' => 1, 'total_ms' => 210000, 'ultima_ms' => 90000],
            self::carIn($feed, self::CAR_A)['box']
        );
        $this->assertSame(
            ['dentro' => false, 'desde_ms' => null, 'paradas' => 0, 'total_ms' => 0, 'ultima_ms' => null],
            self::carIn($feed, self::CAR_B)['box']
        );
        $this->assertNull(self::carIn($feed, self::CAR_A)['abastecimento']);
        $this->assertSame(
            [[self::CAR_A, 'box_entrada', 480], [self::CAR_B, 'box_saida', 300], [self::CAR_A, 'box_saida', 210], [self::CAR_A, 'box_entrada', 120]],
            array_map(
                fn(array $event): array => [$event['carro'], $event['tipo'], intdiv($event['ts_ms'] - self::RACE_START_MS, 1000)],
                $feed['ultimas_paradas']
            )
        );
    }

    /**
     * SITUATION: the feed is read at 10:00 and again at 10:30, with car 11
     * standing in the pit lane the whole time and no recalculation in
     * between.
     *
     * EXPECTED: its total time inside has grown by those thirty seconds.
     * The time a car has spent in the pits is worked out when the feed is
     * read; an overlay showing a running pit-stop clock must not freeze
     * between recalculations.
     */
    public function testTheTimeInsideKeepsRunningBetweenRecalculations(): void
    {
        $token = $this->pitLogToken();
        $this->uploadStops($token, [$this->stopEvent('box_entrada', self::CAR_A, 480)]);
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recompute();

        $atTen = $this->feedAt(600);
        $thirtySecondsLater = $this->feedAt(630);

        $this->assertSame(120000, self::carIn($atTen, self::CAR_A)['box']['total_ms']);
        $this->assertSame(150000, self::carIn($thirtySecondsLater, self::CAR_A)['box']['total_ms']);
        $this->assertSame($atTen['calculado_em_ms'], $thirtySecondsLater['calculado_em_ms'], 'same snapshot both times');
    }

    /**
     * SITUATION: the prova uses the fueling station. Car 11 fueled once,
     * from 4:00 to 4:40, and made no pit stop. The person in charge
     * downloads the stops report ("paradas") as a spreadsheet at 10:00.
     *
     * EXPECTED: one row per present car, with the same five numbers for
     * each of the two areas. Car 11's row shows one fueling stay of forty
     * seconds and nothing for the pit lane; car 22's row is all zeros.
     */
    public function testTheStopsReportHasOneRowPerCarWithBothAreas(): void
    {
        $this->changeSettings(['abastecimento' => true]);
        $token = $this->pitLogToken();
        $this->uploadStops($token, [
            $this->stopEvent('abastecimento_entrada', self::CAR_A, 240),
            $this->stopEvent('abastecimento_saida', self::CAR_A, 280),
        ]);
        $this->newRequest();

        $rows = StopReport::rows($this->service);

        $this->assertSame([self::CAR_A, self::CAR_B, self::CAR_C], array_column($rows, 'numero'));
        $carA = $rows[0];
        $this->assertSame(0, $carA['box_paradas']);
        $this->assertSame(0, $carA['abastecimento_dentro']);
        $this->assertSame(1, $carA['abastecimento_paradas']);
        $this->assertSame(40000, $carA['abastecimento_total_ms']);
        $this->assertSame(40000, $carA['abastecimento_ultima_ms']);
        $this->assertSame(0, $rows[1]['abastecimento_paradas']);
        $this->assertSame(0, $rows[1]['box_total_ms']);
    }
}
