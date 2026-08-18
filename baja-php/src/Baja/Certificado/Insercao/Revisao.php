<?php

namespace Baja\Certificado\Insercao;

/**
 * A validated submission, sorted into the three things an operator can do
 * about it.
 *
 * Errors are values the system cannot use — they are fixed in the
 * spreadsheet, not here. Warnings are values it can use but should not use
 * silently, and each needs an answer. Everything else is fine and is shown as
 * a count, because a screen listing two thousand rows that need nothing is a
 * screen nobody reads to the end.
 */
final class Revisao
{
    /** @var array<int, Linha> */
    public readonly array $erros;

    /** @var array<int, Linha> */
    public readonly array $avisos;

    /** @var array<int, Linha> */
    public readonly array $ok;

    /** @param array<int, Linha> $linhas */
    public function __construct(public readonly array $linhas)
    {
        $erros = $avisos = $ok = [];

        foreach ($linhas as $linha) {
            match ($linha->situacao()) {
                Linha::ERRO  => $erros[]  = $linha,
                Linha::AVISO => $avisos[] = $linha,
                default      => $ok[]     = $linha,
            };
        }

        $this->erros  = $erros;
        $this->avisos = $avisos;
        $this->ok     = $ok;
    }

    /**
     * Nothing commits while an error remains or a warning is unanswered.
     *
     * The second half is as load-bearing as the first. A warning is not a
     * suggestion — it is the system saying it does not know which of two
     * correct things to do, and committing without an answer means picking
     * one at random on the operator's behalf.
     */
    public function podeGravar(): bool
    {
        if ($this->linhas === []) {
            return false;
        }

        foreach ($this->linhas as $linha) {
            if (!$linha->podeGravar()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, Linha> warnings still waiting for an answer */
    public function pendentes(): array
    {
        return array_values(array_filter(
            $this->avisos,
            static fn (Linha $linha): bool => !$linha->estaResolvida()
        ));
    }

    /**
     * How many rows would actually be written.
     *
     * Not the same as the row count: a duplicate the operator chose to skip
     * is a fully resolved row that creates nothing, and saying "2000 rows
     * ready" when 40 of them are skips is a number they cannot reconcile
     * against the summary afterwards.
     */
    public function aCriar(): int
    {
        $n = 0;
        foreach ($this->linhas as $linha) {
            if (!$linha->temErro() && !$linha->ehIgnorada()) {
                $n++;
            }
        }

        return $n;
    }

    public function aIgnorar(): int
    {
        $n = 0;
        foreach ($this->linhas as $linha) {
            if ($linha->ehIgnorada()) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The distinct warnings in this submission, and how many rows carry each.
     *
     * Drives the bulk answers. Answering two thousand duplicates one radio at
     * a time is not a workflow, and the commonest paste that produces them —
     * the same sheet sent twice — wants exactly one answer for the lot.
     *
     * @return array<string, array{problema: Problema, linhas: int}>
     */
    public function agrupados(): array
    {
        $grupos = [];

        foreach ($this->avisos as $linha) {
            foreach ($linha->avisos() as $problema) {
                if (!isset($grupos[$problema->codigo])) {
                    $grupos[$problema->codigo] = ['problema' => $problema, 'linhas' => 0];
                }
                $grupos[$problema->codigo]['linhas']++;
            }
        }

        return $grupos;
    }

    /**
     * A short name for a warning, for the bulk control.
     *
     * The per-row message names the specific row's specific conflict, which
     * is what makes it useful and what makes it useless as a group heading.
     */
    public static function rotuloGrupo(string $codigo): string
    {
        return match ($codigo) {
            Problema::FUNCAO_OBSOLETA      => 'Função que não é mais usada',
            Problema::DOCUMENTO_AMBIGUO    => 'Documento que não passa na verificação de CPF',
            Problema::DUPLICADO            => 'Já existe um certificado igual',
            Problema::DUPLICADO_NO_LOTE    => 'Linha repetida dentro da colagem',
            Problema::NOME_DIVERGENTE_LEVE => 'Nome escrito de outro jeito',
            Problema::NOME_DIVERGENTE      => 'Nome que não parece a mesma pessoa',
            Problema::NOME_UNICO           => 'Nome com uma parte só',
            Problema::NOME_CAIXA           => 'Nome todo em maiúsculas ou minúsculas',
            default                        => $codigo,
        };
    }
}
