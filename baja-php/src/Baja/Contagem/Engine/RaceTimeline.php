<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Ocorrencia;

/**
 * The race as race control and the pit log describe it: when it started,
 * when it was stopped by a red flag, when it ended, and when each car was
 * in the pit lane or in the fueling station.
 *
 * Built once per engine run from the ocorrências. Everything that needs to
 * know "was the race running between these two moments?" asks this class.
 *
 * CORRECTIONS AND CANCELLATIONS. Rows are never edited or deleted. A row
 * that names another one in `substituiId` either corrects it (a new time
 * for the same event) or, when it is a "cancelamento", removes it. They are
 * resolved from the newest row to the oldest, the way decisions are, so
 * that undoing works without special cases:
 *
 * - a cancelled row does nothing, so whatever it had corrected or cancelled
 *   is back in force (cancelling a correction brings the original time back;
 *   cancelling a cancellation restores the event);
 * - a corrected row is out of force for good while its correction stands,
 *   even when the correction is itself corrected later.
 *
 * THE TWO AREAS. The pit lane ("box") and the fueling station
 * ("abastecimento") are logged the same way and are independent: a car can
 * be in both at once. An exit with no entry before it, or an entry followed
 * by another entry with no exit in between, is an unpaired event. It is
 * listed and never blocks anything; pairing is by time, so the missing half
 * makes a stay of it whenever it arrives. A car still inside when the race
 * ends is taken to have left at the finish. See StopLog.
 */
final class RaceTimeline
{
    public const AGUARDANDO = 'aguardando';
    public const CORRENDO = 'correndo';
    public const BANDEIRA_VERMELHA = 'bandeira_vermelha';
    public const ENCERRADA = 'encerrada';

    public const AREA_BOX = 'box';
    public const AREA_ABASTECIMENTO = 'abastecimento';

    /** Start of the race. Null until race control starts it. */
    public ?int $largadaMs = null;

    /** End of the race. Null while it is still running. */
    public ?int $encerramentoMs = null;

    /**
     * Periods during which the race was stopped. An interval still open
     * (no restart yet) has a null end.
     *
     * @var array<int, array{0: int, 1: ?int}>
     */
    public array $redFlagIntervals = [];

    /**
     * Pit stays per car. A car still in the pit lane has a null end.
     *
     * @var array<int, array<int, array{0: int, 1: ?int}>> carro => [[in, out], ...]
     */
    public array $pitIntervals = [];

    /**
     * Stays in the fueling station per car, same shape as `$pitIntervals`.
     *
     * @var array<int, array<int, array{0: int, 1: ?int}>> carro => [[in, out], ...]
     */
    public array $fuelIntervals = [];

    /**
     * Stop events that have no partner: an exit with no entry, or an entry
     * that was followed by another entry with no exit in between. In time
     * order. `inferida` is true for an exit a reviewer inferred.
     *
     * @var array<int, array{area: string, tipo: string, carro: int, ts_ms: int, ocorrencia_id: int, inferida: bool}>
     */
    public array $unpairedStops = [];

    /**
     * Stop events whose time is unknown (`Ocorrencia::$qualidadeTempo` is
     * "desconhecido"): the `ts_ms` they carry is the phone's own clock. They
     * are kept out of the pairing and out of `$stopEvents`, and listed here
     * so the review screen can show them. Same shape as `$unpairedStops`.
     *
     * @var array<int, array{area: string, tipo: string, carro: int, ts_ms: int, ocorrencia_id: int, inferida: bool}>
     */
    public array $unknownTimeStops = [];

    /**
     * Every pit lane and fueling station event in force with a usable time,
     * paired or not, in time order. Same shape as `$unpairedStops`.
     *
     * @var array<int, array{area: string, tipo: string, carro: int, ts_ms: int, ocorrencia_id: int, inferida: bool}>
     */
    public array $stopEvents = [];

    /**
     * Exits the ENGINE assumed while counting, because the log of an area
     * still had a car inside while the counters agreed on two passes of it
     * a lap apart (see HoldRule). Each is ['area', 'carro', 'ts_ms' (the
     * assumed exit: one second before the first of those passes),
     * 'entrada_ms' (the entry of the stay it ends), 'saida_registrada_ms'
     * (the exit the log has for that stay, later than the assumed one, or
     * null when the log has none)]. They are not stored
     * anywhere: the stays above already end there, so everything that
     * reads this timeline agrees with the count. Only the cars that were
     * counted have theirs worked out.
     *
     * @var array<int, array{area: string, carro: int, ts_ms: int, entrada_ms: int, saida_registrada_ms: ?int}>
     */
    public array $assumedExits = [];

    /**
     * The rows still in force once corrections and cancellations were
     * resolved, oldest first (by id). A "cancelamento" is never in here.
     *
     * @var Ocorrencia[]
     */
    public array $eventosEmVigor = [];

    /**
     * @param Ocorrencia[] $ocorrencias in any order
     */
    public static function fromOcorrencias(array $ocorrencias): self
    {
        $timeline = new self();
        $valid = self::rowsInForce($ocorrencias);
        $timeline->eventosEmVigor = $valid;

        // Among several starts (race control edited the start time) the most
        // recently created one wins, whatever time it carries.
        foreach ($valid as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::LARGADA) {
                $timeline->largadaMs = $ocorrencia->tsMs;
            }
            if ($ocorrencia->tipo === Ocorrencia::ENCERRAMENTO) {
                $timeline->encerramentoMs = $ocorrencia->tsMs;
            }
        }

        $byTime = $valid;
        usort($byTime, fn(Ocorrencia $a, Ocorrencia $b) => [$a->tsMs, $a->id] <=> [$b->tsMs, $b->id]);

        $timeline->buildRedFlagIntervals($byTime);

        $stops = StopLog::pair($byTime, $timeline->encerramentoMs);
        $timeline->pitIntervals = $stops->stays[self::AREA_BOX];
        $timeline->fuelIntervals = $stops->stays[self::AREA_ABASTECIMENTO];
        $timeline->unpairedStops = $stops->unpaired;
        $timeline->unknownTimeStops = $stops->unknownTime;
        $timeline->stopEvents = $stops->events;

        return $timeline;
    }

    /** State of the race at a given moment. */
    public function stateAt(int $tsMs): string
    {
        if ($this->largadaMs === null || $tsMs < $this->largadaMs) {
            return self::AGUARDANDO;
        }
        if ($this->encerramentoMs !== null && $tsMs >= $this->encerramentoMs) {
            return self::ENCERRADA;
        }
        if ($this->isRedFlagAt($tsMs)) {
            return self::BANDEIRA_VERMELHA;
        }
        return self::CORRENDO;
    }

    /** True between the start and the finish, red flags included: the race is "on". */
    public function isRunningAt(int $tsMs): bool
    {
        $state = $this->stateAt($tsMs);
        return $state === self::CORRENDO || $state === self::BANDEIRA_VERMELHA;
    }

    /** Race clock: time since the start, frozen at the end. Null before the start. */
    public function elapsedMs(int $nowMs): ?int
    {
        if ($this->largadaMs === null) {
            return null;
        }
        $until = $this->encerramentoMs !== null ? min($nowMs, $this->encerramentoMs) : $nowMs;
        return max(0, $until - $this->largadaMs);
    }

    public function isRedFlagAt(int $tsMs): bool
    {
        foreach ($this->redFlagIntervals as [$start, $end]) {
            if ($tsMs >= $start && ($end === null || $tsMs < $end)) {
                return true;
            }
        }
        return false;
    }

    public function overlapsRedFlag(int $fromMs, int $toMs): bool
    {
        return StopLog::overlapsAny($this->redFlagIntervals, $fromMs, $toMs);
    }

    public function isInPitAt(int $carro, int $tsMs): bool
    {
        return $this->pitEntryBefore($carro, $tsMs) !== null;
    }

    /** When the car entered the pit stay that contains $tsMs, or null if it is not in the pit lane. */
    public function pitEntryBefore(int $carro, int $tsMs): ?int
    {
        return StopLog::entryOfStayAt($this->pitIntervals[$carro] ?? [], $tsMs);
    }

    /** When the car last left the pit lane at or before $tsMs, or null if it never did. */
    public function lastPitExitBefore(int $carro, int $tsMs): ?int
    {
        return StopLog::lastExitAtOrBefore($this->pitIntervals[$carro] ?? [], $tsMs);
    }

    /** @return int[] cars logged as in the pit lane at $tsMs, in ascending order */
    public function carsInPitAt(int $tsMs): array
    {
        return StopLog::carsInsideAt($this->pitIntervals, $tsMs);
    }

    public function overlapsPitStay(int $carro, int $fromMs, int $toMs): bool
    {
        return StopLog::overlapsAny($this->pitIntervals[$carro] ?? [], $fromMs, $toMs);
    }

    public function isFuelingAt(int $carro, int $tsMs): bool
    {
        return $this->fuelEntryBefore($carro, $tsMs) !== null;
    }

    /** When the car entered the fueling stay that contains $tsMs, or null if it is not in the fueling station. */
    public function fuelEntryBefore(int $carro, int $tsMs): ?int
    {
        return StopLog::entryOfStayAt($this->fuelIntervals[$carro] ?? [], $tsMs);
    }

    /** When the car last left the fueling station at or before $tsMs, or null if it never did. */
    public function lastFuelExitBefore(int $carro, int $tsMs): ?int
    {
        return StopLog::lastExitAtOrBefore($this->fuelIntervals[$carro] ?? [], $tsMs);
    }

    /** @return int[] cars logged as in the fueling station at $tsMs, in ascending order */
    public function carsFuelingAt(int $tsMs): array
    {
        return StopLog::carsInsideAt($this->fuelIntervals, $tsMs);
    }

    public function overlapsFuelStay(int $carro, int $fromMs, int $toMs): bool
    {
        return StopLog::overlapsAny($this->fuelIntervals[$carro] ?? [], $fromMs, $toMs);
    }

    /**
     * Ends the stay of a car that began at $entryMs at $exitMs instead of
     * where the log ends it (or leaves it open), and remembers that the
     * exit was assumed.
     *
     * @param string $area self::AREA_BOX or self::AREA_ABASTECIMENTO
     */
    public function assumeExit(string $area, int $carro, int $entryMs, int $exitMs): void
    {
        $stays = $area === self::AREA_ABASTECIMENTO ? $this->fuelIntervals[$carro] ?? [] : $this->pitIntervals[$carro] ?? [];
        $loggedExitMs = null;
        foreach ($stays as $index => [$inMs, $outMs]) {
            if ($inMs === $entryMs) {
                // (A stay the finish closed has no exit in the log either.)
                $loggedExitMs = $outMs !== null && $outMs !== $this->encerramentoMs ? $outMs : null;
                $stays[$index][1] = max($entryMs, $exitMs);
            }
        }
        if ($area === self::AREA_ABASTECIMENTO) {
            $this->fuelIntervals[$carro] = $stays;
        } else {
            $this->pitIntervals[$carro] = $stays;
        }
        $this->assumedExits[] = [
            'area' => $area,
            'carro' => $carro,
            'ts_ms' => max($entryMs, $exitMs),
            'entrada_ms' => $entryMs,
            'saida_registrada_ms' => $loggedExitMs,
        ];
    }

    /**
     * The stays of every car in one area.
     *
     * @param string $area self::AREA_BOX or self::AREA_ABASTECIMENTO
     * @return array<int, array<int, array{0: int, 1: ?int}>> carro => [[in, out], ...]
     */
    public function stayIntervals(string $area): array
    {
        return $area === self::AREA_ABASTECIMENTO ? $this->fuelIntervals : $this->pitIntervals;
    }

    /**
     * Time between two moments during which the race was actually running,
     * i.e. the plain difference minus any red-flag time in between.
     */
    public function racingMsBetween(int $fromMs, int $toMs): int
    {
        if ($toMs <= $fromMs) {
            return 0;
        }
        $stopped = 0;
        foreach ($this->redFlagIntervals as [$start, $end]) {
            $overlapStart = max($start, $fromMs);
            $overlapEnd = min($end ?? $toMs, $toMs);
            if ($overlapEnd > $overlapStart) {
                $stopped += $overlapEnd - $overlapStart;
            }
        }
        return ($toMs - $fromMs) - $stopped;
    }

    /**
     * The moment at which $racingMs of running time will have passed since
     * $fromMs, skipping over red flags. Null when an open red flag means
     * that moment has not been reached and cannot be predicted.
     */
    public function momentAfterRacing(int $fromMs, int $racingMs): ?int
    {
        $cursor = $fromMs;
        $remaining = $racingMs;
        foreach ($this->redFlagIntervals as [$start, $end]) {
            if ($end !== null && $end <= $cursor) {
                continue;
            }
            if ($start > $cursor) {
                $runningUntilFlag = $start - $cursor;
                if ($remaining <= $runningUntilFlag) {
                    return $cursor + $remaining;
                }
                $remaining -= $runningUntilFlag;
            }
            if ($end === null) {
                return null;
            }
            $cursor = $end;
        }
        return $cursor + $remaining;
    }

    /** @return array<string, mixed> */
    public function toArray(int $nowMs): array
    {
        return [
            'largada_ms' => $this->largadaMs,
            'encerramento_ms' => $this->encerramentoMs,
            'estado' => $this->stateAt($nowMs),
            'decorrido_ms' => $this->elapsedMs($nowMs),
            'bandeiras_vermelhas' => $this->redFlagIntervals,
        ];
    }

    /**
     * Resolves corrections and cancellations, from the newest row to the
     * oldest (see the class comment). By the time a row is reached, every
     * later row that could cancel or correct it has already been seen.
     *
     * This is THE definition of "in force". The race-control page asks
     * here too instead of working it out again: two implementations once
     * disagreed about a correction of a correction, and an event could not
     * be corrected twice.
     *
     * @param Ocorrencia[] $ocorrencias every row of the race, in any order
     * @return Ocorrencia[] the rows in force, ordered by id, oldest first; never a "cancelamento"
     */
    public static function rowsInForce(array $ocorrencias): array
    {
        $newestFirst = $ocorrencias;
        usort($newestFirst, fn(Ocorrencia $a, Ocorrencia $b) => $b->id <=> $a->id);

        $cancelledIds = [];
        $correctedIds = [];
        $inForce = [];
        foreach ($newestFirst as $ocorrencia) {
            if (isset($cancelledIds[$ocorrencia->id])) {
                // Cancelled: it is as if this row had never been written,
                // so the row it pointed at is left alone.
                continue;
            }
            $isCancellation = $ocorrencia->tipo === Ocorrencia::CANCELAMENTO;
            if ($ocorrencia->substituiId !== null) {
                if ($isCancellation) {
                    $cancelledIds[$ocorrencia->substituiId] = true;
                } else {
                    $correctedIds[$ocorrencia->substituiId] = true;
                }
            }
            if (!$isCancellation && !isset($correctedIds[$ocorrencia->id])) {
                $inForce[] = $ocorrencia;
            }
        }
        return array_reverse($inForce);
    }

    /**
     * Pairs each red flag with the restart that follows it. An event with
     * no partner changes nothing: a red flag while the race is already
     * stopped, or a restart while it is running (its red flag was cancelled,
     * say), is ignored, so cancelling one half of a pair can never produce
     * overlapping or backwards stoppages.
     *
     * @param Ocorrencia[] $byTime
     */
    private function buildRedFlagIntervals(array $byTime): void
    {
        $openSince = null;
        foreach ($byTime as $ocorrencia) {
            if ($ocorrencia->tipo === Ocorrencia::BANDEIRA_VERMELHA && $openSince === null) {
                $openSince = $ocorrencia->tsMs;
            } elseif ($ocorrencia->tipo === Ocorrencia::RELARGADA && $openSince !== null) {
                $this->redFlagIntervals[] = [$openSince, $ocorrencia->tsMs];
                $openSince = null;
            }
        }
        if ($openSince !== null) {
            // Still stopped. The end of the race also ends the stoppage.
            $this->redFlagIntervals[] = [$openSince, $this->encerramentoMs !== null && $this->encerramentoMs > $openSince ? $this->encerramentoMs : null];
        }
    }
}
