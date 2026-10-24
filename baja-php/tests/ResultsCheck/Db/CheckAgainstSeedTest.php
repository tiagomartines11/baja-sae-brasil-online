<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsCheck\Db;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Runs scripts/check-results-configs.php for real, against a database
 * loaded with the dev seed, and checks two things:
 *
 *   1. it writes NOTHING to the database (every table is the same after
 *      the run, to the byte);
 *   2. it finds the broken configs we know the seed has.
 *
 * HOW TO RUN
 *
 * Wherever the app's database variables (PHPBB_DB_HOST, PHPBB_DB_USER,
 * PHPBB_DB_PASS) point at a database loaded from baja-infra/mysql/init/,
 * e.g. in the dev container:
 *
 *     docker compose exec baja-app vendor/bin/phpunit --testsuite results-check-db
 *
 * Without a reachable database every test here is skipped. CI runs them in
 * the "Results snapshots" job, connected as an account that may only read.
 *
 * WHY THIS MATTERS
 *
 * The tool is meant to be pointed at a copy of production, and then at
 * production itself. "It only reads" has to be something a test shows.
 */
final class CheckAgainstSeedTest extends TestCase
{
    private static ?PDO $database = null;

    /** @var array{exit: int, report: string, sql: string}|null the one run all tests look at */
    private static ?array $run = null;

    /** @var array<string, string>|null table => checksum, before that run */
    private static ?array $before = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        try {
            self::$database = new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string)getenv('PHPBB_DB_USER'),
                (string)getenv('PHPBB_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            self::$database = null;
            return;
        }

        // The events with the seed's known broken configs (see the PR of
        // BAJAAPP-62): "+" on text, a "pos" naming nothing, no "pos".
        self::$before = self::checksums();
        $sqlFile = sys_get_temp_dir() . '/results-check-' . bin2hex(random_bytes(4)) . '.sql';
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/check-results-configs.php')
            . ' --events 22SE,23SE,24NE,25BR,26BR --sql ' . escapeshellarg($sqlFile) . ' 2>&1',
            $output,
            $exitCode
        );
        self::$run = ['exit' => $exitCode, 'report' => implode("\n", $output), 'sql' => (string)@file_get_contents($sqlFile)];
        @unlink($sqlFile);
    }

    protected function setUp(): void
    {
        if (self::$database === null) {
            $this->markTestSkipped('No database: set PHPBB_DB_HOST, PHPBB_DB_USER and PHPBB_DB_PASS (see the class comment).');
        }
    }

    public function testItRunsAndWritesNothing(): void
    {
        $this->assertSame(0, self::$run['exit'], self::$run['report']);
        $this->assertSame(self::$before, self::checksums(), 'No table changed.');
    }

    /** The report names each known case, under its resultado. */
    public function testTheReportFindsTheSeedsKnownCases(): void
    {
        $report = self::$run['report'];

        // "+" on text, with the fix it proposes.
        $this->assertStringContainsString("26BR_REL  Relatório de Projeto  (49 teams)\n  Formula raises an error\n    column \"Pontos\" val\n"
            . "      formula: '<strong>'+ROUND(\$RELPTS,2)+'<strong>'\n"
            . "      error:   TypeError: Unsupported operand types: string + float   (49 of 49 teams)\n"
            . "      fix:     '<strong>'.ROUND(\$RELPTS,2).'</strong>'", $report);
        // "pos" naming a column that does not exist.
        $this->assertMatchesRegularExpression('/^24NE_4X4 .*\n  Ranking config\n    pos "Total" names no column and no variable/m', $report);
        $this->assertMatchesRegularExpression('/^25BR_TST .*\n  Ranking config\n    pos "Pontos" names no column and no variable/m', $report);
        // A "Pos" column but no "pos".
        $this->assertMatchesRegularExpression('/^22SE_SUS .*\n  Ranking config\n    column "Pos" shows positions but there is no "pos"/m', $report);
    }

    /** One UPDATE for each resultado with the "+" problem, and none for the others. */
    public function testTheSqlFixesExactlyTheMechanicalCases(): void
    {
        preg_match_all("/^WHERE resultado_id = '([^']+)'/m", self::$run['sql'], $matches);

        $this->assertSame(
            ['23SE_PRO', '23SE_X6X', '25BR_RDT', '25BR_REL', '25BR_SLP', '26BR_RDT', '26BR_REL', '26BR_SLP'],
            $matches[1],
        );
    }

    /** @return array<string, string> table => checksum of its content */
    private static function checksums(): array
    {
        $tables = self::$database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $checksums = [];
        foreach (self::$database->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->fetchAll(PDO::FETCH_NUM) as [$table, $checksum]) {
            $checksums[(string)$table] = (string)$checksum;
        }
        ksort($checksums);
        return $checksums;
    }
}
