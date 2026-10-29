<?php
declare(strict_types=1);

namespace Baja\Tests\Programa\Db;

use Baja\Tests\Support\WritableTestDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PropelMigration_1793070000 as ProgramaMigration;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/generated-migrations/PropelMigration_1793070000.php';

/**
 * The migration's data step on a real MySQL (BAJAAPP-11): filling in
 * evento.programa_id for events that already exist, from their code.
 *
 * BACKGROUND
 *
 * tests/Unit/Programa/BackfillMappingTest.php checks the table of code
 * endings (BR -> BAJA, AR -> AERO...). This one runs what the migration
 * actually runs against MySQL: the check that stops it, and the UPDATE
 * that stores the programs. If the SQL and the table disagreed, events
 * would land in the wrong program, and permissions given on a program
 * would reach the wrong events.
 *
 * HOW, WITHOUT TOUCHING REAL EVENTS
 *
 * Each test opens its own connection and creates a TEMPORARY table called
 * evento in it. MySQL then uses that table instead of the real one, for
 * that connection only, until it closes. The real events are never read or
 * changed, and nothing is left behind even if the test crashes.
 *
 * HOW TO RUN
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group programa
 *
 * Skipped without BAJA_TEST_DB=1 (see tests/Support/WritableTestDatabase.php).
 */
#[Group('programa')]
final class BackfillTest extends TestCase
{
    private static ?string $skipped = null;

    private PDO $connection;

    public static function setUpBeforeClass(): void
    {
        self::$skipped = WritableTestDatabase::whyUnavailable();
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        // A connection of our own, so the temporary table below is seen by
        // this test and nobody else.
        $this->connection = WritableTestDatabase::separatePdo();
        $this->connection->exec(
            'CREATE TEMPORARY TABLE evento (
                evento_id CHAR(4) NOT NULL PRIMARY KEY,
                programa_id VARCHAR(16) CHARACTER SET latin1 NULL
            ) DEFAULT CHARSET=latin1'
        );
    }

    /**
     * Every ending in the plan: the check lets the migration go on, and the
     * UPDATE gives each event exactly the program the table says.
     */
    public function testEverySuffixIsFilledWithItsProgram(): void
    {
        $expected = [
            '26BR' => 'BAJA', '25SE' => 'BAJA', '25SU' => 'BAJA', '25NE' => 'BAJA',
            '25AR' => 'AERO', '25FA' => 'AERO_FORUM', '25FO' => 'FORMULA',
            '25EQ' => 'ELETROQUAD', '25H2' => 'H2',
        ];
        $this->insertEvents(array_keys($expected));

        ProgramaMigration::assertEveryEventCodeIsKnown($this->connection);
        $this->connection->exec(ProgramaMigration::backfillSql());

        ksort($expected);
        $this->assertSame($expected, $this->programsByEvent());
    }

    /**
     * One event with an ending nobody planned: the migration stops before it
     * changes anything, and the message names the event, so whoever runs it
     * knows what to decide.
     */
    public function testAnUnknownSuffixStopsTheMigrationAndNamesTheEvent(): void
    {
        $this->insertEvents(['26BR', '25XX']);

        try {
            ProgramaMigration::assertEveryEventCodeIsKnown($this->connection);
            $this->fail('The migration went on with an event it cannot place.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('25XX', $e->getMessage());
            $this->assertStringNotContainsString('26BR', $e->getMessage(), 'Only the events it cannot place are listed.');
        }

        // Nothing was filled in: the check runs before any change.
        $this->assertSame(['25XX' => null, '26BR' => null], $this->programsByEvent());
    }

    /**
     * MySQL compares latin1 text without regard to case, so "25ar" would
     * match "AR" in a plain comparison. The migration compares exactly, as
     * the table in PHP does, so a lower case code is refused by the check
     * and left alone by the UPDATE.
     */
    public function testLowerCaseIsNotMistakenForAKnownSuffix(): void
    {
        $this->insertEvents(['25ar']);

        try {
            ProgramaMigration::assertEveryEventCodeIsKnown($this->connection);
            $this->fail('The migration went on with a lower case code.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('25ar', $e->getMessage());
        }

        // Even if the UPDATE ran anyway, it would not place it.
        $this->connection->exec(ProgramaMigration::backfillSql());
        $this->assertSame(['25ar' => null], $this->programsByEvent());
    }

    /** An event that already has a program keeps it: the UPDATE only fills empty ones. */
    public function testAProgramAlreadySetIsNotOverwritten(): void
    {
        $this->insertEvents(['26BR']);
        $this->connection->exec("UPDATE evento SET programa_id = 'FORMULA' WHERE evento_id = '26BR'");

        $this->connection->exec(ProgramaMigration::backfillSql());

        $this->assertSame(['26BR' => 'FORMULA'], $this->programsByEvent());
    }

    /** @param string[] $eventoIds */
    private function insertEvents(array $eventoIds): void
    {
        $insert = $this->connection->prepare('INSERT INTO evento (evento_id) VALUES (?)');
        foreach ($eventoIds as $eventoId) {
            $insert->execute([$eventoId]);
        }
    }

    /** @return array<string, string|null> evento_id => programa_id, in evento_id order */
    private function programsByEvent(): array
    {
        $rows = $this->connection->query('SELECT evento_id, programa_id FROM evento')->fetchAll(PDO::FETCH_KEY_PAIR);
        $byEvent = [];
        foreach ($rows as $eventoId => $programaId) {
            $byEvent[(string) $eventoId] = $programaId;
        }
        ksort($byEvent);
        return $byEvent;
    }
}
