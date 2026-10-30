<?php
declare(strict_types=1);

namespace Baja\Rbac\Role;

use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeType;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Reads and writes the role and role_permission tables.
 *
 * Roles are read once per object (one request) and kept: a role edited by
 * someone else shows on the next request. save() forgets what it changed.
 *
 * role.allowed_scope_types is a comma-separated list of ScopeType values
 * ("prova" or "evento,prova").
 */
final class RoleRepository
{
    /** @var array<int, Role>|null */
    private ?array $byId = null;

    public function __construct(private ConnectionInterface $con)
    {
    }

    public function byId(int $roleId): ?Role
    {
        return $this->all()[$roleId] ?? null;
    }

    public function byCode(string $code): ?Role
    {
        foreach ($this->all() as $role) {
            if ($role->code === $code) {
                return $role;
            }
        }
        return null;
    }

    /** @return array<int, Role> by role_id */
    public function all(): array
    {
        if ($this->byId === null) {
            $permissionsByRole = [];
            foreach ($this->con->query('SELECT role_id, permission FROM role_permission')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $permission = Permission::tryFrom((string) $row['permission']);
                if ($permission !== null) {
                    $permissionsByRole[(int) $row['role_id']][] = $permission;
                }
            }
            $this->byId = [];
            $rows = $this->con->query('SELECT role_id, code, name, description, is_system, allowed_scope_types FROM role')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $id = (int) $row['role_id'];
                $this->byId[$id] = new Role(
                    $id,
                    (string) $row['code'],
                    (string) $row['name'],
                    $row['description'] === null ? null : (string) $row['description'],
                    (bool) $row['is_system'],
                    self::parseScopeTypes((string) $row['allowed_scope_types']),
                    $permissionsByRole[$id] ?? [],
                );
            }
        }
        return $this->byId;
    }

    /**
     * Inserts a role, or replaces the definition of the one with this code.
     * Run inside the caller's transaction (RoleEditor's), which also writes
     * the audit row.
     *
     * @param ScopeType[]  $allowedScopeTypes
     * @param Permission[] $permissions
     * @return int the role_id
     */
    public function save(string $code, string $name, ?string $description, bool $isSystem, array $allowedScopeTypes, array $permissions): int
    {
        $types = implode(',', array_map(fn(ScopeType $t) => $t->value, $allowedScopeTypes));
        $existing = $this->byCode($code);
        if ($existing === null) {
            $this->con->prepare('INSERT INTO role (code, name, description, is_system, allowed_scope_types) VALUES (?, ?, ?, ?, ?)')
                ->execute([$code, $name, $description, (int) $isSystem, $types]);
            $roleId = (int) $this->con->lastInsertId();
        } else {
            $roleId = $existing->id;
            $this->con->prepare('UPDATE role SET name = ?, description = ?, is_system = ?, allowed_scope_types = ? WHERE role_id = ?')
                ->execute([$name, $description, (int) $isSystem, $types, $roleId]);
            $this->con->prepare('DELETE FROM role_permission WHERE role_id = ?')->execute([$roleId]);
        }
        $insert = $this->con->prepare('INSERT INTO role_permission (role_id, permission) VALUES (?, ?)');
        foreach (array_unique(array_map(fn(Permission $p) => $p->value, $permissions)) as $code) {
            $insert->execute([$roleId, $code]);
        }
        $this->byId = null;
        return $roleId;
    }

    /** @return ScopeType[] */
    private static function parseScopeTypes(string $stored): array
    {
        $types = [];
        foreach (explode(',', $stored) as $value) {
            $type = ScopeType::tryFrom(trim($value));
            if ($type !== null) {
                $types[] = $type;
            }
        }
        return $types;
    }
}
