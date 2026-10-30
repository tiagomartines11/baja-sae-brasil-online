<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope\Parents;

use Baja\Programa\ProgramaResolver;
use Baja\Rbac\Scope\ParentFinder;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;

/**
 * A program hangs from the program above it (programa.parent_id), or from
 * the platform when it is at the top. AERO_FORUM -> AERO -> platform.
 */
final class ProgramaParentFinder implements ParentFinder
{
    public function __construct(private ProgramaResolver $programas)
    {
    }

    public function type(): ScopeType
    {
        return ScopeType::PROGRAMA;
    }

    public function parentOf(ScopeNode $node): ?ScopeNode
    {
        $tree = $this->programas->tree();
        if (!$tree->has($node->id)) {
            return null;
        }
        $parentId = $tree->parentOf($node->id);
        return $parentId === null ? ScopeNode::platform() : ScopeNode::programa($parentId);
    }
}
