<?php

namespace Baja\Certificado\Insercao;

use Baja\Model\Participante;

/**
 * One submitted row, and everything validation decided about it.
 *
 * Carries the values as submitted alongside the values as they would be
 * stored, because the review screen has to show a person what they pasted —
 * telling somebody their `funcao` is unknown while displaying the code the
 * system would have used is not a message they can act on.
 */
final class Linha
{
    public const OK    = 'ok';
    public const AVISO = 'aviso';
    public const ERRO  = 'erro';

    /** @var array<int, Problema> */
    private array $problemas = [];

    /** @var array<string, string> problem code => chosen resolution */
    private array $resolucoes = [];

    /** @var array<int, Participante> every row already on file under this document */
    private array $existentes = [];

    // Resolved values, filled in as validation proceeds. Null until decided.
    public ?string $eventoId = null;
    public ?string $funcao   = null;
    public ?string $cpf      = null;
    public ?string $documentoEstrangeiro = null;

    /** The name that would be written, which is not always the one submitted. */
    public ?string $nome = null;

    /**
     * Whether the name was recased on the way in.
     *
     * Not a problem and not a decision — the rule applies without asking. It
     * is here so the review screen can show what changed, because a name that
     * differs from the sheet without a word said about it is worse than the
     * ALL CAPS it replaced.
     */
    public bool $caixaAjustada = false;

    public ?ClassificacaoDocumento $documento = null;

    /** The row this one duplicates, when it duplicates one. */
    public ?Participante $duplicado = null;

    public function __construct(
        /** 1-based, and the number shown on the review screen. */
        public readonly int $numero,
        public readonly string $eventoBruto,
        public readonly string $nomeBruto,
        public readonly string $funcaoBruta,
        public readonly string $documentoBruto,
        /**
         * Which document column the operator said this came from, when they
         * said. Empty means "work it out from the value".
         */
        public readonly string $colunaDocumento = ClassificacaoDocumento::COLUNA_QUALQUER,
        /**
         * The other document value, when the row filled both columns. Kept so
         * that a row handed back to the operator carries everything they
         * pasted into it.
         */
        public readonly string $documentoSecundario = ''
    ) {
    }

    /** The name as submitted, in the case a certificate should print it. */
    public function nomeSugerido(): string
    {
        return Texto::caixaDeNome($this->nomeBruto);
    }

    public function adicionar(Problema $problema): void
    {
        $this->problemas[] = $problema;
    }

    /** @return array<int, Problema> */
    public function problemas(): array
    {
        return $this->problemas;
    }

    /** @return array<int, Problema> */
    public function erros(): array
    {
        return array_values(array_filter($this->problemas, fn(Problema $p) => $p->ehErro()));
    }

    /** @return array<int, Problema> */
    public function avisos(): array
    {
        return array_values(array_filter($this->problemas, fn(Problema $p) => !$p->ehErro()));
    }

    public function temErro(): bool
    {
        return $this->erros() !== [];
    }

    /**
     * Where this row belongs on the review screen.
     *
     * A row with an unresolved warning reports AVISO whether or not the user
     * has looked at it yet; resolving it does not move the row to OK, because
     * the fact that somebody had to decide is worth keeping visible until the
     * batch is committed.
     */
    public function situacao(): string
    {
        if ($this->temErro()) {
            return self::ERRO;
        }

        return $this->avisos() === [] ? self::OK : self::AVISO;
    }

    public function resolver(string $codigo, string $resolucao): void
    {
        foreach ($this->problemas as $problema) {
            if ($problema->codigo === $codigo && $problema->aceita($resolucao)) {
                $this->resolucoes[$codigo] = $resolucao;

                return;
            }
        }
    }

    public function resolucao(string $codigo): ?string
    {
        return $this->resolucoes[$codigo] ?? null;
    }

    /** @return array<string, string> */
    public function resolucoes(): array
    {
        return $this->resolucoes;
    }

    /** Every warning on this row has been answered. */
    public function estaResolvida(): bool
    {
        foreach ($this->avisos() as $aviso) {
            if (!isset($this->resolucoes[$aviso->codigo])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this row is ready to be written.
     *
     * Both halves matter and neither implies the other: an error means the
     * value is unusable, an unanswered warning means nobody has said which
     * way to use it.
     */
    public function podeGravar(): bool
    {
        return !$this->temErro() && $this->estaResolvida();
    }

    /**
     * Whether committing this row should write anything at all.
     *
     * A duplicate the user chose to skip is a valid, fully resolved row that
     * produces no insert. It is not an error and it is not silently dropped —
     * the summary counts it.
     */
    public function ehIgnorada(): bool
    {
        return $this->resolucao(Problema::DUPLICADO) === Problema::IGNORAR
            || $this->resolucao(Problema::DUPLICADO_NO_LOTE) === Problema::IGNORAR;
    }

    /** @param array<int, Participante> $rows */
    public function definirExistentes(array $rows): void
    {
        $this->existentes = $rows;
    }

    /** @return array<int, Participante> */
    public function existentes(): array
    {
        return $this->existentes;
    }
}
