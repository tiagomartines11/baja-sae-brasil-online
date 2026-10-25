<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Api\Resultados;

use Baja\Api\Resultados\Endpoint;
use Baja\Api\Resultados\ResultadoEndpoint;
use Baja\Model\Evento;
use Baja\Model\Resultado;
use Baja\Results\EventStatus;
use Baja\Results\ResultsConfig;
use Baja\Results\ResultTable;
use Baja\Results\ViewerContext;
use Baja\Tests\Unit\Results\Support\Fixtures;
use PHPUnit\Framework\TestCase;

/**
 * How resultado.php writes a results table as JSON.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A "resultado" is one table of the results site: the overall standings
 * ("Geral"), or the scores of one prova (Aceleração, Enduro...). Its columns
 * are formulas the organisers wrote, and Baja\Results\ResultsBuilder works
 * out every team's row from them (docs/results-rules.md). The public page,
 * the judges' copy and the awards all use that builder.
 *
 * The API uses it too, always as the public sees it
 * (ViewerContext::publicView). It adds no rule of its own: it only writes
 * the table the builder returned as JSON. So these tests build a table with
 * the builder, the way the results tests do (Fixtures), hand it to
 * ResultadoEndpoint::shape(), and check what comes out.
 *
 * Each cell has two forms (Baja\Results\Cell):
 *
 *   raw      for programs: HTML removed, and a number when the text is
 *            exactly one ("<b>12,5</b>" gives 12.5). Always sent.
 *   display  what the page shows, HTML included. Sent with ?display=1.
 *
 * (How to read these tests: see the comment at the top of
 * tests/Unit/Results/ColumnFormulasTest.php.)
 */
final class ResultadoShapeTest extends TestCase
{
    private const CONFIG = '{
        "pos": "Total",
        "colunas": [
            {"header": "Pos",    "val": "Pos"},
            {"header": "#",      "val": "EquipeNum"},
            {"header": "Equipe", "val": "Equipe"},
            {"header": "Total",  "val": "\"<b>\" . $TOTAL . \"</b>\"", "val_spoilers": "\"?\""},
            {"header": "Obs",    "val": "$OBS"}
        ]
    }';

    private static function evento(): Evento
    {
        return (new Evento())->setEventoId('26BR');
    }

    private static function resultado(): Resultado
    {
        return (new Resultado())->setResultadoId('26BR_GER')->setNome('Geral')->setInputs(['END', 'ACE']);
    }

    /** What a client receives: the shape, through the same JSON encoding the endpoint uses. */
    private static function json(ResultsConfig $config, ResultTable $table, bool $withDisplay = false): array
    {
        $body = ResultadoEndpoint::shape(self::evento(), self::resultado(), $config, $table, $withDisplay);
        return json_decode(Endpoint::encode($body), true);
    }

    /** Three teams: 7 with 812.4 points, 3 with 650, 9 with no points at all. */
    private static function threeTeams(): array
    {
        return [
            Fixtures::team(7, ['TOTAL' => 812.4, 'OBS' => '1,5 pts']),
            Fixtures::team(3, ['TOTAL' => 650, 'OBS' => '12,5']),
            Fixtures::team(9, ['TOTAL' => '', 'OBS' => 'DNF']),
        ];
    }

    // --- The table -------------------------------------------------------

    /** Who, where, and which column is the one teams are ranked by. */
    public function testTheTableSaysWhatItIsAndHowItIsRanked(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $json = self::json($config, Fixtures::build($config, self::threeTeams()));

        $this->assertSame('26BR', $json['event_id']);
        $this->assertSame('26BR_GER', $json['resultado_id']);
        $this->assertSame('Geral', $json['name']);
        $this->assertSame(['END', 'ACE'], $json['provas']);
        $this->assertSame(['by' => 'Total', 'order' => 'desc'], $json['ranking']);
        $this->assertSame([
            ['header' => 'Pos', 'kind' => 'position', 'ranking' => false],
            ['header' => '#', 'kind' => 'team_number', 'ranking' => false],
            ['header' => 'Equipe', 'kind' => 'team', 'ranking' => false],
            ['header' => 'Total', 'kind' => 'formula', 'ranking' => true],
            ['header' => 'Obs', 'kind' => 'formula', 'ranking' => false],
        ], $json['columns']);
    }

    /** A resultado without "pos" ranks nobody: no ranking, no column marked. */
    public function testWithoutPosThereIsNoRanking(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Total", "val": "$TOTAL"}]}');
        $json = self::json($config, Fixtures::build($config, self::threeTeams()));

        $this->assertNull($json['ranking']);
        $this->assertFalse($json['columns'][0]['ranking']);
        $this->assertSame([null, null, null], array_column($json['rows'], 'position'));
    }

    // --- Rows and positions ----------------------------------------------

    /**
     * Rows come best first. Team 9 has no points, so the builder gives it no
     * position (rule R3): it comes last, with "position": null, not 0 and
     * not 3.
     */
    public function testRowsAreInPositionOrderAndNoValueMeansNullPosition(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $json = self::json($config, Fixtures::build($config, self::threeTeams()));

        $this->assertSame([7, 3, 9], array_column(array_column($json['rows'], 'team'), 'number'));
        $this->assertSame([1, 2, null], array_column($json['rows'], 'position'));
        // The "Pos" column's raw value is the same position.
        $this->assertSame([1, 2, null], array_map(fn($row) => $row['cells'][0]['raw'], $json['rows']));
    }

    /** Whatever the builder ranked, the API says: tested against the builder itself. */
    public function testPositionsAreTheBuildersPositions(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $table = Fixtures::build($config, self::threeTeams());
        $json = self::json($config, $table);

        $this->assertSame(
            array_values(Fixtures::positions($table->rows)),
            array_column($json['rows'], 'position')
        );
    }

    public function testEachRowSaysWhoTheTeamIs(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $table = Fixtures::build($config, [Fixtures::team(7, ['TOTAL' => 1], [], 'PR', disqualified: true)]);

        $this->assertSame(
            ['number' => 7, 'name' => 'Equipe 7', 'school' => 'Escola 7', 'state' => 'PR', 'disqualified' => true],
            self::json($config, $table)['rows'][0]['team']
        );
    }

    // --- Raw and display -------------------------------------------------

    /**
     * One cell per column, in the columns' order. Numbers stay numbers in
     * the JSON: 812.4 and 650, not "812.4". Text that is exactly a number
     * is read as one ("12,5" is 12.5); text that is not stays text.
     */
    public function testRawValuesAreNumbersWhenTheyAreNumbers(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $rows = self::json($config, Fixtures::build($config, self::threeTeams()))['rows'];

        $this->assertSame([['raw' => 1], ['raw' => 7], ['raw' => 'Equipe 7'], ['raw' => 812.4], ['raw' => '1,5 pts']], $rows[0]['cells']);
        $this->assertSame([['raw' => 2], ['raw' => 3], ['raw' => 'Equipe 3'], ['raw' => 650], ['raw' => 12.5]], $rows[1]['cells']);
        // Team 9: "<b></b>" is empty once the HTML goes, so null.
        $this->assertSame([['raw' => null], ['raw' => 9], ['raw' => 'Equipe 9'], ['raw' => null], ['raw' => 'DNF']], $rows[2]['cells']);
    }

    /** Without ?display=1 there is no HTML at all; with it, each cell also has what the page shows. */
    public function testDisplayIsOnlySentWhenAskedFor(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $table = Fixtures::build($config, self::threeTeams());

        $without = self::json($config, $table, withDisplay: false)['rows'][0]['cells'][3];
        $with = self::json($config, $table, withDisplay: true)['rows'][0]['cells'][3];

        $this->assertSame(['raw' => 812.4], $without);
        $this->assertSame(['raw' => 812.4, 'display' => '<b>812.4</b>'], $with);
    }

    /** A column with a list of formulas gives a list of raw values, one per line. */
    public function testAListColumnGivesAListOfRawValues(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "Tempos", "val": ["$T1", "$T2"]}]}');
        $table = Fixtures::build($config, [Fixtures::team(7, ['T1' => '14,37', 'T2' => 'DNF'])]);

        $this->assertSame([14.37, 'DNF'], self::json($config, $table)['rows'][0]['cells'][0]['raw']);
    }

    /**
     * Two columns with one header share one value on the page (the later
     * column's). Each column still gets its place in "cells", so cells and
     * columns always line up.
     */
    public function testColumnsAndCellsAlwaysLineUp(): void
    {
        $config = Fixtures::config('{"colunas": [{"header": "X", "val": "1"}, {"header": "X", "val": "2"}, {"val": "3"}]}');
        $json = self::json($config, Fixtures::build($config, [Fixtures::team(7, [])]));

        $this->assertCount(3, $json['columns']);
        $this->assertSame([['raw' => 2], ['raw' => 2], ['raw' => 3]], $json['rows'][0]['cells']);
        // A column with no header has a null header.
        $this->assertNull($json['columns'][2]['header']);
    }

    // --- Spoilers --------------------------------------------------------

    /**
     * During an event with spoilers on, the public sees "?" in the Total
     * column (its "val_spoilers"), and "?" is not a number, so nobody is
     * ranked (R3, R11). "spoilers_active" is there so that a consumer knows
     * why the values and positions are missing, instead of thinking the
     * table is broken.
     *
     * Exactly what spoilers hide is the builder's business (rules R11 and
     * R12 of docs/results-rules.md); this test only checks that the API
     * says what the builder says.
     */
    public function testDuringSpoilersTheApiSaysSoAndShowsWhatTheBuilderShows(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $viewer = ViewerContext::publicView(new EventStatus(spoilers: true, finished: false));
        $table = Fixtures::build($config, self::threeTeams(), $viewer);
        $json = self::json($config, $table, withDisplay: true);

        $this->assertTrue($table->spoilersHidden);
        $this->assertTrue($json['spoilers_active']);
        $this->assertSame(array_values(Fixtures::positions($table->rows)), array_column($json['rows'], 'position'));
        foreach ($table->rows as $i => $row) {
            $this->assertSame($row->cell('Total')->display, $json['rows'][$i]['cells'][3]['display']);
            $this->assertSame($row->cell('Total')->raw, $json['rows'][$i]['cells'][3]['raw']);
        }
    }

    public function testWithoutSpoilersTheFlagIsFalse(): void
    {
        $config = Fixtures::config(self::CONFIG);
        $this->assertFalse(self::json($config, Fixtures::build($config, self::threeTeams()))['spoilers_active']);
    }
}
