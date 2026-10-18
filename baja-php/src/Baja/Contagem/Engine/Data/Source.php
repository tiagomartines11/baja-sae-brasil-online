<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * One independent stream of count data, with the sync state of its devices
 * already summarised by whoever loaded it.
 */
final class Source
{
    public const TIPO_MANUAL = 'manual';
    public const TIPO_AUTOMATICA = 'automatica';
    public const TIPO_TOTAIS = 'totais';
    /** Internal source the pit-log devices are bound to. It never counts laps. */
    public const TIPO_BOX = 'box';

    public const TEMPO_HUMANA = 'humana';
    public const TEMPO_PRECISA = 'precisa';

    /** Takes part in the count: its entries are votes. The usual case. */
    public const PAPEL_VOTANTE = 'votante';
    /**
     * Records passes that are only COMPARED with the official ones, never
     * counted: a machine-vision system on trial. See ObserverComparator.
     */
    public const PAPEL_OBSERVADORA = 'observadora';

    /**
     * Passes added by a reviewer ("adicionar") are treated as entries from
     * this virtual source. It is not a row in contagem_fonte.
     */
    public const REVISOR_ID = 0;

    public int $id;
    public string $nome;
    public string $tipo;
    public string $qualidadeTempo;
    public bool $ativa;
    public int $ordem;

    /**
     * When the source was switched off, if it was. Its entries from before
     * that moment keep taking part (switching a counter off must not erase
     * what it recorded); from that moment on it is no longer expected.
     * Null with `ativa` false means it never took part.
     */
    public ?int $desativadaEmMs = null;

    /** One of the PAPEL_* constants. */
    public string $papel = self::PAPEL_VOTANTE;

    /**
     * When the current role began; null means since the source exists.
     * Changing the role never reaches back: a source promoted to votante at
     * 01:00:00 counts from 01:00:00 on, and one made an observer keeps the
     * votes it cast before (the same idea as `desativadaEmMs`).
     */
    public ?int $papelDesdeMs = null;

    /** Marks a period off that the source spent as an observer (see periodosForaMs). */
    public const PERIODO_OBSERVADORA = 'observadora';

    /**
     * Closed periods during which this source's entries do not count, as
     * [from, to) with from null for "since always": a period it was switched
     * off before being switched on again, or, marked with a third element
     * PERIODO_OBSERVADORA, a period it was an observer before being promoted
     * (its entries from then are still compared, see wasObservingAt). Empty
     * for nearly every source.
     *
     * @var array<int, array{0: ?int, 1: int, 2?: string}>
     */
    public array $periodosForaMs = [];

    /**
     * "Synced through": the moment up to which we know this source has
     * uploaded everything it recorded.
     *
     * With several devices on one source this is the EARLIEST mark among
     * the devices that are still open, because one phone's empty queue says
     * nothing about what another phone is still holding. Null means an open
     * device has never synced. NOTHING_TO_WAIT_FOR means no device is open
     * on this source, so there is nobody whose upload could still arrive.
     */
    public ?int $sincronizadoAteMs;

    public const NOTHING_TO_WAIT_FOR = PHP_INT_MAX;

    /** Last contact from any device of this source. Null when never seen. */
    public ?int $vistoEmMs;

    public function __construct(
        int $id,
        string $nome,
        string $tipo = self::TIPO_MANUAL,
        string $qualidadeTempo = self::TEMPO_HUMANA,
        bool $ativa = true,
        int $ordem = 0,
        ?int $sincronizadoAteMs = null,
        ?int $vistoEmMs = null
    ) {
        $this->id = $id;
        $this->nome = $nome;
        $this->tipo = $tipo;
        $this->qualidadeTempo = $qualidadeTempo;
        $this->ativa = $ativa;
        $this->ordem = $ordem;
        $this->sincronizadoAteMs = $sincronizadoAteMs;
        $this->vistoEmMs = $vistoEmMs;
    }

    /** True for the kinds of source whose entries are passes (as opposed to totals or the pit log). */
    public function recordsPasses(): bool
    {
        return $this->tipo === self::TIPO_MANUAL || $this->tipo === self::TIPO_AUTOMATICA;
    }

    /** True when the source was taking part in the count at $tsMs. */
    public function wasCountingAt(int $tsMs): bool
    {
        if (!$this->recordsPasses() || $this->isInPeriodOff($tsMs)) {
            return false;
        }
        if ($this->papel === self::PAPEL_OBSERVADORA) {
            // A source made an observer keeps the votes it cast before.
            if ($this->papelDesdeMs === null || $tsMs >= $this->papelDesdeMs) {
                return false;
            }
        }
        return $this->wasSwitchedOnAt($tsMs);
    }

    /**
     * True when the source was observing at $tsMs: an observer then (now, or
     * in a period it observed before being promoted), switched on, and not
     * switched off in between. Its entry from that moment is compared with
     * the official passes instead of counting.
     */
    public function wasObservingAt(int $tsMs): bool
    {
        if (!$this->recordsPasses() || $this->isInPeriodOff($tsMs, false) || !$this->wasSwitchedOnAt($tsMs)) {
            return false;
        }
        if ($this->papel === self::PAPEL_OBSERVADORA && ($this->papelDesdeMs === null || $tsMs >= $this->papelDesdeMs)) {
            return true;
        }
        return $this->isInPeriodOff($tsMs, true);
    }

    /**
     * True for a pass source that is an observer now or was one before being
     * promoted: it has a comparison with the official count to show.
     */
    public function hasObserved(): bool
    {
        if ($this->isObserver()) {
            return true;
        }
        foreach ($this->periodosForaMs as $period) {
            if (($period[2] ?? null) === self::PERIODO_OBSERVADORA) {
                return $this->recordsPasses();
            }
        }
        return false;
    }

    /** True for a source that is taking part in the count right now. */
    public function countsPasses(): bool
    {
        return $this->ativa && $this->recordsPasses() && $this->papel !== self::PAPEL_OBSERVADORA;
    }

    /**
     * The usual case, cheap to check once per source: taking part now and
     * at every earlier moment, so each of its entries counts.
     */
    public function countedAllAlong(): bool
    {
        return $this->countsPasses() && $this->periodosForaMs === [];
    }

    /** True for a pass source that is an observer now. */
    public function isObserver(): bool
    {
        return $this->recordsPasses() && $this->papel === self::PAPEL_OBSERVADORA;
    }

    /**
     * False only for an observer that never voted: none of its entries can
     * ever count, so the engine need not look at it at all.
     */
    public function mayHaveCounted(): bool
    {
        return $this->recordsPasses() && !($this->papel === self::PAPEL_OBSERVADORA && $this->papelDesdeMs === null);
    }

    /**
     * The periods off as stored in contagem_fonte.periodos_fora: a JSON list
     * of [from, to], or [from, to, "observadora"] for a period observed.
     * Anything unreadable in it is left out.
     *
     * @return array<int, array{0: ?int, 1: int, 2?: string}>
     */
    public static function periodsFromJson(?string $json): array
    {
        $decoded = $json === null || $json === '' ? null : json_decode($json, true);
        $periods = [];
        foreach (is_array($decoded) ? $decoded : [] as $period) {
            if (!is_array($period) || !in_array(count($period), [2, 3], true) || !is_int($period[1] ?? null)) {
                continue;
            }
            $fromMs = $period[0] ?? null;
            if ($fromMs !== null && !is_int($fromMs)) {
                continue;
            }
            if (count($period) === 2) {
                $periods[] = [$fromMs, $period[1]];
            } elseif (($period[2] ?? null) === self::PERIODO_OBSERVADORA) {
                $periods[] = [$fromMs, $period[1], self::PERIODO_OBSERVADORA];
            }
        }
        return $periods;
    }

    /** @param array<int, array{0: ?int, 1: int, 2?: string}> $periods */
    public static function periodsToJson(array $periods): ?string
    {
        return $periods === [] ? null : json_encode(array_values($periods));
    }

    private function wasSwitchedOnAt(int $tsMs): bool
    {
        if ($this->ativa) {
            return true;
        }
        return $this->desativadaEmMs !== null && $tsMs < $this->desativadaEmMs;
    }

    /**
     * @param bool|null $observed true: only the periods it observed; false:
     *     only the ones it was switched off; null: any
     */
    private function isInPeriodOff(int $tsMs, ?bool $observed = null): bool
    {
        foreach ($this->periodosForaMs as $period) {
            [$fromMs, $toMs] = $period;
            if ($observed !== null && $observed !== (($period[2] ?? null) === self::PERIODO_OBSERVADORA)) {
                continue;
            }
            if (($fromMs === null || $tsMs >= $fromMs) && $tsMs < $toMs) {
                return true;
            }
        }
        return false;
    }

    /**
     * The end of the latest period off (switched off, or observing before
     * a promotion): before that moment some of the race went by without
     * this source's entries counting. Null when it counted all along.
     */
    public function countsWithoutGapSinceMs(): ?int
    {
        $latest = null;
        foreach ($this->periodosForaMs as $period) {
            $latest = $latest === null ? $period[1] : max($latest, $period[1]);
        }
        return $latest;
    }

    public function hasPreciseTime(): bool
    {
        return $this->qualidadeTempo === self::TEMPO_PRECISA;
    }
}
