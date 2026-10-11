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

    /** Time of the first entry. Only used while matching. */
    public int $primeiroTsMs;

    /** Fused pass time (see PassTimeFuser). Set after matching. */
    public int $tsMs = 0;

    /** Spread between the sources' corrected times, in ms. 0 for a single source. */
    public int $dispersaoMs = 0;

    /** True when a "precisa" source is in the group and supplied the time. */
    public bool $tempoPreciso = false;

    /** A reviewer confirmed this group ("confirmar"). */
    public bool $confirmadoPorRevisor = false;

    /** The group contains a pass added by a reviewer ("adicionar"). */
    public bool $adicionadoPorRevisor = false;

    /** Decided by PublishRule: does this group become an official lap? */
    public bool $contado = false;

    /** Lap number once counted (1-based). Null while not counted. */
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

    /** True when exactly one real source recorded it and no reviewer vouched for it. */
    public function isUnconfirmedSingleSource(): bool
    {
        return count($this->entries) === 1
            && !$this->confirmadoPorRevisor
            && !$this->adicionadoPorRevisor;
    }
}
