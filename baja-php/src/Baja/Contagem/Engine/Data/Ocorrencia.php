<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * A race-control or pit event. Mirrors one row of contagem_ocorrencia.
 */
final class Ocorrencia
{
    public const LARGADA = 'largada';
    public const BANDEIRA_VERMELHA = 'bandeira_vermelha';
    public const RELARGADA = 'relargada';
    public const ENCERRAMENTO = 'encerramento';
    public const BOX_ENTRADA = 'box_entrada';
    public const BOX_SAIDA = 'box_saida';

    public const TIPOS = [
        self::LARGADA,
        self::BANDEIRA_VERMELHA,
        self::RELARGADA,
        self::ENCERRAMENTO,
        self::BOX_ENTRADA,
        self::BOX_SAIDA,
        self::CANCELAMENTO,
    ];

    /**
     * Removes the event named by `substituiId` and is nothing itself: the
     * way to undo a race-control button pressed by mistake.
     */
    public const CANCELAMENTO = 'cancelamento';

    public const TIPOS_BOX = [self::BOX_ENTRADA, self::BOX_SAIDA];

    public int $id;
    public string $tipo;
    public int $tsMs;
    /** Only for pit events. */
    public ?int $carro;
    /** The row this one corrects, if any. A corrected row is ignored. */
    public ?int $substituiId;

    public function __construct(int $id, string $tipo, int $tsMs, ?int $carro = null, ?int $substituiId = null)
    {
        $this->id = $id;
        $this->tipo = $tipo;
        $this->tsMs = $tsMs;
        $this->carro = $carro;
        $this->substituiId = $substituiId;
    }
}
