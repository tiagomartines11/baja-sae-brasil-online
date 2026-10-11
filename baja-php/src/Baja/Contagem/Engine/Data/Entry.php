<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * One raw record: "source S says car N crossed the line at time T".
 *
 * The engine never changes the stored entry. `$carro` here is the car the
 * engine works with, which differs from `$carroDigitado` only when a
 * reviewer reassigned the entry.
 */
final class Entry
{
    public int $id;
    public int $fonteId;
    public int $dispositivoId;
    /** Car number after decisions were applied. */
    public int $carro;
    /** Car number exactly as the counter typed it. */
    public int $carroDigitado;
    /** Corrected time of the crossing, milliseconds since the epoch. */
    public int $tsMs;

    public function __construct(int $id, int $fonteId, int $dispositivoId, int $carro, int $tsMs)
    {
        $this->id = $id;
        $this->fonteId = $fonteId;
        $this->dispositivoId = $dispositivoId;
        $this->carro = $carro;
        $this->carroDigitado = $carro;
        $this->tsMs = $tsMs;
    }
}
