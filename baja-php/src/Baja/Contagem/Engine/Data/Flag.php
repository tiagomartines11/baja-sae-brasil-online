<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * Something the engine wants a reviewer to look at.
 *
 * Flags are derived on every run and never stored. Their id is built from the
 * type and the entry ids involved, so the same situation keeps the same id
 * from one run to the next and the review screen can track it.
 */
final class Flag
{
    public const TROCA_PROVAVEL = 'troca_provavel';
    public const SOLITARIA = 'solitaria';
    public const REPETIDA = 'repetida';
    public const CARRO_DESCONHECIDO = 'carro_desconhecido';
    public const NO_BOX = 'no_box';
    public const VOLTA_CURTA = 'volta_curta';
    public const DIVERGENCIA_TOTAL = 'divergencia_total';

    public string $id;
    public string $tipo;

    /** @var int[] cars this flag is about (one, or two for a probable swap) */
    public array $carros = [];

    /** @var int[] ids of the entries involved */
    public array $passagemIds = [];

    /** The source the flag is about, when there is exactly one. */
    public ?int $fonteId = null;

    /** Moment on the race timeline the reviewer should look at. */
    public ?int $tsMs = null;

    /**
     * The decision the engine suggests, ready to be stored if accepted:
     * ['tipo' => ..., 'passagem_id' => ..., 'equipe_numero' => ...].
     * Null when the reviewer has to choose.
     *
     * @var array<string, mixed>|null
     */
    public ?array $sugestao = null;

    /** One sentence, in Portuguese, explaining the flag to the reviewer. */
    public string $motivo = '';

    /**
     * True when the engine already acted on its own (a double tap it voided).
     * Shown collapsed at the bottom of the queue, for the record.
     */
    public bool $autoResolvido = false;

    /**
     * Best (lowest) standings position among the cars involved; the review
     * queue shows flags that could change the top of the table first.
     */
    public int $prioridade = PHP_INT_MAX;

    /**
     * @param int[] $carros
     * @param int[] $passagemIds
     */
    public function __construct(string $tipo, array $carros, array $passagemIds, string $idSuffix)
    {
        $this->tipo = $tipo;
        $this->carros = $carros;
        $this->passagemIds = $passagemIds;
        $this->id = $tipo . ':' . $idSuffix;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'carros' => $this->carros,
            'passagem_ids' => $this->passagemIds,
            'fonte_id' => $this->fonteId,
            'ts_ms' => $this->tsMs,
            'sugestao' => $this->sugestao,
            'motivo' => $this->motivo,
            'auto_resolvido' => $this->autoResolvido,
            'prioridade' => $this->prioridade === PHP_INT_MAX ? null : $this->prioridade,
        ];
    }
}
