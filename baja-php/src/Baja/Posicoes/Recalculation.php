<?php
declare(strict_types=1);

namespace Baja\Posicoes;

use Baja\Model\Prova;
use Baja\Model\ProvaQuery;

/**
 * Keeps the points of provas with "posicoes" up to date (BAJAAPP-102).
 *
 * Points are stored. A prova that takes a position from a resultado reads
 * the points of every prova in that resultado, so when one of those
 * changes, it has to be worked out again, and then whatever reads IT, and
 * so on (EventGraph::recalculationOrder()).
 *
 * The hooks:
 *
 *   afterProvaRefreshed()   at the end of Prova::refreshVarsAndPontos(), so
 *                           every way points are recalculated is covered
 *                           (a judge's save or delete, Refresh Pontos, lap
 *                           counting, remote.php...) without each page
 *                           having to remember it
 *   afterResultadoChanged() when a resultado's config is saved or deleted:
 *                           its "pos", "tiebreak", "filter" or Inputs can
 *                           change its ranking
 *   afterProvaDeleted()     the resultados it was in rank without it now
 *
 * Every prova is worked out at most once per chain: the provas this one
 * triggers call refreshVarsAndPontos() too, and their own hook sees a chain
 * is already running and does nothing; this one has already listed them.
 */
final class Recalculation
{
    private static bool $running = false;

    public static function afterProvaRefreshed(Prova $prova): void
    {
        self::afterPointsChanged((string)$prova->getEventoId(), (string)$prova->getProvaId());
    }

    public static function afterProvaDeleted(string $eventId, string $provaId): void
    {
        self::afterPointsChanged($eventId, $provaId);
    }

    private static function afterPointsChanged(string $eventId, string $provaId): void
    {
        self::run($eventId, fn(EventGraph $graph) => $graph->recalculationOrder([$provaId]));
    }

    public static function afterResultadoChanged(string $eventId, string $resultadoId): void
    {
        self::run($eventId, fn(EventGraph $graph) => $graph->recalculationOrder([], $graph->provasReading($resultadoId)));
    }

    /** @param callable(EventGraph): list<string> $order which provas to work out again, in order */
    private static function run(string $eventId, callable $order): void
    {
        if (self::$running) {
            return;
        }
        self::$running = true;
        try {
            $graph = EventGraph::load($eventId);
            if (!$graph->hasLinks()) {
                return;  // the usual case: nobody in the event uses "posicoes" from a resultado
            }
            foreach ($order($graph) as $provaId) {
                ProvaQuery::create()->filterByEventoId($eventId)->findOneByProvaId($provaId)?->refreshVarsAndPontos();
            }
        } finally {
            self::$running = false;
        }
    }
}
