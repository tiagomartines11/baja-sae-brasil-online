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
        if (!$this->recordsPasses()) {
            return false;
        }
        if ($this->ativa) {
            return true;
        }
        return $this->desativadaEmMs !== null && $tsMs < $this->desativadaEmMs;
    }

    /** True for a source that is taking part in the count right now. */
    public function countsPasses(): bool
    {
        return $this->ativa && $this->recordsPasses();
    }

    public function hasPreciseTime(): bool
    {
        return $this->qualidadeTempo === self::TEMPO_PRECISA;
    }
}
