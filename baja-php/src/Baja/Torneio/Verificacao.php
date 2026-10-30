<?php
declare(strict_types=1);

namespace Baja\Torneio;

use Baja\Model\Tournament;

/**
 * What scripts/check-tournament-configs.php looks for in the data after the
 * BAJAAPP-37 fixes. It only reports: what to do with each finding is for a
 * person to decide.
 *
 * 1. Seeding criteria inverted twice. "order" used to be ignored (every
 *    criterion sorted bigger first), and the help page told organisers to
 *    negate the formula for "smaller is better". A criterion with order
 *    "asc" AND a negated formula was right by accident; now that "asc" is
 *    honoured it puts the biggest value first.
 *
 * 2. Third-place matches given a semifinal's WINNER. A single race (not
 *    "best of N") sent its winner to the "los<match>" slot, so a car that
 *    won its semifinal, and is in the final, also appears in the match for
 *    third place.
 */
final class Verificacao
{
    /**
     * The criteria of seeding.classification with order "asc" and a
     * negated formula, by position in the list (0 = first criterion).
     *
     * @return list<array{criterio: int, title: ?string, formula: string}>
     */
    public static function criteriosInvertidos(object $params): array
    {
        $achados = [];
        foreach (array_values((array)($params->seeding->classification ?? [])) as $i => $criterio) {
            $formula = $criterio->formula ?? null;
            if (($criterio->order ?? null) === 'asc' && is_string($formula) && self::formulaNegada($formula)) {
                $achados[] = ['criterio' => $i, 'title' => $criterio->title ?? null, 'formula' => $formula];
            }
        }
        return $achados;
    }

    /**
     * Whether the formula's value is the negative of something: "-$TEMPO",
     * "-($A+$B)", "0-$X", "-1*$X", "$X*-1", "$X*(-1)". A formula that only
     * subtracts inside ("$A-$B") is not.
     */
    public static function formulaNegada(string $formula): bool
    {
        $f = preg_replace('/\s+/', '', $formula);
        return preg_match('/^(0)?-/', $f) === 1
            || preg_match('/\*\(?-1(\.0*)?\)?$/', $f) === 1;
    }

    /**
     * The third-place slots of one bracket that hold a car which won its
     * semifinal.
     *
     * @param list<Tournament> $matches every match of one prova of one event
     * @return list<array{match: int, slot: int, carro: int, semifinal: int, decidido: bool}>
     */
    public static function terceiroLugarComVencedor(array $matches): array
    {
        $vencedoresSemifinal = [];
        foreach ($matches as $m) {
            if ((int)$m->getRound() === 1 && $m->getWinner() !== null) {
                $vencedoresSemifinal[(int)$m->getWinner()] = (int)$m->getMatchId();
            }
        }

        $achados = [];
        foreach ($matches as $m) {
            if ((int)$m->getRound() !== -1) {
                continue;
            }
            foreach ([1 => $m->getEquipe1Id(), 2 => $m->getEquipe2Id()] as $slot => $valor) {
                if (ctype_digit((string)$valor) && isset($vencedoresSemifinal[(int)$valor])) {
                    $achados[] = [
                        'match' => (int)$m->getMatchId(),
                        'slot' => $slot,
                        'carro' => (int)$valor,
                        'semifinal' => $vencedoresSemifinal[(int)$valor],
                        'decidido' => $m->getWinner() !== null,
                    ];
                }
            }
        }
        return $achados;
    }
}
