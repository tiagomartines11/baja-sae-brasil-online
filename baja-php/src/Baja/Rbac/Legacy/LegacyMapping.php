<?php
declare(strict_types=1);

namespace Baja\Rbac\Legacy;

use Baja\Rbac\Scope\ScopeNode;

/**
 * What one legacy permission code becomes (LegacyCode::map).
 *
 *     GRANT     one or more grants: [role code, node] pairs
 *     KEPT      stays in user.permissions and is still read there: a
 *               team's own queue code, {EVT}_FILA_{n}_{team}, which is
 *               not a role (the teams package will move it)
 *     DROPPED   means nothing on purpose: 'index', which only opened the
 *               judge start page; that page now follows any grant in the
 *               event
 *     UNMAPPED  should have meant something and maps to nothing: an
 *               event, prova, queue or timer that no longer exists, or a
 *               code nobody recognises. Listed by the migration, never
 *               dropped silently
 */
final class LegacyMapping
{
    public const GRANT = 'grant';
    public const KEPT = 'kept';
    public const DROPPED = 'dropped';
    public const UNMAPPED = 'unmapped';

    /**
     * @param array<int, array{0: string, 1: ScopeNode}> $grants [role code, node]
     */
    private function __construct(
        public readonly string $code,
        public readonly string $outcome,
        public readonly array $grants,
        public readonly string $reason,
    ) {
    }

    public static function grant(string $code, string $roleCode, ScopeNode ...$nodes): self
    {
        return new self($code, self::GRANT, array_map(fn(ScopeNode $n) => [$roleCode, $n], $nodes), '');
    }

    public static function kept(string $code, string $reason): self
    {
        return new self($code, self::KEPT, [], $reason);
    }

    public static function dropped(string $code, string $reason): self
    {
        return new self($code, self::DROPPED, [], $reason);
    }

    public static function unmapped(string $code, string $reason): self
    {
        return new self($code, self::UNMAPPED, [], $reason);
    }
}
