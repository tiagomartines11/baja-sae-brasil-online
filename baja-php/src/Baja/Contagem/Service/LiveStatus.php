<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\CarStatus;
use Baja\Contagem\Engine\Settings;

/**
 * The parts of a race that change with the clock, worked out from a stored
 * snapshot at the moment it is served.
 *
 * A snapshot is recalculated every few seconds at most, and only when
 * something changed. But a car becomes "atrasado" simply because time
 * passes. So the snapshot keeps the numbers the rule needs (each car's last
 * pass and expected lap, the race timeline, how far the counters have
 * uploaded) and this class applies the rule to them with the current time:
 * a little arithmetic per car, no database.
 *
 * The rule itself lives in the engine (CarStatus::statusAt), in one place.
 */
final class LiveStatus
{
    /**
     * @param array<string, mixed> $live the 'tempo_real' part of a snapshot (see SnapshotBuilder)
     * @return array<int, array{status: string, status_desde_ms: ?int}> car => its status now
     */
    public static function ofCars(array $live, Settings $settings, int $nowMs): array
    {
        $timeline = RaceContext::timelineOf($live['corrida'] ?? []);
        $syncedThroughMs = $live['sinc_min_ms'] ?? null;
        $allSyncedThrough = fn(int $momentMs): bool => $syncedThroughMs !== null && $momentMs <= $syncedThroughMs;

        $statusByCar = [];
        foreach ($live['carros'] ?? [] as $carro => $car) {
            [$status, $sinceMs] = CarStatus::statusAt(
                $nowMs,
                (int) $carro,
                isset($car['ultima_passagem_ms']) ? (int) $car['ultima_passagem_ms'] : null,
                isset($car['volta_esperada_ms']) ? (int) $car['volta_esperada_ms'] : null,
                $timeline,
                $settings,
                $allSyncedThrough
            );
            $statusByCar[(int) $carro] = ['status' => $status, 'status_desde_ms' => $sinceMs];
        }
        return $statusByCar;
    }

    /**
     * The race clock and state now.
     *
     * @param array<string, mixed> $live
     * @return array{largada_ms: ?int, encerramento_ms: ?int, estado: string, decorrido_ms: ?int, bandeiras_vermelhas: array}
     */
    public static function ofRace(array $live, int $nowMs): array
    {
        $timeline = RaceContext::timelineOf($live['corrida'] ?? []);
        return [
            'largada_ms' => $timeline->largadaMs,
            'encerramento_ms' => $timeline->encerramentoMs,
            'estado' => $timeline->stateAt($nowMs),
            'decorrido_ms' => $timeline->elapsedMs($nowMs),
            'bandeiras_vermelhas' => $timeline->redFlagIntervals,
        ];
    }
}
