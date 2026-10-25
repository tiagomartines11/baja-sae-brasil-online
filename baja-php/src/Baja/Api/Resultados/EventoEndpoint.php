<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Model\Evento;
use Baja\Model\Prova;
use Baja\Model\ProvaQuery;
use Baja\Model\Resultado;
use Baja\Model\ResultadoQuery;
use Baja\Results\ResultsConfig;

/**
 * GET resultados/evento.php?evt=26BR
 *
 * An event's public information, and what else can be fetched about it:
 *
 *   - its status: whether it is the site's default event, finished,
 *     spoilers active;
 *   - the resultados of its menu, in menu order, with the menu group each
 *     is under, whether it is a table or a tournament, and its URL here;
 *   - the provas those resultados are made of, with their type, so a
 *     consumer can tell a tournament or a lap-counted race, and the URL of
 *     the live race for the lap-counted ones.
 *
 * The menu is the event's "menu" JSON, read the way the results site draws
 * it (Template::printMenu()): a string is a resultado id; an object maps a
 * group name to a list of resultado ids. A value containing "php" is a link
 * to a page (a queue, the clock) and is left out, as are ids that are not a
 * resultado of this event.
 */
final class EventoEndpoint
{
    public const PARAMS = [
        'evt' => [Query::EVENTO, true],
    ];

    /** @param array<string, string> $params */
    public static function handle(array $params): Response
    {
        $evento = Lookup::evento($params['evt']);
        $resultados = [];
        foreach (ResultadoQuery::create()->findByEventoId($evento->getEventoId()) as $resultado) {
            $resultados[$resultado->getResultadoId()] = $resultado;
        }
        $provas = [];
        foreach (ProvaQuery::create()->findByEventoId($evento->getEventoId()) as $prova) {
            $provas[$prova->getProvaId()] = $prova;
        }
        return new Response(self::shape($evento, $resultados, $provas), CachePolicy::forEvent($evento));
    }

    /**
     * The JSON body. No database: the tests pass objects made in memory.
     *
     * @param array<string, Resultado> $resultados the event's resultados, by id
     * @param array<string, Prova> $provas the event's provas, by id
     * @return array<string, mixed>
     */
    public static function shape(Evento $evento, array $resultados, array $provas): array
    {
        $eventoId = $evento->getEventoId();

        $listed = [];
        $provaIds = [];
        foreach (self::menuEntries($evento->getMenu()) as [$group, $resultadoId]) {
            $resultado = $resultados[$resultadoId] ?? null;
            if ($resultado === null || isset($listed[$resultadoId])) {
                continue;
            }
            $tournament = ResultsConfig::fromColunas($resultado->getColunas())->type == 'tournament';
            $inputs = array_values(array_map('strval', (array)$resultado->getInputs()));
            $listed[$resultadoId] = [
                'id' => $resultadoId,
                'name' => $resultado->getNome(),
                'group' => $group,
                'type' => $tournament ? 'tournament' : 'table',
                'provas' => $inputs,
                'url' => ($tournament ? '/resultados/torneio.php' : '/resultados/resultado.php') . '?evt=' . $eventoId . '&id=' . $resultadoId,
            ];
            array_push($provaIds, ...$inputs);
        }

        $provasOut = [];
        foreach (array_unique($provaIds) as $provaId) {
            $prova = $provas[$provaId] ?? null;
            if ($prova === null) {
                continue;
            }
            $type = $prova->getType();
            $provasOut[] = [
                'id' => $provaId,
                'name' => $prova->getNome(),
                'type' => $type,
                'status' => self::provaStatus($prova),
                'tournament' => $type === 'tournament',
                'lap_counted' => $type === 'laps',
                'live_url' => $type === 'laps' ? '/resultados/corrida.php?evt=' . $eventoId . '&p=' . $provaId : null,
            ];
        }

        return [
            'event' => [
                'id' => $eventoId,
                'name' => $evento->getNome(),
                'title' => $evento->getTitulo(),
                'year' => $evento->getAno(),
                'type' => $evento->getTipo(),
                'location' => $evento->getLocal(),
                'dates' => $evento->getData(),
                // Shown in the results site's archive menu. An event that is
                // not is still public at its own address, here as there.
                'listed' => (bool)$evento->getAtivo(),
            ],
            'status' => [
                // evento.em_andamento: the event the results site opens when
                // no event is named (bootstrap.php). It says nothing about
                // whether the event is running; "finished" does.
                'default_event' => (bool)$evento->getEmAndamento(),
                'finished' => (bool)$evento->getFinalizado(),
                // The builder's own rule, asked for a resultado that names no
                // authorized users: the public view never has any.
                'spoilers_active' => ResultadoEndpoint::publicViewer($evento)->hidesSpoilers([]),
            ],
            'resultados' => array_values($listed),
            'provas' => $provasOut,
            'teams_url' => '/resultados/equipes.php?evt=' . $eventoId,
        ];
    }

    /** "partial" while judges are still entering it, "final" once closed. */
    public static function provaStatus(Prova $prova): ?string
    {
        return match ($prova->getStatus()) {
            'Parcial' => 'partial',
            'Final' => 'final',
            default => null,
        };
    }

    /**
     * Every resultado id of a menu, in order, with its group (null at the
     * top level).
     *
     * @return list<array{0: ?string, 1: string}>
     */
    public static function menuEntries(mixed $menu): array
    {
        $entries = [];
        foreach ((array)$menu as $item) {
            if (!is_object($item)) {
                if (is_string($item) || is_int($item)) {
                    $entries[] = [null, (string)$item];
                }
                continue;
            }
            foreach ($item as $group => $subitems) {
                // A group name with a URL is a link, not a group.
                if (!is_array($subitems) && !is_object($subitems)) {
                    continue;
                }
                foreach ($subitems as $value) {
                    if ((is_string($value) || is_int($value)) && !str_contains((string)$value, 'php')) {
                        $entries[] = [(string)$group, (string)$value];
                    }
                }
            }
        }
        return $entries;
    }
}
