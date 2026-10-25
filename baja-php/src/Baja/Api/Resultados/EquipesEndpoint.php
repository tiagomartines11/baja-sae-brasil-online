<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Model\Equipe;
use Baja\Model\EquipeQuery;
use Baja\Model\Evento;

/**
 * GET resultados/equipes.php?evt=26BR
 *
 * The teams of an event, by car number: what the results pages show about
 * a team. Teams only: nothing about the people in them (the participant
 * table is never read here).
 */
final class EquipesEndpoint
{
    public const PARAMS = [
        'evt' => [Query::EVENTO, true],
    ];

    /** @param array<string, string> $params */
    public static function handle(array $params): Response
    {
        $evento = Lookup::evento($params['evt']);
        $equipes = EquipeQuery::create()->filterByEventoId($evento->getEventoId())->orderByEquipeId()->find();
        return new Response(self::shape($evento, iterator_to_array($equipes, false)), CachePolicy::forEvent($evento));
    }

    /**
     * @param list<Equipe> $equipes
     * @return array<string, mixed>
     */
    public static function shape(Evento $evento, array $equipes): array
    {
        return [
            'event_id' => $evento->getEventoId(),
            'teams' => array_map([self::class, 'team'], $equipes),
        ];
    }

    /**
     * One team. Also used, cut down, by the bracket (TorneioEndpoint).
     *
     * @return array<string, mixed>
     */
    public static function team(Equipe $equipe): array
    {
        return [
            'number' => $equipe->getEquipeId(),
            'name' => $equipe->getEquipe(),
            'short_name' => $equipe->getEquipeCurto(),
            'school' => $equipe->getEscola(),
            'school_short' => $equipe->getEscolaCurto(),
            'city' => $equipe->getCidade(),
            'state' => $equipe->getEstado(),
            'disqualified' => (bool)$equipe->getDesclassificado(),
        ];
    }
}
