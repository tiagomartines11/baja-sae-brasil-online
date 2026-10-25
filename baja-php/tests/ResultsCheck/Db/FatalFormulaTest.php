<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsCheck\Db;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * A formula that kills PHP must not kill the check (BAJAAPP-63).
 *
 * BACKGROUND
 *
 * Most broken formulas RAISE an error (a TypeError, say). The app catches
 * those, and the check lists them. A few formulas are worse: PHP 8 cannot
 * even compile them. This one, from 18SU's APR prova, is the case that
 * happened on a copy of production:
 *
 *     ($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))
 *
 * The inner "a ? b : c ? d : e" has no brackets, and PHP 8 refuses to guess
 * how to read it. That is a FATAL error: no try/catch can stop it, and the
 * process ends on the spot. The check used to run everything in one
 * process, so it died on the first such formula, and the rest of the
 * report was lost. Now every resultado and prova is checked in a process of
 * its own, and a process that dies becomes a finding.
 *
 * WHAT IS TESTED
 *
 * The real script, run against a made-up event, 99FX (fatal-formulas.sql
 * next to this file), with that formula in a resultado AND in a prova, and
 * two healthy-ish resultados after it. The run must:
 *
 *   1. finish (exit code 0) and still write nothing to the database;
 *   2. list both fatal ones first, in a banner;
 *   3. show, for each, the formula and both ways to read it;
 *   4. still check the event's other resultados.
 *
 * HOW TO RUN
 *
 * This test WRITES: it inserts 99FX before the run and deletes it after
 * (the tool itself only reads). So, like tests/certificado/run.php, it
 * runs only when BAJA_TEST_DB=1, and is skipped otherwise. Never set that
 * against production. In the dev container:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite results-check-db
 *
 * The fixture is loaded as PHPBB_DB_USER, or as BAJA_TEST_DB_USER /
 * BAJA_TEST_DB_PASS when set. CI sets those to MySQL's root, so that the
 * tool can still run as an account that may only SELECT.
 */
final class FatalFormulaTest extends TestCase
{
    private const EVENT = '99FX';

    /** The formula, exactly as stored in the fixture's prova APR and resultado 99FX_APR. */
    private const FORMULA = '($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))';

    /** What PHP 8 says about it. */
    private const MESSAGE = 'Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)`';

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    /** @var array{exit: int, report: string, sql: string}|null the one run all tests look at */
    private static ?array $run = null;

    /** @var array<string, string> table => checksum, before and after that run */
    private static array $before = [];
    private static array $after = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if (getenv('BAJA_TEST_DB') !== '1' || $host === false || $host === '') {
            self::$skipped = 'Writes a test event: set BAJA_TEST_DB=1 and the database variables (see the class comment).';
            return;
        }
        try {
            self::$database = new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string)(getenv('BAJA_TEST_DB_USER') ?: getenv('PHPBB_DB_USER')),
                (string)(getenv('BAJA_TEST_DB_USER') ? getenv('BAJA_TEST_DB_PASS') : getenv('PHPBB_DB_PASS')),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::$skipped = 'No database: ' . $e->getMessage();
            return;
        }

        self::removeFixture();  // left over from a run that was interrupted
        // One statement at a time, so that an error in any of them stops here.
        foreach (preg_split('/;\s*$/m', (string)file_get_contents(__DIR__ . '/fatal-formulas.sql')) as $statement) {
            if (trim(preg_replace('/^--.*$/m', '', $statement)) !== '') {
                self::$database->exec($statement);
            }
        }

        self::$before = self::checksums();
        $sqlFile = sys_get_temp_dir() . '/results-check-fatal-' . bin2hex(random_bytes(4)) . '.sql';
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/check-results-configs.php')
            . ' --events ' . self::EVENT . ' --sql ' . escapeshellarg($sqlFile) . ' 2>&1',
            $output,
            $exitCode
        );
        self::$after = self::checksums();
        self::$run = ['exit' => $exitCode, 'report' => implode("\n", $output), 'sql' => (string)@file_get_contents($sqlFile)];
        @unlink($sqlFile);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$database !== null) {
            self::removeFixture();
        }
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
    }

    /** 1. The run gets to the end: before BAJAAPP-63 it died on the first fatal formula. */
    public function testTheRunFinishesAndWritesNothing(): void
    {
        $this->assertSame(0, self::$run['exit'], self::$run['report']);
        $this->assertStringContainsString('Checked 1 events: 3 resultados', self::$run['report'], 'The summary at the very end was printed.');
        $this->assertSame(self::$before, self::$after, 'No table changed during the run.');
    }

    /**
     * 2. Fatal errors come first, before any event, each with what it means
     * in production. Here: the same formula, once in a resultado and once
     * in a prova.
     */
    public function testBothFatalErrorsAreListedFirst(): void
    {
        $this->assertStringStartsWith(
            "!!! 2 FATAL ERRORS !!!\n"
            . "PHP stops dead on these: no try/catch can catch it, and nothing after it runs.\n"
            . "\n"
            . "  99FX_APR  Apresentação  (resultado: its results page dies outright in production)\n"
            . "      column \"Pontos\" val: " . self::MESSAGE . "\n"
            . "  99FX_APR  Apresentação  (prova: a judge saving an entry for it gets a fatal error)\n"
            . "      pontos.APRPTS: " . self::MESSAGE . "\n",
            self::$run['report'],
        );
        $this->assertStringContainsString('1 resultados and 1 provas have a FATAL ERROR, listed at the top.', self::$run['report']);
    }

    /**
     * 3. Under the event, the resultado that dies: which formula, the error,
     * and the formula read both ways, for a person to choose. The child
     * process died, so the teams were never counted; the header says so.
     *
     * "PHP 7 read it as" is what production used to compute: for team 2
     * (DTA = 2) it gives -45, where the author surely meant -30. That is
     * why the tool does not pick a reading itself.
     */
    public function testTheResultadoShowsTheFormulaAndBothReadings(): void
    {
        $this->assertStringContainsString(
            "99FX_APR  Apresentação  (PHP died while checking it, so its other checks did not run)\n"
            . "  FATAL ERROR: PHP dies\n"
            . "    column \"Pontos\" val\n"
            . "      formula: " . self::FORMULA . "\n"
            . "      fatal:   " . self::MESSAGE . "\n"
            . "      A \"? :\" inside another without brackets: PHP 8 refuses to guess which one comes first.\n"
            . "      PHP 7 read it as:  (\$DTA == 1 ?  0 : ((\$DTA == 2 ?  -30 : \$DTA == 3) ?  -45 : -90))\n"
            . "      probably meant:    (\$DTA == 1 ?  0 : (\$DTA == 2 ?  -30 : (\$DTA == 3 ?  -45 : -90)))\n"
            . "      no fix:  a person must pick the reading and add the brackets; the two can give different results.\n",
            self::$run['report'],
        );
    }

    /** 3. The same for the prova, in the provas' part of the event. */
    public function testTheProvaShowsTheFormulaAndBothReadings(): void
    {
        $this->assertStringContainsString(
            "  99FX_APR  Apresentação  (PHP died while checking it, so its other checks did not run)\n"
            . "    FATAL ERROR: PHP dies\n"
            . "      pontos.APRPTS\n"
            . "        formula: " . self::FORMULA . "\n"
            . "        fatal:   " . self::MESSAGE . "\n"
            . "        A \"? :\" inside another without brackets",
            self::$run['report'],
        );
    }

    /**
     * 4. The resultados after the fatal one were still checked: 99FX_SLP
     * has an ordinary error, found and fixed as always. 99FX_REL is fine,
     * so it is not in the report at all, but it is counted in the summary
     * (test 1). And no SQL fix is ever written for the fatal one.
     */
    public function testTheEventsOtherResultadosAreStillChecked(): void
    {
        $this->assertStringContainsString(
            "99FX_SLP  Texto com +  (3 teams)\n"
            . "  Formula raises an error\n"
            . "    column \"Destaque\" val\n"
            . "      formula: '<b>'+ROUND(\$RELPTS,2)+'</b>'\n"
            . "      error:   TypeError: Unsupported operand types: string + float   (3 of 3 teams)\n"
            . "      fix:     '<b>'.ROUND(\$RELPTS,2).'</b>'\n",
            self::$run['report'],
        );
        $this->assertStringNotContainsString('99FX_REL', self::$run['report'], 'A resultado with no problem is not listed.');

        preg_match_all("/^WHERE resultado_id = '([^']+)'/m", self::$run['sql'], $matches);
        $this->assertSame(['99FX_SLP'], $matches[1], 'One UPDATE, for the "+" case only.');
    }

    private static function removeFixture(): void
    {
        // Every other row of the fixture hangs off the event, ON DELETE CASCADE.
        self::$database->prepare('DELETE FROM evento WHERE evento_id = ?')->execute([self::EVENT]);
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
