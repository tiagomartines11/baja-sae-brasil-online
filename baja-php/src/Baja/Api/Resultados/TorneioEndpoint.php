<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Model\Equipe;
use Baja\Model\EquipeQuery;
use Baja\Model\Evento;
use Baja\Model\Prova;
use Baja\Model\ProvaQuery;
use Baja\Model\Resultado;
use Baja\Model\Tournament;
use Baja\Model\TournamentQuery;
use Baja\Results\ResultsConfig;

/**
 * GET resultados/torneio.php?evt=26BR&id=26BR_SPR
 *
 * A tournament bracket: the resultado of type "tournament" that publishes
 * it, read the way resultados/torneio.php reads it.
 *
 * HOW A BRACKET IS STORED (table "tournament", one row per match)
 *
 *   round        6 for the first round of 128, 5 for 64 ... 1 the semifinals,
 *                0 the final, -1 the match for third place
 *   equipe1_id,  each slot holds one of:
 *   equipe2_id     a car number ("6")       the team in that slot
 *                  "WO"                     nobody: the other team goes
 *                                           through without playing
 *                  "win97" / "los125"       not known yet: the winner (or
 *                                           loser) of match 97 (or 125)
 *   winner       the winner's car number, or null while it is undecided
 *   dados        {"bestof": 3, "win1": 2, "win2": 0} for a "best of N"
 *                match, with the games each slot has won. "old1"/"old2"
 *                remember which match a slot was filled from, so that a
 *                judge can undo a result; they are not shown here.
 *
 * Seeding: when the bracket was made, the prova's params kept the order of
 * the qualified cars in status.classification (1st = seed 1). That is the
 * "seed" of each team, null when the prova has no such list.
 */
final class TorneioEndpoint
{
    public const PARAMS = [
        'evt' => [Query::EVENTO, true],
        'id' => [Query::RESULTADO, true],
    ];

    /** The public page's names for the rounds. */
    public const ROUND_NAMES = [
        -1 => 'Disputa 3° lugar', 0 => 'Final', 1 => 'Semifinais', 2 => 'Quartas de final',
        3 => 'Oitavas de Final', 4 => '16 avos de Final', 5 => '32 avos de Final', 6 => '64 avos de Final',
    ];

    /** @param array<string, string> $params */
    public static function handle(array $params): Response
    {
        $evento = Lookup::evento($params['evt']);
        $resultado = Lookup::resultado($evento, $params['id']);
        if (ResultsConfig::fromColunas($resultado->getColunas())->type != 'tournament') {
            throw new ApiError(404, ApiError::WRONG_TYPE, 'Este resultado não é um torneio: use resultado.php.');
        }

        $eventoId = $evento->getEventoId();
        $provaIds = (array)$resultado->getInputs();
        $matches = TournamentQuery::create()->filterByEventoId($eventoId)->filterByProvaId($provaIds)
            ->orderByMatchId('asc')->orderByRound('desc')->find();
        $provas = ProvaQuery::create()->filterByEventoId($eventoId)->filterByProvaId($provaIds)->find();
        $equipes = EquipeQuery::create()->filterByEventoId($eventoId)->find();

        return new Response(
            self::shape($evento, $resultado, iterator_to_array($matches, false), iterator_to_array($provas, false), iterator_to_array($equipes, false)),
            CachePolicy::forEvent($evento)
        );
    }

    /**
     * The JSON body. No database.
     *
     * @param list<Tournament> $matches in the public page's order (match id)
     * @param list<Prova> $provas the resultado's provas, for their seeding
     * @param list<Equipe> $equipes the event's teams
     * @return array<string, mixed>
     */
    public static function shape(Evento $evento, Resultado $resultado, array $matches, array $provas, array $equipes): array
    {
        $teams = [];
        foreach ($equipes as $equipe) {
            $teams[(int)$equipe->getEquipeId()] = $equipe;
        }
        $seeds = [];
        foreach ($provas as $prova) {
            $seeds[$prova->getProvaId()] = self::seeds($prova);
        }

        $rounds = [];
        foreach ($matches as $match) {
            $round = (int)$match->getRound();
            $rounds[$round] ??= ['round' => $round, 'name' => self::ROUND_NAMES[$round] ?? null, 'matches' => []];
            $rounds[$round]['matches'][] = self::match($match, $teams, $seeds[$match->getProvaId()] ?? []);
        }

        return [
            'event_id' => $evento->getEventoId(),
            'resultado_id' => $resultado->getResultadoId(),
            'name' => $resultado->getNome(),
            'provas' => array_values((array)$resultado->getInputs()),
            // In the order the page draws them: first round first, the final
            // and the match for third place last.
            'rounds' => array_values($rounds),
        ];
    }

    /**
     * @param array<int, Equipe> $teams
     * @param array<int, int> $seeds car number => seed
     * @return array<string, mixed>
     */
    private static function match(Tournament $match, array $teams, array $seeds): array
    {
        $dados = json_decode((string)$match->getDados());
        // As the page: only an integer "bestof" makes it a "best of N" match.
        $bestOf = is_object($dados) && is_int($dados->bestof ?? null) ? $dados->bestof : null;

        $slots = [];
        foreach ([1 => $match->getEquipe1Id(), 2 => $match->getEquipe2Id()] as $n => $value) {
            $wins = $bestOf !== null ? ($dados->{'win' . $n} ?? null) : null;
            $slots[] = self::slot((string)$value, $teams, $seeds, is_int($wins) ? $wins : null);
        }

        // Only a winner who is one of the two teams counts, as the page
        // only highlights a team whose number equals the winner.
        $winner = $match->getWinner();
        $inMatch = array_filter($slots, fn(array $slot) => $slot['type'] === 'team' && $slot['team']['number'] === $winner);

        return [
            'match_id' => (int)$match->getMatchId(),
            'prova_id' => $match->getProvaId(),
            'best_of' => $bestOf,
            'slots' => $slots,
            'winner' => $winner !== null && $inMatch !== [] ? $winner : null,
        ];
    }

    /**
     * What one slot of a match holds (see the class comment).
     *
     * @param array<int, Equipe> $teams
     * @param array<int, int> $seeds
     * @return array<string, mixed>
     */
    public static function slot(string $value, array $teams, array $seeds, ?int $wins): array
    {
        if (ctype_digit($value) && isset($teams[(int)$value])) {
            $equipe = $teams[(int)$value];
            return [
                'type' => 'team',
                'team' => [
                    'number' => (int)$value,
                    'short_name' => $equipe->getEquipeCurto(),
                    'school_short' => $equipe->getEscolaCurto(),
                ],
                'seed' => $seeds[(int)$value] ?? null,
                'wins' => $wins,
            ];
        }
        if ($value === 'WO') {
            return ['type' => 'bye'];
        }
        if (preg_match('/^(win|los)([0-9]+)$/', $value, $m) === 1) {
            return ['type' => 'pending', 'from_match' => (int)$m[2], 'takes' => $m[1] === 'win' ? 'winner' : 'loser'];
        }
        return ['type' => 'unknown'];
    }

    /**
     * Car number => seed, from the frozen classification the bracket was
     * made from; empty when there is none.
     *
     * @return array<int, int>
     */
    public static function seeds(Prova $prova): array
    {
        $status = $prova->getParams()->status ?? null;
        if (!is_object($status) || empty($status->frozen) || !is_array($status->classification ?? null)) {
            return [];
        }
        $seeds = [];
        foreach (array_values($status->classification) as $index => $carro) {
            $seeds[(int)$carro] ??= $index + 1;
        }
        return $seeds;
    }
}
