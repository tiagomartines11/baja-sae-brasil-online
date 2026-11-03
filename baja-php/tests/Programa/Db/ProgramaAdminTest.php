<?php
declare(strict_types=1);

namespace Baja\Tests\Programa\Db;

use Baja\Programa\ProgramaAdmin;
use Baja\Programa\ProgramaRecusado;
use Baja\Rbac\Audit\AuditLog;
use Baja\Rbac\Audit\DatabaseAuditLog;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use Baja\Tests\Rbac\Db\RbacDbTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The program admin page's rules (BAJAAPP-90): Baja\Programa\ProgramaAdmin,
 * and who may use it.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Every event belongs to a program (Baja SAE, AeroDesign...), and programs
 * can sit under other programs (Fórum AeroDesign is under AeroDesign). That
 * tree matters for access: a role given on a program reaches every program
 * under it and all their events. So changing a program's parent changes
 * who may do what there, which is why:
 *
 *   - only someone with programa.manage ON THE PLATFORM may change
 *     programs (today: platform admins);
 *   - a program can never end up under itself (a loop);
 *   - every change leaves a row in role_audit, the record of access changes;
 *   - a program's code can never change, because grants store it as plain
 *     text with nothing to update it along.
 *
 * THE MADE-UP PROGRAMS
 *
 * On top of RbacDbTestCase's world, each test may create programs whose
 * codes start with ZZT_ (through ProgramaAdmin, like the page), and one
 * event, 96ZT. All of them are removed after each test.
 *
 * The audit rows' details are compared with assertEquals, not assertSame:
 * MySQL's JSON column keeps an object's keys in an order of its own.
 *
 * HOW TO RUN
 *
 *     docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group programa
 */
#[Group('programa')]
#[Group('rbac')]
final class ProgramaAdminTest extends RbacDbTestCase
{
    private const EVENT = '96ZT';

    private ProgramaAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->removeTestPrograms();
        $this->admin = new ProgramaAdmin($this->con, new DatabaseAuditLog($this->con), $this->clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->removeTestPrograms();
        }
        parent::tearDown();
    }

    private function removeTestPrograms(): void
    {
        // Test rows only: the application never deletes programs.
        $this->con->exec("DELETE FROM evento WHERE evento_id = '" . self::EVENT . "'");
        $this->con->exec("UPDATE programa SET parent_id = NULL WHERE programa_id LIKE 'ZZT\\_%'");
        $this->con->exec("DELETE FROM programa WHERE programa_id LIKE 'ZZT\\_%'");
    }

    private function eventIn(string $programa): void
    {
        $this->con->prepare(
            "INSERT INTO evento (evento_id, titulo, nome, ano, ativo, programa_id, data, data_inicio, data_fim)
             VALUES (?, 'Fixture BAJAAPP-90', 'Evento de teste (programas)', 2099, 0, ?, '10 a 12 de março de 2099', '2099-03-10', '2099-03-12')"
        )->execute([self::EVENT, $programa]);
    }

    /** @return array<string, mixed> the stored row */
    private function row(string $code): array
    {
        $statement = $this->con->prepare('SELECT programa_id, parent_id, nome, ordem, ativo FROM programa WHERE programa_id = ?');
        $statement->execute([$code]);
        return $statement->fetchAll(PDO::FETCH_ASSOC)[0] ?? [];
    }

    /** Asserts the call is refused for this reason, and wrote nothing (no program change, no audit row). */
    private function assertRefused(string $reason, callable $attempt): void
    {
        $programsBefore = $this->con->query('SELECT programa_id, parent_id, nome, ordem, ativo FROM programa ORDER BY programa_id')->fetchAll(PDO::FETCH_ASSOC);
        $auditBefore = count($this->auditRows());
        try {
            $attempt();
            $this->fail("Expected a refusal ($reason), but it was allowed.");
        } catch (ProgramaRecusado $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
        $this->assertSame($programsBefore, $this->con->query('SELECT programa_id, parent_id, nome, ordem, ativo FROM programa ORDER BY programa_id')->fetchAll(PDO::FETCH_ASSOC), 'A refusal changed the programs.');
        $this->assertCount($auditBefore, $this->auditRows(), 'A refusal wrote an audit row.');
    }

    // --- Who may use the page -----------------------------------------------------

    /**
     * The page asks programa.manage on the platform. Only the platform
     * admin has it: program_manager carries every OTHER permission, but not
     * this one, and even a role that carried it could only ever reach the
     * platform from the platform itself.
     */
    public function testOnlyProgramaManageOnThePlatformOpensThePage(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        // A made-up role carrying programa.manage, given on a program: it
        // never reaches the platform, which is above.
        $this->createRole('zz_programas', [ScopeType::PROGRAMA], [Permission::PROGRAMA_MANAGE]);
        $this->giveDirectly($this->user('sneaky'), 'zz_programas', ScopeNode::programa('BAJA'));

        $this->assertTrue($this->can('admin', Permission::PROGRAMA_MANAGE, ScopeNode::platform()));
        foreach (['baja', 'evt', 'sneaky', 'nobody'] as $user) {
            $this->assertFalse($this->can($user, Permission::PROGRAMA_MANAGE, ScopeNode::platform()), $user);
        }
        // And it is never asked about a program: the question itself is refused.
        $this->assertFalse($this->can('admin', Permission::PROGRAMA_MANAGE, ScopeNode::programa('BAJA')));
    }

    /** The migration gave programa.manage to platform_admin, and to no other role. */
    public function testOnlyThePlatformAdminRoleCarriesIt(): void
    {
        $holders = $this->con->query(
            "SELECT r.code FROM role_permission rp JOIN role r ON r.role_id = rp.role_id WHERE rp.permission = 'programa.manage' AND r.code NOT LIKE 'zz\\_%'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['platform_admin'], $holders);
    }

    // --- Create, edit, deactivate -------------------------------------------------

    /** A new program: stored as given, active, and one audit row naming who and what. */
    public function testCreatingAProgram(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica de teste', 'BAJA', 70);

        $this->assertSame(['programa_id' => 'ZZT_ROBO', 'parent_id' => 'BAJA', 'nome' => 'Robótica de teste', 'ordem' => 70, 'ativo' => 1], $this->rowAsTyped('ZZT_ROBO'));
        $audit = $this->auditRows();
        $this->assertCount(1, $audit);
        $this->assertSame(AuditLog::PROGRAMA_CREATE, $audit[0]['action']);
        $this->assertSame($this->user('admin'), (int) $audit[0]['actor_user_id']);
        $this->assertSame('programa', $audit[0]['scope_type']);
        $this->assertSame('ZZT_ROBO', $audit[0]['scope_id']);
        $this->assertSame(self::NOW, $audit[0]['occurred_at']);
        $this->assertEquals(
            ['after' => ['nome' => 'Robótica de teste', 'parent_id' => 'BAJA', 'ordem' => 70, 'ativo' => true]],
            json_decode($audit[0]['details'], true)
        );
    }

    /** A program at the top: no parent. An empty parent from the form means the same. */
    public function testAProgramWithNoParentSitsAtTheTop(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_TOPO', 'No topo', '', 0);

        $this->assertNull($this->row('ZZT_TOPO')['parent_id']);
        $this->assertSame(['platform:'], array_map(fn(ScopeNode $n) => $n->key(), $this->rbac->tree->ancestorsOf(ScopeNode::programa('ZZT_TOPO'))));
    }

    /**
     * Editing the name and the order, then making it inactive: each save
     * one audit row, holding only what changed, before and after.
     */
    public function testEditingAndDeactivatingAProgram(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica', null, 70);

        $changed = $this->admin->update($this->user('admin'), 'ZZT_ROBO', 'Robótica SAE', null, 75, true);
        $this->assertSame(['nome', 'ordem'], $changed);
        $this->admin->update($this->user('admin'), 'ZZT_ROBO', 'Robótica SAE', null, 75, false);

        $this->assertSame(['programa_id' => 'ZZT_ROBO', 'parent_id' => null, 'nome' => 'Robótica SAE', 'ordem' => 75, 'ativo' => 0], $this->rowAsTyped('ZZT_ROBO'));
        $updates = array_values(array_filter($this->auditRows(), fn(array $r) => $r['action'] === AuditLog::PROGRAMA_UPDATE));
        $this->assertCount(2, $updates);
        $this->assertEquals(
            ['changed' => ['nome', 'ordem'], 'before' => ['nome' => 'Robótica', 'ordem' => 70], 'after' => ['nome' => 'Robótica SAE', 'ordem' => 75]],
            json_decode($updates[0]['details'], true)
        );
        $this->assertEquals(
            ['changed' => ['ativo'], 'before' => ['ativo' => true], 'after' => ['ativo' => false]],
            json_decode($updates[1]['details'], true)
        );
    }

    /** A save that changes nothing writes nothing, not even an audit row. */
    public function testASaveThatChangesNothingWritesNothing(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica', null, 70);
        $auditBefore = count($this->auditRows());

        $this->assertSame([], $this->admin->update($this->user('admin'), 'ZZT_ROBO', 'Robótica', null, 70, true));
        $this->assertCount($auditBefore, $this->auditRows());
    }

    /**
     * An inactive program keeps working for access: a grant on it still
     * reaches its events. (Today ativo only decides what evento.php offers
     * for new events; the page says so.)
     */
    public function testAnInactiveProgramStillCountsForAccess(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica', null, 70);
        $this->eventIn('ZZT_ROBO');
        $this->giveDirectly($this->user('chefe'), 'program_manager', ScopeNode::programa('ZZT_ROBO'));

        $this->admin->update($this->user('admin'), 'ZZT_ROBO', 'Robótica', null, 70, false);
        $this->newRequest();

        $this->assertTrue($this->can('chefe', Permission::EVENTO_MANAGE, ScopeNode::evento(self::EVENT)));
    }

    // --- The code ------------------------------------------------------------------

    /** Upper case letters, digits and "_", 1 to 16 of them. */
    public function testABadCodeIsRefused(): void
    {
        $seventeen = 'ZZT_DEZESSETE_XYZ';
        $this->assertSame(17, strlen($seventeen));
        foreach (['', 'zzt_minusculo', 'ZZT-HIFEN', 'ZZT ESPACO', $seventeen, 'ZZTÇ'] as $code) {
            $this->assertRefused(ProgramaRecusado::INVALID_CODE, fn() => $this->admin->create($this->user('admin'), $code, 'Nome', null, 0));
        }
        // Sixteen is fine.
        $sixteen = 'ZZT_DEZESSEIS_XY';
        $this->assertSame(16, strlen($sixteen));
        $this->admin->create($this->user('admin'), $sixteen, 'Nome', null, 0);
        $this->assertNotSame([], $this->row($sixteen));
    }

    /** A code already taken, by a test program or a real one. */
    public function testATakenCodeIsRefused(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica', null, 70);

        $this->assertRefused(ProgramaRecusado::CODE_TAKEN, fn() => $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Outro', null, 0));
        $this->assertRefused(ProgramaRecusado::CODE_TAKEN, fn() => $this->admin->create($this->user('admin'), 'BAJA', 'Outro', null, 0));
    }

    /** A name is needed, and fits the column. A parent must exist. */
    public function testANameIsRequiredAndAParentMustExist(): void
    {
        $this->assertRefused(ProgramaRecusado::INVALID_NAME, fn() => $this->admin->create($this->user('admin'), 'ZZT_ROBO', '   ', null, 0));
        $this->assertRefused(ProgramaRecusado::INVALID_NAME, fn() => $this->admin->create($this->user('admin'), 'ZZT_ROBO', str_repeat('x', 101), null, 0));
        $this->assertRefused(ProgramaRecusado::UNKNOWN_PARENT, fn() => $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica', 'ZZT_NAO_EXISTE', 0));
    }

    // --- The parent ----------------------------------------------------------------

    /**
     * ZZT_A > ZZT_B > ZZT_C. A cannot go under itself, under B (its
     * child) or under C (its grandchild): any of those is a loop.
     */
    public function testAParentLoopIsRefused(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_A', 'A', null, 0);
        $this->admin->create($this->user('admin'), 'ZZT_B', 'B', 'ZZT_A', 0);
        $this->admin->create($this->user('admin'), 'ZZT_C', 'C', 'ZZT_B', 0);

        foreach (['ZZT_A', 'ZZT_B', 'ZZT_C'] as $under) {
            $this->assertRefused(ProgramaRecusado::CYCLE, fn() => $this->admin->update($this->user('admin'), 'ZZT_A', 'A', $under, 0, true));
        }
        // Moving C up under A is not a loop.
        $this->assertSame(['parent_id'], $this->admin->update($this->user('admin'), 'ZZT_C', 'C', 'ZZT_A', 0, true));
    }

    /**
     * The point of the tree: ZZT_ROBO, with event 96ZT, is moved from
     * under BAJA to under AERO. Then:
     *
     *   - a program manager of AERO manages 96ZT (they did not before);
     *   - a program manager of BAJA no longer does (they did before).
     *
     * Each "request" (newRequest) reads the tree again, as every web
     * request does; nothing remembers the old parent.
     */
    public function testAParentChangeMovesWhoseGrantsReachTheProgram(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica', 'BAJA', 70);
        $this->eventIn('ZZT_ROBO');
        $this->giveDirectly($this->user('aero'), 'program_manager', ScopeNode::programa('AERO'));
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));
        $event = ScopeNode::evento(self::EVENT);
        $this->newRequest();
        $this->assertFalse($this->can('aero', Permission::EVENTO_MANAGE, $event), 'before: AERO does not reach it');
        $this->assertTrue($this->can('baja', Permission::EVENTO_MANAGE, $event), 'before: BAJA does');

        $this->admin->update($this->user('admin'), 'ZZT_ROBO', 'Robótica', 'AERO', 70, true);
        $this->newRequest();

        $this->assertTrue($this->can('aero', Permission::EVENTO_MANAGE, $event), 'after: AERO reaches it');
        $this->assertFalse($this->can('baja', Permission::EVENTO_MANAGE, $event), 'after: BAJA no longer does');

        $last = array_values(array_filter($this->auditRows(), fn(array $r) => $r['action'] === AuditLog::PROGRAMA_UPDATE));
        $this->assertEquals(
            ['changed' => ['parent_id'], 'before' => ['parent_id' => 'BAJA'], 'after' => ['parent_id' => 'AERO']],
            json_decode($last[0]['details'], true)
        );
    }

    /** How many events a move takes along: the program's, and those of the programs under it. */
    public function testEventsMovedWithCountsTheProgramsBelowToo(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_A', 'A', null, 0);
        $this->admin->create($this->user('admin'), 'ZZT_B', 'B', 'ZZT_A', 0);
        $this->eventIn('ZZT_B');

        $this->assertSame(1, $this->admin->eventsMovedWith('ZZT_A'));
        $this->assertSame(1, $this->admin->eventsMovedWith('ZZT_B'));
        $this->assertSame(0, $this->admin->eventsMovedWith('ZZT_NAO_EXISTE'));
    }

    /** The list: every program, with its parent and how many events it has. */
    public function testTheListCountsEachProgramsEvents(): void
    {
        $this->admin->create($this->user('admin'), 'ZZT_ROBO', 'Robótica', 'BAJA', 70);
        $this->eventIn('ZZT_ROBO');

        $robo = $this->admin->find('ZZT_ROBO');
        $this->assertSame(['programa_id' => 'ZZT_ROBO', 'nome' => 'Robótica', 'parent_id' => 'BAJA', 'ordem' => 70, 'ativo' => true, 'eventos' => 1], $robo);
        // 96BR and 96SE (RbacDbTestCase) are Baja SAE events.
        $this->assertGreaterThanOrEqual(2, $this->admin->find('BAJA')['eventos']);
        $this->assertNull($this->admin->find('baja'), 'exact spelling only');
    }

    /** @return array<string, mixed> the stored row with numbers as numbers */
    private function rowAsTyped(string $code): array
    {
        $row = $this->row($code);
        $row['ordem'] = (int) $row['ordem'];
        $row['ativo'] = (int) $row['ativo'];
        return $row;
    }
}
