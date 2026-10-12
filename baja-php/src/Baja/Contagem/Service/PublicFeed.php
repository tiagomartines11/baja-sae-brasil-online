<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Engine\StopStats;
use Baja\Model\ProvaQuery;

/**
 * The public JSON feed for overlays and broadcast graphics.
 *
 * It NEVER recalculates anything. During a race the audience polls this far
 * more than anybody uploads, and the public site shares its server with the
 * counting. So the feed is assembled from the snapshot the last
 * recalculation left behind, plus the clock-dependent parts (race clock,
 * car status) worked out on the spot. Keeping the snapshot fresh is the job
 * of the uploads and of the review screen.
 */
final class PublicFeed
{
    /** All viewers within this many seconds share one assembled answer. */
    public const CACHE_SECONDS = 2;

    private const BEST_LAP_EVENTS_KEPT = 10;
    private const STOP_EVENTS_KEPT = 10;

    /**
     * @return string|null the feed as JSON, or null when there is no laps prova with these ids
     */
    public static function jsonFor(string $eventoId, string $provaId): ?string
    {
        $cacheKey = 'contagem:' . $eventoId . ':' . $provaId . ':feed';
        $cached = self::readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $prova = ProvaQuery::create()->filterByEventoId($eventoId)->findOneByProvaId($provaId);
        if ($prova === null || $prova->getType() !== 'laps') {
            return null;
        }

        $settings = Settings::fromArray($prova->getParams()->contagem ?? null);
        $feed = self::assemble($eventoId, $provaId, (new SnapshotStore($prova))->getFeedPart(), $settings, Clock::nowMs());

        $json = (string) json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        self::writeCache($cacheKey, $json);
        return $json;
    }

    /**
     * The part of the feed that does not depend on the clock, computed once
     * per recalculation and stored in the snapshot.
     *
     * @param array<int, array{numero: int, nome: string, escola: string}> $teams
     * @return array<string, mixed>
     */
    public static function fromResult(EngineResult $result, array $teams): array
    {
        $cars = [];
        foreach ($result->cars as $carro => $state) {
            // Typos can produce car numbers that are not teams of the event.
            if (!isset($teams[$carro])) {
                continue;
            }
            $cars[] = [
                'posicao' => $state->posicao,
                'numero' => $carro,
                'equipe' => $teams[$carro]['nome'],
                'escola' => $teams[$carro]['escola'],
                'voltas' => $state->voltas,
                'ultima_passagem_ms' => $state->ultimaPassagemMs,
                'ultima_volta_ms' => $state->ultimaVoltaMs,
                'melhor_volta_ms' => $state->melhorVoltaMs,
            ];
        }

        $bestLapEvents = self::bestLapEvents($result, $teams);
        return [
            'carros' => $cars,
            'melhor_volta' => $bestLapEvents === [] ? null : $bestLapEvents[count($bestLapEvents) - 1],
            'novas_melhores_voltas' => array_slice($bestLapEvents, -self::BEST_LAP_EVENTS_KEPT),
            // Newest first: what a "car 12 enters the pit lane" graphic needs.
            'ultimas_paradas' => StopStats::recentEvents($result->timeline, self::STOP_EVENTS_KEPT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function assemble(string $eventoId, string $provaId, ?array $snapshotPart, Settings $settings, int $nowMs): array
    {
        $live = $snapshotPart['tempo_real'] ?? [];
        $stored = $snapshotPart['feed'] ?? [];
        $statusByCar = LiveStatus::ofCars($live, $settings, $nowMs);
        $race = LiveStatus::ofRace($live, $nowMs);

        // Stops are worked out now, not stored: the time a car has spent in
        // the pit lane grows while it stands there.
        $timeline = RaceContext::timelineOf($live['corrida'] ?? []);
        $cars = [];
        foreach ($stored['carros'] ?? [] as $car) {
            $carro = (int) $car['numero'];
            $status = $statusByCar[$carro] ?? ['status' => 'fora', 'status_desde_ms' => null];
            $cars[] = array_merge($car, $status, StopReport::forFeed($timeline, $carro, $settings->abastecimento, $nowMs));
        }

        return [
            'evento_id' => $eventoId,
            'prova_id' => $provaId,
            'gerado_em_ms' => $nowMs,
            // When the numbers below were last recalculated; a consumer can
            // tell a quiet race from a stalled feed.
            'calculado_em_ms' => $snapshotPart['gerado_em_ms'] ?? null,
            // True while the prova accepts simulated passes: a consumer
            // must not show such a race as a real one.
            'simulacao' => $settings->permitirSimulacao,
            'corrida' => [
                'largada_ms' => $race['largada_ms'],
                'encerramento_ms' => $race['encerramento_ms'],
                'estado' => $race['estado'],
                'decorrido_ms' => $race['decorrido_ms'],
            ],
            'carros' => $cars,
            'melhor_volta' => $stored['melhor_volta'] ?? null,
            'novas_melhores_voltas' => $stored['novas_melhores_voltas'] ?? [],
            'ultimas_paradas' => $stored['ultimas_paradas'] ?? [],
        ];
    }

    /**
     * Every moment at which some car beat the best lap of the race so far,
     * oldest first. The last one is therefore the current best lap.
     *
     * @param array<int, array{numero: int, nome: string, escola: string}> $teams
     * @return array<int, array<string, mixed>>
     */
    private static function bestLapEvents(EngineResult $result, array $teams): array
    {
        $eligible = array_filter(
            $result->laps,
            fn(Lap $lap) => $lap->isCounted() && $lap->elegivelMelhorVolta && $lap->tempoVoltaMs !== null
        );
        usort($eligible, fn(Lap $a, Lap $b) => [$a->tsMs, $a->carro] <=> [$b->tsMs, $b->carro]);

        $events = [];
        $bestSoFarMs = null;
        foreach ($eligible as $lap) {
            if ($bestSoFarMs !== null && $lap->tempoVoltaMs >= $bestSoFarMs) {
                continue;
            }
            $bestSoFarMs = $lap->tempoVoltaMs;
            $events[] = [
                'numero' => $lap->carro,
                'equipe' => $teams[$lap->carro]['nome'] ?? null,
                'escola' => $teams[$lap->carro]['escola'] ?? null,
                'volta' => $lap->numeroVolta,
                'tempo_volta_ms' => $lap->tempoVoltaMs,
                'ts_ms' => $lap->tsMs,
            ];
        }
        return $events;
    }

    private static function readCache(string $key): ?string
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return null;
        }
        try {
            $value = $redis->get($key);
        } catch (\Throwable $error) {
            error_log('Contagem\PublicFeed: cache read failed: ' . $error->getMessage());
            return null;
        }
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function writeCache(string $key, string $json): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }
        try {
            $redis->setex($key, self::CACHE_SECONDS, $json);
        } catch (\Throwable $error) {
            error_log('Contagem\PublicFeed: cache write failed: ' . $error->getMessage());
        }
    }
}
