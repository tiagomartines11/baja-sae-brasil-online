<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * One row of contagem_volta: a pass group seen as a lap of its car.
 *
 * "contada" laps are numbered 1..N in time order and are the official count.
 * "inicial" is the car's pass zero, numbered 0: an official pass that
 * completes no lap (the counting line is after the start line).
 * "pendente" rows are pass groups that do not count yet (they await a second
 * source or a reviewer); they are numbered after the counted laps of the car
 * only so that the table's primary key stays unique.
 */
final class Lap
{
    public const CONTADA = 'contada';
    public const INICIAL = 'inicial';
    public const PENDENTE = 'pendente';

    public int $carro;
    public int $numeroVolta;
    /** Fused time of the pass that ended the lap. */
    public int $tsMs;
    public int $dispersaoMs;
    /** How many sources agreed on the pass. */
    public int $fontes;
    /** Null for lap 1 when there is no start time, and for pending rows. */
    public ?int $tempoVoltaMs = null;
    public bool $elegivelMelhorVolta = false;
    public string $estado = self::CONTADA;

    /** True when the lap overlaps a red flag or a pit stay of the car. */
    public bool $interrompida = false;

    /** The pass group that ended this lap. Not stored; kept for the engine's own use. */
    public ?PassGroup $grupo = null;

    /** The pass group that started this lap. Null for lap 1 and for pending rows. */
    public ?PassGroup $grupoAnterior = null;

    public function __construct(int $carro, int $numeroVolta, int $tsMs, int $dispersaoMs, int $fontes)
    {
        $this->carro = $carro;
        $this->numeroVolta = $numeroVolta;
        $this->tsMs = $tsMs;
        $this->dispersaoMs = $dispersaoMs;
        $this->fontes = $fontes;
    }

    /** True for a completed, official lap (pass zero is official but is not a lap). */
    public function isCounted(): bool
    {
        return $this->estado === self::CONTADA;
    }

    /** True for an official pass: a counted lap or the car's pass zero. */
    public function isOfficialPass(): bool
    {
        return $this->estado === self::CONTADA || $this->estado === self::INICIAL;
    }
}
