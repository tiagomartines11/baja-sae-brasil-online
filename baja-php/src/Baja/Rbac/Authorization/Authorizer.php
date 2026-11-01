<?php
declare(strict_types=1);

namespace Baja\Rbac\Authorization;

use Baja\Rbac\Clock;
use Baja\Rbac\Grant\Grant;
use Baja\Rbac\Grant\GrantRepository;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Role\RoleRepository;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeTree;

/**
 * "May this user do this, here?" (BAJAAPP-12)
 *
 * can($user, $permission, $node) is true if and only if:
 *
 *   1. the permission applies to the node's type (Permission::appliesTo);
 *   2. the node exists (ScopeTree knows a path from it to the platform);
 *   3. the user has an ACTIVE grant (not revoked, not past valid_until)
 *      on the node itself or on any node above it;
 *   4. whose role carries the permission, and may be given on the type of
 *      node the grant is on.
 *
 * Anything else is no. There is no special case for anyone: a platform
 * admin is a user with the platform admin role on the platform node, and
 * goes through the same four steps. The legacy 'admin' code means nothing
 * here.
 *
 * Step 4's second half covers a role whose allowed types were narrowed
 * after it was given: a grant that could not be made today does nothing.
 *
 * Grants and roles are read once per object and kept, so one object must
 * live for one request at most (forRequest() in RbacServices). A grant
 * made or revoked elsewhere takes effect on the next request.
 */
final class Authorizer
{
    /** @var array<int, Grant[]> user_id => active grants */
    private array $grantsByUser = [];

    public function __construct(
        private ScopeTree $tree,
        private RoleRepository $roles,
        private GrantRepository $grants,
        private Clock $clock,
    ) {
    }

    public function can(int $userId, Permission $permission, ScopeNode $node): bool
    {
        if (!$permission->appliesToType($node->type)) {
            return false;
        }
        return $this->holdsAt($userId, $permission, $node);
    }

    /**
     * Steps 2 to 4 of can(), without asking whether the permission applies
     * to this node's type.
     *
     * That is the question delegation asks: an event manager giving
     * someone the event manager role on an event must hold "enter scores"
     * there, although "enter scores" is only ever ASKED about a prova. It
     * holds it on the event, and so on every prova of it.
     */
    public function holdsAt(int $userId, Permission $permission, ScopeNode $node): bool
    {
        return $this->grantsGiving($userId, $permission, $node) !== [];
    }

    /**
     * The user's active grants that give $permission on $node: on the node
     * or above it, with a role that carries the permission and may be given
     * on the grant's type of node. Empty when the node does not exist.
     *
     * Delegation uses it to find when the granter's own right to grant
     * ends (RoleGranting).
     *
     * @return Grant[]
     */
    public function grantsGiving(int $userId, Permission $permission, ScopeNode $node): array
    {
        $path = $this->tree->pathToRoot($node);
        if ($path === null) {
            return [];
        }
        $reaches = [];
        foreach ($path as $pathNode) {
            $reaches[$pathNode->key()] = true;
        }

        $giving = [];
        foreach ($this->activeGrants($userId) as $grant) {
            $grantNode = $grant->scope();
            if ($grantNode === null || !isset($reaches[$grantNode->key()])) {
                continue;
            }
            $role = $this->roles->byId($grant->roleId);
            if ($role !== null && $role->isAllowedAt($grantNode->type) && $role->has($permission)) {
                $giving[] = $grant;
            }
        }
        return $giving;
    }

    /** Forgets what was read about this user: after this request changed their grants. */
    public function forgetUser(int $userId): void
    {
        unset($this->grantsByUser[$userId]);
    }

    /**
     * The user's grants that are active now (read once per request, expiry
     * checked every time). For the questions that are not one permission
     * on one node: "does this user hold anything in this event?"
     * (Baja\Rbac\Authorization).
     *
     * @return Grant[]
     */
    public function activeGrants(int $userId): array
    {
        if (!isset($this->grantsByUser[$userId])) {
            $this->grantsByUser[$userId] = $this->grants->activeForUser($userId, $this->clock->now());
        }
        // Read once, but expiry is checked against the clock every time: a
        // long request does not keep using a grant that ended during it.
        $now = $this->clock->now();
        return array_filter($this->grantsByUser[$userId], fn(Grant $g) => $g->isActiveAt($now));
    }
}
