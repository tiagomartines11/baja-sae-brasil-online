<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * One raw record: "source S says car N crossed the line at time T".
 *
 * The engine never changes the stored entry. `$carro` here is the car the
 * engine works with, which differs from `$carroDigitado` only when a
 * reviewer reassigned the entry, or when the number could not be read and
 * the engine worked out which car it was (see UnreadAssigner).
 *
 * AN UNREAD PASS ("??") has no number at all: a counter saw a car cross
 * and could not read it, a camera could not read the plate, or a timing
 * sensor marked the moment (a sensor never knows the car). Its
 * `$carroDigitado` is null, and so is `$carro` until a decision or the
 * engine gives it one. Such an entry is kept out of every per-car step
 * while it has no car.
 *
 * The engine never drops or refuses an entry because of its time. An entry
 * from before the start, from the future or from an absurd date is kept and
 * ends up shown and flagged like any other.
 */
final class Entry
{
    /** Stamped from a server anchor taken in the same page load: as good as hand timing gets. */
    public const TEMPO_EXATO = 'exato';
    /**
     * Typed offline and stamped later from an anchor of an EARLIER page load,
     * so the phone's own clock took part. Matched like any other entry, and
     * its pass group is flagged for the reviewer.
     */
    public const TEMPO_ESTIMADO = 'estimado';
    /**
     * No usable anchor at all: the time is whatever the phone's clock said.
     * Never matched or counted automatically; flagged one by one.
     */
    public const TEMPO_DESCONHECIDO = 'desconhecido';

    public int $id;
    public int $fonteId;
    public int $dispositivoId;
    /** Car number after decisions were applied; null for an unread pass nobody has given a car yet. */
    public ?int $carro;
    /** Car number exactly as the counter typed it; null for an unread pass ("??"). */
    public ?int $carroDigitado;
    /** Corrected time of the crossing, milliseconds since the epoch. */
    public int $tsMs;
    /** How far the time can be trusted: one of the TEMPO_* constants. Set by whoever loads the entry. */
    public string $qualidadeTempo = self::TEMPO_EXATO;

    /**
     * An unread pass whose car the engine worked out by itself (see
     * UnreadAssigner): derived on every run, never stored. Only ever true
     * on a copy; the raw entry is not touched.
     */
    public bool $atribuidoAutomaticamente = false;

    public function __construct(int $id, int $fonteId, int $dispositivoId, ?int $carro, int $tsMs)
    {
        $this->id = $id;
        $this->fonteId = $fonteId;
        $this->dispositivoId = $dispositivoId;
        $this->carro = $carro;
        $this->carroDigitado = $carro;
        $this->tsMs = $tsMs;
    }

    /** True for a pass stored with no car ("??", or a timing sensor's mark), whatever it counts for now. */
    public function isUnread(): bool
    {
        return $this->carroDigitado === null;
    }
}
