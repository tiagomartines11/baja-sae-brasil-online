<?php
declare(strict_types=1);

namespace Baja\Rbac\Delegation;

use Baja\Rbac\Scope\ScopeNode;

/**
 * What RoleGranting::grantOnMany() did (BAJAAPP-91): the grants it wrote,
 * one per place, and the places it left out because another place of the
 * same request is above them (an event and one of its provas: the grant on
 * the event already reaches the prova).
 */
final class GrantBatch
{
    /**
     * @param array<int, array{node: ScopeNode, grantId: int}> $granted in the order asked
     * @param array<int, array{node: ScopeNode, by: ScopeNode}> $covered each left-out place, and the asked-for place above it that was granted
     */
    public function __construct(
        public readonly array $granted,
        public readonly array $covered,
    ) {
    }

    /** @return int[] */
    public function grantIds(): array
    {
        return array_map(fn(array $g) => $g['grantId'], $this->granted);
    }
}
