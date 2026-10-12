<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * A race-control, pit lane or fueling station event. Mirrors one row of
 * contagem_ocorrencia.
 *
 * Rows are never edited. A row whose `substituiId` names another row either
 * corrects it (same kind of event, new time) or, when its type is
 * "cancelamento", removes it. See RaceTimeline for how these are resolved.
 */
final class Ocorrencia
{
    public const LARGADA = 'largada';
    public const BANDEIRA_VERMELHA = 'bandeira_vermelha';
    public const RELARGADA = 'relargada';
    public const ENCERRAMENTO = 'encerramento';
    public const BOX_ENTRADA = 'box_entrada';
    public const BOX_SAIDA = 'box_saida';
    /** The fueling station is a second area, logged exactly like the pit lane and independent of it. */
    public const ABASTECIMENTO_ENTRADA = 'abastecimento_entrada';
    public const ABASTECIMENTO_SAIDA = 'abastecimento_saida';

    public const TIPOS = [
        self::LARGADA,
        self::BANDEIRA_VERMELHA,
        self::RELARGADA,
        self::ENCERRAMENTO,
        self::BOX_ENTRADA,
        self::BOX_SAIDA,
        self::ABASTECIMENTO_ENTRADA,
        self::ABASTECIMENTO_SAIDA,
        self::CANCELAMENTO,
    ];

    /**
     * Removes the event named by `substituiId` and is nothing itself: the
     * way to undo a race-control button pressed by mistake.
     */
    public const CANCELAMENTO = 'cancelamento';

    public const TIPOS_BOX = [self::BOX_ENTRADA, self::BOX_SAIDA];
    public const TIPOS_ABASTECIMENTO = [self::ABASTECIMENTO_ENTRADA, self::ABASTECIMENTO_SAIDA];
    /** Every event that says a car went into or out of an area: pit lane and fueling station. */
    public const TIPOS_DE_PARADA = [self::BOX_ENTRADA, self::BOX_SAIDA, self::ABASTECIMENTO_ENTRADA, self::ABASTECIMENTO_SAIDA];

    public int $id;
    public string $tipo;
    public int $tsMs;
    /** Only for pit lane and fueling station events. */
    public ?int $carro;
    /** The row this one corrects or cancels, if any. */
    public ?int $substituiId;

    /**
     * How good the time of a pit lane or fueling event is: 'exato',
     * 'estimado' or 'desconhecido' (the same words as `Entry::TEMPO_*`). An
     * event of unknown time carries whatever the phone's clock said, so it
     * is listed for the reviewer and never paired into a stay.
     */
    public string $qualidadeTempo = Entry::TEMPO_EXATO;

    /**
     * True for an exit nobody logged at the gate: a reviewer inferred it
     * from a pass at the line ("marcar saída do box"). It is an exit like
     * any other; the review lanes show it as inferred.
     */
    public bool $inferida = false;

    public function __construct(int $id, string $tipo, int $tsMs, ?int $carro = null, ?int $substituiId = null)
    {
        $this->id = $id;
        $this->tipo = $tipo;
        $this->tsMs = $tsMs;
        $this->carro = $carro;
        $this->substituiId = $substituiId;
    }
}
