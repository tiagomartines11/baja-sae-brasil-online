<?php

namespace Baja\Certificado\Insercao;

/**
 * Something the review screen has to show about one row.
 *
 * Two severities, and the difference between them is who can clear it. An
 * ERRO is a value the system cannot use at all — nothing commits until it is
 * gone from the sheet. An AVISO is a value the system can use but should not
 * use silently; it commits once a person has said which way.
 *
 * A warning with no resolution chosen is not "probably fine". It blocks the
 * commit exactly as an error does. The distinction is only that the user can
 * clear it here rather than in their spreadsheet.
 */
final class Problema
{
    public const ERRO  = 'erro';
    public const AVISO = 'aviso';

    // --- error codes ---------------------------------------------------------
    public const CAMPO_OBRIGATORIO      = 'campo_obrigatorio';
    public const EVENTO_DESCONHECIDO    = 'evento_desconhecido';
    public const FUNCAO_DESCONHECIDA    = 'funcao_desconhecida';
    public const NOTACAO_CIENTIFICA     = 'notacao_cientifica';
    public const DOCUMENTO_AUSENTE      = 'documento_ausente';
    public const CARACTERES_INVALIDOS   = 'caracteres_invalidos';
    public const CODIFICACAO_INVALIDA   = 'codificacao_invalida';
    public const CAMPO_LONGO            = 'campo_longo';
    public const EVENTO_AMBIGUO         = 'evento_ambiguo';
    public const DOIS_DOCUMENTOS        = 'dois_documentos';
    public const DOCUMENTO_CONTRADIZ    = 'documento_contradiz';

    // --- warning codes -------------------------------------------------------
    public const FUNCAO_OBSOLETA        = 'funcao_obsoleta';
    public const DOCUMENTO_AMBIGUO      = 'documento_ambiguo';
    public const DUPLICADO              = 'duplicado';
    public const DUPLICADO_NO_LOTE      = 'duplicado_no_lote';
    public const NOME_DIVERGENTE_LEVE   = 'nome_divergente_leve';
    public const NOME_DIVERGENTE        = 'nome_divergente';
    public const NOME_UNICO             = 'nome_unico';

    // --- resolutions ---------------------------------------------------------
    /** Yes, I meant that. Used where there is nothing to choose between. */
    public const CONFIRMAR        = 'confirmar';
    /** An ambiguous document: read it as one column or the other. */
    public const LER_COMO_CPF     = 'cpf';
    public const LER_COMO_ESTRANGEIRO = 'estrangeiro';
    /** A duplicate: leave the existing row alone, or overwrite its name. */
    public const IGNORAR          = 'ignorar';
    public const ATUALIZAR        = 'atualizar';
    /** A name conflict. */
    public const USAR_EXISTENTE   = 'usar_existente';
    public const ATUALIZAR_NOME   = 'atualizar_nome';
    public const MANTER_AMBOS     = 'manter_ambos';

    private function __construct(
        public readonly string $severidade,
        public readonly string $codigo,
        public readonly string $mensagem,
        /** @var array<int, string> resolution codes, in the order to offer them */
        public readonly array $resolucoes,
        /** @var array<string, mixed> whatever the screen needs to render it */
        public readonly array $contexto
    ) {
    }

    public static function erro(string $codigo, string $mensagem, array $contexto = []): self
    {
        return new self(self::ERRO, $codigo, $mensagem, [], $contexto);
    }

    /**
     * @param array<int, string> $resolucoes must not be empty: a warning
     *                                       nobody can resolve is an error
     *                                       wearing the wrong label, and
     *                                       would block the commit forever.
     */
    public static function aviso(string $codigo, string $mensagem, array $resolucoes, array $contexto = []): self
    {
        if ($resolucoes === []) {
            throw new \LogicException(
                "Warning $codigo offers no resolution. Nothing would ever clear it, "
                . 'so it is an error, not a warning.'
            );
        }

        return new self(self::AVISO, $codigo, $mensagem, $resolucoes, $contexto);
    }

    public function ehErro(): bool
    {
        return $this->severidade === self::ERRO;
    }

    public function aceita(string $resolucao): bool
    {
        return in_array($resolucao, $this->resolucoes, true);
    }

    /**
     * What choosing "correct the stored name" would actually rewrite.
     *
     * Empty when nothing would change. Shown beside the option rather than
     * after it is taken, because this is the resolution an operator reaches
     * for constantly — fixing "Joao" to "João" is exactly what it is for —
     * and a name correction that silently rewrites six certificates across
     * four years is not what they thought they were clicking. Those rows are
     * what already-issued certificates render when re-downloaded, and what a
     * verifier sees on /verificar.
     */
    public function alcance(): string
    {
        $linhas = (int) ($this->contexto['linhas_afetadas'] ?? 0);

        if ($linhas === 0) {
            return '';
        }

        $eventos = (array) ($this->contexto['eventos_afetados'] ?? []);

        return sprintf(
            'reescreve %d certificado%s já emitido%s%s',
            $linhas,
            $linhas === 1 ? '' : 's',
            $linhas === 1 ? '' : 's',
            $eventos === [] ? '' : ' (' . implode(', ', $eventos) . ')'
        );
    }

    /** How a resolution is offered to the user, in Portuguese. */
    public static function rotuloResolucao(string $resolucao): string
    {
        return match ($resolucao) {
            self::CONFIRMAR             => 'Confirmar assim mesmo',
            self::LER_COMO_CPF          => 'É um CPF',
            self::LER_COMO_ESTRANGEIRO  => 'É um passaporte ou documento estrangeiro',
            self::IGNORAR               => 'Não criar esta linha',
            self::ATUALIZAR             => 'Atualizar o registro existente',
            self::USAR_EXISTENTE        => 'Usar o nome já registrado',
            self::ATUALIZAR_NOME        => 'Corrigir o nome registrado',
            self::MANTER_AMBOS          => 'Manter os dois nomes',
            default                     => $resolucao,
        };
    }
}
