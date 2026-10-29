<?php
declare(strict_types=1);

namespace Baja\Programa;

/**
 * The programs and which one each sits under (programa.parent_id), as a
 * tree that can be walked up and down.
 *
 * Pure: it is built from the parent of every program and never touches
 * the database, so it is tested on made-up trees (tests/Unit/Programa).
 * ProgramaResolver builds the real one.
 *
 * Every walk is cycle-safe. The database cannot stop A under B and B
 * under A (a foreign key only checks that the parent exists), and a walk
 * that followed such a loop would never end. Each walk remembers what it
 * has visited and stops on a program it has already seen, so every answer
 * is finite and lists each program once.
 */
final class ProgramaTree
{
    /**
     * @param array<string, string|null> $parentById every program's id => the id of the program it sits under, or null at the top
     */
    public function __construct(private array $parentById)
    {
    }

    public function has(string $programaId): bool
    {
        return array_key_exists($programaId, $this->parentById);
    }

    /** The program this one sits under, or null at the top (or when it does not exist). */
    public function parentOf(string $programaId): ?string
    {
        return $this->parentById[$programaId] ?? null;
    }

    /**
     * Every program above this one, nearest first: for AERO_FORUM, [AERO].
     * Not the program itself. Empty for a program at the top, or one that
     * does not exist.
     *
     * @return string[]
     */
    public function ancestorsOf(string $programaId): array
    {
        $ancestors = [];
        $seen = [$programaId => true];
        $current = $this->parentOf($programaId);
        while ($current !== null && !isset($seen[$current])) {
            $ancestors[] = $current;
            $seen[$current] = true;
            $current = $this->parentOf($current);
        }
        return $ancestors;
    }

    /**
     * Every program below this one, at any depth, nearest first: for AERO,
     * [AERO_FORUM]. Not the program itself.
     *
     * @return string[]
     */
    public function descendantsOf(string $programaId): array
    {
        $descendants = [];
        $seen = [$programaId => true];
        $queue = [$programaId];
        while ($queue !== []) {
            $parent = array_shift($queue);
            foreach ($this->childrenOf($parent) as $child) {
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $descendants[] = $child;
                $queue[] = $child;
            }
        }
        return $descendants;
    }

    /**
     * True when $upper is $lower itself or any program above it. "Does
     * something given on $upper reach $lower?"
     */
    public function isAtOrAbove(string $upper, string $lower): bool
    {
        if (!$this->has($upper) || !$this->has($lower)) {
            return false;
        }
        return $upper === $lower || in_array($upper, $this->ancestorsOf($lower), true);
    }

    /** @return string[] the programs directly under this one, in id order */
    private function childrenOf(string $programaId): array
    {
        $children = array_keys(array_filter(
            $this->parentById,
            fn(?string $parent) => $parent === $programaId
        ));
        $children = array_map('strval', $children);
        sort($children);
        return $children;
    }
}
