<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope\Parents;

use Baja\Rbac\Scope\ParentFinder;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * A queue hangs from its event (fila.evento_id). Node id "26BR/3".
 * The queue must exist; its number must be written plainly ("3", not "03").
 */
final class FilaParentFinder implements ParentFinder
{
    /** @var array<string, bool> node id => whether the queue exists */
    private array $exists = [];

    public function __construct(private ConnectionInterface $con)
    {
    }

    public function type(): ScopeType
    {
        return ScopeType::FILA;
    }

    public function parentOf(ScopeNode $node): ?ScopeNode
    {
        [$eventoId, $filaId] = $node->eventAndLocalId();
        if (!array_key_exists($node->id, $this->exists)) {
            $found = false;
            if (preg_match('/^[1-9][0-9]*$/', $filaId) === 1) {
                $statement = $this->con->prepare('SELECT evento_id FROM fila WHERE evento_id = ? AND fila_id = ?');
                $statement->execute([$eventoId, (int) $filaId]);
                $row = $statement->fetchAll(PDO::FETCH_ASSOC)[0] ?? null;
                $found = $row !== null && $row['evento_id'] === $eventoId;
            }
            $this->exists[$node->id] = $found;
        }
        return $this->exists[$node->id] ? ScopeNode::evento($eventoId) : null;
    }
}
