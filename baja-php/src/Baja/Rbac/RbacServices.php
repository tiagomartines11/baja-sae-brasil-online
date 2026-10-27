<?php
declare(strict_types=1);

namespace Baja\Rbac;

use Baja\Rbac\Audit\AuditLog;
use Baja\Rbac\Audit\DatabaseAuditLog;
use Baja\Rbac\Authorization\Authorizer;
use Baja\Rbac\Delegation\RoleEditor;
use Baja\Rbac\Delegation\RoleGranting;
use Baja\Rbac\Expiry\GrantExpiry;
use Baja\Rbac\Grant\GrantRepository;
use Baja\Rbac\Role\RoleRepository;
use Baja\Rbac\Scope\ScopeTree;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;

/**
 * The RBAC objects for one request, sharing one cache (BAJAAPP-12).
 *
 * Everything RBAC reads (the tree, roles, grants) is kept for the life of
 * these objects and nowhere else: no Redis, no static cache that outlives
 * the request. A grant made or revoked shows on the next request.
 *
 * forRequest() gives the same set for the whole of one PHP request.
 * A long-running command line script should build a new set with create()
 * whenever it needs fresh answers.
 */
final class RbacServices
{
    private static ?self $current = null;

    private function __construct(
        public readonly ScopeTree $tree,
        public readonly RoleRepository $roles,
        public readonly GrantRepository $grants,
        public readonly Authorizer $authorizer,
        public readonly RoleGranting $granting,
        public readonly RoleEditor $roleEditor,
    ) {
    }

    public static function forRequest(): self
    {
        return self::$current ??= self::create(Propel::getWriteConnection('resultados'), new SystemClock());
    }

    /**
     * @param callable|null $warn fn(string $message, array $context): void, for refusals and unknown nodes; Baja\Logger::warning by default
     * @param AuditLog|null $audit the role_audit table by default
     * @param GrantExpiry|null $expiry from evento.data and the config by default
     */
    public static function create(ConnectionInterface $con, Clock $clock, ?callable $warn = null, ?AuditLog $audit = null, ?GrantExpiry $expiry = null): self
    {
        $tree = ScopeTree::forDatabase($con, $warn);
        $roles = new RoleRepository($con);
        $grants = new GrantRepository($con);
        $authorizer = new Authorizer($tree, $roles, $grants, $clock);
        $audit ??= new DatabaseAuditLog($con);
        $expiry ??= GrantExpiry::forDatabase($con, $clock);
        return new self(
            $tree,
            $roles,
            $grants,
            $authorizer,
            new RoleGranting($con, $tree, $roles, $grants, $authorizer, $expiry, $audit, $clock, $warn),
            new RoleEditor($con, $roles, $authorizer, $audit, $clock, $warn),
        );
    }
}
