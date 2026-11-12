<?php
declare(strict_types=1);

namespace Baja\Tests\ResultsCheck\Db;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The seed's provas whose STORED vars or pontos differ from what their
 * formulas give today (BAJAAPP-56), every event, finished or not.
 *
 * Results pages show what is stored. Saving any entry of a prova works out
 * every team's vars and pontos again, so for these provas the next save
 * changes what was published. This test pins the list, so that a change to
 * it (a formula edit, a data fix, a change in the formula engine or in PHP)
 * is seen. It changes nothing: the recompute is in memory, on a read-only
 * connection, and the test checks that no table changed.
 *
 * The finished events' teams and values are in the results snapshots too
 * (tests/results-snapshots/<EV>/formulas.<PROVA>.json, "differs_from_stored");
 * this also covers the events that are not finished (24BR, 25NE, 25SE).
 *
 * Why each one differs, and what to do about it, is in the report of
 * BAJAAPP-56; tests/Unit/Formula/StoredPointsTest.php pins the formulas.
 *
 * HOW TO RUN
 *
 * Against a database loaded with the dev seed, e.g. in the dev container:
 *
 *     docker compose exec baja-app vendor/bin/phpunit tests/ResultsCheck/Db/StoredPointsDifferTest.php
 *
 * Without a reachable database it is skipped.
 */
final class StoredPointsDifferTest extends TestCase
{
    /**
     * Teams whose stored vars / pontos differ, per prova.
     *
     *   22BR_GRI, 22BR_VLT, 24BR_GRI  no formulas: stored NULL, recomputed []; no number changes
     *   22BR_TRA                      vars only: DISTANCIA_MAX was added to the config after the last save
     *   22SE_SUS                      pontos.PENALTS was added after the last save (it gives 0)
     *   *_ST1..4 of 22SU-25SU         max() of an empty list: 0 stored (PHP 7), null today (PHP 8)
     *   23BR_APR                      stale: two teams' dados changed after the last save
     *   23SU_ET1, 24BR_APR            never worked out (stored NULL); the formulas read fields the entries do not have
     *   24NE_SUS                      Ncompletaram without "$": 1 stored (PHP 7), null today (PHP 8)
     */
    private const DIFFER = [
        '22BR_GRI' => ['vars' => 48, 'pontos' => 48],
        '22BR_TRA' => ['vars' => 43, 'pontos' => 0],
        '22BR_VLT' => ['vars' => 49, 'pontos' => 49],
        '22SE_SUS' => ['vars' => 0, 'pontos' => 18],
        '22SU_ST1' => ['vars' => 0, 'pontos' => 3],
        '23BR_APR' => ['vars' => 2, 'pontos' => 2],
        '23SU_ET1' => ['vars' => 17, 'pontos' => 17],
        '23SU_ST1' => ['vars' => 0, 'pontos' => 12],
        '23SU_ST2' => ['vars' => 0, 'pontos' => 6],
        '23SU_ST3' => ['vars' => 0, 'pontos' => 12],
        '23SU_ST4' => ['vars' => 0, 'pontos' => 8],
        '24BR_APR' => ['vars' => 20, 'pontos' => 20],
        '24BR_GRI' => ['vars' => 45, 'pontos' => 45],
        '24NE_SUS' => ['vars' => 0, 'pontos' => 8],
        '24SU_ST1' => ['vars' => 0, 'pontos' => 1],
        '24SU_ST2' => ['vars' => 0, 'pontos' => 1],
        '24SU_ST3' => ['vars' => 0, 'pontos' => 1],
        '24SU_ST4' => ['vars' => 0, 'pontos' => 7],
        '25SU_ST2' => ['vars' => 0, 'pontos' => 2],
        '25SU_ST3' => ['vars' => 0, 'pontos' => 1],
    ];

    private static ?PDO $database = null;

    protected function setUp(): void
    {
        $host = getenv('PHPBB_DB_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('No database: set PHPBB_DB_HOST, PHPBB_DB_USER and PHPBB_DB_PASS.');
        }
        try {
            self::$database ??= new PDO(
                'mysql:host=' . $host . ';dbname=baja_resultados;charset=utf8mb4',
                (string)getenv('PHPBB_DB_USER'),
                (string)getenv('PHPBB_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('No database: ' . $e->getMessage());
        }
    }

    public function testTheProvasWhoseStoredValuesDiffer(): void
    {
        $before = self::checksums();
        $events = self::$database->query('SELECT evento_id FROM evento ORDER BY evento_id')->fetchAll(PDO::FETCH_COLUMN);

        $differ = [];
        foreach ($events as $event) {
            $command = 'REDIRECT_EVENT=' . escapeshellarg((string)$event) . ' SKIP_AUTH=1 ENV=prod '
                . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/stored-points-child.php') . ' 2>/dev/null';
            exec($command, $output, $exit);
            $this->assertSame(0, $exit, 'The child for ' . $event . ' ran.');
            $differ += (array)json_decode((string)array_pop($output), true, 512, JSON_THROW_ON_ERROR);
            $output = [];
        }
        ksort($differ);

        $this->assertSame(self::DIFFER, $differ);
        $this->assertSame($before, self::checksums(), 'No table changed.');
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
