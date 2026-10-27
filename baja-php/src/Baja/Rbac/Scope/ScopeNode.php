<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope;

use InvalidArgumentException;

/**
 * One place in the scope tree: a type and an id (BAJAAPP-12).
 *
 *     platform   id ''               ScopeNode::platform()
 *     programa   id 'AERO'           ScopeNode::programa('AERO')
 *     evento     id '26BR'           ScopeNode::evento('26BR')
 *     prova      id '26BR/END'       ScopeNode::prova('26BR', 'END')
 *     fila       id '26BR/3'         ScopeNode::fila('26BR', 3)
 *
 * A prova or fila id carries its event because prova_id and fila_id are
 * only unique within one event (END exists at every event).
 *
 * Only a value: it says nothing about whether the place exists. ScopeTree
 * answers that, from the real tables.
 */
final class ScopeNode
{
    private const SEPARATOR = '/';

    public function __construct(public readonly ScopeType $type, public readonly string $id)
    {
    }

    public static function platform(): self
    {
        return new self(ScopeType::PLATFORM, '');
    }

    public static function programa(string $programaId): self
    {
        return new self(ScopeType::PROGRAMA, $programaId);
    }

    public static function evento(string $eventoId): self
    {
        return new self(ScopeType::EVENTO, $eventoId);
    }

    public static function prova(string $eventoId, string $provaId): self
    {
        return new self(ScopeType::PROVA, $eventoId . self::SEPARATOR . $provaId);
    }

    public static function fila(string $eventoId, int|string $filaId): self
    {
        return new self(ScopeType::FILA, $eventoId . self::SEPARATOR . $filaId);
    }

    /**
     * Rebuilds a node from what user_role stores. An unknown type gives
     * null: a grant on a kind of place this code does not know reaches
     * nothing.
     */
    public static function fromStored(string $scopeType, string $scopeId): ?self
    {
        $type = ScopeType::tryFrom($scopeType);
        return $type === null ? null : new self($type, $scopeId);
    }

    /** One string per node, for comparing and as an array key: "prova:26BR/END". */
    public function key(): string
    {
        return $this->type->value . ':' . $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /**
     * For a prova or fila id: its two parts, [event, prova or fila].
     *
     * @return array{0: string, 1: string}
     * @throws InvalidArgumentException when the id does not have exactly two non-empty parts
     */
    public function eventAndLocalId(): array
    {
        $parts = explode(self::SEPARATOR, $this->id);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException("Not an <event>/<id> scope id: '{$this->id}'");
        }
        return [$parts[0], $parts[1]];
    }

    /** The event this node is in or is, for evento, prova and fila; null above an event. */
    public function eventoId(): ?string
    {
        return match ($this->type) {
            ScopeType::EVENTO => $this->id,
            ScopeType::PROVA, ScopeType::FILA => $this->eventAndLocalId()[0],
            default => null,
        };
    }

    public function __toString(): string
    {
        return $this->key();
    }
}
