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

    /**
     * The counters' entries for one crossing are further apart than
     * `dispersao_alerta_ms`: either one entry set apart from the two that
     * agree, or a whole group that is held because nobody agrees closely.
     */
    public const DISPERSAO_ALTA = 'dispersao_alta';
    /** A pass the counters agree on, held because it is too close to the car's previous official pass. */
    public const PASSAGEM_PROXIMA_DEMAIS = 'passagem_proxima_demais';

    /**
     * Two lone entries of one car, from two counters, too close together to
     * be two passes and too far apart to have been matched: one crossing,
     * one of the counters typed it late. Neither counts until a reviewer
     * says which time is right ("usar_tempo").
     */
    public const REGISTRO_TARDIO = 'registro_tardio';

    /**
     * Informational: the pit log (or the fueling log) still had the car
     * inside, the counters agreed on two passes a lap apart, and the engine
     * assumed the exit nobody logged and counted them. Holds nothing.
     */
    public const SAIDA_PRESUMIDA = 'saida_presumida';

    /**
     * An unread pass ("??", or a timing sensor's mark nobody else
     * recorded) whose car the engine could not settle by itself. Carries
     * the ranked `candidatos`. Settled by "reatribuir" (the reviewer picks
     * the car) or "anular"; "confirmar" only stops the warning.
     */
    public const NUMERO_ILEGIVEL = 'numero_ilegivel';

    /**
     * Auto-resolved, for the record: an unread pass the engine gave a car
     * by itself, with the evidence in `atribuicao`. Worked out again on
     * every run; a decision on the entry replaces it.
     */
    public const NUMERO_ATRIBUIDO = 'numero_atribuido';

    /** The car may have one lap too many: two counted passes closer than a believable lap. */
    public const DUVIDA_A_MAIS = 'a_mais';
    /** The car may have one lap too few: a pass is held and may be real. */
    public const DUVIDA_A_MENOS = 'a_menos';

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
     * Whether the car's official count is in doubt right now because of what
     * this flag is about: null (it is not), DUVIDA_A_MAIS or DUVIDA_A_MENOS.
     * Such flags come first in the review queue.
     */
    public ?string $contagemEmDuvida = null;

    /**
     * Only for "volta_curta": the two passes that are too close together,
     * the earlier one first, each as ['passagem_ids' => int[], 'ts_ms' => int].
     * Either may be the wrong one; the screen lets the reviewer choose which
     * to discard.
     *
     * @var array<int, array{passagem_ids: int[], ts_ms: int}>|null
     */
    public ?array $passagens = null;

    /**
     * Only on "no_box" / "no_abastecimento": how many held passes of the car
     * would start counting if its exit were recorded just before this
     * flag's pass (this pass and the later ones held in the same stay; 0
     * when only one counter saw this pass). The screen writes it on the
     * button: "Marcar saída do box (+N voltas)".
     */
    public ?int $voltasAoMarcarSaida = null;

    /**
     * Only on flags about a lone entry ("solitaria", "registro_tardio"): the
     * car's official pass before it, ['passagem_id' => its anchor entry,
     * 'ts_ms' => its pass time]. The review screen widens the flag's
     * timeline back to it, so the lap the entry would make is on screen.
     * Null when the car has no pass before.
     *
     * @var array{passagem_id: int, ts_ms: int}|null
     */
    public ?array $passagemAnterior = null;

    /**
     * Where the reviewer may say which entry times the pass ("usar_tempo"):
     * on a held pass whose entries are too far apart, and on a crossing
     * typed late ("registro_tardio"). One item per entry that could be the
     * right time: ['passagem_id', 'fonte_id', 'ts_ms', 'passagem_alvo_id'],
     * the last being the other entry when it is in another pass group (null
     * when every entry is in the same one). Each item also says what the
     * decision would do to the count in force ('voltas': +1, 0 or -1; see
     * ConfirmEffect::describeTimes) and the shortest lap the pass would
     * make at that time ('volta_resultante_ms', null when there is none to
     * measure). The screen writes one button per item: "Usar o tempo de
     * Contagem 1 (h:mm:ss)", with "(+1 volta)" only when it adds one. Null
     * elsewhere.
     *
     * @var array<int, array{passagem_id: int, fonte_id: int, ts_ms: int, passagem_alvo_id: ?int, voltas: int, volta_resultante_ms: ?int}>|null
     */
    public ?array $temposPossiveis = null;

    /**
     * Only on "numero_ilegivel": the cars the unread pass may have been,
     * most likely first, each as ['carro', 'motivo', 'score', 'ts_ms']:
     * motivo 'outros_contadores' (other sources read that car within the
     * tolerance; ts_ms is their crossing), 'carro_esperado' (the car was
     * due at the line then, by the pace, and has had no official pass
     * since; ts_ms null) or 'volta_faltando' (the car's passes before and
     * after are one short; ts_ms null). score goes from 0 to 1. Null
     * elsewhere.
     *
     * @var array<int, array{carro: int, motivo: string, score: float, ts_ms: ?int}>|null
     */
    public ?array $candidatos = null;

    /**
     * Only on "numero_atribuido": what the engine matched the unread pass
     * with: ['carro', 'ts_ms' (the crossing), 'desvio_ms' (the entry minus
     * the crossing), 'margem_ms' (how much worse the next best answer was;
     * null when there was none), 'passagem_ids' and 'fonte_ids' (the read
     * entries of the crossing and their sources)]. Null elsewhere.
     *
     * @var array{carro: int, ts_ms: int, desvio_ms: int, margem_ms: ?int, passagem_ids: int[], fonte_ids: int[]}|null
     */
    public ?array $atribuicao = null;

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
        $array = [
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
            'contagem_em_duvida' => $this->contagemEmDuvida,
            'passagens' => $this->passagens,
            'voltas_ao_marcar_saida' => $this->voltasAoMarcarSaida,
            'passagem_anterior' => $this->passagemAnterior,
            'tempos_possiveis' => $this->temposPossiveis,
        ];
        // Only the flags about unread passes have these keys at all: a race
        // without one sends exactly what it always did.
        if ($this->candidatos !== null) {
            $array['candidatos'] = $this->candidatos;
        }
        if ($this->atribuicao !== null) {
            $array['atribuicao'] = $this->atribuicao;
        }
        return $array;
    }
}
