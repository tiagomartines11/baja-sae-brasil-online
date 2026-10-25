<?php
declare(strict_types=1);

namespace Baja\Tests\FormulaCompare\Db;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * On the dev seed, the two formula engines give the same value for every
 * formula, for every team, and the comparison writes nothing.
 *
 * HOW TO READ THESE TESTS
 *
 * scripts/formula-engine-compare.php is run once on the whole seed (17
 * events, ~80,000 evaluations, ~10 s); the tests check what it printed and
 * that no table changed. assertSame(expected, actual) fails, showing both,
 * when they differ.
 *
 * BACKGROUND
 *
 * BAJAAPP-35 replaces eval() with SafeEngine for prova and resultado
 * formulas. The bar is zero unexplained differences, here on the seed and,
 * before the engine is switched on, on a copy of production
 * (docs/formula-engine.md). CompareFixtureTest shows that the tool does
 * report differences when there are some.
 *
 * Read-only, so it needs only the app's database variables (CI runs it as
 * an account that may only SELECT); skipped without them.
 *
 *     docker compose exec baja-app vendor/bin/phpunit --testsuite formula-compare-db
 */
final class SeedComparisonTest extends TestCase
{
    private static ?string $skipped = null;

    /** @var array{exit: int, report: string}|null */
    private static ?array $run = null;

    /** @var array<string, string> table => checksum, before and after the run */
    private static array $before = [];
    private static array $after = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if ($host === false || $host === '') {
            self::$skipped = 'No database variables (PHPBB_DB_HOST...).';
            return;
        }
        try {
            $database = new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string)getenv('PHPBB_DB_USER'),
                (string)getenv('PHPBB_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            self::$skipped = 'No database: ' . $e->getMessage();
            return;
        }
        self::$before = self::checksums($database);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/formula-engine-compare.php') . ' 2>&1', $output, $exitCode);
        self::$after = self::checksums($database);
        self::$run = ['exit' => $exitCode, 'report' => implode("\n", $output)];
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
    }

    public function testNoFormulaDiffers(): void
    {
        $this->assertSame(0, self::$run['exit'], self::$run['report']);
        $this->assertStringContainsString('DIFFERENT VALUES (a page would change; each must be fixed or listed as deliberate): 0 formulas', self::$run['report']);
        $this->assertStringContainsString('Result: no difference but the deliberate ones.', self::$run['report']);
    }

    public function testItWritesNothing(): void
    {
        $this->assertSame(self::$before, self::$after, 'No table changed during the run.');
    }

    /** @return array<string, string> */
    private static function checksums(PDO $database): array
    {
        $tables = $database->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $checksums = [];
        foreach ($database->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->fetchAll(PDO::FETCH_NUM) as [$table, $checksum]) {
            $checksums[(string)$table] = (string)$checksum;
        }
        ksort($checksums);
        return $checksums;
    }
}
