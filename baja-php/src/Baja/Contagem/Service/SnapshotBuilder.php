<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\PublishedValues;
use Baja\Contagem\Review\StateSnapshot;

/**
 * Turns one full engine run into the plain array stored as a RaceSnapshot.
 *
 * The array has four parts, one per consumer:
 *
 *   estado      what the review screen draws (built by Review\StateSnapshot)
 *   feed        the clock-independent part of the public feed
 *   tempo_real  the numbers LiveStatus needs to work out, at read time,
 *               the race clock and each car's status
 *   melhores    each car's best lap in whole seconds, for publishing BEST
 */
final class SnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(ContagemService $service, EngineInput $input, EngineResult $result): array
    {
        $bestSecondsByCar = [];
        $liveCars = [];
        foreach ($result->cars as $carro => $state) {
            $bestSecondsByCar[$carro] = PublishedValues::bestLapSeconds($state->melhorVoltaMs);
            $liveCars[$carro] = [
                'ultima_passagem_ms' => $state->ultimaPassagemMs,
                'volta_esperada_ms' => $state->voltaEsperadaMs,
            ];
        }

        return [
            'estado' => StateSnapshot::fromResult($service, $input, $result),
            'feed' => PublicFeed::fromResult($result, $service->loadTeams(false)),
            'tempo_real' => [
                'corrida' => RaceContext::describeTimeline($result->timeline),
                'sinc_min_ms' => self::earliestSyncMark($input),
                'carros' => $liveCars,
            ],
            'melhores' => $bestSecondsByCar,
        ];
    }

    /**
     * How far EVERY counting source has uploaded: the earliest of their
     * marks. A car is only called late once all counters have uploaded past
     * the moment it became late; until then the pass that would prove it is
     * fine may be sitting in a phone. Null when some source has never synced.
     */
    private static function earliestSyncMark(EngineInput $input): ?int
    {
        $earliestMs = PHP_INT_MAX;
        foreach ($input->sources as $source) {
            if (!$source->countsPasses()) {
                continue;
            }
            if ($source->sincronizadoAteMs === null) {
                return null;
            }
            $earliestMs = min($earliestMs, $source->sincronizadoAteMs);
        }
        return $earliestMs;
    }
}
