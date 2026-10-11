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
     * "Synced through": the latest moment for which we know this source has
     * uploaded everything it recorded. Null when it never synced. With
     * several devices on one source this is the most recent mark among them.
     */
    public ?int $sincronizadoAteMs;

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

    /** True for sources whose entries take part in lap counting. */
    public function countsPasses(): bool
    {
        return $this->ativa
            && ($this->tipo === self::TIPO_MANUAL || $this->tipo === self::TIPO_AUTOMATICA);
    }

    public function hasPreciseTime(): bool
    {
        return $this->qualidadeTempo === self::TEMPO_PRECISA;
    }
}
