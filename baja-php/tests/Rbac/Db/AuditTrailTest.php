<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Audit\AuditLog;
use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\RbacServices;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The audit trail of roles (BAJAAPP-12): role_audit.
 *
 * WHY IT MATTERS
 *
 * When something goes wrong at an event ("who let this person change the
 * results?"), role_audit is where the answer is. It only helps if it is
 * complete and exact:
 *
 *   - every grant and every revoke leaves EXACTLY ONE row, saying who did
 *     it, to whom, which role, where, and when;
 *   - a refused attempt leaves NONE (it changed nothing, so there is
 *     nothing to record; it is logged as a warning instead);
 *   - the change and its row are written in ONE transaction: if the row
 *     cannot be written, the grant is not made either. A grant with no
 *     trace is exactly what an attacker would want;
 *   - editing a role (what everyone with it may do) is recorded too, with
 *     the role before and after; only role.manage holders may do it.
 */
#[Group('rbac')]
final class AuditTrailTest extends RbacDbTestCase
{
    /** One grant: one row, with everything about it. */
    public function testAGrantLeavesExactlyOneRow(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $grantId = $this->rbac->granting->grant($this->user('evt'), $this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $rows = $this->auditRows();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(AuditLog::GRANT, $row['action']);
        $this->assertSame($this->user('evt'), (int) $row['actor_user_id']);
        $this->assertSame($this->user('judge'), (int) $row['target_user_id']);
        $this->assertSame($grantId, (int) $row['grant_id']);
        $this->assertSame('prova', $row['scope_type']);
        $this->assertSame('96BR/ZZA', $row['scope_id']);
        $this->assertSame(self::NOW, $row['occurred_at']);
        $this->assertSame(['role' => 'judge', 'valid_until' => '2099-03-19 23:59:59'], json_decode($row['details'], true));
    }

    /** One revoke: one more row. */
    public function testARevokeLeavesExactlyOneRow(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));
        $grantId = $this->rbac->granting->grant($this->user('head'), $this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->rbac->granting->revoke($this->user('head'), $grantId);

        $rows = $this->auditRows();
        $this->assertCount(2, $rows);
        $this->assertSame(AuditLog::REVOKE, $rows[1]['action']);
        $this->assertSame($this->user('head'), (int) $rows[1]['actor_user_id']);
        $this->assertSame($this->user('judge'), (int) $rows[1]['target_user_id']);
        $this->assertSame($grantId, (int) $rows[1]['grant_id']);
    }

    /** Three grants and two revokes: five rows, in order. */
    public function testEveryChangeHasItsOwnRow(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        $evt = $this->user('evt');
        $a = $this->rbac->granting->grant($evt, $this->user('a'), 'judge', ScopeNode::prova('96BR', 'ZZA'));
        $b = $this->rbac->granting->grant($evt, $this->user('b'), 'judge', ScopeNode::prova('96BR', 'ZZB'));
        $this->rbac->granting->grant($evt, $this->user('c'), 'queue_manager', ScopeNode::fila('96BR', 1));
        $this->rbac->granting->revoke($evt, $a);
        $this->rbac->granting->revoke($evt, $b);

        $this->assertSame(
            ['grant', 'grant', 'grant', 'revoke', 'revoke'],
            array_column($this->auditRows(), 'action')
        );
    }

    /** A refused grant leaves no row, and no grant. */
    public function testAFailedGrantLeavesNoRow(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        try {
            $this->rbac->granting->grant($this->user('judge'), $this->user('x'), 'judge', ScopeNode::prova('96BR', 'ZZA'));
            $this->fail('A judge gave a role.');
        } catch (GrantRefused) {
        }

        $this->assertSame([], $this->auditRows());
        $this->assertSame([], $this->grantRows('x'));
    }

    /** A refused revoke leaves no row, and the grant as it was. */
    public function testAFailedRevokeLeavesNoRow(): void
    {
        $grantId = $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        try {
            $this->rbac->granting->revoke($this->user('nobody'), $grantId);
            $this->fail('Somebody with no role revoked a grant.');
        } catch (GrantRefused) {
        }

        $this->assertSame([], $this->auditRows());
        $this->assertNull($this->grantRows('judge')[0]['revoked_at']);
    }

    /**
     * The audit row cannot be written (here: an audit log that always
     * fails). The grant must not be made: both or neither.
     */
    public function testAGrantWhoseAuditRowCannotBeWrittenIsNotMade(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        $failingAudit = new class implements AuditLog {
            public function record(string $action, ?int $actorUserId, DateTimeImmutable $occurredAt, ?int $grantId = null, ?int $roleId = null, ?int $targetUserId = null, ?ScopeNode $scope = null, array $details = []): void
            {
                throw new RuntimeException('The audit table is unavailable.');
            }
        };
        $rbac = RbacServices::create($this->con, $this->clock, fn() => null, $failingAudit);

        try {
            $rbac->granting->grant($this->user('evt'), $this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));
            $this->fail('The grant went through without its audit row.');
        } catch (RuntimeException $e) {
            $this->assertSame('The audit table is unavailable.', $e->getMessage());
        }

        $this->assertSame([], $this->grantRows('judge'));
        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /** The same for a revoke: if its row cannot be written, the grant stays active. */
    public function testARevokeWhoseAuditRowCannotBeWrittenIsNotMade(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));
        $grantId = $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));
        $failingAudit = new class implements AuditLog {
            public function record(string $action, ?int $actorUserId, DateTimeImmutable $occurredAt, ?int $grantId = null, ?int $roleId = null, ?int $targetUserId = null, ?ScopeNode $scope = null, array $details = []): void
            {
                throw new RuntimeException('The audit table is unavailable.');
            }
        };
        $rbac = RbacServices::create($this->con, $this->clock, fn() => null, $failingAudit);

        try {
            $rbac->granting->revoke($this->user('head'), $grantId);
            $this->fail('The revoke went through without its audit row.');
        } catch (RuntimeException) {
        }

        $this->assertNull($this->grantRows('judge')[0]['revoked_at']);
    }

    // --- Role edits -------------------------------------------------------------------

    /** Creating a role, then changing it: two rows, with the role before and after. */
    public function testRoleEditsAreRecorded(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $editor = $this->rbac->roleEditor;

        $roleId = $editor->save($this->user('admin'), 'zz_scorer', 'Apontador', null, false, [ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES]);
        $editor->save($this->user('admin'), 'zz_scorer', 'Apontador', 'Agora também conta voltas', false, [ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES, Permission::CONTAGEM_COUNT]);

        $rows = $this->con->query("SELECT action, actor_user_id, details FROM role_audit WHERE role_id = $roleId ORDER BY audit_id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(['role_create', 'role_update'], array_column($rows, 'action'));
        $update = json_decode($rows[1]['details'], true);
        $this->assertSame(['prova.enter_scores'], $update['before']['permissions']);
        $this->assertSame(['contagem.count', 'prova.enter_scores'], $update['after']['permissions']);
        $this->assertSame($this->user('admin'), (int) $rows[1]['actor_user_id']);
    }

    /**
     * Only holders of role.manage may edit roles. A program manager may
     * give roles, but not change what they are: otherwise they could add
     * any permission to a role they hold.
     */
    public function testOnlyRoleManageMayEditRoles(): void
    {
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));

        try {
            $this->rbac->roleEditor->save($this->user('baja'), 'program_manager', 'Gestor de programa', null, false, [ScopeType::PROGRAMA], Permission::cases());
            $this->fail('A program manager edited a role.');
        } catch (GrantRefused $e) {
            $this->assertSame(GrantRefused::NO_ROLE_MANAGE, $e->reason);
        }

        $this->newRequest();
        $this->assertFalse($this->rbac->roles->byCode('program_manager')->has(Permission::ROLE_MANAGE), 'The role was changed anyway.');
    }

    /** A role edit takes effect on the next request, for everyone who has it. */
    public function testARoleEditChangesWhatItsHoldersMayDo(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $this->rbac->roleEditor->save($this->user('admin'), 'zz_scorer', 'Apontador', null, false, [ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES]);
        $this->giveDirectly($this->user('judge'), 'zz_scorer', ScopeNode::prova('96BR', 'ZZA'));
        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));

        $this->rbac->roleEditor->save($this->user('admin'), 'zz_scorer', 'Apontador', null, false, [ScopeType::PROVA], []);
        $this->newRequest();

        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }
}
