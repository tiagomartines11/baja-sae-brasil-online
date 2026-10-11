<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\RaceTimeline;

/**
 * The lanes of a timeline that are not a counter's: race control, the pit
 * lane and the fueling station.
 *
 * The pit lane and the fueling station are logged by one person at the way
 * in and another at the way out, so events go missing: an exit with no
 * entry, a second entry with no exit. Those are stored like any other and
 * shown here marked "sem par" (unpaired). They never block anything; the
 * reviewer just has to be able to see that the log has a hole.
 */
final class TimelineStops
{
    public const BOX = 'box';
    public const ABASTECIMENTO = 'abastecimento';

    /**
     * @param Ocorrencia[] $ocorrencias every stored event
     * @param callable(Ocorrencia): bool $wantsOcorrencia
     * @return array<int, array<string, mixed>> the events in force, in time order
     */
    public static function describeOcorrencias(array $ocorrencias, RaceTimeline $timeline, callable $wantsOcorrencia): array
    {
        // A row that another row corrected or cancelled was a mistake; the
        // engine ignores it and so does the timeline.
        $takenOut = RaceEvents::resolve($ocorrencias);
        $unpairedIds = [];
        foreach ($timeline->unpairedStops as $unpaired) {
            $unpairedIds[(int) $unpaired['ocorrencia_id']] = true;
        }

        $rows = [];
        foreach ($ocorrencias as $ocorrencia) {
            // A cancellation is nothing itself; it only removes its target.
            $isCancellation = $ocorrencia->tipo === Ocorrencia::CANCELAMENTO;
            if ($isCancellation || isset($takenOut[$ocorrencia->id]) || !$wantsOcorrencia($ocorrencia)) {
                continue;
            }
            $area = self::areaOf($ocorrencia->tipo);
            $rows[] = [
                'id' => $ocorrencia->id,
                'tipo' => $ocorrencia->tipo,
                'carro' => $ocorrencia->carro,
                'ts_ms' => $ocorrencia->tsMs,
                // null for a race-control event.
                'area' => $area,
                'box' => $area === self::BOX,
                'sem_par' => isset($unpairedIds[$ocorrencia->id]),
            ];
        }
        usort($rows, fn(array $a, array $b) => [$a['ts_ms'], $a['id']] <=> [$b['ts_ms'], $b['id']]);
        return $rows;
    }

    /**
     * The stays the engine paired up: "car 12 was in the pit lane from here
     * to there". Drawn as bars under the marks, so a pass typed while the
     * car was inside is seen at a glance.
     *
     * @param int|null $carro only this car's stays; null for every car
     * @return array<int, array{area: string, carro: int, de_ms: int, ate_ms: ?int}> a stay still open has no end
     */
    public static function describeStays(RaceTimeline $timeline, ?int $carro, ?int $fromMs, ?int $untilMs): array
    {
        $stays = [];
        $byArea = [self::BOX => $timeline->pitIntervals, self::ABASTECIMENTO => $timeline->fuelIntervals];
        foreach ($byArea as $area => $intervalsByCar) {
            foreach ($intervalsByCar as $car => $intervals) {
                if ($carro !== null && (int) $car !== $carro) {
                    continue;
                }
                foreach ($intervals as [$in, $out]) {
                    $endsBeforeWindow = $fromMs !== null && $out !== null && $out < $fromMs;
                    $startsAfterWindow = $untilMs !== null && $in > $untilMs;
                    if (!$endsBeforeWindow && !$startsAfterWindow) {
                        $stays[] = ['area' => $area, 'carro' => (int) $car, 'de_ms' => $in, 'ate_ms' => $out];
                    }
                }
            }
        }
        usort($stays, fn(array $a, array $b) => [$a['de_ms'], $a['carro']] <=> [$b['de_ms'], $b['carro']]);
        return $stays;
    }

    /**
     * Unpaired pit and fueling events of the whole race, for the review
     * state: a short list under the sources, so a hole in the log is seen
     * without opening every car.
     *
     * @return array<int, array{area: string, tipo: string, carro: int, ts_ms: int}>
     */
    public static function describeUnpaired(RaceTimeline $timeline): array
    {
        $rows = [];
        foreach ($timeline->unpairedStops as $unpaired) {
            $rows[] = [
                'area' => (string) $unpaired['area'],
                'tipo' => (string) $unpaired['tipo'],
                'carro' => (int) $unpaired['carro'],
                'ts_ms' => (int) $unpaired['ts_ms'],
            ];
        }
        usort($rows, fn(array $a, array $b) => [$a['ts_ms'], $a['carro']] <=> [$b['ts_ms'], $b['carro']]);
        return $rows;
    }

    private static function areaOf(string $tipo): ?string
    {
        if (in_array($tipo, Ocorrencia::TIPOS_BOX, true)) {
            return self::BOX;
        }
        return in_array($tipo, Ocorrencia::TIPOS_ABASTECIMENTO, true) ? self::ABASTECIMENTO : null;
    }
}
