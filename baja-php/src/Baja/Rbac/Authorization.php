<?php
declare(strict_types=1);

namespace Baja\Rbac;

use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Rbac\Grant\Grant;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use Baja\Session;
use PDO;
use Propel\Runtime\Propel;

/**
 * The one door every staff page goes through to ask "may this person do
 * this, here?" (BAJAAPP-76, phase 3 of "Plan: programs and RBAC").
 *
 * It replaced Session::hasPermission / permissionCheck and every read of
 * user.permissions. The answers come from Authorizer (docs/rbac.md, "The
 * rules"); this class only turns a page's situation into a node:
 *
 *     the request's event       $_SERVER['REDIRECT_EVENT']   currentEvent()
 *     a prova of it             ?p= / ?id=                   prova()
 *     a queue, a program        whatever the page is about   ScopeNode::...
 *
 * and answers the few questions that are not "one permission on one node":
 *
 *     hasAnyGrantInEvent()   the judge start page: any active grant in the
 *                            event, in one of its provas or queues, or above
 *     isStaff()              the results cache cookie: any active grant
 *     canAnywhere()          a page that lists what you manage (events,
 *                            users): the permission held on some node
 *     canEverywhere()        the certificate pages, which work across every
 *                            event: the permission on every top program
 *
 * Nobody gets through for free: a platform admin passes because their role
 * carries every permission on the platform node, like anyone else's.
 *
 * A null user (no session) is always refused.
 */
final class Authorization
{
    /** The refusal every staff page has always printed. */
    public const REFUSAL = 'Você não tem acesso a essa página';

    private static ?RbacServices $services = null;

    /** @var string[]|null top-level programa_ids, read once per request */
    private static ?array $topProgramas = null;

    /**
     * The RBAC objects this request uses. Tests put their own in with
     * useServices() (a fixed clock, a test connection).
     */
    public static function services(): RbacServices
    {
        return self::$services ?? RbacServices::forRequest();
    }

    /**
     * Tests only: answer from these objects instead of the request's own.
     * Null goes back to RbacServices::forRequest(). Also forgets the list
     * of programs, which a test may have changed.
     */
    public static function useServices(?RbacServices $services): void
    {
        self::$services = $services;
        self::$topProgramas = null;
    }

    // ------------------------------------------------------------------
    // The questions
    // ------------------------------------------------------------------

    /** May $user do $permission on $node? */
    public static function can(?User $user, Permission $permission, ScopeNode $node): bool
    {
        if ($user === null || $user->getUserId() === null) {
            return false;
        }
        return self::services()->authorizer->can((int) $user->getUserId(), $permission, $node);
    }

    /**
     * The same, for the logged-in user. A visitor with no session is sent
     * to the login page first (Session::getCurrentUser), as every staff
     * page did with the legacy codes.
     */
    public static function currentUserCan(Permission $permission, ScopeNode $node): bool
    {
        return self::can(Session::getCurrentUser(), $permission, $node);
    }

    /** The same, for a user known only by name (the lap counting upload has no session). */
    public static function usernameCan(string $username, Permission $permission, ScopeNode $node): bool
    {
        return $username !== '' && self::can(UserQuery::create()->findOneByUsername($username), $permission, $node);
    }

    /**
     * Ends the request with the standard refusal unless the logged-in user
     * may. Sends a visitor with no session to the login first, as
     * permissionCheck() did.
     */
    public static function require(Permission $permission, ScopeNode $node): User
    {
        $user = Session::getCurrentUser();
        if (!self::can($user, $permission, $node)) {
            die(self::REFUSAL);
        }
        return $user;
    }

    /**
     * Does $user hold any active grant that reaches into event $eventoId:
     * on the event, on one of its provas or queues, or on a node above it
     * (its program, a program above, the platform)?
     *
     * That is who reaches the judge start page (the legacy 'index' code,
     * which granted nothing else). A grant in ANOTHER event does not count.
     */
    public static function hasAnyGrantInEvent(?User $user, string $eventoId): bool
    {
        if ($user === null || $user->getUserId() === null || $eventoId === '') {
            return false;
        }
        $rbac = self::services();
        $event = ScopeNode::evento($eventoId);
        foreach (self::usableGrants((int) $user->getUserId()) as $node) {
            if ($node->type === ScopeType::PROVA || $node->type === ScopeType::FILA) {
                if ($node->eventoId() === $eventoId && $rbac->tree->exists($node)) {
                    return true;
                }
            } elseif ($rbac->tree->isAtOrAbove($node, $event)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does $user hold any active grant at all, anywhere? Judges, counters,
     * queue managers... but not a team member who only has the queue code
     * of their team (that is not a role). Gives the results cache cookie
     * (Baja\Auth\StaffCookie).
     */
    public static function isStaff(?User $user): bool
    {
        if ($user === null || $user->getUserId() === null) {
            return false;
        }
        $tree = self::services()->tree;
        foreach (self::usableGrants((int) $user->getUserId()) as $node) {
            if ($tree->exists($node)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does $user hold $permission on at least one node? For a page that
     * lists what the user manages (events, users) and then checks each one.
     */
    public static function canAnywhere(?User $user, Permission $permission): bool
    {
        return self::nodesGiving($user, $permission) !== [];
    }

    /**
     * The nodes where $user holds $permission through a grant ON that node
     * (not the nodes below them). A page offers these and everything under
     * them.
     *
     * @return ScopeNode[]
     */
    public static function nodesGiving(?User $user, Permission $permission): array
    {
        if ($user === null || $user->getUserId() === null) {
            return [];
        }
        $rbac = self::services();
        $nodes = [];
        foreach ($rbac->authorizer->activeGrants((int) $user->getUserId()) as $grant) {
            $node = $grant->scope();
            $role = $rbac->roles->byId($grant->roleId);
            if ($node !== null && $role !== null && $role->isAllowedAt($node->type) && $role->has($permission) && $rbac->tree->exists($node)) {
                $nodes[$node->key()] = $node;
            }
        }
        return array_values($nodes);
    }

    /**
     * Does $user hold $permission on EVERY top-level program, and so on
     * every event there is?
     *
     * For the certificate pages: one form takes any event, one batch may
     * mix events, the search covers all of them. Until those pages ask per
     * event, they are only for someone whose permission covers every event.
     * The legacy 'certificados' code was global, and the migration gives
     * it as certificate_manager on every top-level program, so its holders
     * keep the pages.
     */
    public static function canEverywhere(?User $user, Permission $permission): bool
    {
        $programas = self::topLevelProgramas();
        if ($programas === []) {
            return false;
        }
        foreach ($programas as $programaId) {
            if (!self::can($user, $permission, ScopeNode::programa($programaId))) {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Nodes from the request
    // ------------------------------------------------------------------

    /** The request's event: /26BR/... sets REDIRECT_EVENT (src/bootstrap.php picks one otherwise). */
    public static function currentEventId(): string
    {
        return (string) ($_SERVER['REDIRECT_EVENT'] ?? '');
    }

    public static function currentEvent(): ScopeNode
    {
        return ScopeNode::evento(self::currentEventId());
    }

    /** A prova of the request's event; $provaId as the page received it. */
    public static function prova(mixed $provaId): ScopeNode
    {
        return ScopeNode::prova(self::currentEventId(), is_scalar($provaId) ? (string) $provaId : '');
    }

    // ------------------------------------------------------------------

    /**
     * The nodes of the user's active grants whose role may be given there
     * (the second half of Authorizer's step 4: a grant that could not be
     * made today does nothing).
     *
     * @return ScopeNode[]
     */
    private static function usableGrants(int $userId): array
    {
        $rbac = self::services();
        $nodes = [];
        foreach ($rbac->authorizer->activeGrants($userId) as $grant) {
            /** @var Grant $grant */
            $node = $grant->scope();
            $role = $rbac->roles->byId($grant->roleId);
            if ($node !== null && $role !== null && $role->isAllowedAt($node->type) && $role->permissions !== []) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    /** @return string[] programs with no program above them */
    private static function topLevelProgramas(): array
    {
        if (self::$topProgramas === null) {
            self::$topProgramas = Propel::getReadConnection('resultados')
                ->query('SELECT programa_id FROM programa WHERE parent_id IS NULL ORDER BY programa_id')
                ->fetchAll(PDO::FETCH_COLUMN);
        }
        return self::$topProgramas;
    }
}
