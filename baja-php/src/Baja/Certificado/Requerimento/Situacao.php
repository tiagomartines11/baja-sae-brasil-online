<?php

namespace Baja\Certificado\Requerimento;

/**
 * Where a report is: the four statuses, and the words for them.
 *
 * In one place because two pages say them now. The staff queue
 * (juiz/certificados_requerimentos.php) and the participant's status page
 * (certificado/acompanhar.php, BAJAAPP-88) must call the same row by the
 * same word, or a participant told "Em análise" and a member of staff
 * looking at "Em andamento" are describing one report two ways.
 *
 * The order of TODOS is the schema's valueSet, and that one is permanent:
 * Propel stores the index into it, not the label. See schema.xml.
 */
final class Situacao
{
    public const ABERTO     = 'aberto';
    public const EM_ANALISE = 'em_analise';
    public const RESOLVIDO  = 'resolvido';
    public const RECUSADO   = 'recusado';

    /**
     * The order a report moves through them. Do not reorder.
     *
     * @var array<int, string>
     */
    public const TODOS = [self::ABERTO, self::EM_ANALISE, self::RESOLVIDO, self::RECUSADO];

    /**
     * Still somebody's open request. The same list Duplicata matches on.
     *
     * @var array<int, string>
     */
    public const ABERTOS = [self::ABERTO, self::EM_ANALISE];

    /**
     * The two that end a report, and so the two that owe the participant a
     * reply. "Em análise" is not one of them: it says somebody picked the
     * report up, not that anything was decided.
     *
     * @var array<int, string>
     */
    public const FECHADOS = [self::RESOLVIDO, self::RECUSADO];

    public static function valida(string $situacao): bool
    {
        return in_array($situacao, self::TODOS, true);
    }

    public static function fechada(string $situacao): bool
    {
        return in_array($situacao, self::FECHADOS, true);
    }

    /** The status in words, as both pages print it. */
    public static function rotulo(string $situacao): string
    {
        return match ($situacao) {
            self::ABERTO     => 'Aberto',
            self::EM_ANALISE => 'Em análise',
            self::RESOLVIDO  => 'Resolvido',
            self::RECUSADO   => 'Recusado',
            default          => $situacao,
        };
    }
}
