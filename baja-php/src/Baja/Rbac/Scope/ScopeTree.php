<?php
declare(strict_types=1);

namespace Baja\Rbac\Scope;

use Baja\Logger;
use Baja\Programa\ProgramaResolver;
use Baja\Rbac\Scope\Parents\EventoParentFinder;
use Baja\Rbac\Scope\Parents\FilaParentFinder;
use Baja\Rbac\Scope\Parents\ProgramaParentFinder;
use Baja\Rbac\Scope\Parents\ProvaParentFinder;
use InvalidArgumentException;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * The tree of places a role can be given on (BAJAAPP-12), worked out from
 * the real tables:
 *
 *     prova  -> its evento      (prova.evento_id)
 *     fila   -> its evento      (fila.evento_id)
 *     evento -> its programa    (evento.programa_id)
 *     programa -> the programa above it (programa.parent_id),
 *                 or the platform at the top
 *
 * It answers two questions: "what is above N?" (pathToRoot, ancestorsOf)
 * and "is A at or above B?" (isAtOrAbove). A grant on A reaches B exactly
 * when A is at or above B.
 *
 * A node the tables do not have is UNKNOWN: it has no path, nothing is at
 * or above it, and every check on it denies. The same goes for a node of a
 * type no finder answers for, and for a node whose way up loops (programs
 * A under B and B under A: corrupt data, which is denied rather than
 * guessed about). Each case is logged.
 *
 * New node types are added by giving the constructor another ParentFinder.
 * Nothing about grants changes.
 */
final class ScopeTree
{
    /** @var array<string, ParentFinder> by ScopeType value */
    private array $finders = [];

    /** @var callable(string, array): void */
    private $warn;

    /**
     * @param ParentFinder[] $finders one per node type below the platform
     * @param callable|null $warn fn(string $message, array $context): void; Baja\Logger::warning by default
     */
    public function __construct(array $finders, ?callable $warn = null)
    {
        foreach ($finders as $finder) {
            $this->finders[$finder->type()->value] = $finder;
        }
        $this->warn = $warn ?? [Logger::class, 'warning'];
    }

    /** The tree over the app's tables. Its answers are kept for its lifetime: one request. */
    public static function forDatabase(ConnectionInterface $con, ?callable $warn = null): self
    {
        $programas = new ProgramaResolver($con);
        return new self([
            new ProgramaParentFinder($programas),
            new EventoParentFinder($con),
            new ProvaParentFinder($con),
            new FilaParentFinder($con),
        ], $warn);
    }

    public function exists(ScopeNode $node): bool
    {
        return $this->pathToRoot($node) !== null;
    }

    /**
     * The node itself, then every node above it, up to and including the
     * platform. Null when the node is unknown.
     *
     * @return ScopeNode[]|null
     */
    public function pathToRoot(ScopeNode $node): ?array
    {
        $path = [];
        $seen = [];
        $current = $node;
        while (true) {
            if (isset($seen[$current->key()])) {
                ($this->warn)('RBAC: the scope tree loops; treating the node as unknown', ['node' => $node->key(), 'loop_at' => $current->key()]);
                return null;
            }
            $seen[$current->key()] = true;
            $path[] = $current;

            if ($current->type === ScopeType::PLATFORM) {
                if (!$current->equals(ScopeNode::platform())) {
                    ($this->warn)('RBAC: unknown scope node', ['node' => $node->key(), 'reason' => 'the platform node has no id']);
                    return null;
                }
                return $path;
            }

            $finder = $this->finders[$current->type->value] ?? null;
            if ($finder === null) {
                ($this->warn)('RBAC: unknown scope node', ['node' => $node->key(), 'reason' => 'no finder for type ' . $current->type->value]);
                return null;
            }
            try {
                $parent = $finder->parentOf($current);
            } catch (InvalidArgumentException $e) {
                $parent = null;
            }
            if ($parent === null) {
                ($this->warn)('RBAC: unknown scope node', ['node' => $node->key(), 'missing' => $current->key()]);
                return null;
            }
            $current = $parent;
        }
    }

    /**
     * Every node above this one, nearest first, ending with the platform.
     * Not the node itself. Null when the node is unknown.
     *
     * @return ScopeNode[]|null
     */
    public function ancestorsOf(ScopeNode $node): ?array
    {
        $path = $this->pathToRoot($node);
        return $path === null ? null : array_slice($path, 1);
    }

    /**
     * True when $upper is $lower itself or one of the nodes above it, and
     * $lower exists. Never true for siblings, or from below.
     */
    public function isAtOrAbove(ScopeNode $upper, ScopeNode $lower): bool
    {
        foreach ($this->pathToRoot($lower) ?? [] as $node) {
            if ($node->equals($upper)) {
                return true;
            }
        }
        return false;
    }
}
