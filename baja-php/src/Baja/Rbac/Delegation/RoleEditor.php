<?php
declare(strict_types=1);

namespace Baja\Rbac\Delegation;

use Baja\Logger;
use Baja\Rbac\Audit\AuditLog;
use Baja\Rbac\Authorization\Authorizer;
use Baja\Rbac\Clock;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Role\RoleRepository;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use Propel\Runtime\Connection\ConnectionInterface;
use Throwable;

/**
 * Creating a role or changing what one is (BAJAAPP-12). Only holders of
 * role.manage on the platform may, because changing a role changes what
 * everyone who has it may do, everywhere.
 *
 * Each save writes one audit row with the role before and after, in the
 * same transaction. A change takes effect on the next request.
 */
final class RoleEditor
{
    /** @var callable(string, array): void */
    private $warn;

    public function __construct(
        private ConnectionInterface $con,
        private RoleRepository $roles,
        private Authorizer $authorizer,
        private AuditLog $audit,
        private Clock $clock,
        ?callable $warn = null,
    ) {
        $this->warn = $warn ?? [Logger::class, 'warning'];
    }

    /**
     * Creates the role with this code, or replaces its definition.
     *
     * @param ScopeType[]  $allowedScopeTypes at least one
     * @param Permission[] $permissions
     * @return int the role_id
     * @throws GrantRefused NO_ROLE_MANAGE or INVALID_ROLE; nothing is written then
     */
    public function save(int $editorId, string $code, string $name, ?string $description, bool $isSystem, array $allowedScopeTypes, array $permissions): int
    {
        try {
            if (!$this->authorizer->can($editorId, Permission::ROLE_MANAGE, ScopeNode::platform())) {
                throw new GrantRefused(GrantRefused::NO_ROLE_MANAGE, "User $editorId may not edit roles.");
            }
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $code) !== 1 || $allowedScopeTypes === []) {
                throw new GrantRefused(GrantRefused::INVALID_ROLE, "A role needs a code like 'event_manager' and at least one node type.");
            }
        } catch (GrantRefused $refused) {
            ($this->warn)('RBAC: role edit refused', ['actor' => $editorId, 'role' => $code, 'reason' => $refused->reason]);
            throw $refused;
        }

        $before = $this->roles->byCode($code);
        $now = $this->clock->now();
        $this->con->beginTransaction();
        try {
            $roleId = $this->roles->save($code, $name, $description, $isSystem, $allowedScopeTypes, $permissions);
            $after = $this->roles->byId($roleId);
            $this->audit->record(
                $before === null ? AuditLog::ROLE_CREATE : AuditLog::ROLE_UPDATE,
                $editorId, $now, null, $roleId, null, null,
                ['before' => $before?->describe(), 'after' => $after?->describe()]
            );
            $this->con->commit();
        } catch (Throwable $e) {
            $this->con->rollBack();
            throw $e;
        }
        return $roleId;
    }
}
