<?php
declare(strict_types=1);

namespace Baja\Posicoes;

use Baja\Model\ResultadoQuery;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ResultTable;
use Baja\Results\ViewerContext;

/**
 * Positions in another resultado's ranking: {"resultado": "26SU_TRA"}.
 *
 * Nothing is worked out here: the resultado's table is built by the same
 * builder as its page, the premiação and the API (Results\ResultsBuilder),
 * so its "pos", "pos_order", "tiebreak", "filter" and every other rule
 * apply exactly as there. A team's position is the one in its "Pos"
 * column.
 *
 * Always the REAL ranking, never the one the public sees while spoilers
 * hide it: the table is built as the premiação builds it
 * (ViewerContext::premiacao(), which never hides spoilers). The config
 * check warns when that would give a hidden ranking away (PosicoesRules).
 */
final class ExternalPositions
{
    /**
     * The positions in the event's resultado $resultadoId. Empty (so every
     * team gets null) when there is no such resultado in the event, it is a
     * tournament, or it ranks nobody (no "pos").
     *
     * @return array<int|string, int> team id => position; teams with none are left out
     */
    public static function ofResultado(string $eventId, string $resultadoId): array
    {
        $resultado = ResultadoQuery::create()->filterByEventoId($eventId)->findOneByResultadoId($resultadoId);
        if ($resultado === null) {
            return [];
        }
        $config = ResultsConfig::fromColunas($resultado->getColunas());
        if ($config->type === 'tournament' || $config->rankingKey() === null) {
            return [];
        }
        return self::ofTable(ResultsBuilder::buildFor($resultado, $config, ViewerContext::premiacao()));
    }

    /**
     * The position of each team in a built table. A team with several rows
     * (one per run) gets its best one.
     *
     * @return array<int|string, int> team id => position; teams with none are left out
     */
    public static function ofTable(ResultTable $table): array
    {
        $positions = [];
        foreach ($table->rows as $row) {
            if ($row->position === null) {
                continue;
            }
            $team = $row->team->teamId;
            $positions[$team] = min($positions[$team] ?? PHP_INT_MAX, $row->position);
        }
        return $positions;
    }
}
