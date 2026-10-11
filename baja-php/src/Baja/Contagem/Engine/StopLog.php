<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Ocorrencia;

/**
 * The pit lane and fueling station logs, turned into stays.
 *
 * Each area has somebody logging cars going in and somebody logging cars
 * coming out, usually two people on two phones. Entries and exits are
 * paired per area and per car, in time order. People miss cars, so an event
 * can be left without a partner: an exit with no entry before it, or a
 * second entry with no exit in between. Such an event is kept and listed,
 * and blocks nothing; because pairing goes by time, the missing half makes
 * a stay of it whenever it is uploaded.
 *
 * RaceTimeline builds one of these and exposes what it found; the helpers
 * at the bottom answer the questions asked of a list of stays.
 */
final class StopLog
{
    /** Which area each stop event belongs to, and whether it is the way in. */
    private const STOP_EVENTS = [
        Ocorrencia::BOX_ENTRADA => [RaceTimeline::AREA_BOX, true],
        Ocorrencia::BOX_SAIDA => [RaceTimeline::AREA_BOX, false],
        Ocorrencia::ABASTECIMENTO_ENTRADA => [RaceTimeline::AREA_ABASTECIMENTO, true],
        Ocorrencia::ABASTECIMENTO_SAIDA => [RaceTimeline::AREA_ABASTECIMENTO, false],
    ];

    /**
     * Stays per area and car. A car still inside has a null end.
     *
     * @var array<string, array<int, array<int, array{0: int, 1: ?int}>>> area => carro => [[in, out], ...]
     */
    public array $stays = [RaceTimeline::AREA_BOX => [], RaceTimeline::AREA_ABASTECIMENTO => []];

    /** @var array<int, array{area: string, tipo: string, carro: int, ts_ms: int, ocorrencia_id: int}> events with no partner, in time order */
    public array $unpaired = [];

    /** @var array<int, array{area: string, tipo: string, carro: int, ts_ms: int, ocorrencia_id: int}> every stop event, in time order */
    public array $events = [];

    /**
     * Pairs entries and exits per area and car.
     *
     * @param Ocorrencia[] $byTime the events in force, in time order (earlier id first within a millisecond)
     */
    public static function pair(array $byTime): self
    {
        $log = new self();
        $stays = $log->stays;
        $openSince = [RaceTimeline::AREA_BOX => [], RaceTimeline::AREA_ABASTECIMENTO => []];

        foreach ($byTime as $ocorrencia) {
            $carro = $ocorrencia->carro;
            if ($carro === null || !isset(self::STOP_EVENTS[$ocorrencia->tipo])) {
                continue;
            }
            [$area, $isEntry] = self::STOP_EVENTS[$ocorrencia->tipo];
            $event = [
                'area' => $area,
                'tipo' => $ocorrencia->tipo,
                'carro' => $carro,
                'ts_ms' => $ocorrencia->tsMs,
                'ocorrencia_id' => $ocorrencia->id,
            ];
            $log->events[] = $event;

            $isInside = isset($openSince[$area][$carro]);
            if ($isEntry === $isInside) {
                // A second entry while inside, or an exit while outside.
                $log->unpaired[] = $event;
            } elseif ($isEntry) {
                $openSince[$area][$carro] = $ocorrencia->tsMs;
            } else {
                $stays[$area][$carro][] = [$openSince[$area][$carro], $ocorrencia->tsMs];
                unset($openSince[$area][$carro]);
            }
        }
        foreach ($openSince as $area => $sinceByCar) {
            foreach ($sinceByCar as $carro => $since) {
                $stays[$area][$carro][] = [$since, null];
            }
        }
        $log->stays = $stays;
        return $log;
    }

    /**
     * @param array<int, array{0: int, 1: ?int}> $stays
     * @return int|null when the stay that contains $tsMs began
     */
    public static function entryOfStayAt(array $stays, int $tsMs): ?int
    {
        foreach ($stays as [$in, $out]) {
            if ($tsMs >= $in && ($out === null || $tsMs < $out)) {
                return $in;
            }
        }
        return null;
    }

    /** @param array<int, array{0: int, 1: ?int}> $stays */
    public static function lastExitAtOrBefore(array $stays, int $tsMs): ?int
    {
        $lastExit = null;
        foreach ($stays as [$in, $out]) {
            if ($out !== null && $out <= $tsMs && ($lastExit === null || $out > $lastExit)) {
                $lastExit = $out;
            }
        }
        return $lastExit;
    }

    /**
     * @param array<int, array<int, array{0: int, 1: ?int}>> $staysByCar
     * @return int[]
     */
    public static function carsInsideAt(array $staysByCar, int $tsMs): array
    {
        $cars = [];
        foreach ($staysByCar as $carro => $stays) {
            if (self::entryOfStayAt($stays, $tsMs) !== null) {
                $cars[] = $carro;
            }
        }
        sort($cars);
        return $cars;
    }

    /** @param array<int, array{0: int, 1: ?int}> $intervals */
    public static function overlapsAny(array $intervals, int $fromMs, int $toMs): bool
    {
        foreach ($intervals as [$start, $end]) {
            if ($start < $toMs && ($end === null || $end > $fromMs)) {
                return true;
            }
        }
        return false;
    }
}
