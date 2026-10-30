<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The audit trail can only grow, and grants are never deleted (BAJAAPP-12).
 *
 * WHY IT MATTERS
 *
 * role_audit is the answer to "who gave this person access, and when?".
 * If code could change or delete its rows, someone could give themselves a
 * role and then erase the trace. user_role is the same kind of record: a
 * revoked grant keeps its row, with who revoked it.
 *
 * The database account the site uses can do anything to its tables, so
 * the rule is kept in the code. This test reads every file of the RBAC
 * code and fails if any of them UPDATEs or DELETEs role_audit, or DELETEs
 * from user_role.
 *
 * (A real UPDATE of user_role exists: the one that fills revoked_at.)
 */
final class AuditIsAppendOnlyTest extends TestCase
{
    /** @return array<string, string> path => file contents */
    private static function rbacSources(): array
    {
        $root = dirname(__DIR__, 3) . '/src/Baja/Rbac';
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        return $files;
    }

    public function testTheRbacCodeIsWhereThisTestLooks(): void
    {
        $this->assertGreaterThan(10, count(self::rbacSources()));
    }

    public function testNothingUpdatesOrDeletesAuditRows(): void
    {
        foreach (self::rbacSources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/UPDATE\s+`?role_audit/i', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+`?role_audit/i', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/TRUNCATE/i', $source, $path);
        }
    }

    public function testNothingDeletesGrants(): void
    {
        foreach (self::rbacSources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/DELETE\s+FROM\s+`?user_role/i', $source, $path);
        }
    }

    /** The one UPDATE of user_role only ever fills a revoke, and only on a grant not yet revoked. */
    public function testTheOnlyGrantUpdateIsTheRevoke(): void
    {
        $updates = [];
        foreach (self::rbacSources() as $path => $source) {
            if (preg_match_all('/UPDATE\s+`?user_role`?\s+SET[^\'"]*/i', $source, $m)) {
                foreach ($m[0] as $statement) {
                    $updates[] = trim($statement);
                }
            }
        }
        $this->assertSame(['UPDATE user_role SET revoked_at = ?, revoked_by = ? WHERE grant_id = ? AND revoked_at IS NULL'], $updates);
    }
}
