<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Contagem\Service\PublicFeed;
use Baja\Model\Evento;
use Baja\Model\Prova;

/**
 * GET resultados/corrida.php?evt=26BR&p=END
 *
 * A lap-counted race (a prova of type "laps") as it stands: positions, laps,
 * last pass, lap times, each car's status and stops, and the race clock.
 *
 * Everything about the race comes from the lap counting's public feed
 * (PublicFeed, also served as resultados/contagem_feed.php). The feed never
 * recalculates the race; it reads the snapshot the last recalculation left,
 * and keeps its answer for 2 s in Redis. This endpoint calls it and only
 * renames: the feed's fields are in Portuguese and may grow, this API's are
 * in English and fixed (docs/results-api.md). Words the feed may add later
 * pass through unchanged instead of being dropped (see rename()).
 *
 * The feed has no gaps between cars. A consumer can work them out from
 * "laps" and "last_pass_ms"; this endpoint does not, so that it shows only
 * what the lap counting itself states.
 */
final class CorridaEndpoint
{
    public const PARAMS = [
        'evt' => [Query::EVENTO, true],
        'p' => [Query::PROVA, true],
    ];

    /** The feed's race states. */
    public const RACE_STATES = [
        'aguardando' => 'waiting',
        'correndo' => 'running',
        'bandeira_vermelha' => 'red_flag',
        'encerrada' => 'finished',
    ];

    /** The feed's car statuses (Contagem\Engine\Data\CarState). */
    public const CAR_STATUSES = [
        'correndo' => 'running',
        'atrasado' => 'late',
        'parado' => 'stopped',
        'no_box' => 'in_pit',
        'abastecendo' => 'fueling',
        'fora' => 'out',
    ];

    /** The feed's stop areas and event types (Contagem\Engine\Data\Ocorrencia). */
    public const STOP_AREAS = ['box' => 'pit', 'abastecimento' => 'fueling'];
    public const STOP_EVENTS = [
        'box_entrada' => 'enter', 'box_saida' => 'exit',
        'abastecimento_entrada' => 'enter', 'abastecimento_saida' => 'exit',
    ];

    /** @param array<string, string> $params */
    public static function handle(array $params): Response
    {
        $evento = Lookup::evento($params['evt']);
        $prova = Lookup::prova($evento, $params['p']);
        $json = $prova->getType() === 'laps' ? PublicFeed::jsonFor($evento->getEventoId(), $prova->getProvaId()) : null;
        if ($json === null) {
            throw new ApiError(404, ApiError::WRONG_TYPE, 'Esta prova não tem contagem de voltas.');
        }
        return new Response(self::shape($evento, $prova, (array)json_decode($json, true)), CachePolicy::forRace($evento));
    }

    /**
     * The JSON body, from the feed as PublicFeed returns it. No database.
     *
     * @param array<string, mixed> $feed
     * @return array<string, mixed>
     */
    public static function shape(Evento $evento, Prova $prova, array $feed): array
    {
        $race = $feed['corrida'] ?? [];
        $tempo = $prova->getTempo();

        return [
            'event_id' => $evento->getEventoId(),
            'prova_id' => $prova->getProvaId(),
            'name' => $prova->getNome(),
            // A race made of simulated passes, for testing: not to be shown
            // as a real one.
            'simulation' => (bool)($feed['simulacao'] ?? false),
            // When the numbers below were last recalculated. A race whose
            // calculated_at_ms stops moving while cars run is a stalled
            // count, not a quiet race. Null before the first calculation.
            'calculated_at_ms' => $feed['calculado_em_ms'] ?? null,
            'clock' => [
                'state' => self::rename($race['estado'] ?? null, self::RACE_STATES),
                'start_ms' => $race['largada_ms'] ?? null,
                'finish_ms' => $race['encerramento_ms'] ?? null,
                // Race time at server_time_ms: from the start, red flags
                // included, frozen at the finish. Null before the start.
                'elapsed_ms' => $race['decorrido_ms'] ?? null,
                'server_time_ms' => $feed['gerado_em_ms'] ?? null,
                // No prova records how long its race is planned to be yet.
                'planned_duration_ms' => null,
                // What the results page and overlaydata.php?time show: the
                // start the scoring has, in seconds, written by the lap
                // counting when it publishes, or by the spreadsheet when it
                // does not. The race clock above is the lap counting's own.
                'scoring_start_ms' => $tempo === null ? null : (int)$tempo * 1000,
                'prova_status' => EventoEndpoint::provaStatus($prova),
            ],
            'pace' => self::pace($feed['ritmo'] ?? null),
            'cars' => array_map([self::class, 'car'], $feed['carros'] ?? []),
            'best_lap' => self::bestLap($feed['melhor_volta'] ?? null),
            'best_lap_history' => array_map([self::class, 'bestLap'], $feed['novas_melhores_voltas'] ?? []),
            'recent_stops' => array_map([self::class, 'stopEvent'], $feed['ultimas_paradas'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $car
     * @return array<string, mixed>
     */
    private static function car(array $car): array
    {
        return [
            // Null for a car with no laps yet.
            'position' => $car['posicao'] ?? null,
            'number' => $car['numero'] ?? null,
            'team' => $car['equipe'] ?? null,
            'school' => $car['escola'] ?? null,
            'laps' => $car['voltas'] ?? null,
            'last_pass_ms' => $car['ultima_passagem_ms'] ?? null,
            'last_lap_ms' => $car['ultima_volta_ms'] ?? null,
            'best_lap_ms' => $car['melhor_volta_ms'] ?? null,
            'expected_lap_ms' => $car['volta_esperada_ms'] ?? null,
            'status' => self::rename($car['status'] ?? null, self::CAR_STATUSES),
            'status_since_ms' => $car['status_desde_ms'] ?? null,
            'pit' => self::stays($car['box'] ?? null),
            // Null when the prova does not log the fueling station.
            'fueling' => self::stays($car['abastecimento'] ?? null),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function stays(?array $stays): ?array
    {
        if ($stays === null) {
            return null;
        }
        return [
            'inside' => (bool)($stays['dentro'] ?? false),
            'since_ms' => $stays['desde_ms'] ?? null,
            'stops' => $stays['paradas'] ?? 0,
            'total_ms' => $stays['total_ms'] ?? 0,
            'last_ms' => $stays['ultima_ms'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function bestLap(?array $lap): ?array
    {
        if ($lap === null) {
            return null;
        }
        return [
            'number' => $lap['numero'] ?? null,
            'team' => $lap['equipe'] ?? null,
            'school' => $lap['escola'] ?? null,
            'lap' => $lap['volta'] ?? null,
            'lap_time_ms' => $lap['tempo_volta_ms'] ?? null,
            'at_ms' => $lap['ts_ms'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private static function stopEvent(array $event): array
    {
        return [
            'area' => self::rename($event['area'] ?? null, self::STOP_AREAS),
            'event' => self::rename($event['tipo'] ?? null, self::STOP_EVENTS),
            'number' => $event['carro'] ?? null,
            'at_ms' => $event['ts_ms'] ?? null,
            // An exit a reviewer marked by hand.
            'inferred' => (bool)($event['inferida'] ?? false),
            // An exit nobody logged, which the count assumed.
            'assumed' => (bool)($event['presumida'] ?? false),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function pace(?array $pace): ?array
    {
        if ($pace === null) {
            return null;
        }
        return [
            // The median lap of the field over the window; null under eight laps.
            'median_lap_ms' => $pace['volta_mediana_ms'] ?? null,
            'laps_in_window' => $pace['voltas_na_janela'] ?? 0,
            'window_s' => $pace['janela_s'] ?? null,
        ];
    }

    /**
     * The API's word for one of the feed's. A word the feed adds later
     * passes through as it is, so a consumer sees it rather than a null.
     *
     * @param array<string, string> $names
     */
    public static function rename(mixed $word, array $names): mixed
    {
        return is_string($word) ? ($names[$word] ?? $word) : $word;
    }
}
