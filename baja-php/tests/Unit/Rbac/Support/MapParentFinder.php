<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac\Support;

use Baja\Rbac\Scope\ParentFinder;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;

/**
 * A ParentFinder over a plain list, for testing ScopeTree without a
 * database: "this node's parent is that node". A node not in the list
 * does not exist.
 */
final class MapParentFinder implements ParentFinder
{
    /** @param array<string, ScopeNode> $parentById node id => its parent */
    public function __construct(private ScopeType $type, private array $parentById)
    {
    }

    public function type(): ScopeType
    {
        return $this->type;
    }

    public function parentOf(ScopeNode $node): ?ScopeNode
    {
        return $this->parentById[$node->id] ?? null;
    }
}
