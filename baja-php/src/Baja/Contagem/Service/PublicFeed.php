<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Lap;
use Baja\Model\Prova;
use Baja\Model\ProvaQuery;

/**
 * The read-only picture of a race for the public: broadcast overlays, the
 * results site, anyone's script. Served by resultados/contagem_feed.php.
 *
 * It only ever shows official numbers (counted laps). Flags, pending
 * passes and per-source counts are review material and stay out.
 */
final class PublicFeed
{
    /** Many viewers poll this; they all share one engine run per this many seconds. */
    private const CACHE_SECONDS = 2;

    private const BEST_LAP_EVENTS_KEPT = 10;

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

        $json = (string) json_encode(self::build($prova), JSON_UNESCAPED_UNICODE);
        self::writeCache($cacheKey, $json);
        return $json;
    }

    /** @return array<string, mixed> */
    public static function build(Prova $prova): array
    {
        $service = ContagemService::forProva($prova);
        $result = $service->compute();
        $teams = $service->loadTeams(false);
        $nowMs = Clock::nowMs();
        $timeline = $result->timeline;

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
                'status' => $state->status,
                'status_desde_ms' => $state->statusDesdeMs,
            ];
        }

        $bestLapEvents = self::bestLapEvents($result, $teams);

        return [
            'evento_id' => $prova->getEventoId(),
            'prova_id' => $prova->getProvaId(),
            'gerado_em_ms' => $nowMs,
            'corrida' => [
                'largada_ms' => $timeline->largadaMs,
                'encerramento_ms' => $timeline->encerramentoMs,
                'estado' => $timeline->stateAt($nowMs),
                'decorrido_ms' => $timeline->elapsedMs($nowMs),
            ],
            'carros' => $cars,
            'melhor_volta' => $bestLapEvents === [] ? null : $bestLapEvents[count($bestLapEvents) - 1],
            'novas_melhores_voltas' => array_slice($bestLapEvents, -self::BEST_LAP_EVENTS_KEPT),
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
