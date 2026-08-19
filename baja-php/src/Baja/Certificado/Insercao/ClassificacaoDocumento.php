<?php

namespace Baja\Certificado\Insercao;

use Baja\Certificado\Documento;

/**
 * Which column a submitted document belongs in, or that the answer is not
 * ours to give.
 *
 * Deciding this wrongly is expensive in both directions. A passport filed as
 * a CPF fails the CHECK constraint or, worse, passes it and becomes eleven
 * digits nobody can trace back. A CPF filed as a foreign document leaves the
 * person unfindable by anyone who types their CPF correctly.
 *
 * So the classifier is deliberately unwilling to guess: it answers CPF only
 * when the check digits say so, foreign only when there is a letter, and
 * otherwise says AMBIGUO and leaves it to the person who can ask.
 */
final class ClassificacaoDocumento
{
    public const CPF                 = 'cpf';
    public const ESTRANGEIRO         = 'estrangeiro';
    public const AMBIGUO             = 'ambiguo';
    public const NOTACAO_CIENTIFICA  = 'notacao_cientifica';
    public const VAZIO               = 'vazio';
    /** A letter in a column the operator said holds CPFs. */
    public const CONTRADIZ_COLUNA    = 'contradiz_coluna';
    /** Both document columns filled on one row. */
    public const DOIS_DOCUMENTOS     = 'dois_documentos';

    /** No column hint: decide from the value alone. */
    public const COLUNA_QUALQUER     = '';
    /** The operator mapped this column as CPF. */
    public const COLUNA_CPF          = 'cpf';
    /** The operator mapped this column as passport or other foreign document. */
    public const COLUNA_ESTRANGEIRA  = 'estrangeiro';
    /** Both were mapped and both are filled, which is one person too many. */
    public const COLUNA_AMBAS        = 'ambos';

    /**
     * What a number-formatted Excel column does to an eleven-digit CPF.
     *
     * `1.23457E+10` — the digits are gone, not reformatted, and no amount of
     * parsing brings them back. Detecting the shape is the only correct
     * response; producing a value from it would invent somebody's document
     * number.
     *
     * Digits, an optional decimal part, E, an exponent. The separator may be
     * a comma: the same sheet opened under a pt-BR locale writes it that way.
     * The sign on the exponent is optional, which is wider than what Excel
     * emits and is the intended trade — `1.23457E10` reaching the classifier
     * would otherwise contain a letter and be filed as a passport, stored
     * verbatim and wrong. The cost is that a document that genuinely reads as
     * digits-E-digits is refused; no issuing authority produces one.
     */
    private const NOTACAO_CIENTIFICA_RE = '/\A[-+]?\d+(?:[.,]\d+)?[eE][-+]?\d+\z/';

    private function __construct(
        public readonly string $tipo,
        /** The value as submitted, trimmed. */
        public readonly string $original,
        /** Eleven digits, when the value could be a CPF at all. */
        public readonly ?string $cpf,
        /** The value as it would be stored in documento_estrangeiro. */
        public readonly ?string $estrangeiro,
        /** Which column the operator said this came from, if they said. */
        public readonly string $coluna = self::COLUNA_QUALQUER
    ) {
    }

    /**
     * @param string $coluna which column the value came from, when the
     *                       operator said. A sheet with separate CPF and
     *                       passaporte columns is stating something the value
     *                       alone cannot: that a digits-only passport is a
     *                       passport, and not a CPF that fails its check
     *                       digits. Without it, a passport column full of the
     *                       digits-only numbers this system spent years
     *                       recording would raise a warning on every row.
     */
    public static function de(string $raw, string $coluna = self::COLUNA_QUALQUER): self
    {
        $valor = Texto::limpar($raw);

        if ($coluna === self::COLUNA_AMBAS) {
            return new self(self::DOIS_DOCUMENTOS, $valor, null, null);
        }

        if ($valor === '') {
            return new self(self::VAZIO, $valor, null, null);
        }

        // Before every other rule, including the column's. The digits in
        // 1.23457E+10 are gone whichever column it was pasted into, and the E
        // in it is a letter, so the rules below would otherwise file it as a
        // passport and store it.
        if (preg_match(self::NOTACAO_CIENTIFICA_RE, $valor) === 1) {
            return new self(self::NOTACAO_CIENTIFICA, $valor, null, null);
        }

        // The operator said this column holds foreign documents. That is the
        // one thing the value cannot say for itself, so it is taken at its
        // word: no check digits, no ambiguity, stored verbatim.
        if ($coluna === self::COLUNA_ESTRANGEIRA) {
            return new self(self::ESTRANGEIRO, $valor, null, $valor);
        }

        // Any letter means it is not a CPF, whatever else it is — and if the
        // column said CPF, the two statements disagree and neither is ours to
        // overrule.
        if (preg_match('/\p{L}/u', $valor) === 1) {
            return $coluna === self::COLUNA_CPF
                ? new self(self::CONTRADIZ_COLUNA, $valor, null, $valor)
                : new self(self::ESTRANGEIRO, $valor, null, $valor);
        }

        $digits = Documento::digits($valor);

        if ($digits === '') {
            // Punctuation and nothing else. Not a document of any kind, and
            // reported as an absent one rather than an ambiguous one — there
            // is no reading of it for a person to choose between.
            return new self(self::VAZIO, $valor, null, null);
        }

        // Pad before testing, never measure length first. A CPF beginning
        // with 00 leaves a number-formatted Excel column as nine digits, and
        // a rule that treated nine digits as "probably foreign" would misfile
        // a large slice of genuine CPFs. Length is not the discriminator; the
        // check digits are.
        $cpf = Documento::normalizeCpf($valor);

        if ($cpf !== null && Documento::isValidCpf($cpf)) {
            return new self(self::CPF, $valor, $cpf, null);
        }

        // Digits that are not a valid CPF. Either a foreign document recorded
        // the way this system's whole digits-only habit recorded them, or a
        // CPF with a typo in it. Both are common and nothing here can tell
        // them apart, so neither is chosen.
        //
        // $cpf is null when there are more than eleven digits, which is not a
        // CPF under any padding — the caller offers only the resolutions that
        // are actually available.
        return new self(self::AMBIGUO, $valor, $cpf, $valor, $coluna);
    }

    public function ehResolvida(): bool
    {
        return $this->tipo === self::CPF || $this->tipo === self::ESTRANGEIRO;
    }

    /**
     * The readings a person may choose between for an ambiguous value.
     *
     * One, and deliberately: a foreign document. There is no "record it as a
     * CPF anyway".
     *
     * Digits that fail the check are a transcription error with near
     * certainty — catching exactly that is what check digits are for — and
     * writing them into `cpf` would create a person nobody can find by their
     * real number, including themselves. The two things it can actually be
     * are both reachable without that: a passport recorded as digits, which
     * this confirms, or a mistyped CPF, which is fixed in the sheet.
     *
     * Nothing is lost by refusing. /buscar compares foreign documents by
     * their digits, so somebody who registered under a mistyped number still
     * finds their certificate by typing that same number — verified against
     * the running system rather than assumed. What the old "É um CPF" option
     * bought was the ability to put a known-wrong value in the column
     * reserved for right ones.
     *
     * This governs creating records. Historical rows holding invalid CPFs are
     * untouched and still resolve: the CHECK constraint deliberately does not
     * test the check digits, because those rows must stay valid rows.
     *
     * @return array<int, string>
     */
    public function leiturasPossiveis(): array
    {
        return $this->tipo === self::AMBIGUO ? [self::ESTRANGEIRO] : [];
    }
}
