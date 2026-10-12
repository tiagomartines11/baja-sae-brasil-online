<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Entry;
use Baja\Contagem\Engine\Data\Ocorrencia;

/**
 * The pit lane and fueling station logs, turned into stays.
 *
 * Each area has somebody logging cars going in and somebody logging cars
 * coming out, usually two people on two phones. Entries and exits are
 * paired per area and per car, in time order. People miss cars, so an event
 * can be left without a partner: an exit with no entry before it, or an
 * entry whose exit nobody logged. Such an event is kept and listed, and
 * blocks nothing; because pairing goes by time, the missing half makes a
 * stay of it whenever it is uploaded.
 *
 * A MISSED EXIT must not turn into a stay that lasts for hours:
 * - when a car that is logged as inside enters AGAIN, it obviously left in
 *   between. The OLDER entry is the one left without a partner, and the
 *   new one opens the stay the car is in now;
 * - a car still logged as inside when the race ends is taken to have left
 *   at the finish.
 *
 * AN EVENT OF UNKNOWN TIME (the phone could not take its time from the
 * server) carries whatever the phone's clock said. Pairing it would invent
 * a stay at a moment nobody knows, so it is listed apart and pairs with
 * nothing.
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

    /** @var array<int, array{area: string, tipo: string, carro: int, ts_ms: int, ocorrencia_id: int, inferida: bool}> every stop event with a usable time, in time order */
    public array $events = [];

    /** @var array<int, array{area: string, tipo: string, carro: int, ts_ms: int, ocorrencia_id: int, inferida: bool}> events of unknown time: listed, never paired */
    public array $unknownTime = [];

    /**
     * Pairs entries and exits per area and car.
     *
     * @param Ocorrencia[] $byTime the events in force, in time order (earlier id first within a millisecond)
     * @param int|null $encerramentoMs the end of the race, when it has one
     */
    public static function pair(array $byTime, ?int $encerramentoMs = null): self
    {
        $log = new self();
        $stays = $log->stays;
        /** @var array<string, array<int, array<string, mixed>>> area => carro => the entry event of the stay under way */
        $openEntry = [RaceTimeline::AREA_BOX => [], RaceTimeline::AREA_ABASTECIMENTO => []];

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
                'inferida' => $ocorrencia->inferida,
            ];
            if ($ocorrencia->qualidadeTempo === Entry::TEMPO_DESCONHECIDO) {
                $log->unknownTime[] = $event;
                continue;
            }
            $log->events[] = $event;

            $entryUnderWay = $openEntry[$area][$carro] ?? null;
            if ($isEntry) {
                if ($entryUnderWay !== null) {
                    // The car left in between and nobody logged it: the
                    // older entry is the one without a partner.
                    $log->unpaired[] = $entryUnderWay;
                }
                $openEntry[$area][$carro] = $event;
            } elseif ($entryUnderWay === null) {
                // An exit while outside.
                $log->unpaired[] = $event;
            } else {
                $stays[$area][$carro][] = [$entryUnderWay['ts_ms'], $ocorrencia->tsMs];
                unset($openEntry[$area][$carro]);
            }
        }
        foreach ($openEntry as $area => $entryByCar) {
            foreach ($entryByCar as $carro => $entry) {
                $sinceMs = $entry['ts_ms'];
                // Still inside when the race ended: the stay ends with the race.
                $stays[$area][$carro][] = [$sinceMs, $encerramentoMs !== null && $encerramentoMs > $sinceMs ? $encerramentoMs : null];
            }
        }
        if ($log->unpaired !== []) {
            // An older entry is noticed only when the next one arrives; put
            // the list back in time order.
            usort($log->unpaired, fn(array $a, array $b) => [$a['ts_ms'], $a['ocorrencia_id']] <=> [$b['ts_ms'], $b['ocorrencia_id']]);
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
