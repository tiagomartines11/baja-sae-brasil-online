<?php
declare(strict_types=1);

namespace Baja\Posicoes;

use Baja\Model\ProvaQuery;
use Baja\Model\ResultadoQuery;
use Baja\Results\ResultsConfig;

/**
 * Which prova reads which, through "posicoes" (BAJAAPP-102), in one event.
 *
 * Two kinds of link:
 *
 *   prova → resultado   the prova's "posicoes" take a position from it
 *   resultado → prova   the prova is one of the resultado's Inputs
 *
 * So "CMP → 26SU_TRA → TRA" means: the points of CMP depend on the ranking
 * of 26SU_TRA, which depends on the points of TRA. When TRA's points change,
 * CMP must be worked out again (recalculationOrder()). A chain that comes
 * back to where it started ("CMP → 26SU_GER → CMP") can never be worked
 * out, and is refused on save (cycleFromProva(), cycleFromResultado()).
 *
 * Built from the database (load()) or by hand (the tests). Never changed:
 * withProva() and withResultado() give a copy with one config replaced, to
 * check a config before it is saved.
 */
final class EventGraph
{
    /**
     * @param array<string, mixed> $provas         prova id => its params (decoded JSON)
     * @param array<string, array{inputs: list<string>, colunas: mixed}> $resultados
     *        resultado id => its Inputs (prova ids) and its "colunas" (decoded JSON)
     */
    public function __construct(
        public readonly string $eventId,
        private readonly array $provas,
        private readonly array $resultados,
    ) {
    }

    public static function load(string $eventId): self
    {
        $provas = [];
        foreach (ProvaQuery::create()->filterByEventoId($eventId)->find() as $prova) {
            $provas[(string)$prova->getProvaId()] = $prova->getParams();
        }
        $resultados = [];
        foreach (ResultadoQuery::create()->filterByEventoId($eventId)->find() as $resultado) {
            $resultados[(string)$resultado->getResultadoId()] = [
                'inputs' => self::provaIds($resultado->getInputs()),
                'colunas' => $resultado->getColunas(),
            ];
        }
        return new self($eventId, $provas, $resultados);
    }

    /** A copy where prova $provaId has these params (a new prova, or new params). */
    public function withProva(string $provaId, mixed $params): self
    {
        return new self($this->eventId, [$provaId => $params] + $this->provas, $this->resultados);
    }

    /**
     * A copy where resultado $resultadoId has these Inputs and "colunas".
     *
     * @param mixed $inputs a list of prova ids, as Resultado::getInputs() gives it
     */
    public function withResultado(string $resultadoId, mixed $inputs, mixed $colunas): self
    {
        return new self(
            $this->eventId,
            $this->provas,
            [$resultadoId => ['inputs' => self::provaIds($inputs), 'colunas' => $colunas]] + $this->resultados,
        );
    }

    /** Whether the event has a resultado with this id. */
    public function hasResultado(string $resultadoId): bool
    {
        return isset($this->resultados[$resultadoId]);
    }

    /** The resultado's config, read; null when the event has no such resultado. */
    public function resultadoConfig(string $resultadoId): ?ResultsConfig
    {
        return isset($this->resultados[$resultadoId])
            ? ResultsConfig::fromColunas($this->resultados[$resultadoId]['colunas'])
            : null;
    }

    /** @return list<string> the resultados whose Inputs include the prova */
    public function resultadosOf(string $provaId): array
    {
        $ids = [];
        foreach ($this->resultados as $id => $resultado) {
            if (in_array($provaId, $resultado['inputs'], true)) {
                $ids[] = (string)$id;
            }
        }
        return $ids;
    }

    /** @return list<string> the resultados the prova's "posicoes" take a position from */
    public function referencedBy(string $provaId): array
    {
        $ids = [];
        foreach (PositionSpec::allOf($this->provas[$provaId] ?? null) as $spec) {
            if ($spec->isExternal()) {
                $ids[] = (string)$spec->resultadoId();
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return list<string> the provas whose "posicoes" take a position from the resultado */
    public function provasReading(string $resultadoId): array
    {
        $ids = [];
        foreach (array_keys($this->provas) as $provaId) {
            if (in_array($resultadoId, $this->referencedBy((string)$provaId), true)) {
                $ids[] = (string)$provaId;
            }
        }
        return $ids;
    }

    /**
     * The provas whose points depend directly on the points of $provaId:
     * those that take a position from a resultado that includes it.
     *
     * @return list<string>
     */
    public function dependentsOf(string $provaId): array
    {
        $ids = [];
        foreach ($this->resultadosOf($provaId) as $resultadoId) {
            array_push($ids, ...$this->provasReading($resultadoId));
        }
        return array_values(array_unique($ids));
    }

    /** Whether any prova of the event has a "posicoes" entry that takes from a resultado. */
    public function hasLinks(): bool
    {
        foreach (array_keys($this->provas) as $provaId) {
            if ($this->referencedBy((string)$provaId) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * The provas to work out again, in an order where each comes after
     * every prova it depends on: the $changed provas' dependents, their
     * dependents, and so on. $changed themselves are not listed (they were
     * just worked out); $alsoRecalculate are listed with their dependents
     * (the provas reading a resultado whose config changed). Each prova is
     * listed once. Should a cycle have been saved anyway, its provas are
     * still listed once each, at the end.
     *
     * @param list<string> $changed
     * @param list<string> $alsoRecalculate
     * @return list<string>
     */
    public function recalculationOrder(array $changed, array $alsoRecalculate = []): array
    {
        // 1. Everything reachable.
        $affected = array_fill_keys($alsoRecalculate, true);
        $queue = array_merge($changed, $alsoRecalculate);
        while ($queue !== []) {
            $provaId = array_shift($queue);
            foreach ($this->dependentsOf($provaId) as $dependent) {
                if (!isset($affected[$dependent])) {
                    $affected[$dependent] = true;
                    $queue[] = $dependent;
                }
            }
        }
        foreach ($changed as $provaId) {
            if (!in_array($provaId, $alsoRecalculate, true)) {
                unset($affected[$provaId]);
            }
        }

        // 2. In order: a prova waits for the affected provas it depends on.
        $waitingFor = [];
        foreach (array_keys($affected) as $provaId) {
            $waitingFor[$provaId] = 0;
        }
        foreach (array_keys($affected) as $provaId) {
            $provaId = (string)$provaId;
            foreach ($this->dependentsOf($provaId) as $dependent) {
                if (isset($waitingFor[$dependent]) && $dependent !== $provaId) {
                    $waitingFor[$dependent]++;
                }
            }
        }
        $order = [];
        $ready = array_keys(array_filter($waitingFor, fn(int $count) => $count === 0));
        while ($ready !== []) {
            $provaId = (string)array_shift($ready);
            $order[] = $provaId;
            unset($waitingFor[$provaId]);
            foreach ($this->dependentsOf($provaId) as $dependent) {
                if (isset($waitingFor[$dependent]) && --$waitingFor[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
        }
        // What is left is in a cycle: once each, so nothing runs forever.
        return array_merge($order, array_map('strval', array_keys($waitingFor)));
    }

    /**
     * A chain from the prova back to itself, e.g. ["CMP", "26SU_GER", "CMP"];
     * null when there is none.
     *
     * @return list<string>|null
     */
    public function cycleFromProva(string $provaId): ?array
    {
        return $this->cycleFrom('p:' . $provaId);
    }

    /**
     * A chain from the resultado back to itself, e.g.
     * ["26SU_GER", "CMP", "26SU_GER"]; null when there is none.
     *
     * @return list<string>|null
     */
    public function cycleFromResultado(string $resultadoId): ?array
    {
        return $this->cycleFrom('r:' . $resultadoId);
    }

    /**
     * Depth-first search for a way back to $start. Nodes are "p:ID" for a
     * prova and "r:ID" for a resultado; the chain is given without the prefix.
     *
     * @return list<string>|null
     */
    private function cycleFrom(string $start): ?array
    {
        $visited = [];
        $path = [$start];
        $found = $this->search($start, $start, $visited, $path);
        return $found === null ? null : array_map(fn(string $node) => substr($node, 2), $found);
    }

    /**
     * @param array<string, true> $visited
     * @param list<string> $path
     * @return list<string>|null
     */
    private function search(string $node, string $start, array &$visited, array $path): ?array
    {
        foreach ($this->next($node) as $next) {
            if ($next === $start) {
                return [...$path, $next];
            }
            if (isset($visited[$next])) {
                continue;
            }
            $visited[$next] = true;
            $found = $this->search($next, $start, $visited, [...$path, $next]);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function next(string $node): array
    {
        $id = substr($node, 2);
        if ($node[0] === 'p') {
            return array_map(fn(string $resultado) => 'r:' . $resultado, $this->referencedBy($id));
        }
        return array_map(fn(string $prova) => 'p:' . $prova, $this->resultados[$id]['inputs'] ?? []);
    }

    /**
     * Prova ids from a resultado's Inputs, as stored (a list, maybe with
     * spaces or empty items).
     *
     * @return list<string>
     */
    private static function provaIds(mixed $inputs): array
    {
        $ids = [];
        foreach ((array)$inputs as $id) {
            if (is_scalar($id) && trim((string)$id) !== '') {
                $ids[] = trim((string)$id);
            }
        }
        return $ids;
    }
}
