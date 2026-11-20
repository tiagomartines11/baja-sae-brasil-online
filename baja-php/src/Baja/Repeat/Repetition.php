<?php
declare(strict_types=1);

namespace Baja\Repeat;

/**
 * One repetition of a repeated input group (BAJAAPP-103): "Tentativa 3" of
 * a group of six. See InputGroups.
 *
 *   group   which group of the prova it belongs to, counted from 1 in the
 *           order of "inputs" (a prova can have more than one group)
 *   name    the group's "nome" ("Tentativa"); "" when the config has none
 *   number  which repetition this is, from 1
 *   total   how many repetitions the group has ("repetir")
 *   pass    the pass its fields are in
 *   codes   the codes of its fields, in order ("T_3", "D_3")
 */
final class Repetition
{
    /**
     * @param list<string> $codes
     */
    public function __construct(
        public readonly int $group,
        public readonly string $name,
        public readonly int $number,
        public readonly int $total,
        public readonly int $pass,
        public readonly array $codes,
    ) {
    }

    /** "Tentativa 3", or just "3" when the group has no "nome". */
    public function title(): string
    {
        return trim($this->name . ' ' . $this->number);
    }

    /**
     * The id of this repetition's block on the judge screen, and the
     * anchor the page jumps to: "repeticao-1-3" (group 1, repetition 3).
     */
    public function anchor(): string
    {
        return 'repeticao-' . $this->group . '-' . $this->number;
    }

    /** The code of its first field: where its block opens on the judge screen. */
    public function firstCode(): ?string
    {
        return $this->codes[0] ?? null;
    }

    /** The code of its last field: where its block closes. */
    public function lastCode(): ?string
    {
        return $this->codes === [] ? null : $this->codes[count($this->codes) - 1];
    }

    public function isFirst(): bool
    {
        return $this->number === 1;
    }

    public function isLast(): bool
    {
        return $this->number === $this->total;
    }
}
