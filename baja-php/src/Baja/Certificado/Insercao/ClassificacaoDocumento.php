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
        public readonly ?string $estrangeiro
    ) {
    }

    public static function de(string $raw): self
    {
        $valor = Texto::limpar($raw);

        if ($valor === '') {
            return new self(self::VAZIO, $valor, null, null);
        }

        // Before the letter rule, and this order is the whole point: the E in
        // 1.23457E+10 is a letter, so the rule below would file it as a
        // passport and store it.
        if (preg_match(self::NOTACAO_CIENTIFICA_RE, $valor) === 1) {
            return new self(self::NOTACAO_CIENTIFICA, $valor, null, null);
        }

        // Any letter means it is not a CPF, whatever else it is.
        if (preg_match('/\p{L}/u', $valor) === 1) {
            return new self(self::ESTRANGEIRO, $valor, null, $valor);
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
        return new self(self::AMBIGUO, $valor, $cpf, $valor);
    }

    public function ehResolvida(): bool
    {
        return $this->tipo === self::CPF || $this->tipo === self::ESTRANGEIRO;
    }

    /**
     * The readings a person may choose between for an ambiguous value.
     *
     * @return array<int, string>
     */
    public function leiturasPossiveis(): array
    {
        if ($this->tipo !== self::AMBIGUO) {
            return [];
        }

        return $this->cpf === null
            ? [self::ESTRANGEIRO]
            : [self::CPF, self::ESTRANGEIRO];
    }
}
