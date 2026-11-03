<?php
declare(strict_types=1);

namespace Baja\Rbac\Delegation;

use Baja\Rbac\Scope\ScopeNode;
use RuntimeException;

/**
 * RoleGranting::grantOnMany() refused one or more of the places it was
 * asked for (BAJAAPP-91). Nothing was written, not even for the places
 * that would have been allowed: a batch is all or nothing.
 *
 * $refusals lists every refused place with its own reason, so the page can
 * say what to untick.
 */
final class BatchRefused extends RuntimeException
{
    /** @param array<int, array{node: ScopeNode, refused: GrantRefused}> $refusals */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct(count($refusals) . ' place(s) refused: ' . implode('; ', array_map(
            fn(array $r) => $r['node']->key() . ' (' . $r['refused']->reason . ')',
            $refusals
        )));
    }
}
