<?php
declare(strict_types=1);

namespace Baja\Tests\Api\Resultados\Db;

use Baja\Api\Resultados\ApiError;
use Baja\Api\Resultados\CorridaEndpoint;
use Baja\Api\Resultados\Endpoint;
use Baja\Api\Resultados\EquipesEndpoint;
use Baja\Api\Resultados\EventoEndpoint;
use Baja\Api\Resultados\ResultadoEndpoint;
use Baja\Api\Resultados\TorneioEndpoint;
use Baja\Model\Evento;
use Baja\Model\EventoQuery;
use Baja\Model\Map\EventoTableMap;
use Baja\Model\Resultado;
use Baja\Model\ResultadoQuery;
use Baja\Model\TournamentQuery;
use Baja\Results\Column;
use Baja\Results\EventStatus;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ViewerContext;
use Baja\ResultsSnapshot\Capture\DuringEventView;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Propel;

/**
 * The public results API against real data: the dev seed.
 *
 * HOW TO RUN
 *
 * Wherever the app's database variables (PHPBB_DB_HOST, PHPBB_DB_USER,
 * PHPBB_DB_PASS) point at a database loaded from baja-infra/mysql/init/,
 * e.g. in the dev container:
 *
 *     docker compose exec baja-app vendor/bin/phpunit --testsuite results-api-db
 *
 * Without a reachable database every test here is skipped. CI runs them in
 * the "Results snapshots" job, connected as an account that may only read.
 * The session is also switched to read-only first (ReadOnlyDatabase), so
 * if any endpoint tried to write, its test would fail with MySQL error 1792.
 *
 * WHAT IS CHECKED, AND WHY THIS WAY
 *
 * The API must show exactly what the public results page shows: same
 * builder (ResultsBuilder), same viewer (ViewerContext::publicView). So the
 * main tests do not say what a table should contain. They build every
 * resultado of an event a second time, straight with the builder, and
 * check that the API's JSON says the same thing, position by position and
 * cell by cell. When the builder's rules change (BAJAAPP-42 is changing
 * what spoilers hide), these tests keep passing, because the API follows.
 *
 * The events used:
 *   26BR  finished; has an overall table (GER), a tournament (SPR) and a
 *         lap-counted prova (END)
 *   25SU  finished, but it has spoiler columns: shown "during the event"
 *         by changing its two flags in memory, as the snapshot tool does
 *         (DuringEventView). Nothing is written.
 */
final class AgainstSeedTest extends TestCase
{
    private static bool $connected = false;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        try {
            require_once dirname(__DIR__, 4) . '/src/config.php';
            Propel::getReadConnection('resultados')->query('SELECT 1');
            ReadOnlyDatabase::enforce();
        } catch (\Throwable) {
            return;
        }
        // Formulas mention these bare words; src/bootstrap.php defines them.
        foreach (['Pos', 'EquipeNum', 'Equipe'] as $constant) {
            defined($constant) || define($constant, $constant);
        }
        self::$connected = true;
    }

    protected function setUp(): void
    {
        if (!self::$connected) {
            $this->markTestSkipped('No database: set PHPBB_DB_HOST, PHPBB_DB_USER and PHPBB_DB_PASS (see the class comment).');
        }
    }

    protected function tearDown(): void
    {
        if (self::$connected) {
            // The spoiler test changes an Evento in memory. Propel keeps
            // loaded rows and would hand that same object to the next test.
            EventoTableMap::clearInstancePool();
            DuringEventView::setCurrentEvent(null);
        }
    }

    /** What a client receives: the body through the endpoint's own JSON encoding. */
    private static function client(array $body): array
    {
        return json_decode(Endpoint::encode($body), true);
    }

    /** @return list<Resultado> the event's resultados that are tables (not tournaments) */
    private static function tables(string $eventoId): array
    {
        $tables = [];
        foreach (ResultadoQuery::create()->filterByEventoId($eventoId)->orderByResultadoId()->find() as $resultado) {
            if (ResultsConfig::fromColunas($resultado->getColunas())->type != 'tournament') {
                $tables[] = $resultado;
            }
        }
        return $tables;
    }

    /**
     * The table the public results page shows, straight from the builder,
     * written in the API's terms: per row, the position, the car number,
     * and per column the raw value and the display.
     *
     * @return array{spoilers: bool, rows: list<array{position: ?int, number: mixed, cells: list<array{raw: mixed, display: mixed}>}>}
     */
    private static function builderPublicTable(Evento $evento, Resultado $resultado): array
    {
        $config = ResultsConfig::fromColunas($resultado->getColunas());
        $table = ResultsBuilder::buildFor($resultado, $config, ViewerContext::publicView(EventStatus::of($evento)));
        $rows = [];
        foreach ($table->rows as $row) {
            $rows[] = [
                'position' => $row->position,
                'number' => $row->team->number,
                'cells' => array_map(
                    fn(Column $column) => ['raw' => $row->cell($column->key())?->raw, 'display' => $row->cell($column->key())?->display],
                    $table->columns
                ),
            ];
        }
        return self::client(['spoilers' => $table->spoilersHidden, 'rows' => $rows]);
    }

    /** The same, read from what the API answered. */
    private static function apiTable(array $json): array
    {
        $rows = [];
        foreach ($json['rows'] as $row) {
            $rows[] = ['position' => $row['position'], 'number' => $row['team']['number'], 'cells' => $row['cells']];
        }
        return ['spoilers' => $json['spoilers_active'], 'rows' => $rows];
    }

    /** Asserts the request fails with this status and code. */
    private function assertApiError(int $status, string $code, callable $request): void
    {
        try {
            $request();
        } catch (ApiError $error) {
            $this->assertSame([$status, $code], [$error->status, $error->errorCode], $error->getMessage());
            return;
        }
        $this->fail("Expected $status $code");
    }

    // --- Results tables --------------------------------------------------

    /**
     * Every table of 26BR, the overall one included: the API's positions,
     * raw values and displays are the builder's public ones, row by row,
     * in the same order, with the same JSON types (a number stays a
     * number, an integer stays an integer).
     */
    public function testEveryResultadoOfAFinishedEventIsTheBuildersPublicTable(): void
    {
        $evento = EventoQuery::create()->findPk('26BR');
        $checked = 0;
        foreach (self::tables('26BR') as $resultado) {
            $id = $resultado->getResultadoId();
            $json = self::client(ResultadoEndpoint::handle(['evt' => '26BR', 'id' => $id, 'display' => '1'])->body);

            $this->assertSame(self::builderPublicTable($evento, $resultado), self::apiTable($json), $id);
            $this->assertFalse($json['spoilers_active'], $id);
            $checked++;
        }
        $this->assertGreaterThan(30, $checked, 'The seed has some forty 26BR tables.');
    }

    /** The overall standings in particular: ranked by Total, everybody placed. */
    public function testTheOverallStandingsAreRanked(): void
    {
        $json = self::client(ResultadoEndpoint::handle(['evt' => '26BR', 'id' => '26BR_GER'])->body);

        $this->assertSame(['by' => 'Total', 'order' => 'desc'], $json['ranking']);
        $this->assertSame(1, $json['rows'][0]['position']);
        $positions = array_filter(array_column($json['rows'], 'position'), fn($p) => $p !== null);
        $this->assertSame($positions, array_values($positions), 'Ranked rows come first.');
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Best first.');
        // Without ?display=1, no HTML.
        $this->assertSame(['raw'], array_keys($json['rows'][0]['cells'][0]));
    }

    /**
     * During an event with spoilers on, still the builder's public table.
     * 25SU is shown as running with spoilers (in memory only). Every one of
     * its tables must match the builder, every one must say
     * spoilers_active, and at least one must actually look different from
     * the finished event, or this would not be a spoiler case at all.
     */
    public function testDuringSpoilersTheApiIsStillTheBuildersPublicTable(): void
    {
        $finished = [];
        $finishedEvento = EventoQuery::create()->findPk('25SU');
        foreach (self::tables('25SU') as $resultado) {
            $finished[$resultado->getResultadoId()] = self::builderPublicTable($finishedEvento, $resultado);
        }
        EventoTableMap::clearInstancePool();

        $evento = DuringEventView::show('25SU');
        $differs = 0;
        foreach (self::tables('25SU') as $resultado) {
            $id = $resultado->getResultadoId();
            $json = self::client(ResultadoEndpoint::forEvento($evento, ['evt' => '25SU', 'id' => $id, 'display' => '1'])->body);
            $expected = self::builderPublicTable($evento, $resultado);

            $this->assertSame($expected, self::apiTable($json), $id);
            $this->assertTrue($json['spoilers_active'], $id);
            $differs += $expected['rows'] !== $finished[$id]['rows'] ? 1 : 0;
        }
        $this->assertGreaterThan(0, $differs, 'Some 25SU table hides something during spoilers.');
    }

    // --- Tournament ------------------------------------------------------

    /**
     * 26BR's Super Prime: 64 matches, from the round of 64 (round 5) to the
     * final and the match for third place, all decided. Each match says
     * what its row in the "tournament" table says.
     */
    public function testTheTournamentIsWellFormed(): void
    {
        $json = self::client(TorneioEndpoint::handle(['evt' => '26BR', 'id' => '26BR_SPR'])->body);
        $teams = array_column(self::client(EquipesEndpoint::handle(['evt' => '26BR'])->body)['teams'], 'number');

        $this->assertSame([5, 4, 3, 2, 1, 0, -1], array_column($json['rounds'], 'round'));
        $this->assertSame([32, 16, 8, 4, 2, 1, 1], array_map(fn($r) => count($r['matches']), $json['rounds']));

        $rows = [];
        foreach (TournamentQuery::create()->filterByEventoId('26BR')->filterByProvaId('SPR')->find() as $row) {
            $rows[$row->getMatchId()] = $row;
        }
        foreach ($json['rounds'] as $round) {
            foreach ($round['matches'] as $match) {
                $row = $rows[$match['match_id']];
                foreach ($match['slots'] as $i => $slot) {
                    $stored = $i === 0 ? $row->getEquipe1Id() : $row->getEquipe2Id();
                    $this->assertContains($slot['type'], ['team', 'bye'], "Match {$match['match_id']}: all decided");
                    $this->assertSame($stored === 'WO' ? 'bye' : 'team', $slot['type']);
                    if ($slot['type'] === 'team') {
                        $this->assertSame((int)$stored, $slot['team']['number']);
                        $this->assertContains($slot['team']['number'], $teams);
                    }
                }
                $this->assertSame($row->getWinner(), $match['winner'], "Match {$match['match_id']}");
            }
        }

        $final = $json['rounds'][5]['matches'][0];
        $this->assertSame(['Final', 5, 3], [$json['rounds'][5]['name'], $final['winner'], $final['best_of']]);
        $this->assertSame([0, 2], array_column($final['slots'], 'wins'));
        // The frozen classification began with cars 6 and 5: seeds 1 and 2.
        $this->assertSame([6, 1], [$json['rounds'][0]['matches'][0]['slots'][0]['team']['number'], $json['rounds'][0]['matches'][0]['slots'][0]['seed']]);
    }

    // --- Live race -------------------------------------------------------

    /**
     * 26BR's Enduro is a lap-counted prova, but the seed has no race in the
     * lap counting (that needs passes, and this test may not write). The
     * answer is still complete, and says what the feed says. A real race
     * is in tests/Contagem/Db/PublicResultsApiRaceTest.php.
     */
    public function testALapCountedProvaIsWellFormed(): void
    {
        $json = self::client(CorridaEndpoint::handle(['evt' => '26BR', 'p' => 'END'])->body);

        $this->assertSame(
            ['event_id', 'prova_id', 'name', 'simulation', 'calculated_at_ms', 'clock', 'pace', 'cars', 'best_lap', 'best_lap_history', 'recent_stops'],
            array_keys($json)
        );
        $this->assertSame(
            ['state', 'start_ms', 'finish_ms', 'elapsed_ms', 'server_time_ms', 'planned_duration_ms', 'scoring_start_ms', 'prova_status'],
            array_keys($json['clock'])
        );
        $this->assertSame(['26BR', 'END', 'waiting'], [$json['event_id'], $json['prova_id'], $json['clock']['state']]);
        $this->assertIsInt($json['clock']['server_time_ms']);
        // prova.tempo is set in the seed (the race was run from the spreadsheet).
        $this->assertSame(1774789261000, $json['clock']['scoring_start_ms']);
        $this->assertIsArray($json['cars']);
    }

    // --- Event and teams -------------------------------------------------

    /**
     * Everything evento.php says can be fetched can be fetched: each
     * resultado from its URL, each lap-counted prova's live race.
     *
     * 26BR has the lap-counted Enduro. Its tournament (26BR_SPR) is not in
     * its menu, so it is not listed: the public site only reaches it by its
     * address, and so does the API (testTheTournamentIsWellFormed). 24SU
     * has its tournament in the menu.
     */
    public function testEverythingTheEventListsCanBeFetched(): void
    {
        $listedProvas = [];
        foreach (['26BR', '24SU'] as $eventoId) {
            $json = self::client(EventoEndpoint::handle(['evt' => $eventoId])->body);

            $this->assertContains($eventoId . '_GER', array_column($json['resultados'], 'id'));
            foreach ($json['resultados'] as $listed) {
                parse_str((string)parse_url($listed['url'], PHP_URL_QUERY), $params);
                $endpoint = $listed['type'] === 'tournament' ? TorneioEndpoint::class : ResultadoEndpoint::class;
                $this->assertSame($listed['id'], $endpoint::handle($params)->body['resultado_id']);
            }
            foreach ($json['provas'] as $prova) {
                if ($prova['live_url'] !== null) {
                    parse_str((string)parse_url($prova['live_url'], PHP_URL_QUERY), $params);
                    $this->assertSame($prova['id'], CorridaEndpoint::handle($params)->body['prova_id']);
                }
            }
            $listedProvas[$eventoId] = [
                'lap_counted' => array_values(array_column(array_filter($json['provas'], fn($p) => $p['lap_counted']), 'id')),
                'tournament' => array_values(array_column(array_filter($json['provas'], fn($p) => $p['tournament']), 'id')),
            ];
        }

        $this->assertSame([
            '26BR' => ['lap_counted' => ['END'], 'tournament' => []],
            '24SU' => ['lap_counted' => [], 'tournament' => ['SPR']],
        ], $listedProvas);
    }

    public function testTheStatusOfAFinishedEvent(): void
    {
        $status = self::client(EventoEndpoint::handle(['evt' => '26BR'])->body)['status'];

        // The seed's 26BR is marked both in progress and finished.
        $this->assertSame(['in_progress' => true, 'finished' => true, 'spoilers_active' => false], $status);
    }

    /** Teams, and only what the documentation lists: nothing about people. */
    public function testTeamsHaveOnlyTheDocumentedFields(): void
    {
        $teams = self::client(EquipesEndpoint::handle(['evt' => '26BR'])->body)['teams'];

        $this->assertNotEmpty($teams);
        foreach ($teams as $team) {
            $this->assertSame(['number', 'name', 'short_name', 'school', 'school_short', 'city', 'state', 'disqualified'], array_keys($team));
        }
        $numbers = array_column($teams, 'number');
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers, 'By car number.');
    }

    // --- Ids that name nothing -------------------------------------------

    public function testUnknownIdsAre404(): void
    {
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => EventoEndpoint::handle(['evt' => '99XX']));
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => EquipesEndpoint::handle(['evt' => '99XX']));
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => ResultadoEndpoint::handle(['evt' => '26BR', 'id' => '26BR_ZZZ']));
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => CorridaEndpoint::handle(['evt' => '26BR', 'p' => 'ZZZ']));
    }

    /**
     * MySQL would find 26BR from "26br". The API does not: one URL per
     * answer, or Cloudflare keeps two copies of it.
     */
    public function testIdsMustBeWrittenExactlyAsStored(): void
    {
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => EventoEndpoint::handle(['evt' => '26br']));
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => ResultadoEndpoint::handle(['evt' => '26BR', 'id' => '26BR_ger']));
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => CorridaEndpoint::handle(['evt' => '26BR', 'p' => 'end']));
    }

    /** A resultado belongs to one event: 25BR's is not found under 26BR. */
    public function testAResultadoOfAnotherEventIsNotFound(): void
    {
        $this->assertApiError(404, ApiError::NOT_FOUND, fn() => ResultadoEndpoint::handle(['evt' => '26BR', 'id' => '25BR_GER']));
    }

    /** Each kind of thing has its endpoint; asking the wrong one says so. */
    public function testTheWrongEndpointForAnIdIsA404WrongType(): void
    {
        $this->assertApiError(404, ApiError::WRONG_TYPE, fn() => ResultadoEndpoint::handle(['evt' => '26BR', 'id' => '26BR_SPR']));
        $this->assertApiError(404, ApiError::WRONG_TYPE, fn() => TorneioEndpoint::handle(['evt' => '26BR', 'id' => '26BR_GER']));
        $this->assertApiError(404, ApiError::WRONG_TYPE, fn() => CorridaEndpoint::handle(['evt' => '26BR', 'p' => 'SGE']));
    }
}
