<?php
declare(strict_types=1);

namespace Baja\Torneio;

/**
 * Puts the qualified cars of a tournament in seeding order, on
 * juiz/torneio.php, from the prova's seeding.classification criteria.
 *
 * Each car has one score per criterion, in the criteria's order. The first
 * criterion decides; on a tie the next one does, and so on. Each criterion
 * has its own "order":
 *
 *   "asc"   smaller is better (a time, a position)
 *   "desc"  bigger is better (points); also anything else, and no order,
 *           as every criterion was sorted before BAJAAPP-37
 *
 * A null score (the formula failed, or the car has no input) is last in
 * either order: a car without a score must not become seed 1.
 *
 * The page's comparator used to read the orders from a variable it did not
 * capture, so every criterion was sorted bigger first, and it returned true
 * or false instead of -1, 0 or 1 (BAJAAPP-37).
 */
final class CriteriosClassificacao
{
    /** @param list<string|null> $ordens one per criterion: "asc", "desc" */
    public function __construct(private array $ordens)
    {
    }

    /**
     * For usort(): below zero when car A goes before car B, above zero when
     * after, zero when every score ties (usort keeps them as they came).
     *
     * @param list<mixed> $a car A's scores, one per criterion
     * @param list<mixed> $b car B's scores
     */
    public function compare(array $a, array $b): int
    {
        foreach (array_values($this->ordens) as $i => $ordem) {
            $scoreA = $a[$i] ?? null;
            $scoreB = $b[$i] ?? null;
            if ($scoreA === null || $scoreB === null) {
                $c = ($scoreA === null) <=> ($scoreB === null);
            } else {
                $c = $ordem === 'asc' ? $scoreA <=> $scoreB : $scoreB <=> $scoreA;
            }
            if ($c !== 0) {
                return $c;
            }
        }
        return 0;
    }
}
