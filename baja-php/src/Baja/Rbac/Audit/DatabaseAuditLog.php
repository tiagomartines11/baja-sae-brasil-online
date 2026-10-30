<?php
declare(strict_types=1);

namespace Baja\Rbac\Audit;

use Baja\Rbac\Grant\GrantRepository;
use Baja\Rbac\Scope\ScopeNode;
use DateTimeImmutable;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * The audit log in the role_audit table. Only INSERTs: this class has no
 * UPDATE or DELETE, and tests/Unit/Rbac/AuditIsAppendOnlyTest.php checks
 * that no code under src/Baja/Rbac writes either to role_audit.
 */
final class DatabaseAuditLog implements AuditLog
{
    public function __construct(private ConnectionInterface $con)
    {
    }

    public function record(
        string $action,
        ?int $actorUserId,
        DateTimeImmutable $occurredAt,
        ?int $grantId = null,
        ?int $roleId = null,
        ?int $targetUserId = null,
        ?ScopeNode $scope = null,
        array $details = [],
    ): void {
        $this->con->prepare(
            'INSERT INTO role_audit (occurred_at, actor_user_id, action, grant_id, role_id, target_user_id, scope_type, scope_id, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            GrantRepository::format($occurredAt),
            $actorUserId,
            $action,
            $grantId,
            $roleId,
            $targetUserId,
            $scope?->type->value,
            $scope?->id,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }
}
