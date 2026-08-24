<?php

namespace Baja\Certificado\Requerimento;

/**
 * What a participant is reporting.
 *
 * Three cases, and the difference between them is not cosmetic — each one
 * starts somewhere different and each one asks for different evidence.
 *
 *  - INCORRETO and INDEVIDO are about a certificate that exists. The person
 *    is looking at it on /buscar, so the form is reached from that result and
 *    carries its token. There is no version of these two that starts from a
 *    blank form: without the token, staff would have to find the row from a
 *    name and a document, which is the search the person has already done.
 *
 *  - AUSENTE is the opposite. It is a report that nothing came back, so there
 *    is no token by construction and the event has to be named by hand.
 *
 * INDEVIDO is deliberately not a deletion request in the privacy sense, and
 * the form says so in as many words. A certificate SAE BRASIL correctly
 * issued stays verifiable: somebody is relying on it — an employer, a
 * university, a professional council — and a verification link that stops
 * resolving on the holder's say-so makes every other link worth less. LGPD
 * Art. 16 II covers exactly this: data kept for compliance with a regulatory
 * obligation and for the exercise of rights is retained. What INDEVIDO
 * reports is an assertion that was never true — a certificate naming somebody
 * who did not participate, or issued twice, which is a data-accuracy problem
 * (Art. 6 V) and is what voiding exists for.
 */
final class Caso
{
    /** A certificate exists and something on it is wrong. */
    public const INCORRETO = 'incorreto';

    /** A certificate that should exist does not. */
    public const AUSENTE = 'ausente';

    /** A certificate exists that was never earned. */
    public const INDEVIDO = 'indevido';

    /**
     * The order the form offers them in, which is the order they happen in.
     *
     * Also the order of the schema's valueSet, and that one is permanent —
     * Propel stores the index, not the label. Do not reorder either list.
     *
     * @var array<int, string>
     */
    public const TODOS = [self::INCORRETO, self::AUSENTE, self::INDEVIDO];

    /**
     * The label on the radio button.
     *
     * @return array<string, string>
     */
    public static function rotulos(): array
    {
        return [
            self::INCORRETO => 'Um certificado meu tem dados incorretos',
            self::AUSENTE   => 'Falta um certificado que eu deveria ter',
            self::INDEVIDO  => 'Existe um certificado em meu nome que não deveria existir',
        ];
    }

    /**
     * The sentence under the label, which is where the real distinction is.
     *
     * @return array<string, string>
     */
    public static function explicacoes(): array
    {
        return [
            self::INCORRETO => 'O nome, a participação, o evento ou a data estão errados.',
            self::AUSENTE   => 'Você participou do evento e nada aparece na busca.',
            self::INDEVIDO  => 'O certificado registra uma participação que não aconteceu, '
                             . 'ou é uma segunda via do mesmo certificado.',
        ];
    }

    public static function valido(string $caso): bool
    {
        return in_array($caso, self::TODOS, true);
    }

    /**
     * Whether this case has to name a certificate.
     *
     * True for the two that start from a /buscar result. The form refuses
     * them without a token rather than accepting a report staff cannot act
     * on, and sends the person to search first.
     */
    public static function exigeToken(string $caso): bool
    {
        return $caso === self::INCORRETO || $caso === self::INDEVIDO;
    }

    /** Whether this case has to name an event, because no token will. */
    public static function exigeEvento(string $caso): bool
    {
        return $caso === self::AUSENTE;
    }

    public static function rotulo(string $caso): string
    {
        return self::rotulos()[$caso] ?? $caso;
    }

    /** Short form, for an email subject line and the staff queue. */
    public static function resumo(string $caso): string
    {
        return match ($caso) {
            self::INCORRETO => 'Dados incorretos',
            self::AUSENTE   => 'Certificado faltando',
            self::INDEVIDO  => 'Certificado indevido',
            default         => $caso,
        };
    }
}
