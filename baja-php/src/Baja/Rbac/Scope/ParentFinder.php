<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope;

/**
 * Knows, for one kind of node, the node directly above it.
 *
 * One implementation per ScopeType below the platform, each reading the
 * real table that says where a node hangs (prova.evento_id,
 * evento.programa_id, programa.parent_id...). ScopeTree chains them.
 *
 * The contract: every node except the platform has a parent. null means
 * "this node does not exist", and the tree then treats it as unknown,
 * which denies.
 */
interface ParentFinder
{
    /** The node type this finder answers for. */
    public function type(): ScopeType;

    /** The node directly above $node, or null when $node does not exist. */
    public function parentOf(ScopeNode $node): ?ScopeNode;
}
