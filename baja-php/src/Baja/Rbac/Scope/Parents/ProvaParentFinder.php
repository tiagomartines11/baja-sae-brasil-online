<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope\Parents;

use Baja\Rbac\Scope\ParentFinder;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * A prova hangs from its event (prova.evento_id). Node id "26BR/END".
 * The prova must exist, with that exact spelling (see EventoParentFinder).
 */
final class ProvaParentFinder implements ParentFinder
{
    /** @var array<string, bool> node id => whether the prova exists */
    private array $exists = [];

    public function __construct(private ConnectionInterface $con)
    {
    }

    public function type(): ScopeType
    {
        return ScopeType::PROVA;
    }

    public function parentOf(ScopeNode $node): ?ScopeNode
    {
        [$eventoId, $provaId] = $node->eventAndLocalId();
        if (!array_key_exists($node->id, $this->exists)) {
            $statement = $this->con->prepare('SELECT evento_id, prova_id FROM prova WHERE evento_id = ? AND prova_id = ?');
            $statement->execute([$eventoId, $provaId]);
            $row = $statement->fetchAll(PDO::FETCH_ASSOC)[0] ?? null;
            $this->exists[$node->id] = $row !== null && $row['evento_id'] === $eventoId && $row['prova_id'] === $provaId;
        }
        return $this->exists[$node->id] ? ScopeNode::evento($eventoId) : null;
    }
}
