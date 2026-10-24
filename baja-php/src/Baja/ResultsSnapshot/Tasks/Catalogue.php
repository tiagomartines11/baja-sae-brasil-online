<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Tasks;

use Baja\Model\EventoQuery;
use Baja\Model\PremiacaoQuery;
use Baja\Model\ProvaQuery;
use Baja\Model\Resultado;
use Baja\Model\ResultadoQuery;

/**
 * The list of everything to snapshot, read from the database.
 *
 * For every event with finalizado = 1 (the seed has 17 events, 14 of them
 * finished):
 *
 *   every resultado     prova.php, prova_juiz.php and prova_debug.php
 *   tournament ones     also torneio.php
 *   spoiler ones        also prova.php as seen during the event
 *   every premiação     Resolver::resolve()
 *   every prova         the formulas, recomputed
 *
 * Everything is listed in a fixed order (by id), so two runs list the same
 * tasks in the same order.
 */
final class Catalogue
{
    /**
     * @param list<string>|null $onlyEvents limit to these events, or null for all
     * @return list<Task>
     */
    public static function tasks(?array $onlyEvents = null): array
    {
        $query = EventoQuery::create()->filterByFinalizado(true)->orderByEventoId();
        if ($onlyEvents !== null) {
            $query->filterByEventoId($onlyEvents);
        }

        $tasks = [];
        foreach ($query->find() as $evento) {
            $eventoId = $evento->getEventoId();

            foreach (ResultadoQuery::create()->filterByEventoId($eventoId)->orderByResultadoId()->find() as $resultado) {
                $id = $resultado->getResultadoId();
                $tasks[] = new PageTask('prova.php', $eventoId, $id);
                $tasks[] = new PageTask('prova_juiz.php', $eventoId, $id);
                $tasks[] = new PageTask('prova_debug.php', $eventoId, $id);
                if (self::isTournament($resultado)) {
                    $tasks[] = new PageTask('torneio.php', $eventoId, $id);
                }
                if (self::usesSpoilers($resultado)) {
                    $tasks[] = new PageTask('prova.php', $eventoId, $id, duringEvent: true);
                }
            }

            foreach (PremiacaoQuery::create()->filterByEventoId($eventoId)->orderByPremiacaoId()->find() as $premiacao) {
                $tasks[] = JobTask::premiacao($eventoId, $premiacao->getPremiacaoId());
            }

            foreach (ProvaQuery::create()->filterByEventoId($eventoId)->orderByProvaId()->find() as $prova) {
                $tasks[] = JobTask::formulas($eventoId, $prova->getProvaId());
            }
        }
        return $tasks;
    }

    /** Same test as prova.php and torneio.php: colunas.type == "tournament". */
    public static function isTournament(Resultado $resultado): bool
    {
        $colunas = $resultado->getColunas();
        return is_object($colunas) && ($colunas->type ?? null) === 'tournament';
    }

    /**
     * Whether any column has a val_spoilers formula: what prova.php shows
     * in that column instead of val while spoilers are on.
     */
    public static function usesSpoilers(Resultado $resultado): bool
    {
        $colunas = $resultado->getColunas();
        foreach ((array)(is_object($colunas) ? ($colunas->colunas ?? []) : []) as $column) {
            if (is_object($column) && !empty($column->val_spoilers)) {
                return true;
            }
        }
        return false;
    }
}
