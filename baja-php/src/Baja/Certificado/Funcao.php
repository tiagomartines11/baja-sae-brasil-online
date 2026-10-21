<?php

namespace Baja\Certificado;

/**
 * The role a participant held at an event.
 *
 * One table, because the value exists in three forms that must agree: the
 * code stored in `participantes.funcao`, the name a reader is shown, and the
 * name printed inside the certificate's body sentence. Two of those bear no
 * resemblance to the code — `comite` is printed COMISSÃO TÉCNICA — so any
 * second copy of the mapping is a certificate whose stated role differs from
 * the role its record holds.
 *
 * This was two switch statements inside Certificado, kept adjacent with a
 * comment asking that they not drift. Extracted here because the insertion
 * pages need the same mapping in the other direction, and a third copy is
 * where the comment would have stopped being enough.
 */
final class Funcao
{
    /** 'Participou da …' — the person took part in the event. */
    private const PARTICIPOU = 'participou';

    /** 'Realizou trabalho voluntário na organização da …' — the person ran it. */
    private const VOLUNTARIO = 'voluntario';

    /**
     * Every role, stored code first.
     *
     * `impresso` is the name the certificate prints, in the case it prints it
     * in; null means the sentence names no role, which is only true of a
     * competitor. `obsoleta` marks a role no longer offered for new records —
     * it is not a statement about the certificates already carrying it, which
     * were validly issued and must keep rendering.
     */
    private const ROLES = [
        'competidor' => ['label' => 'Competidor',          'impresso' => null,                   'frase' => self::PARTICIPOU, 'obsoleta' => false],
        'orientador' => ['label' => 'Professor Orientador', 'impresso' => 'PROFESSOR ORIENTADOR', 'frase' => self::PARTICIPOU, 'obsoleta' => false],
        'fiscal'     => ['label' => 'Fiscal',              'impresso' => 'FISCAL',               'frase' => self::VOLUNTARIO, 'obsoleta' => true],
        'comissario' => ['label' => 'Comissário',          'impresso' => 'COMISSÁRIO',           'frase' => self::VOLUNTARIO, 'obsoleta' => false],
        'juiz'       => ['label' => 'Juiz',                'impresso' => 'JUIZ',                 'frase' => self::VOLUNTARIO, 'obsoleta' => false],
        'comite'     => ['label' => 'Comissão Técnica',    'impresso' => 'COMISSÃO TÉCNICA',     'frase' => self::VOLUNTARIO, 'obsoleta' => false],
        'engenheiro' => ['label' => 'Engenheiro',          'impresso' => 'ENGENHEIRO',           'frase' => self::VOLUNTARIO, 'obsoleta' => true],
        'assessor'   => ['label' => 'Assessor Técnico',    'impresso' => 'ASSESSOR TÉCNICO',     'frase' => self::VOLUNTARIO, 'obsoleta' => false],
    ];

    public static function exists(string $codigo): bool
    {
        return isset(self::ROLES[$codigo]);
    }

    /**
     * How this role is named to a reader.
     *
     * Empty string for an unrecognised code, which is what the switch this
     * replaced returned. A row holding something not in the table is a data
     * problem, and rendering nothing is how it has always surfaced.
     */
    public static function label(string $codigo): string
    {
        return self::ROLES[$codigo]['label'] ?? '';
    }

    /**
     * Every role, code => display name. Includes the deprecated ones: this is
     * for rendering what exists, not for offering what may be chosen.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn(array $r) => $r['label'], self::ROLES);
    }

    /**
     * The roles a new record may be given, code => display name.
     *
     * @return array<string, string>
     */
    public static function selectable(): array
    {
        return array_map(
            fn(array $r) => $r['label'],
            array_filter(self::ROLES, fn(array $r) => !$r['obsoleta'])
        );
    }

    public static function isDeprecated(string $codigo): bool
    {
        return (self::ROLES[$codigo]['obsoleta'] ?? false) === true;
    }

    /**
     * A value somebody typed or pasted, resolved to a stored code.
     *
     * Matches against both the code and the display name, because a sheet
     * copied out of Excel carries display names — nobody types `comite` into
     * a spreadsheet — while an export from this system carries codes.
     *
     * Folded with Nome::fold(), so case and accents do not matter and
     * "COMISSAO TECNICA" reaches `comite`. Exact on the folded string and
     * nothing looser: `comissario` and `comissao tecnica` share a
     * seven-character prefix, so any prefix or similarity rule will
     * eventually file a Comissário as Comissão Técnica. An unrecognised value
     * is null, for the caller to raise as an error the user resolves.
     */
    public static function resolve(string $raw): ?string
    {
        $wanted = self::foldKey($raw);

        if ($wanted === '') {
            return null;
        }

        foreach (self::ROLES as $codigo => $role) {
            if (self::foldKey($codigo) === $wanted || self::foldKey($role['label']) === $wanted) {
                return $codigo;
            }
        }

        return null;
    }

    /**
     * The body sentence of the certificate.
     *
     * Wording moved verbatim from certificado.php, including the <b> markup
     * that the PDF template inlines, and it is not ours to change: a COMISSÃO
     * TÉCNICA certificate says "Realizou trabalho voluntário na organização
     * da…", which is not what a competitor's says.
     *
     * The carga horária clause is a competitor's alone — it is the only role
     * whose sentence names no function, so it is the only one with room for it.
     */
    public static function texto(string $codigo, string $cabecalho, ?int $cargaHoraria = null): string
    {
        $role = self::ROLES[$codigo] ?? null;

        if ($role === null) {
            return '';
        }

        if ($role['frase'] === self::PARTICIPOU) {
            if ($role['impresso'] === null) {
                return 'Participou da ' . $cabecalho . ($cargaHoraria
                    ? ', com carga horária de ' . (string) $cargaHoraria . ' horas.'
                    : '.');
            }

            return 'Participou da ' . $cabecalho . ' na função de <b>' . $role['impresso'] . '</b>.';
        }

        return 'Realizou trabalho voluntário na organização da ' . $cabecalho
            . ' na função de <b>' . $role['impresso'] . '</b>.';
    }

    /**
     * The comparison key, shared with everything else matching a whole pasted
     * string against a stored one.
     *
     * Nome::chave() rather than a rule of this class's own: a second folding
     * scheme that has to agree with the first is one that eventually will not.
     */
    private static function foldKey(string $value): string
    {
        return Nome::chave($value);
    }
}
