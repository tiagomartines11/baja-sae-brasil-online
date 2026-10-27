<?php
declare(strict_types=1);

namespace Baja\Rbac\Grant;

use Baja\Rbac\Scope\ScopeNode;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Reads and writes user_role. There is no delete: a grant ends by being
 * revoked or by expiring, and its row stays.
 *
 * Times are stored as DATETIME in the application's time zone
 * (America/Sao_Paulo, set in src/bootstrap.php), like the rest of the
 * database.
 */
final class GrantRepository
{
    public const DATETIME_FORMAT = 'Y-m-d H:i:s';

    private const COLUMNS = 'grant_id, user_id, role_id, scope_type, scope_id, granted_by, granted_at, valid_until, revoked_at, revoked_by';

    public function __construct(private ConnectionInterface $con)
    {
    }

    /**
     * Every grant of the user that is active now. Revoked and expired ones
     * are left out here, so nothing downstream can forget to.
     *
     * @return Grant[]
     */
    public function activeForUser(int $userId, DateTimeImmutable $now): array
    {
        $statement = $this->con->prepare('SELECT ' . self::COLUMNS . ' FROM user_role WHERE user_id = ? AND revoked_at IS NULL');
        $statement->execute([$userId]);
        $grants = array_map([self::class, 'fromRow'], $statement->fetchAll(PDO::FETCH_ASSOC));
        return array_values(array_filter($grants, fn(Grant $g) => $g->isActiveAt($now)));
    }

    /**
     * One grant, locked until the end of the caller's transaction, so two
     * revokes of the same grant cannot both go through.
     */
    public function findForUpdate(int $grantId): ?Grant
    {
        $statement = $this->con->prepare('SELECT ' . self::COLUMNS . ' FROM user_role WHERE grant_id = ? FOR UPDATE');
        $statement->execute([$grantId]);
        $row = $statement->fetchAll(PDO::FETCH_ASSOC)[0] ?? null;
        return $row === null ? null : self::fromRow($row);
    }

    /** @return int the new grant_id */
    public function insert(int $userId, int $roleId, ScopeNode $node, int $grantedBy, DateTimeImmutable $grantedAt, ?DateTimeImmutable $validUntil): int
    {
        $this->con->prepare(
            'INSERT INTO user_role (user_id, role_id, scope_type, scope_id, granted_by, granted_at, valid_until)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $userId, $roleId, $node->type->value, $node->id, $grantedBy,
            self::format($grantedAt), $validUntil === null ? null : self::format($validUntil),
        ]);
        return (int) $this->con->lastInsertId();
    }

    public function markRevoked(int $grantId, int $revokedBy, DateTimeImmutable $revokedAt): void
    {
        $this->con->prepare('UPDATE user_role SET revoked_at = ?, revoked_by = ? WHERE grant_id = ? AND revoked_at IS NULL')
            ->execute([self::format($revokedAt), $revokedBy, $grantId]);
    }

    public static function format(DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(self::zone())->format(self::DATETIME_FORMAT);
    }

    private static function parse(?string $stored): ?DateTimeImmutable
    {
        return $stored === null ? null : new DateTimeImmutable($stored, self::zone());
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    private static function fromRow(array $row): Grant
    {
        return new Grant(
            (int) $row['grant_id'],
            (int) $row['user_id'],
            (int) $row['role_id'],
            (string) $row['scope_type'],
            (string) $row['scope_id'],
            $row['granted_by'] === null ? null : (int) $row['granted_by'],
            self::parse((string) $row['granted_at']),
            self::parse($row['valid_until']),
            self::parse($row['revoked_at']),
            $row['revoked_by'] === null ? null : (int) $row['revoked_by'],
        );
    }
}
