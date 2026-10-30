<?php
declare(strict_types=1);

namespace Baja\Rbac\Grant;

use Baja\Rbac\Scope\ScopeNode;
use DateTimeImmutable;

/**
 * One row of user_role: user U has role R on node N, from granted_at,
 * until valid_until (if set), unless revoked.
 *
 * Rows are never deleted. Revoking fills revoked_at and revoked_by; the
 * row stays as the record of who had what, and when.
 */
final class Grant
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $roleId,
        public readonly string $scopeType,
        public readonly string $scopeId,
        public readonly ?int $grantedBy,
        public readonly DateTimeImmutable $grantedAt,
        public readonly ?DateTimeImmutable $validUntil,
        public readonly ?DateTimeImmutable $revokedAt,
        public readonly ?int $revokedBy,
    ) {
    }

    /** The node, or null when its type is one this code does not know (such a grant reaches nothing). */
    public function scope(): ?ScopeNode
    {
        return ScopeNode::fromStored($this->scopeType, $this->scopeId);
    }

    /**
     * Active: not revoked, and not past valid_until. A grant whose
     * valid_until is exactly now has ended.
     */
    public function isActiveAt(DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null
            && ($this->validUntil === null || $now < $this->validUntil);
    }
}
