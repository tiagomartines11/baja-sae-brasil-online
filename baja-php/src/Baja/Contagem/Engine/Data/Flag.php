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
    /** A car's first pass came much later than the field's: its pass zero was probably missed. */
    public const PRIMEIRA_PASSAGEM_TARDIA = 'primeira_passagem_tardia';
    /** A pass typed shortly before the start: probably the car's initial pass, with the start recorded a little late. */
    public const ANTES_DA_LARGADA = 'antes_da_largada';
    /** The entries of one pass lie on both sides of the finish: the median alone decided which side it counts on. */
    public const NO_ENCERRAMENTO = 'no_encerramento';
    /** A pass group holds an entry whose time was estimated by the phone (typed offline, stamped after a reload). */
    public const TEMPO_ESTIMADO = 'tempo_estimado';
    /** An entry whose time is unknown. One flag per entry; it is in no pass group. */
    public const TEMPO_DESCONHECIDO = 'tempo_desconhecido';
    /** A pass typed for a car that is present but not classified. It never counts unless confirmed. */
    public const CARRO_NAO_CLASSIFICADO = 'carro_nao_classificado';
    /** A pass at the line while the car was logged as inside the fueling station. */
    public const NO_ABASTECIMENTO = 'no_abastecimento';

    /** Confirming the flag only silences it: the count stays as it is. */
    public const EFEITO_NENHUM = 'nenhum';
    /** Confirming the flag gives the car one more official pass. */
    public const EFEITO_MAIS_UMA_PASSAGEM = 'mais_uma_passagem';

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
     * What a reviewer's "confirmar" on this flag would do to the count: one
     * of the EFEITO_* constants. The review screen words its button from it
     * ("está correto, parar de avisar" or "contar esta passagem (+1 volta)").
     */
    public string $efeitoDeConfirmar = self::EFEITO_NENHUM;

    /**
     * Only with EFEITO_MAIS_UMA_PASSAGEM: the shortest lap the new pass
     * would create (racing time, in ms), so the screen can ask "are you
     * sure?" when it is impossibly short. Null when the car has no other
     * pass to measure a lap from.
     */
    public ?int $voltaResultanteMs = null;

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
            'efeito_de_confirmar' => $this->efeitoDeConfirmar,
            'volta_resultante_ms' => $this->voltaResultanteMs,
        ];
    }
}
