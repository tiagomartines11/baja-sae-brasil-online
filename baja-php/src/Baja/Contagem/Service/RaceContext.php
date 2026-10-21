<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Engine\Settings;
use Baja\Model\Prova;
use Baja\Model\ProvaQuery;

/**
 * The few facts about a race that every count page needs in every answer:
 * when it started, whether it is stopped, which cars are in the pit lane or
 * fueling, which cars take part, how long the matching window is.
 *
 * Phones ask every five seconds. These facts change a handful of times in a
 * whole race, so they are kept in Redis and a heartbeat reads them without
 * touching MySQL (it does not even load the prova, which is why this class
 * takes the two ids).
 *
 * Whoever changes one of them (race control, the pit log, the settings
 * page) calls refresh(), which REPLACES the cached copy with a fresh one.
 * Merely deleting it would leave a gap in which a heartbeat that had read
 * the old facts a moment earlier could write them back, and phones would
 * show "waiting for the start" for half a minute after the start.
 *
 * The short expiry covers changes made elsewhere, such as a team being
 * marked present. Without Redis the context is simply rebuilt on every
 * request: slower, still right.
 */
final class RaceContext
{
    private const CACHE_SECONDS = 30;

    private string $eventoId;
    private string $provaId;
    private string $key;

    public function __construct(string $eventoId, string $provaId)
    {
        $this->eventoId = $eventoId;
        $this->provaId = $provaId;
        $this->key = 'contagem:' . $eventoId . ':' . $provaId . ':contexto';
    }

    /**
     * @return array<string, mixed> see build()
     */
    public function get(): array
    {
        $redis = RedisClient::get();
        if ($redis !== null) {
            try {
                $cached = $redis->get($this->key);
                $decoded = is_string($cached) ? json_decode($cached, true) : null;
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable $error) {
                error_log('Contagem\RaceContext: read failed: ' . $error->getMessage());
            }
        }
        return $this->fillIfStillEmpty();
    }

    /**
     * The cache was empty: builds the context and stores it ONLY IF nobody
     * stored one meanwhile ("SET NX"). The somebody would be refresh(),
     * called right after a change: its copy is newer than what this request
     * just read from the database, and must not be overwritten by it.
     *
     * @return array<string, mixed>
     */
    private function fillIfStillEmpty(): array
    {
        $context = $this->build();
        $redis = RedisClient::get();
        if ($redis === null) {
            return $context;
        }
        try {
            $json = (string) json_encode($context, JSON_UNESCAPED_UNICODE);
            $stored = $redis->set($this->key, $json, ['nx', 'ex' => self::CACHE_SECONDS]);
            if ($stored === false) {
                $newer = json_decode((string) $redis->get($this->key), true);
                if (is_array($newer)) {
                    return $newer;
                }
            }
        } catch (\Throwable $error) {
            error_log('Contagem\RaceContext: write failed: ' . $error->getMessage());
        }
        return $context;
    }

    /**
     * Rebuilds the context from the database and stores it. Call this
     * AFTER the change has been committed.
     *
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $context = $this->build();
        $redis = RedisClient::get();
        if ($redis !== null) {
            try {
                $redis->setex($this->key, self::CACHE_SECONDS, (string) json_encode($context, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $error) {
                error_log('Contagem\RaceContext: write failed: ' . $error->getMessage());
            }
        }
        return $context;
    }

    /**
     * The race timeline described by a context (or by the same keys of a
     * snapshot), for asking "what is the state right now?".
     *
     * @param array<string, mixed> $context
     */
    public static function timelineOf(array $context): RaceTimeline
    {
        $timeline = new RaceTimeline();
        $timeline->largadaMs = isset($context['largada_ms']) ? (int) $context['largada_ms'] : null;
        $timeline->encerramentoMs = isset($context['encerramento_ms']) ? (int) $context['encerramento_ms'] : null;
        $timeline->redFlagIntervals = $context['bandeiras_vermelhas'] ?? [];
        // JSON turns the car numbers into string keys; the engine wants integers.
        foreach ($context['box'] ?? [] as $carro => $intervals) {
            $timeline->pitIntervals[(int) $carro] = $intervals;
        }
        foreach ($context['abastecimento_paradas'] ?? [] as $carro => $intervals) {
            $timeline->fuelIntervals[(int) $carro] = $intervals;
        }
        $timeline->assumedExits = $context['saidas_presumidas'] ?? [];
        return $timeline;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describeTimeline(RaceTimeline $timeline): array
    {
        return [
            'largada_ms' => $timeline->largadaMs,
            'encerramento_ms' => $timeline->encerramentoMs,
            'bandeiras_vermelhas' => $timeline->redFlagIntervals,
            'box' => $timeline->pitIntervals,
            'abastecimento_paradas' => $timeline->fuelIntervals,
            // Exits nobody logged, which the count assumed (see HoldRule).
            // The stays above already end there; the list says which ones.
            'saidas_presumidas' => $timeline->assumedExits,
        ];
    }

    /**
     * Ends, in a timeline read from the log, the stays the last whole-race
     * count assumed over.
     *
     * The log alone still has such a car inside: nobody logged its exit.
     * The count decided it had left (the counters agreed on two passes a
     * lap apart) and counts its laps. Without this, the pit-log phone would
     * go on listing the car as inside and the stops report would show a
     * stay that grows for the rest of the race, both contradicting the
     * results page.
     *
     * An assumed exit is only applied while the log still has nothing
     * better: once somebody logs or marks the real exit earlier than the
     * assumed one, the log wins. (The next whole-race count drops the
     * assumption anyway; this covers the seconds in between.)
     *
     * @param array<int, array{area: string, carro: int, ts_ms: int, entrada_ms: int}> $assumedExits
     */
    public static function applyAssumedExits(RaceTimeline $timeline, array $assumedExits): void
    {
        foreach ($assumedExits as $assumed) {
            $area = (string) ($assumed['area'] ?? '');
            $carro = (int) ($assumed['carro'] ?? 0);
            $entryMs = (int) ($assumed['entrada_ms'] ?? 0);
            $exitMs = (int) ($assumed['ts_ms'] ?? 0);
            $stays = $area === RaceTimeline::AREA_ABASTECIMENTO
                ? $timeline->fuelIntervals[$carro] ?? []
                : $timeline->pitIntervals[$carro] ?? [];
            foreach ($stays as [$inMs, $outMs]) {
                $logHasNothingBetter = $outMs === null || $outMs > $exitMs;
                if ($inMs === $entryMs && $logHasNothingBetter) {
                    $timeline->assumeExit($area, $carro, $entryMs, $exitMs);
                }
            }
        }
    }

    /**
     * The exits the last whole-race count assumed, from the snapshot it
     * left. Empty when there is no snapshot yet.
     *
     * @return array<int, array{area: string, carro: int, ts_ms: int, entrada_ms: int}>
     */
    private static function exitsTheLastCountAssumed(Prova $prova): array
    {
        $lastCount = (new SnapshotStore($prova))->getFeedPart();
        $assumed = $lastCount['tempo_real']['corrida']['saidas_presumidas'] ?? [];
        return is_array($assumed) ? $assumed : [];
    }

    /**
     * @return array<string, mixed> the timeline keys of describeTimeline(), plus:
     *   valid_cars         cars that take part: [{numero, nome}]
     *   unclassified_cars  present but not classified: [{numero, nome}]
     *   janela_ms          the matching window
     *   abastecimento      whether the fueling area is in use
     *   simulacao          whether simulated entries are allowed
     */
    private function build(): array
    {
        $prova = ProvaQuery::create()->findPk([$this->eventoId, $this->provaId]);
        if ($prova === null) {
            return self::describeTimeline(new RaceTimeline()) + [
                'valid_cars' => [],
                'unclassified_cars' => [],
                'janela_ms' => (new Settings())->janelaMs,
                'abastecimento' => false,
                'simulacao' => false,
            ];
        }
        $settings = Settings::fromArray($prova->getParams()->contagem ?? null);
        $loader = new EngineInputLoader($prova);
        $roster = CarRoster::forProva($prova);
        $nameAndNumber = fn(array $car): array => ['numero' => $car['numero'], 'nome' => $car['nome']];

        $timeline = RaceTimeline::fromOcorrencias($loader->loadOcorrencias());
        self::applyAssumedExits($timeline, self::exitsTheLastCountAssumed($prova));
        $context = self::describeTimeline($timeline);
        $context['valid_cars'] = array_values(array_map($nameAndNumber, $roster->classified()));
        $context['unclassified_cars'] = array_values(array_map($nameAndNumber, $roster->unclassified()));
        $context['janela_ms'] = $settings->janelaMs;
        $context['abastecimento'] = $settings->abastecimento;
        $context['simulacao'] = $settings->permitirSimulacao;
        $context['publica'] = $settings->publishesToScoring();
        $context['passagem_inicial'] = $settings->passagemInicial;
        return $context;
    }
}
