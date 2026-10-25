<?php
declare(strict_types=1);

namespace Baja\Tests\FormulaCompare\Db;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The corpus comparison finds differences when there are some.
 *
 * HOW TO READ THESE TESTS
 *
 * The real script, scripts/formula-engine-compare.php, is run once against
 * a made-up event, 98FE (compare-fixture.sql next to this file), and each
 * test checks one part of what it printed: assertStringContainsString
 * (expected, actual) fails, showing both, when the text is not in it.
 *
 * BACKGROUND
 *
 * On the dev seed the comparison reports no difference at all
 * (SeedComparisonTest). That only means something if the tool would have
 * reported one: this event has one formula of each kind it sorts, and the
 * tests check each lands where it should.
 *
 *   DIFFERENT VALUES  strtoupper() and PHP_VERSION: eval() gives a value,
 *                     SafeEngine refuses them. Exit code 1.
 *   DELIBERATE        18SU's nested ternary and a bare "die": eval() kills
 *                     PHP; the run must survive them and carry on.
 *   nothing           time() against a deadline: both get the fixed now.
 *
 * HOW TO RUN
 *
 * This test WRITES: it inserts 98FE before the run and deletes it after
 * (the tool itself only reads). It runs only when BAJA_TEST_DB=1, and is
 * skipped otherwise. Never set that against production. In the dev
 * container:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite formula-compare-db
 *
 * The fixture is loaded as PHPBB_DB_USER, or as BAJA_TEST_DB_USER /
 * BAJA_TEST_DB_PASS when set (CI sets MySQL's root, so that the tool still
 * runs as an account that may only SELECT).
 */
final class CompareFixtureTest extends TestCase
{
    private const EVENT = '98FE';

    private static ?PDO $database = null;

    private static ?string $skipped = null;

    /** @var array{exit: int, report: string}|null the one run all tests look at */
    private static ?array $run = null;

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
        foreach (preg_split('/;\s*$/m', (string)file_get_contents(__DIR__ . '/compare-fixture.sql')) as $statement) {
            if (trim(preg_replace('/^--.*$/m', '', $statement)) !== '') {
                self::$database->exec($statement);
            }
        }
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/formula-engine-compare.php')
            . ' --events ' . self::EVENT . ' --now 1800000000 2>&1',
            $output,
            $exitCode
        );
        self::$run = ['exit' => $exitCode, 'report' => implode("\n", $output)];
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

    /** It got to the end, despite two formulas that kill PHP, and says something differs. */
    public function testTheRunFinishesAndSaysThereAreDifferences(): void
    {
        $this->assertSame(1, self::$run['exit'], self::$run['report']);
        $this->assertStringContainsString('Result: DIFFERENCES.', self::$run['report']);
        $this->assertStringContainsString('fixed now 2027-01-15 05:00:00 (1800000000)', self::$run['report'], '--now is used');
        $this->assertStringNotContainsString('PROCESSES THAT DIED', self::$run['report'], 'The probe found both fatal formulas beforehand.');
    }

    /** strtoupper() and PHP_VERSION: where, how often, and both answers. */
    public function testDifferentValuesAreListedWithExamples(): void
    {
        $report = self::$run['report'];
        $this->assertStringContainsString('DIFFERENT VALUES (a page would change; each must be fixed or listed as deliberate): 2 formulas', $report);
        $this->assertStringContainsString(
            "  formula: \"PHP_VERSION\"\n"
            . "    where:   98FE_MIX resultado column \"Versão\" val\n"
            . "    3 of 3 evaluations\n"
            . "    with no variables\n"
            . '      eval: "' . PHP_VERSION . "\"\n"
            . "      safe: Error: Undefined constant \"PHP_VERSION\"\n",
            $report,
        );
        $this->assertStringContainsString(
            "  formula: \"strtoupper(\$NOME)\"\n"
            . "    where:   98FE_OFF prova pontos.OFFPTS\n"
            . "    2 of 2 evaluations\n"
            . "    with {\"NOME\":\"abc\"}\n"
            . "      eval: \"ABC\"\n"
            . "      safe: Baja\\Formula\\Syntax\\FormulaSyntaxError: function strtoupper() is not allowed in a formula",
            $report,
        );
    }

    /** The two that kill PHP under eval() are the deliberate ones, with what PHP says. */
    public function testFormulasThatKillEvalAreDeliberate(): void
    {
        $report = self::$run['report'];
        $this->assertStringContainsString('DELIBERATE: eval() kills PHP on these, SafeEngine gives null and the page goes on: 2 formulas', $report);
        $this->assertStringContainsString(
            "  formula: \"(\$DTA == 1 ?  0 : (\$DTA == 2 ?  -30 : \$DTA == 3 ?  -45 : -90))\"\n"
            . "    where:   98FE_APR prova pontos.APRPTS\n"
            . "    3 of 3 evaluations\n"
            . "    with {\"DTA\":1}\n"
            . "      eval: FATAL: Unparenthesized `a ? b : c ? d : e` is not supported.",
            $report,
        );
        $this->assertStringContainsString(
            "  formula: \"die\"\n"
            . "    where:   98FE_MIX resultado column \"Fim\" val\n"
            . "    3 of 3 evaluations\n"
            . "    with no variables\n"
            . "      eval: FATAL: PHP stopped",
            $report,
        );
    }

    /** time() gives the same with both engines: the fixed now. It appears nowhere in the report. */
    public function testTheClockIsTheSameForBoth(): void
    {
        $this->assertStringNotContainsString('time()', self::$run['report']);
        $this->assertStringContainsString('SAME VALUE, OTHER ERROR (no page changes; only the config check\'s wording would): 0 formulas', self::$run['report']);
    }

    private static function removeFixture(): void
    {
        self::$database->prepare('DELETE FROM evento WHERE evento_id = ?')->execute([self::EVENT]);
    }
}
