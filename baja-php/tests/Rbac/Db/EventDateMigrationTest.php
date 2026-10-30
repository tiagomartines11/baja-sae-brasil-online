<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Tests\Support\WritableTestDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Propel\Generator\Manager\MigrationManager;
use Propel\Generator\Util\SqlParser;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Connection\PdoConnection;
use PropelMigration_1793090000 as EventDatesMigration;

require_once dirname(__DIR__, 3) . '/generated-migrations/PropelMigration_1793090000.php';

/**
 * evento.data_inicio and data_fim on a real MySQL (BAJAAPP-12).
 *
 * BACKGROUND
 *
 * A role given on an event ends a few days after the event's last day,
 * evento.data_fim. The migration that adds the column fills it in for the
 * events that already exist, from their free-text `data`.
 * tests/Unit/Rbac/EventDateBackfillTest.php checks how the text is read;
 * this one runs the migration itself against MySQL, the way
 * `propel migration:migrate` does: preUp() reads the texts and prints what
 * it cannot read, then every statement of getUpSQL() runs.
 *
 * HOW, WITHOUT TOUCHING REAL EVENTS
 *
 * Except for the first test, which only looks at the real table, each test
 * works on a TEMPORARY table called evento, in a connection of its own.
 * MySQL uses it instead of the real one for that connection only, and it
 * is gone when the connection closes.
 *
 * HOW TO RUN
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group rbac
 */
#[Group('rbac')]
final class EventDateMigrationTest extends TestCase
{
    private static ?string $skipped = null;

    private ConnectionWrapper $connection;

    public static function setUpBeforeClass(): void
    {
        self::$skipped = WritableTestDatabase::whyUnavailable();
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        // A Propel connection, as the migration gets one, but of our own,
        // so the temporary table below is seen by this test and nobody else.
        $this->connection = new ConnectionWrapper(new PdoConnection(
            'mysql:host=' . getenv('PHPBB_DB_HOST') . ';dbname=baja_resultados;charset=utf8mb4',
            (string) getenv('PHPBB_DB_USER'),
            (string) getenv('PHPBB_DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        ));
    }

    /**
     * The real evento table (the dev seed, or a migrated database) has both
     * columns, as DATEs that may be empty: an event whose dates nobody
     * knows yet can still be saved.
     */
    public function testTheRealTableHasBothDateColumns(): void
    {
        $columns = $this->connection->query(
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evento' AND COLUMN_NAME IN ('data_inicio', 'data_fim')
             ORDER BY COLUMN_NAME"
        )->fetchAll(PDO::FETCH_NUM);

        $this->assertSame([['data_fim', 'date', 'YES'], ['data_inicio', 'date', 'YES']], $columns);
    }

    /**
     * Events whose text can be read get both dates. One whose text cannot
     * ("a definir") keeps them empty, and the migration prints it with its
     * text, so whoever runs it knows which events to fix by hand.
     */
    public function testReadableEventsAreFilledAndTheOthersListed(): void
    {
        $this->createOldEventoTable();
        $this->insertEvents([
            '22BR' => '20 a 24 de abril de 2022',
            '23BR' => '01 a 05 de março de 2023',
            '25AR' => '30 de abril a 2 de maio de 2025',
            '26SE' => 'a definir',
        ]);

        $printed = $this->runTheMigration();

        $this->assertSame([
            '22BR' => ['2022-04-20', '2022-04-24'],
            '23BR' => ['2023-03-01', '2023-03-05'],
            '25AR' => ['2025-04-30', '2025-05-02'],
            '26SE' => [null, null],
        ], $this->datesByEvent());
        $this->assertStringContainsString("26SE  'a definir'", $printed);
        $this->assertStringNotContainsString('22BR', $printed, 'Only the events it could not read are listed.');
    }

    /**
     * MySQL may hand the text over as latin1 bytes (a connection without a
     * character set, as a propel.yaml may well be). "março" must still be
     * read as March, not dropped as unreadable.
     */
    public function testTextArrivingAsLatin1IsReadToo(): void
    {
        $this->createOldEventoTable();
        $this->insertEvents(['23BR' => '01 a 05 de março de 2023']);
        $this->connection->exec('SET NAMES latin1');

        $this->runTheMigration();

        $this->assertSame(['23BR' => ['2023-03-01', '2023-03-05']], $this->datesByEvent());
    }

    /** No text at all is listed too, never filled. */
    public function testAnEventWithNoTextIsListed(): void
    {
        $this->createOldEventoTable();
        $this->insertEvents(['26BR' => null]);

        $printed = $this->runTheMigration();

        $this->assertSame(['26BR' => [null, null]], $this->datesByEvent());
        $this->assertStringContainsString('26BR  NULL', $printed);
    }

    /**
     * An event that already has a last day keeps it: the UPDATEs only fill
     * empty ones, so they never undo a date an admin set in the meantime.
     */
    public function testADateAlreadySetIsNotOverwritten(): void
    {
        $this->createOldEventoTable();
        $this->insertEvents(['22BR' => '20 a 24 de abril de 2022']);
        $plan = EventDatesMigration::planBackfill($this->connection);
        $this->runStatements((new EventDatesMigration())->getUpSQL()['resultados']);
        $this->connection->exec("UPDATE evento SET data_inicio = '2022-04-21', data_fim = '2022-04-25' WHERE evento_id = '22BR'");

        $this->runStatements(implode("\n", $plan['updates']));

        $this->assertSame(['22BR' => ['2022-04-21', '2022-04-25']], $this->datesByEvent());
    }

    /** evento as it is before the migration: latin1, with only the columns it reads. */
    private function createOldEventoTable(): void
    {
        $this->connection->exec(
            'CREATE TEMPORARY TABLE evento (
                evento_id CHAR(4) NOT NULL PRIMARY KEY,
                data VARCHAR(100)
            ) DEFAULT CHARSET=latin1'
        );
    }

    /** @param array<string, ?string> $texts evento_id => data */
    private function insertEvents(array $texts): void
    {
        $insert = $this->connection->prepare('INSERT INTO evento (evento_id, data) VALUES (?, ?)');
        foreach ($texts as $eventoId => $text) {
            $insert->execute([$eventoId, $text]);
        }
    }

    /**
     * The migration, as `migration:migrate` runs it: preUp(), then each
     * statement of getUpSQL() in turn.
     *
     * @return string what it printed
     */
    private function runTheMigration(): string
    {
        $manager = $this->createMock(MigrationManager::class);
        $manager->method('getAdapterConnection')->willReturn($this->connection);
        $migration = new EventDatesMigration();

        ob_start();
        try {
            $migration->preUp($manager);
        } finally {
            $printed = (string) ob_get_clean();
        }
        $this->runStatements($migration->getUpSQL()['resultados']);
        return $printed;
    }

    private function runStatements(string $sql): void
    {
        foreach (SqlParser::parseString($sql) as $statement) {
            $this->connection->exec($statement);
        }
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    private function datesByEvent(): array
    {
        $byEvent = [];
        foreach ($this->connection->query('SELECT evento_id, data_inicio, data_fim FROM evento ORDER BY evento_id')->fetchAll(PDO::FETCH_NUM) as [$eventoId, $first, $last]) {
            $byEvent[(string) $eventoId] = [$first, $last];
        }
        return $byEvent;
    }
}
