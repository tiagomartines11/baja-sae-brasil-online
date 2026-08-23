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

    /**
     * The rows that could be written right now.
     *
     * @return array<int, Linha>
     */
    public function prontas(): array
    {
        return array_values(array_filter(
            $this->linhas,
            static fn (Linha $linha): bool => $linha->podeGravar()
        ));
    }

    /**
     * The rows that could not — an error, or a decision nobody has made.
     *
     * These are what a partial commit leaves behind, and what comes back out
     * as a sheet. Errors and unanswered warnings end up in the same pile on
     * purpose: from the operator's side both mean "I cannot deal with this
     * one now", whether because the sheet is wrong or because the answer is
     * in somebody else's head.
     *
     * @return array<int, Linha>
     */
    public function naoProntas(): array
    {
        return array_values(array_filter(
            $this->linhas,
            static fn (Linha $linha): bool => !$linha->podeGravar()
        ));
    }

    /** How many rows a partial commit would actually create. */
    public function aCriarProntas(): int
    {
        $n = 0;
        foreach ($this->prontas() as $linha) {
            if (!$linha->ehIgnorada()) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Whether committing part of this batch is a meaningful thing to offer.
     *
     * Not when everything is ready — the ordinary button covers that — and not
     * when nothing is, which would be a button that writes nothing.
     */
    public function podeGravarParcial(): bool
    {
        return $this->naoProntas() !== [] && $this->aCriarProntas() > 0;
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

    /**
     * Rows whose name was recased on the way in.
     *
     * Counted so the review can say so once at the top. The rule needs no
     * answer, but it changes what a certificate prints, and a change nobody
     * was told about is the kind that surfaces months later as a complaint.
     */
    public function caixaAjustada(): int
    {
        $n = 0;
        foreach ($this->linhas as $linha) {
            if ($linha->caixaAjustada) {
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
            default                        => $codigo,
        };
    }
}
