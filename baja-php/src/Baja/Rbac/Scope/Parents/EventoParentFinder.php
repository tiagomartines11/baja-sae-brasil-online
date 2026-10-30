<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope\Parents;

use Baja\Rbac\Scope\ParentFinder;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * An event hangs from its program, as stored in evento.programa_id
 * (BAJAAPP-11). Never from what its code looks like.
 *
 * The id must match exactly, case included. MySQL would find 26BR when
 * asked for "26br", and a grant stored on "26br" would then never match a
 * check on 26BR; refusing the odd spelling up front keeps one id per event.
 */
final class EventoParentFinder implements ParentFinder
{
    /** @var array<string, string|null> evento_id => programa_id, null when there is no such event */
    private array $programaByEvento = [];

    public function __construct(private ConnectionInterface $con)
    {
    }

    public function type(): ScopeType
    {
        return ScopeType::EVENTO;
    }

    public function parentOf(ScopeNode $node): ?ScopeNode
    {
        if (!array_key_exists($node->id, $this->programaByEvento)) {
            $statement = $this->con->prepare('SELECT evento_id, programa_id FROM evento WHERE evento_id = ?');
            $statement->execute([$node->id]);
            $row = $statement->fetchAll(PDO::FETCH_ASSOC)[0] ?? null;
            $this->programaByEvento[$node->id] = ($row !== null && $row['evento_id'] === $node->id)
                ? (string) $row['programa_id']
                : null;
        }
        $programaId = $this->programaByEvento[$node->id];
        return $programaId === null ? null : ScopeNode::programa($programaId);
    }
}
