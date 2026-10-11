<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * Entries from different sources that the engine believes describe the same
 * physical crossing of the line by one car.
 */
final class PassGroup
{
    public int $carro;

    /** @var Entry[] in time order; at most one per source */
    public array $entries = [];

    /** Time of the first entry. */
    public int $primeiroTsMs;

    /**
     * The pass time: the middle value (median) of the times the sources
     * recorded, or the time of a precise source when there is one. It only
     * depends on the group's own entries, so it never changes after the fact
     * unless the group itself changes. Used for ordering, for LAST and for
     * car status.
     */
    public int $tsMs = 0;

    /**
     * The pass time with each counter's typing habit removed (see
     * PassTimeFuser). Used only to measure lap durations and agreement.
     */
    public int $tsAjustadoMs = 0;

    /** Spread between the sources' habit-corrected times, in ms. 0 for a single source. */
    public int $dispersaoMs = 0;

    /** True when a "precisa" source is in the group and supplied the time. */
    public bool $tempoPreciso = false;

    /** A reviewer confirmed this group ("confirmar"). */
    public bool $confirmadoPorRevisor = false;

    /** The group contains a pass added by a reviewer ("adicionar"). */
    public bool $adicionadoPorRevisor = false;

    /** Decided by PublishRule and PassRoles: is this group an official pass in the current publish mode? */
    public bool $contado = false;

    /**
     * Would this group be a pass under the default rule (two sources agree,
     * or a reviewer said so)? Flags and the pace model look at this instead
     * of `contado`, so that they come out the same in every publish mode.
     */
    public bool $referencia = false;

    /**
     * The car's initial crossing, typed inside the start window (setting
     * `janela_largada_s`). Kept and shown, never a lap.
     */
    public bool $dentroDaJanelaDeLargada = false;

    /**
     * The pass time is before the start of the race. Kept and shown, and
     * not an official pass unless a reviewer confirms it; a confirmed one is
     * the car's pass zero (the start was recorded a little late).
     */
    public bool $antesDaLargada = false;

    /** One of the entries has an estimated time (`Entry::TEMPO_ESTIMADO`). */
    public bool $tempoEstimado = false;

    /** A pass after the finish that the `apos_encerramento` rule leaves out. Shown, never a lap. */
    public bool $ignoradoAposEncerramento = false;

    /**
     * Left out by the race rules (start window, after the finish) when the
     * race is judged under the default counting rule. Review flags skip
     * such a group in every publish mode: it is nobody's mistake.
     */
    public bool $foraDaReferencia = false;

    /**
     * Number of the official pass: 0 for the car's pass zero (not a lap),
     * 1 for the pass that completes lap 1, and so on. Null while not counted.
     */
    public ?int $numeroVolta = null;

    /** @var string[] types of the open flags attached to this group */
    public array $alertasAbertos = [];

    public function __construct(Entry $first)
    {
        $this->carro = $first->carro;
        $this->primeiroTsMs = $first->tsMs;
        $this->entries[] = $first;
    }

    public function hasSource(int $fonteId): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->fonteId === $fonteId) {
                return true;
            }
        }
        return false;
    }

    /** @return int[] ids of the real sources in the group (the reviewer is not one) */
    public function realSourceIds(): array
    {
        $ids = [];
        foreach ($this->entries as $entry) {
            if ($entry->fonteId !== Source::REVISOR_ID) {
                $ids[] = $entry->fonteId;
            }
        }
        return $ids;
    }

    public function sourceCount(): int
    {
        return count($this->entries);
    }

    /** @return int[] */
    public function entryIds(): array
    {
        $ids = [];
        foreach ($this->entries as $entry) {
            $ids[] = $entry->id;
        }
        return $ids;
    }

    /**
     * The smallest entry id in the group. It never changes when another
     * source's entry arrives late, so it is what flag ids are built from.
     */
    public function anchorEntryId(): int
    {
        return min($this->entryIds());
    }

    /** True when a reviewer vouched for this group: confirmed it, or added the pass. */
    public function isReviewersWord(): bool
    {
        return $this->confirmadoPorRevisor || $this->adicionadoPorRevisor;
    }

    /** True when exactly one real source recorded it and no reviewer vouched for it. */
    public function isUnconfirmedSingleSource(): bool
    {
        return count($this->entries) === 1
            && !$this->confirmadoPorRevisor
            && !$this->adicionadoPorRevisor;
    }
}
