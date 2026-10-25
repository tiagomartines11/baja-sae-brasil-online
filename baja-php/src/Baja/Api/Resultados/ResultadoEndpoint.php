<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Model\Evento;
use Baja\Model\Resultado;
use Baja\Results\Column;
use Baja\Results\EventStatus;
use Baja\Results\ResultRow;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ResultTable;
use Baja\Results\ViewerContext;

/**
 * GET resultados/resultado.php?evt=26BR&id=26BR_GER[&display=1]
 *
 * One resultado's table, exactly as the public results page computes it:
 * ResultsBuilder with ViewerContext::publicView(). This class decides
 * nothing about spoilers or ranking. It only writes the builder's table as
 * JSON, so whatever the builder hides from the public is hidden here too.
 *
 * Rows come in position order (ResultTable::$rows): ranked rows best first,
 * then the rows with no position. The page lists them in another order
 * (R10), which only matters for drawing it.
 */
final class ResultadoEndpoint
{
    public const PARAMS = [
        'evt' => [Query::EVENTO, true],
        'id' => [Query::RESULTADO, true],
        'display' => [Query::FLAG, false],
    ];

    /** @param array<string, string> $params */
    public static function handle(array $params): Response
    {
        return self::forEvento(Lookup::evento($params['evt']), $params);
    }

    /**
     * handle() for an event already loaded. The spoiler test passes one
     * whose flags it changed in memory, as the snapshot tool does.
     *
     * @param array<string, string> $params
     */
    public static function forEvento(Evento $evento, array $params): Response
    {
        $resultado = Lookup::resultado($evento, $params['id']);
        $config = ResultsConfig::fromColunas($resultado->getColunas());
        // Loose, as resultados/prova.php compares it.
        if ($config->type == 'tournament') {
            throw new ApiError(404, ApiError::WRONG_TYPE, 'Este resultado é um torneio: use torneio.php.');
        }

        $table = ResultsBuilder::buildFor($resultado, $config, self::publicViewer($evento));
        return new Response(self::shape($evento, $resultado, $config, $table, isset($params['display'])), CachePolicy::forEvent($evento));
    }

    /** Who the API shows results to: the public, always. */
    public static function publicViewer(Evento $evento): ViewerContext
    {
        return ViewerContext::publicView(EventStatus::of($evento));
    }

    /**
     * The JSON body for a table the builder made. No database.
     *
     * @return array<string, mixed>
     */
    public static function shape(Evento $evento, Resultado $resultado, ResultsConfig $config, ResultTable $table, bool $withDisplay): array
    {
        $rankingKey = $config->rankingKey();

        $columns = [];
        foreach ($table->columns as $column) {
            $columns[] = [
                'header' => $column->header,
                'kind' => $column->kind(),
                'ranking' => $rankingKey !== null && (string)$column->key() === (string)$rankingKey,
            ];
        }

        $rows = [];
        foreach ($table->rows as $row) {
            $rows[] = [
                'position' => $row->position,
                'team' => self::team($row),
                'cells' => self::cells($row, $table->columns, $withDisplay),
            ];
        }

        return [
            'event_id' => $evento->getEventoId(),
            'resultado_id' => $resultado->getResultadoId(),
            'name' => $resultado->getNome(),
            'provas' => array_values((array)$resultado->getInputs()),
            // Why values or positions may be missing: while true, columns
            // with a spoiler value show it instead (often "?" or
            // "SPOILERS"), and positions follow what is shown.
            'spoilers_active' => $table->spoilersHidden,
            'ranking' => $rankingKey === null ? null : ['by' => $rankingKey, 'order' => $config->direction()],
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private static function team(ResultRow $row): array
    {
        return [
            'number' => $row->team->number,
            'name' => $row->team->name,
            'school' => $row->team->school,
            'state' => $row->team->state,
            'disqualified' => $row->team->isDisqualified(),
        ];
    }

    /**
     * One cell per column, in the columns' order. Two columns with the same
     * header share one cell, as on the page (ResultRow::$cells).
     *
     * @param list<Column> $columns
     * @return list<array<string, mixed>>
     */
    private static function cells(ResultRow $row, array $columns, bool $withDisplay): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $cell = $row->cell($column->key());
            $cells[] = $withDisplay
                ? ['raw' => $cell?->raw, 'display' => $cell?->display]
                : ['raw' => $cell?->raw];
        }
        return $cells;
    }
}
