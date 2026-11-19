<?php
declare(strict_types=1);

namespace Baja\Tests\Prova\Db;

use Baja\Tests\Prova\Db\Support\JuizPages;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The judge's list of teams for one prova, juiz/dashboard.php, run for real.
 *
 * BACKGROUND, FOR SOMEONE NEW TO THIS
 *
 * A judge opens dashboard.php?p=<prova> to see every team present at the
 * event, one row each, with every field of the prova as a column (red when
 * the team has no value for it yet) and an "Editar" link at both ends of
 * the row that opens the team's entry page.
 *
 * The page was made usable on a phone (360 px wide): the table scrolls
 * inside its own box instead of the whole page scrolling sideways, the
 * links are big enough for a finger, and the title bar with "Voltar" sits
 * above the table. What the page SHOWS did not change. These tests open the
 * real page against a real database and check both: that it shows the same
 * data, and that the phone-friendly pieces are there. Any PHP warning or
 * error the page raises fails the test (Support/JuizPages).
 *
 * HOW THE PAGE RUNS
 *
 * In a PHP process of its own, as php-fpm runs it, with the judge logged
 * in, but without a web server or the forum (Support/JuizPages and
 * run-juiz-page.php). So it runs in CI, which has a database only.
 *
 * WHAT EVERY TEST STARTS WITH
 *
 * A made-up event 02NE (it never existed) with the prova DSH ("Dashboard
 * de teste": a number TEMPO and a choice CONE), and three teams:
 *
 *     7  present, TEMPO 4.512 and CONE "SIM" saved
 *     8  present, nothing saved yet
 *     9  NOT present: not listed
 *
 * and a judge account "zz_dash_juiz" allowed to score 02NE_DSH. All of it
 * is removed afterwards.
 *
 * HOW TO RUN
 *
 * It writes, so only with BAJA_TEST_DB=1:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite prova-db
 *
 * It connects as PHPBB_DB_USER, or as BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS
 * when set (CI sets them, because its usual account may only read).
 */
final class DashboardPageTest extends TestCase
{
    use JuizPages;

    private const EVENT = '02NE';
    private const PROVA = 'DSH';
    private const JUDGE = 'zz_dash_juiz';

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            self::$skipped = 'Writes a test event: set BAJA_TEST_DB=1 and the database variables (see the class comment).';
            return;
        }
        // The page (through Propel) connects as PHPBB_DB_USER. Point it at
        // the test account too, when one is given; the page process inherits it.
        if (getenv('BAJA_TEST_DB_USER')) {
            putenv('PHPBB_DB_USER=' . getenv('BAJA_TEST_DB_USER'));
            putenv('PHPBB_DB_PASS=' . getenv('BAJA_TEST_DB_PASS'));
        }
        try {
            self::$database = new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string)getenv('PHPBB_DB_USER'),
                (string)getenv('PHPBB_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::$skipped = 'No database: ' . $e->getMessage();
            return;
        }
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        self::removeFixture();  // left over from a run that was interrupted
        self::insertFixture();
    }

    protected function tearDown(): void
    {
        if (self::$database !== null && self::$skipped === null) {
            self::removeFixture();
        }
    }

    /**
     * The page opens (no PHP warning: JuizPages fails the test on one) and
     * shows what it always showed: a row per present team, a column per
     * field, the saved values, and red cells where a value is missing.
     */
    public function testThePageShowsTheSameDataAsBefore(): void
    {
        $page = $this->openDashboard();

        $this->assertSame(200, $page['status']);
        // The field codes are the column titles, between the two "Editar" columns.
        $this->assertMatchesRegularExpression('#<th>Equipe</th>\s*<th class="sorter-false">Editar</th><th>TEMPO</th><th>CONE</th><th class="sorter-false">Editar</th>#', $page['body']);
        // Team 7's saved values; team 8 has none, so both its cells are red.
        $this->assertStringContainsString("<td style=''>4.512</td><td style=''>SIM</td>", $page['body']);
        $this->assertSame(2, substr_count(self::row($page['body'], 8), 'background-color: #FFD1D1'));
        // Only present teams: 7 and 8, not 9.
        $this->assertSame(2, preg_match_all('#<td class="dashboard-numero">#', $page['body']));
        $this->assertStringNotContainsString('EQUIPE NOVE', $page['body']);
    }

    /**
     * Names are text, never HTML: a team called "<b>Sete</b>" shows those
     * characters instead of bold text (Baja\Site\Html).
     */
    public function testTeamNamesAreEscaped(): void
    {
        $body = $this->openDashboard()['body'];

        $this->assertStringContainsString('<td class="dashboard-equipe">&lt;b&gt;Sete&lt;/b&gt;</td>', $body);
        $this->assertStringNotContainsString('<b>Sete</b>', $body);
    }

    /**
     * Each row links to the team's entry page from both ends, with links
     * marked to be at least 44 px tall and wide (the page's CSS).
     */
    public function testEachRowLinksToTheEntryPageFromBothEnds(): void
    {
        $row = self::row($this->openDashboard()['body'], 7);

        $this->assertSame(2, substr_count($row, '<a class="dashboard-editar" href="entry.php?p=DSH&amp;t=7">Editar</a>'));
    }

    /**
     * The phone-friendly pieces: the page lifts the theme's 520 px minimum
     * width (on this page only), the table sits in its own scrolling box,
     * the title bar with "Voltar" is above the table, and the links are
     * at least 44 px.
     */
    public function testThePageIsMadeForAPhone(): void
    {
        $body = $this->openDashboard()['body'];

        $this->assertStringContainsString('body { min-width: 0; }', $body);
        $this->assertStringContainsString('<div class="dashboard-tabela"><table id="myTable" class="tablesorter">', $body);
        $this->assertMatchesRegularExpression('#\.dashboard-tabela \{[^}]*overflow: auto;#', $body);
        $this->assertMatchesRegularExpression('#a\.dashboard-voltar, a\.dashboard-editar \{[^}]*min-width: 44px; min-height: 44px;#', $body);
        $this->assertMatchesRegularExpression('#<div class="dashboard-titulo">\s*<a class="dashboard-voltar" href="index.php">Voltar</a>\s*<span class="dashboard-titulo-nome">Dashboard de teste</span>#', $body);
        $this->assertLessThan(strpos($body, '<div class="dashboard-tabela">'), strpos($body, '<div class="dashboard-titulo">'));
    }

    /** Without ?p= the page sends the judge back to the list of provas, as before. */
    public function testWithoutAProvaItGoesBackToTheList(): void
    {
        $page = $this->openPage(self::EVENT, 'dashboard.php', self::JUDGE, []);

        $this->assertSame(302, $page['status']);
        $this->assertSame('index.php', $page['location']);
    }

    // ------------------------------------------------------------------

    /** @return array{status: int, location: string, body: string} */
    private function openDashboard(): array
    {
        return $this->openPage(self::EVENT, 'dashboard.php', self::JUDGE, ['p' => self::PROVA]);
    }

    /** The HTML of one team's row, found by its car number. */
    private static function row(string $body, int $team): string
    {
        $start = strpos($body, '<td class="dashboard-numero">' . $team . '</td>');
        self::assertNotFalse($start, "no row for team $team");
        $end = strpos($body, '</tr>', (int)$start);
        return substr($body, (int)$start, (int)$end - (int)$start);
    }

    private static function insertFixture(): void
    {
        self::execute(
            "INSERT INTO evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers) VALUES (?, 'Fixture dashboard', 'Evento de teste (lista do juiz)', 0, 2002, 0, 0, 0)",
            [self::EVENT]
        );
        self::execute(
            "INSERT INTO prova (evento_id, prova_id, nome, status, params, totals) VALUES (?, ?, 'Dashboard de teste', 1, ?, '{}')",
            [self::EVENT, self::PROVA, json_encode(['inputs' => [
                ['code' => 'TEMPO', 'name' => 'Tempo [s]', 'type' => 'number', 'precision' => 3, 'sensitive' => true],
                ['code' => 'CONE', 'name' => 'Cone', 'type' => 'enum', 'options' => ['SIM', 'NÃO'], 'sensitive' => true],
            ]])]
        );
        foreach ([[7, '<b>Sete</b>', 1], [8, 'EQUIPE OITO', 1], [9, 'EQUIPE NOVE', 0]] as [$number, $name, $present]) {
            self::execute(
                "INSERT INTO equipe (evento_id, equipe_id, escola, equipe, estado, desclassificado, presente) VALUES (?, ?, 'ESCOLA DE TESTE', ?, 'SP', 0, ?)",
                [self::EVENT, $number, $name, $present]
            );
        }
        self::execute(
            "INSERT INTO input (evento_id, prova_id, equipe_id, dados, vars, pontos) VALUES (?, ?, 7, ?, '{}', '{}')",
            [self::EVENT, self::PROVA, json_encode(['TEMPO' => 4.512, 'CONE' => 'SIM'])]
        );
        // As Propel stores a permission list.
        self::execute("INSERT INTO user (username, permissions) VALUES (?, '| index | 02NE_DSH |')", [self::JUDGE]);
    }

    private static function removeFixture(): void
    {
        self::execute('DELETE FROM user WHERE username = ?', [self::JUDGE]);
        // Every other row of the fixture hangs off the event, ON DELETE CASCADE.
        self::execute('DELETE FROM evento WHERE evento_id = ?', [self::EVENT]);
    }

    private static function execute(string $sql, array $params): void
    {
        self::$database->prepare($sql)->execute($params);
    }
}
