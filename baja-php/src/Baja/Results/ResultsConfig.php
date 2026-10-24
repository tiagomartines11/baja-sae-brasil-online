<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * A resultado's "colunas" JSON, read once:
 *
 *     {
 *       "colunas":  [ {column}, ... ],     see Column
 *       "pos":      "Total",               what to rank by (none: no ranking)
 *       "pos_order": "asc",                lowest first; anything else: highest first
 *       "tiebreak": "$TEMPO",              formula that orders teams with equal "pos"
 *       "filter":   "PRESENTE",            only teams whose variable PRESENTE is truthy
 *       "authorized_users": ["jbresolin"], forum users who see past spoilers (judge view)
 *       "type":     "tournament"           shown by torneio.php instead
 *     }
 *
 * Values are kept as written (null when missing) because the two behaviours
 * read some of them differently; see Behaviour\PageBehaviour and
 * Behaviour\PremiacaoBehaviour.
 */
final class ResultsConfig
{
    /**
     * @param list<Column> $columns
     * @param array<mixed> $authorizedUsers
     */
    public function __construct(
        public readonly array $columns,
        public readonly mixed $pos = null,
        public readonly mixed $posOrder = null,
        public readonly mixed $tiebreak = null,
        public readonly mixed $filter = null,
        public readonly array $authorizedUsers = [],
        public readonly mixed $type = null,
    ) {
    }

    /** From what Resultado::getColunas() returns (an object, or null for no JSON). */
    public static function fromColunas(mixed $colunas): self
    {
        $colunas = is_object($colunas) ? $colunas : (object)[];
        $columns = [];
        foreach ((array)($colunas->colunas ?? null) as $column) {
            $columns[] = Column::fromJson($column);
        }
        return new self(
            $columns,
            $colunas->pos ?? null,
            $colunas->pos_order ?? null,
            $colunas->tiebreak ?? null,
            $colunas->filter ?? null,
            (array)($colunas->authorized_users ?? null),
            $colunas->type ?? null,
        );
    }
}
