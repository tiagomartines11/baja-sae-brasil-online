<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Results\Support;

use Baja\Results\EventStatus;
use Baja\Results\ResultRow;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ResultTable;
use Baja\Results\TeamInput;
use Baja\Results\ViewerContext;

/**
 * Small helpers that let the results tests describe a resultado in a few
 * lines, with no database.
 *
 * In the real site, a resultado's settings are a JSON text typed by the
 * organisers, and every team's scores come from the "input" table. Here we
 * write the same JSON by hand (config()) and make the input rows by hand
 * (team()), then hand both to the builder (build()).
 */
final class Fixtures
{
    /**
     * A resultado's settings, written as the organisers write them, e.g.
     *     Fixtures::config('{"colunas": [{"header": "Pontos", "val": "$PONTOS"}]}')
     */
    public static function config(string $json): ResultsConfig
    {
        $decoded = json_decode($json);
        if ($decoded === null) {
            throw new \InvalidArgumentException('Not valid JSON: ' . $json);
        }
        return ResultsConfig::fromColunas($decoded);
    }

    /**
     * One team's input row for one prova.
     *
     * $dados is what the judges typed, e.g. ['PONTOS' => 10], or
     * ['entries' => [[...], [...]]] for a prova with several runs. It is
     * turned into objects the same way the database JSON is.
     *
     * @param array<string, mixed> $dados
     * @param array<string, mixed> $vars
     */
    public static function team(int $number, array $dados, array $vars = [], string $state = 'SP', bool $disqualified = false): TeamInput
    {
        return new TeamInput(
            $number,
            $number,
            'Equipe ' . $number,
            'Escola ' . $number,
            $state,
            $disqualified ? 1 : 0,
            self::json($dados),
            self::json($vars),
            null,
        );
    }

    /**
     * The table as someone sees it. By default: the public page of an
     * event that is over (so no spoilers).
     *
     * @param list<TeamInput> $inputs
     */
    public static function build(ResultsConfig $config, array $inputs, ?ViewerContext $viewer = null, ?ResultsBuilder $builder = null): ResultTable
    {
        $viewer ??= ViewerContext::publicView(new EventStatus(spoilers: false, finished: true));
        $builder ??= ResultsBuilder::forPages();
        return $builder->build($config, $inputs, $viewer);
    }

    /**
     * One column of every row, in row order, as "car number => value", so a
     * test can compare a whole table in one line.
     *
     * @param list<ResultRow> $rows
     * @return array<int|string, mixed>
     */
    public static function column(array $rows, string $header): array
    {
        $values = [];
        foreach ($rows as $row) {
            $values[$row->team->number] = $row->display($header);
        }
        return $values;
    }

    /**
     * Car numbers in row order.
     *
     * @param list<ResultRow> $rows
     * @return list<mixed>
     */
    public static function numbers(array $rows): array
    {
        return array_map(fn(ResultRow $row) => $row->team->number, $rows);
    }

    /**
     * Position of each car, as "car number => position".
     *
     * @param list<ResultRow> $rows
     * @return array<int|string, ?int>
     */
    public static function positions(array $rows): array
    {
        $positions = [];
        foreach ($rows as $row) {
            $positions[$row->team->number] = $row->position;
        }
        return $positions;
    }

    /** Arrays become objects, as json_decode() makes them from the database. */
    private static function json(array $value): mixed
    {
        return $value === [] ? new \stdClass() : json_decode(json_encode($value));
    }
}
