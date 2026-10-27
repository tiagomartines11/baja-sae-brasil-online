<?php
declare(strict_types=1);

namespace Baja\Rbac\Role;

use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeType;

/**
 * A role as stored in role and role_permission: a named set of
 * permissions, and the node types it may be given on.
 *
 * Permission codes or node types in the database that this code does not
 * know (left behind by a later version, or typed by hand) are dropped when
 * the role is loaded. A role can only ever do LESS because of them.
 */
final class Role
{
    /**
     * @param ScopeType[]  $allowedScopeTypes
     * @param Permission[] $permissions
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly bool $isSystem,
        public readonly array $allowedScopeTypes,
        public readonly array $permissions,
    ) {
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function isAllowedAt(ScopeType $type): bool
    {
        return in_array($type, $this->allowedScopeTypes, true);
    }

    /** @return string[] permission codes, sorted: for audit rows and messages */
    public function permissionCodes(): array
    {
        $codes = array_map(fn(Permission $p) => $p->value, $this->permissions);
        sort($codes);
        return $codes;
    }

    /** @return string[] */
    public function allowedScopeTypeCodes(): array
    {
        return array_map(fn(ScopeType $t) => $t->value, $this->allowedScopeTypes);
    }

    /** What an audit row records about the role. */
    public function describe(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->isSystem,
            'allowed_scope_types' => $this->allowedScopeTypeCodes(),
            'permissions' => $this->permissionCodes(),
        ];
    }
}
