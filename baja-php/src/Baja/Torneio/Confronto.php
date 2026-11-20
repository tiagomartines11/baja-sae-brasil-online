<?php
declare(strict_types=1);

namespace Baja\Torneio;

/**
 * One match of a tournament bracket (a row of the table "tournament"), as
 * the judge page and the public bracket read its two slots.
 *
 * A slot holds a car number ("6"), "WO" (nobody) or "win97" / "los125"
 * (whoever wins / loses match 97 / 125, not known yet); see
 * Baja\Api\Resultados\TorneioEndpoint for the whole layout.
 */
final class Confronto
{
    /**
     * A judge clicked ✅ under $escolhido on juiz/tournament_entry.php.
     * Returns [winner, loser] once that decides the match, null otherwise.
     *
     * A single race is decided by the click (when $escolhido is one of the
     * two slots). A "best of N" match ($dados->bestof) counts the click in
     * $dados->win1 / win2, which the page saves, and is decided once a slot
     * has won more than half of N.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function registrarVitoria(string $equipe1, string $equipe2, ?object $dados, string $escolhido): ?array
    {
        $vencedor = null;
        if (isset($dados->bestof)) {
            if ($escolhido == $equipe1) {
                $dados->win1 = ($dados->win1 ?? 0) + 1;
            } elseif ($escolhido == $equipe2) {
                $dados->win2 = ($dados->win2 ?? 0) + 1;
            }
            if ((float)($dados->win1 ?? 0) > (float)($dados->bestof / 2)) {
                $vencedor = $equipe1;
            } elseif ((float)($dados->win2 ?? 0) > (float)($dados->bestof / 2)) {
                $vencedor = $equipe2;
            }
        } elseif ($escolhido == $equipe1 || $escolhido == $equipe2) {
            $vencedor = $escolhido == $equipe1 ? $equipe1 : $equipe2;
        }
        return $vencedor === null ? null : [$vencedor, self::outraEquipe($equipe1, $equipe2, $vencedor)];
    }

    /**
     * The slot that is not $equipe: the loser when $equipe is the winner.
     * Null when $equipe is in neither slot.
     *
     * juiz/tournament_entry.php sends this to the "los<match>" slots, which
     * is how the match for third place gets the semifinals' losers. Single
     * races used to send the winner there instead (BAJAAPP-37).
     */
    public static function outraEquipe(string $equipe1, string $equipe2, string $equipe): ?string
    {
        if ($equipe == $equipe1) {
            return $equipe2;
        }
        if ($equipe == $equipe2) {
            return $equipe1;
        }
        return null;
    }

    /**
     * Whether the car in $slot won the match: resultados/torneio.php marks
     * that row with tournament-bracket__team--winner. A slot that is not a
     * car number ("WO", "win97") never won. The second row used to ask
     * whether the FIRST slot was a car number, so a car in slot 2 that won
     * against a "WO" in slot 1 was not marked (BAJAAPP-37).
     */
    public static function venceu(?int $winner, ?string $slot): bool
    {
        return $slot !== null && ctype_digit($slot) && $winner == $slot;
    }
}
