<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\Ocorrencia;

/**
 * The race as race control and the pit log describe it: when it started,
 * when it was stopped by a red flag, when it ended, and when each car was
 * in the pit lane.
 *
 * Built once per engine run from the ocorrências. Everything that needs to
 * know "was the race running between these two moments?" asks this class.
 */
final class RaceTimeline
{
    public const AGUARDANDO = 'aguardando';
    public const CORRENDO = 'correndo';
    public const BANDEIRA_VERMELHA = 'bandeira_vermelha';
    public const ENCERRADA = 'encerrada';

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
     * @param Ocorrencia[] $ocorrencias in any order
     */
    public static function fromOcorrencias(array $ocorrencias): self
    {
        $timeline = new self();
        $valid = self::dropCorrected($ocorrencias);

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
        $timeline->buildPitIntervals($byTime);

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
        return self::overlapsAny($this->redFlagIntervals, $fromMs, $toMs);
    }

    public function isInPitAt(int $carro, int $tsMs): bool
    {
        return $this->pitEntryBefore($carro, $tsMs) !== null;
    }

    /** When the car entered the pit stay that contains $tsMs, or null if it is on track. */
    public function pitEntryBefore(int $carro, int $tsMs): ?int
    {
        foreach ($this->pitIntervals[$carro] ?? [] as [$in, $out]) {
            if ($tsMs >= $in && ($out === null || $tsMs < $out)) {
                return $in;
            }
        }
        return null;
    }

    /** When the car last left the pit lane at or before $tsMs, or null if it never did. */
    public function lastPitExitBefore(int $carro, int $tsMs): ?int
    {
        $lastExit = null;
        foreach ($this->pitIntervals[$carro] ?? [] as [$in, $out]) {
            if ($out !== null && $out <= $tsMs && ($lastExit === null || $out > $lastExit)) {
                $lastExit = $out;
            }
        }
        return $lastExit;
    }

    /** @return int[] cars logged as in the pit lane at $tsMs, in ascending order */
    public function carsInPitAt(int $tsMs): array
    {
        $cars = [];
        foreach (array_keys($this->pitIntervals) as $carro) {
            if ($this->isInPitAt($carro, $tsMs)) {
                $cars[] = $carro;
            }
        }
        sort($cars);
        return $cars;
    }

    public function overlapsPitStay(int $carro, int $fromMs, int $toMs): bool
    {
        return self::overlapsAny($this->pitIntervals[$carro] ?? [], $fromMs, $toMs);
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
     * A row that another row "substitui" was a mistake and is ignored.
     *
     * @param Ocorrencia[] $ocorrencias
     * @return Ocorrencia[] ordered by id, oldest first
     */
    private static function dropCorrected(array $ocorrencias): array
    {
        $correctedIds = [];
        foreach ($ocorrencias as $ocorrencia) {
            if ($ocorrencia->substituiId !== null) {
                $correctedIds[$ocorrencia->substituiId] = true;
            }
        }
        $valid = array_filter($ocorrencias, fn(Ocorrencia $o) => !isset($correctedIds[$o->id]));
        usort($valid, fn(Ocorrencia $a, Ocorrencia $b) => $a->id <=> $b->id);
        return $valid;
    }

    /** @param Ocorrencia[] $byTime */
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

    /** @param Ocorrencia[] $byTime */
    private function buildPitIntervals(array $byTime): void
    {
        $openSince = [];
        foreach ($byTime as $ocorrencia) {
            $carro = $ocorrencia->carro;
            if ($carro === null) {
                continue;
            }
            if ($ocorrencia->tipo === Ocorrencia::BOX_ENTRADA && !isset($openSince[$carro])) {
                $openSince[$carro] = $ocorrencia->tsMs;
            } elseif ($ocorrencia->tipo === Ocorrencia::BOX_SAIDA && isset($openSince[$carro])) {
                $this->pitIntervals[$carro][] = [$openSince[$carro], $ocorrencia->tsMs];
                unset($openSince[$carro]);
            }
        }
        foreach ($openSince as $carro => $since) {
            $this->pitIntervals[$carro][] = [$since, null];
        }
    }

    /** @param array<int, array{0: int, 1: ?int}> $intervals */
    private static function overlapsAny(array $intervals, int $fromMs, int $toMs): bool
    {
        foreach ($intervals as [$start, $end]) {
            if ($start < $toMs && ($end === null || $end > $fromMs)) {
                return true;
            }
        }
        return false;
    }
}
