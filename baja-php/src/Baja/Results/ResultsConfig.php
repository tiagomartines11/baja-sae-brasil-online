<?php
declare(strict_types=1);

namespace Baja\Results;

/**
 * A resultado's "colunas" JSON, read once:
 *
 *     {
 *       "colunas":  [ {column}, ... ],     see Column
 *       "pos":      "Total",               what to rank by (none: no ranking)
 *       "pos_order": "asc",                lowest first; "desc" (default): highest first
 *       "tiebreak": "$TEMPO",              formula that separates teams with equal "pos"
 *       "filter":   "PRESENTE",            only teams whose variable PRESENTE is truthy
 *       "authorized_users": ["jbresolin"], forum users who see past spoilers (judge view)
 *       "type":     "tournament"           shown by torneio.php instead
 *     }
 *
 * The properties keep the JSON as written (null when missing); the methods
 * below say how the rules read them (docs/results-rules.md, R5 and R6).
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

    /** What to rank by (R5): "pos", or null when it is missing or "" (no ranking). */
    public function rankingKey(): string|int|null
    {
        if ($this->pos === null || $this->pos === '') {
            return null;
        }
        return is_int($this->pos) ? $this->pos : (string)$this->pos;
    }

    /** Whether at least one column has a "val_spoilers", so spoilers can hide something. */
    public function hasSpoilerColumns(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->hasSpoilerValue()) {
                return true;
            }
        }
        return false;
    }

    /** "asc" (lowest first) or "desc" (highest first, the default); not case sensitive (R5). */
    public function direction(): string
    {
        return strtolower(trim((string)$this->posOrder)) === 'asc' ? 'asc' : 'desc';
    }

    /** The tiebreak formula, or null when it is missing or "" (R6). "0" is a formula. */
    public function tiebreakFormula(): mixed
    {
        return ($this->tiebreak === null || $this->tiebreak === '') ? null : $this->tiebreak;
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
