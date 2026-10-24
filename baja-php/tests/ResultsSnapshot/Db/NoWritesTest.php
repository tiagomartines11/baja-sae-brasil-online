<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsSnapshot\Db;

use Baja\ResultsSnapshot\Capture\ChildProcess;
use Baja\ResultsSnapshot\Capture\ReadOnlyDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Propel;

/**
 * Proves, against a real database, that the snapshot tool writes nothing.
 *
 * HOW TO RUN
 *
 * Wherever the app's database variables (PHPBB_DB_HOST, PHPBB_DB_USER,
 * PHPBB_DB_PASS) point at a database loaded from baja-infra/mysql/init/,
 * e.g. in the dev container:
 *
 *     docker compose exec baja-app vendor/bin/phpunit --testsuite results-snapshot-db
 *
 * Without a reachable database every test here is skipped. CI runs them in
 * the "Results snapshots" job.
 *
 * WHY THIS MATTERS
 *
 * The tool is meant to be pointed at a copy of production. "It only reads"
 * has to be something a test shows, not something the code promises.
 */
final class NoWritesTest extends TestCase
{
    private static ?PDO $database = null;

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
        }
    }

    protected function setUp(): void
    {
        if (self::$database === null) {
            $this->markTestSkipped('No database: set PHPBB_DB_HOST, PHPBB_DB_USER and PHPBB_DB_PASS (see the class comment).');
        }
    }

    public function testAFullRunLeavesEveryTableExactlyAsItWas(): void
    {
        // A checksum of each table's whole content, before and after. The
        // two events chosen cover every kind of snapshot: 25SU has the
        // spoiler pages, 26BR a tournament and a premiação, both have
        // formulas.
        $before = $this->checksums();

        $folder = sys_get_temp_dir() . '/results-snapshot-nowrites-' . bin2hex(random_bytes(4));
        $root = dirname(__DIR__, 3);
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/results-snapshot.php')
            . ' --update --dir ' . escapeshellarg($folder) . ' --events 25SU,26BR 2>&1',
            $output,
            $exitCode
        );
        exec('rm -rf ' . escapeshellarg($folder));

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertSame($before, $this->checksums(), 'No table changed.');
    }

    /**
     * What MySQL answers to a write that is refused. Two answers are right:
     * "Cannot execute statement in a READ ONLY transaction" (the session
     * switch at work), or "UPDATE command denied" when the account itself
     * only has SELECT, as in CI, because MySQL checks privileges first. That
     * the session switch is on is then checked separately, by asking MySQL.
     */
    private const REFUSED_WRITE = '/READ ONLY transaction|command denied/';

    public function testTheToolsConnectionRefusesWrites(): void
    {
        // The command's own connection, after ReadOnlyDatabase::enforce().
        require_once dirname(__DIR__, 3) . '/src/config.php';
        ReadOnlyDatabase::enforce();
        $this->assertTrue(ReadOnlyDatabase::isEnforced(), 'MySQL says this session is read-only.');

        try {
            // Would change nothing even if allowed; it must not be allowed.
            Propel::getWriteConnection('resultados')->exec("UPDATE evento SET ativo = ativo WHERE evento_id = '00XX'");
            $this->fail('A write went through on a read-only connection.');
        } catch (PDOException $e) {
            $this->assertMatchesRegularExpression(self::REFUSED_WRITE, $e->getMessage());
        }
    }

    public function testAPageThatTriedToWriteWouldFailAndBeRecorded(): void
    {
        // A stand-in page, run through the same harness as the real ones
        // (scripts/results-snapshot/page-prepend.php). It first prints
        // whether its session is read-only, then tries to write. The
        // harness must have switched the session to read-only before the
        // page started, so the write fails and the failure is recorded.
        $root = dirname(__DIR__, 3);
        $work = sys_get_temp_dir() . '/results-snapshot-writer-' . bin2hex(random_bytes(4));
        mkdir($work);
        file_put_contents($work . '/writer.php', '<?php $c = \Propel\Runtime\Propel::getWriteConnection("resultados");'
            . ' echo "read-only=" . $c->query("SELECT @@SESSION.transaction_read_only")->fetchColumn() . ";";'
            . ' $c->exec("UPDATE evento SET ativo = ativo WHERE evento_id = \'00XX\'"); echo "wrote";');

        $child = new ChildProcess(
            arguments: [$work . '/writer.php'],
            environment: ['REDIRECT_EVENT' => '22BR', 'SKIP_AUTH' => '1', 'ENV' => 'prod', 'SNAPSHOT_QUERY' => 'id=22BR_GER'],
            workingDirectory: $work,
            resultFile: $work . '/result.json',
            stderrFile: $work . '/stderr.txt',
            ini: ['auto_prepend_file' => $root . '/scripts/results-snapshot/page-prepend.php'],
        );
        // Started the way ProcessPool starts every page: its own environment, its own folder.
        $process = proc_open($child->command(), [1 => ['file', '/dev/null', 'w'], 2 => ['file', $child->stderrFile, 'w']], $pipes, $work, $child->fullEnvironment());
        proc_close($process);
        $result = json_decode((string)file_get_contents($child->resultFile), true);
        exec('rm -rf ' . escapeshellarg($work));

        $this->assertSame('read-only=1;', (string)$result['html'], 'The page ran on a read-only session and did not get past its write.');
        $this->assertMatchesRegularExpression(self::REFUSED_WRITE, (string)($result['fatal']['message'] ?? ''), 'The failed write is recorded as the error.');
    }

    /** @return array<string, string> table => checksum of its content */
    private function checksums(): array
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
