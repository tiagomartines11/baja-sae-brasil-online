<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Tests\Support\WritableTestDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * role_audit is append-only in MySQL itself, not only in our code
 * (BAJAAPP-12).
 *
 * BACKGROUND
 *
 * role_audit is the record of who gave or took away which role, and when.
 * If it can be edited, it proves nothing: someone who gave themselves a
 * role could also erase the line that says so. tests/Unit/Rbac/
 * AuditIsAppendOnlyTest.php checks that our code never updates or deletes
 * it. This test checks that MySQL refuses it even when somebody tries: two
 * triggers (PropelMigration_1793100000, and baja-infra/mysql/init/
 * 11-role-audit-triggers.sql on a fresh dev database) stop every UPDATE and
 * every DELETE on the table with an error.
 *
 * HOW, WITHOUT LEAVING ANYTHING BEHIND
 *
 * Each test writes one audit row inside a transaction and rolls it back at
 * the end. Rolling back an INSERT is not a DELETE, so the triggers allow
 * it, and the real audit trail is exactly as it was.
 *
 * HOW TO RUN
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group rbac
 */
#[Group('rbac')]
final class AuditIsAppendOnlyInMysqlTest extends TestCase
{
    private static ?string $skipped = null;

    private PDO $connection;
    private int $auditId;

    public static function setUpBeforeClass(): void
    {
        self::$skipped = WritableTestDatabase::whyUnavailable();
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        $this->connection = WritableTestDatabase::separatePdo();
        $this->connection->beginTransaction();
        // Adding a row is allowed: append-only, not read-only.
        $this->connection->exec(
            "INSERT INTO role_audit (occurred_at, actor_user_id, action, details)
             VALUES ('2099-03-01 10:00:00', NULL, 'grant', '{\"test\": \"AuditIsAppendOnlyInMysqlTest\"}')"
        );
        $this->auditId = (int) $this->connection->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (self::$skipped === null) {
            $this->connection->rollBack();
        }
    }

    /** Changing a row fails, with the triggers' message, and the row is as it was. */
    public function testAnUpdateFails(): void
    {
        $this->assertRefusedByMysql("UPDATE role_audit SET action = 'revoke' WHERE audit_id = {$this->auditId}", 'cannot be changed');

        $this->assertSame('grant', $this->actionOfTheRow(), 'The row was changed.');
    }

    /** Deleting a row fails, with the triggers' message, and the row is still there. */
    public function testADeleteFails(): void
    {
        $this->assertRefusedByMysql("DELETE FROM role_audit WHERE audit_id = {$this->auditId}", 'cannot be deleted');

        $this->assertSame('grant', $this->actionOfTheRow(), 'The row was deleted.');
    }

    /**
     * Both triggers exist under their names, BEFORE the change, on
     * role_audit. A trigger AFTER the change would let MySQL write first.
     */
    public function testBothTriggersAreInPlace(): void
    {
        $triggers = $this->connection->query(
            "SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING FROM information_schema.TRIGGERS
             WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'role_audit' ORDER BY TRIGGER_NAME"
        )->fetchAll(PDO::FETCH_NUM);

        $this->assertSame([
            ['role_audit_no_delete', 'DELETE', 'BEFORE'],
            ['role_audit_no_update', 'UPDATE', 'BEFORE'],
        ], $triggers);
    }

    private function assertRefusedByMysql(string $statement, string $message): void
    {
        try {
            $this->connection->exec($statement);
            $this->fail("MySQL allowed: $statement");
        } catch (PDOException $e) {
            // 45000 is the "error raised by a trigger" state the triggers SIGNAL.
            $this->assertSame('45000', $e->getCode(), $e->getMessage());
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function actionOfTheRow(): ?string
    {
        $statement = $this->connection->prepare('SELECT action FROM role_audit WHERE audit_id = ?');
        $statement->execute([$this->auditId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN)[0] ?? null;
    }
}
