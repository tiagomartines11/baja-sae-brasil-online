<?php
declare(strict_types=1);

namespace Baja\Programa;

use Baja\Rbac\Audit\AuditLog;
use Baja\Rbac\Audit\DatabaseAuditLog;
use Baja\Rbac\Clock;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\SystemClock;
use PDO;
use Propel\Runtime\Connection\ConnectionInterface;
use Propel\Runtime\Propel;
use Throwable;

/**
 * Creating and editing the competition programs (BAJAAPP-90), for
 * juiz/admin_programas.php. The page checks programa.manage on the
 * platform; this class keeps the rules of the data:
 *
 *   - the code (programa_id) is upper case letters, digits and '_', at
 *     most 16 characters, and unique;
 *   - THE CODE NEVER CHANGES after creation. Grants (user_role) and the
 *     audit trail (role_audit) store it in a plain scope_id column with no
 *     foreign key, so a renamed program would leave them pointing at
 *     nothing. update() has no way to change it;
 *   - there is no delete: a program is made inactive (ativo = 0) instead;
 *   - a program cannot sit under itself or under one of its own
 *     descendants (that would be a loop, and ScopeTree treats every node
 *     of a loop as unknown: every check in it would say no);
 *   - every create and every update writes one role_audit row, in the same
 *     transaction (programa.create, programa.update), with the program
 *     before and after.
 *
 * Nothing about programs is cached beyond one request (ProgramaResolver,
 * ScopeTree and Authorization keep what they read for the life of the
 * request only), so a change shows on the next request.
 */
final class ProgramaAdmin
{
    /** What a program code may look like. */
    public const CODE_PATTERN = '/^[A-Z0-9_]{1,16}$/';

    public const NAME_MAX = 100;

    public function __construct(
        private ConnectionInterface $con,
        private AuditLog $audit,
        private Clock $clock,
    ) {
    }

    public static function forApp(): self
    {
        $con = Propel::getWriteConnection('resultados');
        return new self($con, new DatabaseAuditLog($con), new SystemClock());
    }

    /**
     * Every program, in the order they are listed everywhere (ordem, then
     * code), with how many events each has directly.
     *
     * @return array<int, array{programa_id: string, nome: string, parent_id: ?string, ordem: int, ativo: bool, eventos: int}>
     */
    public function all(): array
    {
        $rows = $this->con->query(
            'SELECT p.programa_id, p.nome, p.parent_id, p.ordem, p.ativo,
                    (SELECT COUNT(*) FROM evento e WHERE e.programa_id = p.programa_id) AS eventos
             FROM programa p ORDER BY p.ordem, p.programa_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'fromRow'], $rows);
    }

    /** @return array{programa_id: string, nome: string, parent_id: ?string, ordem: int, ativo: bool, eventos: int}|null */
    public function find(string $code): ?array
    {
        foreach ($this->all() as $programa) {
            // Exact spelling: MySQL would find BAJA when asked for "baja".
            if ($programa['programa_id'] === $code) {
                return $programa;
            }
        }
        return null;
    }

    /**
     * How many events a change of parent moves: those of the program and
     * of every program under it, which move with it.
     */
    public function eventsMovedWith(string $code): int
    {
        $codes = [$code, ...$this->tree()->descendantsOf($code)];
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $statement = $this->con->prepare("SELECT COUNT(*) FROM evento WHERE programa_id IN ($placeholders)");
        $statement->execute($codes);
        return (int) $statement->fetchAll(PDO::FETCH_COLUMN)[0];
    }

    /**
     * @throws ProgramaRecusado when a rule says no; nothing is written then
     */
    public function create(int $actorId, string $code, string $nome, ?string $parentId, int $ordem): void
    {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new ProgramaRecusado(ProgramaRecusado::INVALID_CODE, 'O código deve ter de 1 a 16 caracteres: letras maiúsculas, dígitos e "_".');
        }
        $nome = $this->validName($nome);
        $parentId = $this->emptyToNull($parentId);

        $this->inTransaction(function () use ($actorId, $code, $nome, $parentId, $ordem): void {
            $tree = $this->lockedTree();
            if ($tree->has($code) || $this->existsInAnyCase($code)) {
                throw new ProgramaRecusado(ProgramaRecusado::CODE_TAKEN, "Já existe um programa $code.");
            }
            if ($parentId !== null && !$tree->has($parentId)) {
                throw new ProgramaRecusado(ProgramaRecusado::UNKNOWN_PARENT, "Não existe o programa $parentId para ficar acima deste.");
            }
            $this->con->prepare('INSERT INTO programa (programa_id, parent_id, nome, ordem, ativo) VALUES (?, ?, ?, ?, 1)')
                ->execute([$code, $parentId, $nome, $ordem]);
            $after = ['nome' => $nome, 'parent_id' => $parentId, 'ordem' => $ordem, 'ativo' => true];
            $this->audit->record(AuditLog::PROGRAMA_CREATE, $actorId, $this->clock->now(), null, null, null, ScopeNode::programa($code), ['after' => $after]);
        });
    }

    /**
     * Changes a program's name, parent, order and whether it is active.
     * Its code cannot change (see the class comment): there is no
     * parameter for it.
     *
     * Writes nothing, not even an audit row, when nothing changed.
     *
     * @return string[] the fields that changed (nome, parent_id, ordem, ativo)
     * @throws ProgramaRecusado when a rule says no; nothing is written then
     */
    public function update(int $actorId, string $code, string $nome, ?string $parentId, int $ordem, bool $ativo): array
    {
        $nome = $this->validName($nome);
        $parentId = $this->emptyToNull($parentId);

        return $this->inTransaction(function () use ($actorId, $code, $nome, $parentId, $ordem, $ativo): array {
            $tree = $this->lockedTree();
            $before = $this->find($code);
            if ($before === null) {
                throw new ProgramaRecusado(ProgramaRecusado::UNKNOWN_PROGRAM, "Não existe o programa $code.");
            }
            if ($parentId !== null && !$tree->has($parentId)) {
                throw new ProgramaRecusado(ProgramaRecusado::UNKNOWN_PARENT, "Não existe o programa $parentId para ficar acima deste.");
            }
            if ($parentId !== null && ($parentId === $code || in_array($parentId, $tree->descendantsOf($code), true))) {
                throw new ProgramaRecusado(ProgramaRecusado::CYCLE, $parentId === $code
                    ? 'Um programa não pode ficar abaixo de si mesmo.'
                    : "$parentId está abaixo de $code: $code não pode ficar abaixo dele.");
            }

            $old = ['nome' => $before['nome'], 'parent_id' => $before['parent_id'], 'ordem' => $before['ordem'], 'ativo' => $before['ativo']];
            $new = ['nome' => $nome, 'parent_id' => $parentId, 'ordem' => $ordem, 'ativo' => $ativo];
            $changed = array_keys(array_filter($new, fn($value, $field) => $old[$field] !== $value, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return [];
            }

            $this->con->prepare('UPDATE programa SET nome = ?, parent_id = ?, ordem = ?, ativo = ? WHERE programa_id = ?')
                ->execute([$nome, $parentId, $ordem, (int) $ativo, $code]);
            $this->audit->record(AuditLog::PROGRAMA_UPDATE, $actorId, $this->clock->now(), null, null, null, ScopeNode::programa($code), [
                'changed' => $changed,
                'before' => array_intersect_key($old, array_flip($changed)),
                'after' => array_intersect_key($new, array_flip($changed)),
            ]);
            return $changed;
        });
    }

    /**
     * The tree as it is now, with every program row locked until the end of
     * the transaction: two edits at once cannot each pass the loop check
     * and together make a loop.
     */
    private function lockedTree(): ProgramaTree
    {
        $parentById = [];
        foreach ($this->con->query('SELECT programa_id, parent_id FROM programa FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parentById[(string) $row['programa_id']] = $row['parent_id'] === null ? null : (string) $row['parent_id'];
        }
        return new ProgramaTree($parentById);
    }

    private function tree(): ProgramaTree
    {
        return (new ProgramaResolver($this->con))->tree();
    }

    /** The column compares without case: "baja" would clash with BAJA. Codes are upper case anyway; this is the belt. */
    private function existsInAnyCase(string $code): bool
    {
        $statement = $this->con->prepare('SELECT programa_id FROM programa WHERE programa_id = ?');
        $statement->execute([$code]);
        return $statement->fetchAll(PDO::FETCH_COLUMN) !== [];
    }

    private function validName(string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > self::NAME_MAX) {
            throw new ProgramaRecusado(ProgramaRecusado::INVALID_NAME, 'O nome é obrigatório, com no máximo ' . self::NAME_MAX . ' caracteres.');
        }
        return $nome;
    }

    private function emptyToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    private static function fromRow(array $row): array
    {
        return [
            'programa_id' => (string) $row['programa_id'],
            'nome' => (string) $row['nome'],
            'parent_id' => $row['parent_id'] === null ? null : (string) $row['parent_id'],
            'ordem' => (int) $row['ordem'],
            'ativo' => (bool) $row['ativo'],
            'eventos' => (int) $row['eventos'],
        ];
    }

    private function inTransaction(callable $work): mixed
    {
        $this->con->beginTransaction();
        try {
            $result = $work();
            $this->con->commit();
            return $result;
        } catch (Throwable $e) {
            $this->con->rollBack();
            throw $e;
        }
    }
}
