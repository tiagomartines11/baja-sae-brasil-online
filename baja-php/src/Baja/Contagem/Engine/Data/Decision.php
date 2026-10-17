<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine\Data;

/**
 * A correction made by a person. Mirrors one row of contagem_decisao.
 *
 * Which fields are used depends on the type:
 *
 *   anular         passagemId                  ignore this entry
 *   reatribuir     passagemId, carro           this entry was really car X
 *   confirmar      passagemId                  count the pass group this entry
 *                                              belongs to, and stop flagging it
 *   adicionar      carro, tsMs                 add a pass nobody recorded
 *   excluir_fonte  fonteId, tsMs, tsFimMs      ignore a source between two times
 *   desfazer       desfazDecisaoId             reverse an earlier decision
 *   usar_tempo     passagemId, passagemAlvoId  count the pass group this entry
 *                                              belongs to, timed by THIS entry
 *                                              alone (not the median of the
 *                                              group). With passagemAlvoId:
 *                                              that other entry, of another
 *                                              counter, is the same crossing
 *                                              typed late; it joins this
 *                                              entry's pass as a witness that
 *                                              does not time it
 *   marcar_saida   carro, tsMs                 a reviewer inferred an exit from
 *                                              the pit lane or the fueling
 *                                              station; the exit itself is an
 *                                              ocorrência, and this row is only
 *                                              its line in the history (so it
 *                                              can be undone). The engine
 *                                              ignores it.
 */
final class Decision
{
    public const ANULAR = 'anular';
    public const REATRIBUIR = 'reatribuir';
    public const CONFIRMAR = 'confirmar';
    public const ADICIONAR = 'adicionar';
    public const EXCLUIR_FONTE = 'excluir_fonte';
    public const DESFAZER = 'desfazer';
    public const MARCAR_SAIDA = 'marcar_saida';
    public const USAR_TEMPO = 'usar_tempo';

    public const TIPOS = [
        self::ANULAR,
        self::REATRIBUIR,
        self::CONFIRMAR,
        self::ADICIONAR,
        self::EXCLUIR_FONTE,
        self::DESFAZER,
        self::MARCAR_SAIDA,
        self::USAR_TEMPO,
    ];

    public const ORIGEM_CONTADOR = 'contador';
    public const ORIGEM_REVISOR = 'revisor';

    public int $id;
    public string $tipo;
    public ?int $passagemId = null;
    public ?int $fonteId = null;
    public ?int $carro = null;
    public ?int $tsMs = null;
    public ?int $tsFimMs = null;
    public ?int $desfazDecisaoId = null;
    /** Only for "usar_tempo": the other entry, when it is in another pass group (a crossing typed late). */
    public ?int $passagemAlvoId = null;
    public string $origem = self::ORIGEM_REVISOR;

    public function __construct(int $id, string $tipo)
    {
        $this->id = $id;
        $this->tipo = $tipo;
    }

    public static function anular(int $id, int $passagemId, string $origem = self::ORIGEM_REVISOR): self
    {
        $decision = new self($id, self::ANULAR);
        $decision->passagemId = $passagemId;
        $decision->origem = $origem;
        return $decision;
    }

    public static function reatribuir(int $id, int $passagemId, int $carro): self
    {
        $decision = new self($id, self::REATRIBUIR);
        $decision->passagemId = $passagemId;
        $decision->carro = $carro;
        return $decision;
    }

    public static function confirmar(int $id, int $passagemId): self
    {
        $decision = new self($id, self::CONFIRMAR);
        $decision->passagemId = $passagemId;
        return $decision;
    }

    public static function usarTempo(int $id, int $passagemId, ?int $passagemAlvoId = null): self
    {
        $decision = new self($id, self::USAR_TEMPO);
        $decision->passagemId = $passagemId;
        $decision->passagemAlvoId = $passagemAlvoId;
        return $decision;
    }

    public static function adicionar(int $id, int $carro, int $tsMs): self
    {
        $decision = new self($id, self::ADICIONAR);
        $decision->carro = $carro;
        $decision->tsMs = $tsMs;
        return $decision;
    }

    /** A null end means "until further notice". */
    public static function excluirFonte(int $id, int $fonteId, ?int $tsMs, ?int $tsFimMs): self
    {
        $decision = new self($id, self::EXCLUIR_FONTE);
        $decision->fonteId = $fonteId;
        $decision->tsMs = $tsMs;
        $decision->tsFimMs = $tsFimMs;
        return $decision;
    }

    public static function desfazer(int $id, int $desfazDecisaoId): self
    {
        $decision = new self($id, self::DESFAZER);
        $decision->desfazDecisaoId = $desfazDecisaoId;
        return $decision;
    }
}
