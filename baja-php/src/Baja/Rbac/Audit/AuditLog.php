<?php
declare(strict_types=1);

namespace Baja\Rbac\Audit;

use Baja\Rbac\Scope\ScopeNode;
use DateTimeImmutable;

/**
 * The record of every change to who may do what (BAJAAPP-12): each grant,
 * each revoke, each edit of a role. Append-only: there is a way to add an
 * entry and none to change or remove one.
 *
 * RoleGranting and RoleEditor write the entry in the same transaction as
 * the change itself, so either both happen or neither does.
 */
interface AuditLog
{
    public const GRANT = 'grant';
    public const REVOKE = 'revoke';
    public const ROLE_CREATE = 'role_create';
    public const ROLE_UPDATE = 'role_update';

    /**
     * @param string     $action  one of the constants above
     * @param int|null   $actorUserId who did it; null for the migration
     * @param array      $details what changed, stored as JSON
     */
    public function record(
        string $action,
        ?int $actorUserId,
        DateTimeImmutable $occurredAt,
        ?int $grantId = null,
        ?int $roleId = null,
        ?int $targetUserId = null,
        ?ScopeNode $scope = null,
        array $details = [],
    ): void;
}
