<?php
declare(strict_types=1);

namespace Baja\Programa;

use Baja\Model\Map\ProgramaTableMap;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;

/**
 * Answers "which program is this event in?" and "which programs are above
 * or below this one?" from the database (BAJAAPP-11).
 *
 * The program of an event is evento.programa_id, as stored. It is never
 * worked out from the event code: 26BR is a Baja SAE event because its row
 * says BAJA, not because it ends in BR.
 *
 * Reads are kept for the life of the object, which is meant to be one web
 * request: the whole programa table is a handful of rows and is read once,
 * the first time a tree question is asked. A change made by someone else
 * shows on the next request.
 */
final class ProgramaResolver
{
    private ?ProgramaTree $tree = null;

    /** @var array<string, string|null> evento_id => programa_id (null: no such event) */
    private array $programaByEvento = [];

    public function __construct(private ConnectionInterface $con)
    {
    }

    public static function forApp(): self
    {
        return new self(Propel::getReadConnection(ProgramaTableMap::DATABASE_NAME));
    }

    /** The program of an event, or null when there is no such event. */
    public function programaOfEvento(string $eventoId): ?string
    {
        if (!array_key_exists($eventoId, $this->programaByEvento)) {
            $statement = $this->con->prepare('SELECT programa_id FROM evento WHERE evento_id = ?');
            $statement->execute([$eventoId]);
            // fetchAll, not fetchColumn: through Propel's statement wrapper
            // "no row" does not come back as false.
            $found = $statement->fetchAll(PDO::FETCH_COLUMN);
            $this->programaByEvento[$eventoId] = $found === [] ? null : (string) $found[0];
        }
        return $this->programaByEvento[$eventoId];
    }

    /**
     * The programs above this one, nearest first, not including it.
     *
     * @return string[]
     */
    public function ancestorsOf(string $programaId): array
    {
        return $this->tree()->ancestorsOf($programaId);
    }

    /**
     * The programs below this one, at any depth, not including it.
     *
     * @return string[]
     */
    public function descendantsOf(string $programaId): array
    {
        return $this->tree()->descendantsOf($programaId);
    }

    /** Every program, inactive ones included: an old event keeps its program. */
    public function tree(): ProgramaTree
    {
        if ($this->tree === null) {
            $parentById = [];
            $rows = $this->con->query('SELECT programa_id, parent_id FROM programa')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $parentById[(string) $row['programa_id']] = $row['parent_id'] === null ? null : (string) $row['parent_id'];
            }
            $this->tree = new ProgramaTree($parentById);
        }
        return $this->tree;
    }
}
