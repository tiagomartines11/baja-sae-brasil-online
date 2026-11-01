<?php
declare(strict_types=1);

namespace Baja\Rbac\Delegation;

use Baja\Logger;
use Baja\Rbac\Audit\AuditLog;
use Baja\Rbac\Authorization\Authorizer;
use Baja\Rbac\Clock;
use Baja\Rbac\Expiry\GrantExpiry;
use Baja\Rbac\Grant\GrantRepository;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Role\Role;
use Baja\Rbac\Role\RoleRepository;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeTree;
use DateTimeImmutable;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;
use Throwable;

/**
 * Giving and taking away roles (BAJAAPP-12): delegation.
 *
 * Someone may give role R to a user on node N only when ALL of these hold:
 *
 *   1. N exists;
 *   2. R may be given on N's type (a judge on a prova, not on an event);
 *   3. they hold role.grant on N or above it;
 *   4. they hold, on N, every permission R carries. Nobody can hand out
 *      more than they have: that is what stops escalation;
 *   5. if R is a system role (platform admin), they hold role.manage.
 *
 * And the new grant never outlives the granter's own right to grant there
 * (granterRightEnds(), applied by GrantExpiry).
 *
 * Taking a grant away (revoke) asks the same of the grant's role and node:
 * whoever could give it may take it away.
 *
 * Each grant and revoke writes exactly one audit row, in the same
 * transaction. A refusal writes nothing; it is logged as a warning.
 *
 * grantByAuthority() and revokeByAuthority() are a second, narrower door,
 * for a page that hands out ONE fixed role as part of its own work: the
 * lap counting's "Usuários" page, where a race controller (no role.grant)
 * adds counters and pit loggers on race morning, as they could before
 * RBAC (BAJAAPP-76). There, rules 3 and 4 become "the granter holds
 * $authority on N", which the page names; rules 1, 2 and 5 stay (a system
 * role is always refused), and so do the expiry cap and the audit row. A
 * role carrying $authority itself or role.grant is refused: holding an
 * authority never lets you hand it on.
 */
final class RoleGranting
{
    /** @var callable(string, array): void */
    private $warn;

    public function __construct(
        private ConnectionInterface $con,
        private ScopeTree $tree,
        private RoleRepository $roles,
        private GrantRepository $grants,
        private Authorizer $authorizer,
        private GrantExpiry $expiry,
        private AuditLog $audit,
        private Clock $clock,
        ?callable $warn = null,
    ) {
        $this->warn = $warn ?? [Logger::class, 'warning'];
    }

    /**
     * Gives $roleCode to $userId on $node.
     *
     * @param DateTimeImmutable|null $validUntil when it ends; null for the default (GrantExpiry)
     * @return int the new grant's id
     * @throws GrantRefused when the rules do not allow it; nothing is written then
     */
    public function grant(int $granterId, int $userId, string $roleCode, ScopeNode $node, ?DateTimeImmutable $validUntil = null): int
    {
        try {
            $role = $this->roles->byCode($roleCode);
            if ($role === null) {
                throw new GrantRefused(GrantRefused::UNKNOWN_ROLE, "There is no role '$roleCode'.");
            }
            if (!$this->userExists($userId)) {
                throw new GrantRefused(GrantRefused::UNKNOWN_USER, "There is no user $userId.");
            }
            $this->assertMayHandOut($granterId, $role, $node);
            $validUntil = $this->expiry->validUntilFor($node, $validUntil, $this->granterRightEnds($granterId, $node, Permission::ROLE_GRANT));
        } catch (GrantRefused $refused) {
            $this->logRefusal('grant', $granterId, $refused, ['user' => $userId, 'role' => $roleCode, 'node' => $node->key()]);
            throw $refused;
        }

        return $this->insertGrant($granterId, $userId, $role, $node, $validUntil, []);
    }

    /**
     * Gives $roleCode to $userId on $node because the granter holds
     * $authority there, not role.grant (see the class comment). The end is
     * always the default, capped by the end of the granter's own grants
     * giving $authority.
     *
     * @return int the new grant's id
     * @throws GrantRefused when the rules do not allow it; nothing is written then
     */
    public function grantByAuthority(Permission $authority, int $granterId, int $userId, string $roleCode, ScopeNode $node): int
    {
        try {
            $role = $this->roles->byCode($roleCode);
            if ($role === null) {
                throw new GrantRefused(GrantRefused::UNKNOWN_ROLE, "There is no role '$roleCode'.");
            }
            if (!$this->userExists($userId)) {
                throw new GrantRefused(GrantRefused::UNKNOWN_USER, "There is no user $userId.");
            }
            $this->assertHoldsAuthority($authority, $granterId, $role, $node);
            $validUntil = $this->expiry->validUntilFor($node, null, $this->granterRightEnds($granterId, $node, $authority));
        } catch (GrantRefused $refused) {
            $this->logRefusal('grant', $granterId, $refused, ['user' => $userId, 'role' => $roleCode, 'node' => $node->key(), 'authority' => $authority->value]);
            throw $refused;
        }

        return $this->insertGrant($granterId, $userId, $role, $node, $validUntil, ['authority' => $authority->value]);
    }

    /**
     * Takes a grant away. The row stays, with revoked_at and revoked_by.
     *
     * @throws GrantRefused when the rules do not allow it; nothing is written then
     */
    public function revoke(int $revokerId, int $grantId): void
    {
        $this->revokeChecked($revokerId, $grantId, null, fn(Role $role, ScopeNode $node) => $this->assertMayHandOut($revokerId, $role, $node));
    }

    /**
     * Takes a grant away because the revoker holds $authority on its node
     * (the other door: see the class comment). Only for a grant of
     * $roleCode, so a page that hands out one role can take away that role
     * and nothing else.
     *
     * @throws GrantRefused when the rules do not allow it; nothing is written then
     */
    public function revokeByAuthority(Permission $authority, string $roleCode, int $revokerId, int $grantId): void
    {
        $this->revokeChecked($revokerId, $grantId, $authority, function (Role $role, ScopeNode $node) use ($authority, $roleCode, $revokerId, $grantId): void {
            if ($role->code !== $roleCode) {
                throw new GrantRefused(GrantRefused::ROLE_NOT_ALLOWED_HERE, "Grant $grantId is of the role '{$role->code}', not '$roleCode'.");
            }
            $this->assertHoldsAuthority($authority, $revokerId, $role, $node);
        });
    }

    /** @param callable(Role, ScopeNode): void $assertAllowed throws GrantRefused */
    private function revokeChecked(int $revokerId, int $grantId, ?Permission $authority, callable $assertAllowed): void
    {
        $userId = null;
        try {
            $this->inTransaction(function () use ($revokerId, $grantId, $authority, $assertAllowed, &$userId): void {
                // Locked first, so that two revokes of one grant cannot both pass.
                $grant = $this->grants->findForUpdate($grantId);
                if ($grant === null) {
                    throw new GrantRefused(GrantRefused::UNKNOWN_GRANT, "There is no grant $grantId.");
                }
                if ($grant->revokedAt !== null) {
                    throw new GrantRefused(GrantRefused::ALREADY_REVOKED, "Grant $grantId was already revoked.");
                }
                $role = $this->roles->byId($grant->roleId);
                $node = $grant->scope();
                if ($role === null || $node === null) {
                    throw new GrantRefused(GrantRefused::UNKNOWN_NODE, "Grant $grantId refers to a role or a kind of node that no longer exists.");
                }
                $assertAllowed($role, $node);

                $now = $this->clock->now();
                $this->grants->markRevoked($grantId, $revokerId, $now);
                $details = ['role' => $role->code];
                if ($authority !== null) {
                    $details['authority'] = $authority->value;
                }
                $this->audit->record(AuditLog::REVOKE, $revokerId, $now, $grantId, $role->id, $grant->userId, $node, $details);
                $userId = $grant->userId;
            });
        } catch (GrantRefused $refused) {
            $this->logRefusal('revoke', $revokerId, $refused, ['grant' => $grantId] + ($authority === null ? [] : ['authority' => $authority->value]));
            throw $refused;
        }
        $this->authorizer->forgetUser((int) $userId);
    }

    /** Rules 1 to 5 above. */
    private function assertMayHandOut(int $granterId, Role $role, ScopeNode $node): void
    {
        if (!$this->tree->exists($node)) {
            throw new GrantRefused(GrantRefused::UNKNOWN_NODE, "There is no {$node->key()}.");
        }
        if (!$role->isAllowedAt($node->type)) {
            throw new GrantRefused(GrantRefused::ROLE_NOT_ALLOWED_HERE, "The role '{$role->code}' cannot be given on a {$node->type->value}.");
        }
        if (!$this->authorizer->can($granterId, Permission::ROLE_GRANT, $node)) {
            throw new GrantRefused(GrantRefused::NO_ROLE_GRANT, "User $granterId may not give roles on {$node->key()}.");
        }
        $missing = array_values(array_filter(
            $role->permissions,
            fn(Permission $p) => !$this->authorizer->holdsAt($granterId, $p, $node)
        ));
        if ($missing !== []) {
            $codes = implode(', ', array_map(fn(Permission $p) => $p->value, $missing));
            throw new GrantRefused(GrantRefused::ESCALATION, "The role '{$role->code}' carries permissions user $granterId does not hold on {$node->key()}: $codes.");
        }
        if ($role->isSystem && !$this->authorizer->can($granterId, Permission::ROLE_MANAGE, ScopeNode::platform())) {
            throw new GrantRefused(GrantRefused::SYSTEM_ROLE, "The role '{$role->code}' is a system role: only holders of role.manage may give or take it.");
        }
    }

    /**
     * Rules 1, 2 and 5 of the class comment, with "holds $authority on the
     * node" in place of 3 and 4: for grantByAuthority/revokeByAuthority.
     */
    private function assertHoldsAuthority(Permission $authority, int $granterId, Role $role, ScopeNode $node): void
    {
        if (!$this->tree->exists($node)) {
            throw new GrantRefused(GrantRefused::UNKNOWN_NODE, "There is no {$node->key()}.");
        }
        if (!$role->isAllowedAt($node->type)) {
            throw new GrantRefused(GrantRefused::ROLE_NOT_ALLOWED_HERE, "The role '{$role->code}' cannot be given on a {$node->type->value}.");
        }
        if ($role->isSystem) {
            throw new GrantRefused(GrantRefused::SYSTEM_ROLE, "The role '{$role->code}' is a system role: only holders of role.manage may give or take it.");
        }
        // This door never hands on the authority itself, nor the right to
        // give roles: a controller adds counters, not other controllers.
        if ($role->has($authority) || $role->has(Permission::ROLE_GRANT)) {
            throw new GrantRefused(GrantRefused::ESCALATION, "The role '{$role->code}' carries {$authority->value} or role.grant, which cannot be handed on by holding {$authority->value}.");
        }
        if (!$this->authorizer->can($granterId, $authority, $node)) {
            throw new GrantRefused(GrantRefused::NO_ROLE_GRANT, "User $granterId does not hold {$authority->value} on {$node->key()}.");
        }
    }

    /**
     * When the granter's right to give roles on $node ends: the end of the
     * latest-ending of their grants carrying $permission (role.grant, or
     * the authority of grantByAuthority) on $node or above. Null when one
     * of them never ends (a platform admin's, usually).
     *
     * A grant made now must not outlive it (GrantExpiry): otherwise a head
     * judge whose role ends in five days could make a judge for a year.
     */
    private function granterRightEnds(int $granterId, ScopeNode $node, Permission $permission): ?DateTimeImmutable
    {
        $covering = $this->authorizer->grantsGiving($granterId, $permission, $node);
        if ($covering === []) {
            // The assertions before this have already refused it; never read
            // "no covering grant" as "no limit".
            throw new GrantRefused(GrantRefused::NO_ROLE_GRANT, "User $granterId may not give roles on {$node->key()}.");
        }
        $latest = null;
        foreach ($covering as $grant) {
            if ($grant->validUntil === null) {
                return null;
            }
            if ($latest === null || $grant->validUntil > $latest) {
                $latest = $grant->validUntil;
            }
        }
        return $latest;
    }

    /** Writes the grant and its audit row in one transaction. */
    private function insertGrant(int $granterId, int $userId, Role $role, ScopeNode $node, ?DateTimeImmutable $validUntil, array $extraDetails): int
    {
        $now = $this->clock->now();
        $grantId = $this->inTransaction(function () use ($granterId, $userId, $role, $node, $now, $validUntil, $extraDetails): int {
            $grantId = $this->grants->insert($userId, $role->id, $node, $granterId, $now, $validUntil);
            $this->audit->record(
                AuditLog::GRANT, $granterId, $now, $grantId, $role->id, $userId, $node,
                ['role' => $role->code, 'valid_until' => $validUntil === null ? null : GrantRepository::format($validUntil)] + $extraDetails
            );
            return $grantId;
        });
        $this->authorizer->forgetUser($userId);
        return $grantId;
    }

    private function userExists(int $userId): bool
    {
        $statement = $this->con->prepare('SELECT user_id FROM user WHERE user_id = ?');
        $statement->execute([$userId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN) !== [];
    }

    /**
     * Runs $work in a transaction: committed if it returns, rolled back if
     * it throws (and the exception goes on).
     */
    private function inTransaction(callable $work): mixed
    {
        $this->con->beginTransaction();
        try {
            $result = $work();
            $this->con->commit();
            return $result;
        } catch (Throwable $e) {
            $this->con->rollBack();
            throw $e;
        }
    }

    private function logRefusal(string $what, int $actorId, GrantRefused $refused, array $context): void
    {
        ($this->warn)("RBAC: $what refused", ['actor' => $actorId, 'reason' => $refused->reason, 'message' => $refused->getMessage()] + $context);
    }
}
