<?php
declare(strict_types=1);

namespace Baja\Tests\Support;

use Baja\Rbac\Legacy\DatabaseLegacyWorld;
use Baja\Rbac\Legacy\LegacyCode;
use Baja\Rbac\Legacy\LegacyMapping;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Gives a test user "what the migration would give for these legacy codes"
 * (BAJAAPP-76).
 *
 * Many tests were written as "this user holds the code 00NE_ZZL_CONT".
 * Since the switch-over, the pages ignore codes and read grants. This
 * helper keeps those tests readable: the test still names the old code,
 * and the user gets the grant the migration turns that code into
 * (Baja\Rbac\Legacy\LegacyCode, the same table the migration uses). So
 * every such test is also a small parity check: same code, same answer.
 *
 * The grants are written straight to user_role, with no end and
 * granted_by NULL, like the migration's own; there is no audit row (test
 * data). Codes that map to no grant (index, a team's queue code, an
 * unknown code) give nothing, as in the migration.
 */
final class LegacyGrants
{
    /**
     * @param string[] $codes legacy codes, e.g. ['index', '00NE_ZZL_CONT']
     * @return int[] the ids of the grants written
     */
    public static function give(ConnectionInterface $con, int $userId, array $codes): array
    {
        // A new world each time: the test may have just created the prova.
        $world = new DatabaseLegacyWorld($con);
        $insert = $con->prepare(
            "INSERT INTO user_role (user_id, role_id, scope_type, scope_id, granted_by, granted_at, valid_until)
             SELECT ?, role_id, ?, ?, NULL, NOW(), NULL FROM role WHERE code = ?"
        );
        $ids = [];
        foreach ($codes as $code) {
            $mapping = LegacyCode::map($code, $world);
            if ($mapping->outcome !== LegacyMapping::GRANT) {
                continue;
            }
            foreach ($mapping->grants as [$role, $node]) {
                $insert->execute([$userId, $node->type->value, $node->id, $role]);
                $ids[] = (int) $con->lastInsertId();
            }
        }
        return $ids;
    }

    /** Removes grants a test wrote (only tests ever delete grants). */
    public static function remove(ConnectionInterface $con, array $grantIds): void
    {
        if ($grantIds === []) {
            return;
        }
        $con->exec('DELETE FROM user_role WHERE grant_id IN (' . implode(',', array_map('intval', $grantIds)) . ')');
    }

    /** Removes every grant of the users whose name matches $usernameLike (a LIKE pattern). */
    public static function removeForUsers(ConnectionInterface $con, string $usernameLike): void
    {
        $statement = $con->prepare('SELECT user_id FROM user WHERE username LIKE ?');
        $statement->execute([$usernameLike]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($ids !== []) {
            $con->exec('DELETE FROM user_role WHERE user_id IN (' . implode(',', array_map('intval', $ids)) . ')');
        }
    }
}
