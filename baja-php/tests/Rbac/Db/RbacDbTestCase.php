<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Grant\GrantRepository;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\RbacServices;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use Baja\Tests\Support\FixedClock;
use Baja\Tests\Support\WritableTestDatabase;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * Base of the RBAC database tests (BAJAAPP-12).
 *
 * THE MADE-UP WORLD
 *
 * Before each test this is created, and after it everything is removed
 * again:
 *
 *     platform
 *       BAJA  (real program)
 *         96BR  10 to 12 March 2099           provas ZZA, ZZB   queues 1, 2
 *         96SE  no dates yet (data_fim empty)  prova ZZA
 *       AERO  (real program)
 *         96AR                                 prova ZZA
 *         AERO_FORUM  (real program)
 *           96FA                               prova ZZA
 *
 * Events in 2099 so that their default expiry is always in the future of
 * the test's clock, which is stopped at 1 March 2099, 10:00.
 *
 * Users are created as needed with user(), named zzrbac_<name>. The real
 * roles (the migration's eight) are used as they are; tests that need a
 * role of their own create one named zz_<something>.
 *
 * A grant that a test needs as a starting point (somebody has to be the
 * first event manager) is written straight to the table with
 * giveDirectly(). Every grant whose WAY of being made is under test goes
 * through RoleGranting.
 *
 * HOW TO RUN
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group rbac
 *
 * Skipped without BAJA_TEST_DB=1 (see tests/Support/WritableTestDatabase.php).
 */
abstract class RbacDbTestCase extends TestCase
{
    protected const NOW = '2099-03-01 10:00:00';

    private const USER_PREFIX = 'zzrbac_';
    private const EVENTS = ['96BR', '96SE', '96AR', '96FA'];

    private static ?string $skipped = null;

    protected ConnectionInterface $con;
    protected FixedClock $clock;
    protected RbacServices $rbac;

    /** @var array<int, array{0: string, 1: array}> warnings RBAC logged in this test */
    protected array $warnings = [];

    /** @var array<string, int> name => user_id */
    private array $users = [];

    public static function setUpBeforeClass(): void
    {
        self::$skipped = WritableTestDatabase::whyUnavailable();
    }

    protected function setUp(): void
    {
        if (self::$skipped !== null) {
            $this->markTestSkipped(self::$skipped);
        }
        $this->con = WritableTestDatabase::propel();
        $this->clock = new FixedClock(self::NOW);
        $this->removeWorld();
        $this->createWorld();
        $this->newRequest();
    }

    protected function tearDown(): void
    {
        if (self::$skipped === null) {
            $this->removeWorld();
        }
    }

    /**
     * Starts what stands for a new web request: new RBAC objects, so
     * nothing read before is remembered.
     */
    protected function newRequest(): void
    {
        $this->rbac = RbacServices::create($this->con, $this->clock, function (string $message, array $context): void {
            $this->warnings[] = [$message, $context];
        });
    }

    /** The id of the made-up user with this name, created on first use. */
    protected function user(string $name): int
    {
        if (!isset($this->users[$name])) {
            $this->con->prepare("INSERT INTO user (username, permissions) VALUES (?, '| index |')")->execute([self::USER_PREFIX . $name]);
            $this->users[$name] = (int) $this->con->lastInsertId();
        }
        return $this->users[$name];
    }

    /** A grant written straight to the table, as a starting point. */
    protected function giveDirectly(int $userId, string $roleCode, ScopeNode $node, ?string $validUntil = null, ?string $revokedAt = null): int
    {
        $this->con->prepare(
            "INSERT INTO user_role (user_id, role_id, scope_type, scope_id, granted_by, granted_at, valid_until, revoked_at, revoked_by)
             SELECT ?, role_id, ?, ?, NULL, '2099-01-01 00:00:00', ?, ?, NULL FROM role WHERE code = ?"
        )->execute([$userId, $node->type->value, $node->id, $validUntil, $revokedAt, $roleCode]);
        return (int) $this->con->lastInsertId();
    }

    /**
     * A role of the test's own, code zz_<...>.
     *
     * @param ScopeType[]  $allowedAt
     * @param Permission[] $permissions
     */
    protected function createRole(string $code, array $allowedAt, array $permissions, bool $isSystem = false): void
    {
        $this->con->prepare('INSERT INTO role (code, name, is_system, allowed_scope_types) VALUES (?, ?, ?, ?)')
            ->execute([$code, 'Test role ' . $code, (int) $isSystem, implode(',', array_map(fn(ScopeType $t) => $t->value, $allowedAt))]);
        $roleId = (int) $this->con->lastInsertId();
        $insert = $this->con->prepare('INSERT INTO role_permission (role_id, permission) VALUES (?, ?)');
        foreach ($permissions as $permission) {
            $insert->execute([$roleId, $permission->value]);
        }
    }

    protected function can(string $user, Permission $permission, ScopeNode $node): bool
    {
        return $this->rbac->authorizer->can($this->user($user), $permission, $node);
    }

    /** @return array<int, array<string, mixed>> the audit rows about the made-up users, oldest first */
    protected function auditRows(): array
    {
        if ($this->users === []) {
            return [];
        }
        $ids = implode(',', array_map('intval', $this->users));
        return $this->con->query(
            "SELECT * FROM role_audit WHERE actor_user_id IN ($ids) OR target_user_id IN ($ids) ORDER BY audit_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> the grants of one made-up user */
    protected function grantRows(string $user): array
    {
        $statement = $this->con->prepare('SELECT * FROM user_role WHERE user_id = ? ORDER BY grant_id');
        $statement->execute([$this->user($user)]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    protected static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment);
    }

    protected static function stored(DateTimeImmutable $moment): string
    {
        return GrantRepository::format($moment);
    }

    private function createWorld(): void
    {
        $event = $this->con->prepare(
            "INSERT INTO evento (evento_id, titulo, nome, ano, ativo, programa_id, data, data_inicio, data_fim)
             VALUES (?, 'Fixture BAJAAPP-12', 'Evento de teste (RBAC)', 2099, 0, ?, ?, ?, ?)"
        );
        $event->execute(['96BR', 'BAJA', '10 a 12 de março de 2099', '2099-03-10', '2099-03-12']);
        $event->execute(['96SE', 'BAJA', 'a definir', null, null]);
        $event->execute(['96AR', 'AERO', '10 a 12 de março de 2099', '2099-03-10', '2099-03-12']);
        $event->execute(['96FA', 'AERO_FORUM', '10 a 12 de março de 2099', '2099-03-10', '2099-03-12']);

        $prova = $this->con->prepare("INSERT INTO prova (evento_id, prova_id, nome) VALUES (?, ?, 'Prova de teste')");
        foreach ([['96BR', 'ZZA'], ['96BR', 'ZZB'], ['96SE', 'ZZA'], ['96AR', 'ZZA'], ['96FA', 'ZZA']] as $p) {
            $prova->execute($p);
        }

        $fila = $this->con->prepare("INSERT INTO fila (evento_id, fila_id, nome) VALUES ('96BR', ?, 'Fila de teste')");
        $fila->execute([1]);
        $fila->execute([2]);
    }

    private function removeWorld(): void
    {
        $users = $this->con->query("SELECT user_id FROM user WHERE username LIKE 'zzrbac\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $testRoles = $this->con->query("SELECT role_id FROM role WHERE code LIKE 'zz\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        // role_audit rows stay, the tests' own too: MySQL refuses to delete
        // them (AuditIsAppendOnlyInMysqlTest). They name users and roles
        // that are gone, and AUTO_INCREMENT never hands those ids out
        // again, so no later test can mistake them for its own.
        if ($users !== []) {
            $ids = implode(',', array_map('intval', $users));
            // Test rows only: the application itself never deletes grants.
            $this->con->exec("DELETE FROM user_role WHERE user_id IN ($ids)");
            $this->con->exec("DELETE FROM user WHERE user_id IN ($ids)");
        }
        if ($testRoles !== []) {
            $ids = implode(',', array_map('intval', $testRoles));
            $this->con->exec("DELETE FROM user_role WHERE role_id IN ($ids)");
            $this->con->exec("DELETE FROM role WHERE role_id IN ($ids)");
        }
        $events = "'" . implode("','", self::EVENTS) . "'";
        // Provas and queues go with their event (ON DELETE CASCADE).
        $this->con->exec("DELETE FROM evento WHERE evento_id IN ($events)");
        $this->users = [];
    }
}
