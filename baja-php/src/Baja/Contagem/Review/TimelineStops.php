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
 *
 * Two more kinds of event are told apart:
 *
 *   - an event of UNKNOWN TIME (its phone had no usable clock): the engine
 *     keeps it out of the pairing, and it has no place on a time axis, so it
 *     is listed apart ("paradas_sem_horario");
 *   - an exit a REVIEWER inferred from a pass at the line ("Marcar saída do
 *     box"): nobody at the pit lane logged it, and the lane says so;
 *   - an exit the ENGINE assumed ("saída presumida"): the log had the car
 *     inside while two counters agreed on two passes of it a lap apart, so
 *     the count went on as if it had left one second before the first of
 *     them. It is no row of the database. It is drawn where it ends the
 *     stay, marked as assumed, until a person confirms it (it then becomes
 *     an inferred exit) or the pit log is put right.
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
        $inForce = [];
        foreach (RaceTimeline::rowsInForce($ocorrencias) as $ocorrencia) {
            $inForce[$ocorrencia->id] = true;
        }
        $unpairedIds = [];
        foreach ($timeline->unpairedStops as $unpaired) {
            $unpairedIds[(int) $unpaired['ocorrencia_id']] = true;
        }

        $inferredIds = self::inferredIds($ocorrencias);

        $rows = [];
        foreach ($ocorrencias as $ocorrencia) {
            // A cancellation is nothing itself; it only removes its target.
            $isCancellation = $ocorrencia->tipo === Ocorrencia::CANCELAMENTO;
            if ($isCancellation || !isset($inForce[$ocorrencia->id]) || self::hasUnknownTime($ocorrencia) || !$wantsOcorrencia($ocorrencia)) {
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
                // An exit marked by a reviewer, not logged at the pit lane.
                'inferida' => isset($inferredIds[$ocorrencia->id]),
                'presumida' => false,
            ];
        }
        foreach (self::describeAssumedExits($timeline) as $assumed) {
            $exitType = $assumed['area'] === self::BOX ? Ocorrencia::BOX_SAIDA : Ocorrencia::ABASTECIMENTO_SAIDA;
            // Asked like a stored event: same car, same moment.
            if (!$wantsOcorrencia(new Ocorrencia(0, $exitType, $assumed['ts_ms'], $assumed['carro']))) {
                continue;
            }
            $rows[] = [
                // Not a row of the database: there is nothing to point at.
                'id' => null,
                'tipo' => $exitType,
                'carro' => $assumed['carro'],
                'ts_ms' => $assumed['ts_ms'],
                'area' => $assumed['area'],
                'box' => $assumed['area'] === self::BOX,
                'sem_par' => false,
                'inferida' => false,
                'presumida' => true,
            ];
        }
        usort($rows, fn(array $a, array $b) => [$a['ts_ms'], $a['id'] ?? PHP_INT_MAX] <=> [$b['ts_ms'], $b['id'] ?? PHP_INT_MAX]);
        return $rows;
    }

    /**
     * The exits the engine assumed, oldest first.
     *
     * @return array<int, array{area: string, carro: int, ts_ms: int, entrada_ms: int}>
     */
    public static function describeAssumedExits(RaceTimeline $timeline): array
    {
        $rows = [];
        foreach ($timeline->assumedExits ?? [] as $assumed) {
            $rows[] = [
                'area' => (string) $assumed['area'],
                'carro' => (int) $assumed['carro'],
                'ts_ms' => (int) $assumed['ts_ms'],
                'entrada_ms' => (int) $assumed['entrada_ms'],
            ];
        }
        usort($rows, fn(array $a, array $b) => [$a['ts_ms'], $a['carro']] <=> [$b['ts_ms'], $b['carro']]);
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

    /**
     * Pit and fueling events of unknown time. The engine pairs none of
     * them: an entry whose time nobody knows cannot open a stay. They are
     * listed so that somebody notices and logs the stop again by hand.
     *
     * @param int[]|null $cars only these cars; null for every car
     * @return array<int, array{area: string, tipo: string, carro: int, ocorrencia_id: int}> by car, then by row
     */
    public static function describeUnknownTime(RaceTimeline $timeline, ?array $cars = null): array
    {
        $rows = [];
        foreach ($timeline->unknownTimeStops ?? [] as $stop) {
            if ($cars !== null && !in_array((int) $stop['carro'], $cars, true)) {
                continue;
            }
            $rows[] = [
                'area' => (string) $stop['area'],
                'tipo' => (string) $stop['tipo'],
                'carro' => (int) $stop['carro'],
                'ocorrencia_id' => (int) $stop['ocorrencia_id'],
            ];
        }
        usort($rows, fn(array $a, array $b) => [$a['carro'], $a['ocorrencia_id']] <=> [$b['carro'], $b['ocorrencia_id']]);
        return $rows;
    }

    /**
     * Ids of the exits a reviewer inferred. A correction of such an exit
     * (a new time typed in race control) is still an inferred exit: nobody
     * at the pit lane saw it either.
     *
     * @param Ocorrencia[] $ocorrencias
     * @return array<int, true>
     */
    public static function inferredIds(array $ocorrencias): array
    {
        $oldestFirst = $ocorrencias;
        usort($oldestFirst, fn(Ocorrencia $a, Ocorrencia $b) => $a->id <=> $b->id);

        $inferred = [];
        foreach ($oldestFirst as $ocorrencia) {
            $correctsAnInferredExit = $ocorrencia->tipo !== Ocorrencia::CANCELAMENTO
                && $ocorrencia->substituiId !== null && isset($inferred[$ocorrencia->substituiId]);
            if (($ocorrencia->inferida ?? false) || $correctsAnInferredExit) {
                $inferred[$ocorrencia->id] = true;
            }
        }
        return $inferred;
    }

    private static function hasUnknownTime(Ocorrencia $ocorrencia): bool
    {
        return ($ocorrencia->qualidadeTempo ?? 'exato') === 'desconhecido';
    }

    private static function areaOf(string $tipo): ?string
    {
        if (in_array($tipo, Ocorrencia::TIPOS_BOX, true)) {
            return self::BOX;
        }
        return in_array($tipo, Ocorrencia::TIPOS_ABASTECIMENTO, true) ? self::ABASTECIMENTO : null;
    }
}
