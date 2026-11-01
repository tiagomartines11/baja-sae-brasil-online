<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * What PropelMigration_1793080000 leaves in the database (BAJAAPP-12):
 * the eight starting roles, and the platform admin role for the users who
 * had the legacy 'admin' code. Then what PropelMigration_1793110000
 * (BAJAAPP-76) added: four roles for the legacy codes no starting role
 * matched, and premiacao.view. Checked on the dev database, where the same
 * data comes from baja-infra/mysql/init/09-rbac.sql and 12-rbac-switchover.sql.
 * The switch-over's grants are tested in LegacyMigrationTest.
 *
 * WHY IT MATTERS
 *
 * The starting roles are what everyone will be given. A permission too
 * many in one of them is access for every holder; one too few locks
 * people out. And the one-time step must give the platform admin role to
 * the legacy admins AND NOBODY ELSE: a mistake there is a silent
 * promotion.
 */
#[Group('rbac')]
final class MigrationDataTest extends RbacDbTestCase
{
    /** role code => [system?, allowed node types, permissions] */
    private const EXPECTED = [
        'platform_admin' => [true, ['platform'], 'ALL'],
        'program_manager' => [false, ['programa'], 'ALL_BUT_ROLE_MANAGE'],
        'event_manager' => [false, ['evento'], [
            'contagem.control', 'contagem.count', 'contagem.pit_log', 'equipe.manage', 'evento.manage', 'fila.manage',
            'notificacao.send', 'premiacao.manage', 'premiacao.view', 'prova.configure', 'prova.enter_scores', 'resultado.edit',
            'role.grant', 'timer.manage', 'timer.operate',
        ]],
        'head_judge' => [false, ['prova'], ['contagem.control', 'prova.enter_scores', 'role.grant']],
        'judge' => [false, ['prova'], ['contagem.control', 'prova.enter_scores']],
        'queue_manager' => [false, ['fila'], ['fila.manage']],
        'timer_manager' => [false, ['evento'], ['timer.manage', 'timer.operate']],
        'certificate_manager' => [false, ['programa'], ['certificado.manage']],
        // Added by the switch-over (BAJAAPP-76): one legacy code each.
        'lap_counter' => [false, ['prova'], ['contagem.count']],
        'pit_logger' => [false, ['prova'], ['contagem.pit_log']],
        'timer_operator' => [false, ['evento'], ['timer.operate']],
        'awards_viewer' => [false, ['evento'], ['premiacao.view']],
    ];

    public function testEveryRoleIsExactlyAsPlanned(): void
    {
        $all = array_map(fn(Permission $p) => $p->value, Permission::cases());
        sort($all);
        $allButRoleManage = array_values(array_diff($all, ['role.manage']));

        foreach (self::EXPECTED as $code => [$isSystem, $types, $permissions]) {
            $role = $this->rbac->roles->byCode($code);
            $this->assertNotNull($role, "Role $code is missing.");
            $expected = match ($permissions) {
                'ALL' => $all,
                'ALL_BUT_ROLE_MANAGE' => $allButRoleManage,
                default => $permissions,
            };
            $this->assertSame($isSystem, $role->isSystem, "$code: system?");
            $this->assertSame($types, $role->allowedScopeTypeCodes(), "$code: where it may be given");
            $this->assertSame($expected, $role->permissionCodes(), "$code: permissions");
        }
    }

    /** Every permission code stored for the starting roles is one the code knows: none is silently dropped. */
    public function testEveryStoredPermissionIsKnown(): void
    {
        $stored = $this->con->query(
            "SELECT DISTINCT rp.permission FROM role_permission rp JOIN role r ON r.role_id = rp.role_id WHERE r.code NOT LIKE 'zz\\_%'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($stored as $code) {
            $this->assertNotNull(Permission::tryFrom($code), "Unknown permission '$code' in role_permission.");
        }
    }

    /** Only the platform admin is a system role: the others must be delegable, or a head judge could not add a judge. */
    public function testOnlyThePlatformAdminIsASystemRole(): void
    {
        $system = array_filter(self::EXPECTED, fn($e) => $e[0]);
        $this->assertSame(['platform_admin'], array_keys($system));
    }

    /** Every role that carries role.grant can pass on each role meant to be below it. */
    public function testEachRoleCanHandOutTheRolesBelowIt(): void
    {
        $below = [
            'program_manager' => ['event_manager', 'timer_manager', 'certificate_manager', 'head_judge', 'judge', 'queue_manager'],
            'event_manager' => ['timer_manager', 'head_judge', 'judge', 'queue_manager'],
            'head_judge' => ['judge'],
        ];
        foreach ($below as $upperCode => $lowerCodes) {
            $upper = $this->rbac->roles->byCode($upperCode);
            foreach ($lowerCodes as $lowerCode) {
                $missing = array_diff($this->rbac->roles->byCode($lowerCode)->permissionCodes(), $upper->permissionCodes());
                $this->assertSame([], array_values($missing), "$upperCode cannot hand out $lowerCode");
            }
        }
    }

    /**
     * The legacy admins: each user whose permissions hold 'admin' has one
     * active platform admin grant on the platform, made by nobody (the
     * migration), with no end, and one audit row for it.
     */
    public function testEveryLegacyAdminIsAPlatformAdmin(): void
    {
        $admins = $this->con->query("SELECT user_id FROM user WHERE permissions LIKE '%| admin |%' AND username NOT LIKE 'zzrbac\\_%'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotEmpty($admins, 'The dev seed has a legacy admin (superadmin).');

        foreach ($admins as $userId) {
            $statement = $this->con->prepare(
                "SELECT g.grant_id, g.scope_type, g.scope_id, g.granted_by, g.valid_until, g.revoked_at
                 FROM user_role g JOIN role r ON r.role_id = g.role_id
                 WHERE g.user_id = ? AND r.code = 'platform_admin'"
            );
            $statement->execute([$userId]);
            $grants = $statement->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(1, $grants, "User $userId");
            $this->assertSame(['platform', ''], [$grants[0]['scope_type'], $grants[0]['scope_id']]);
            $this->assertNull($grants[0]['granted_by']);
            $this->assertNull($grants[0]['valid_until']);
            $this->assertNull($grants[0]['revoked_at']);

            $audit = $this->con->prepare("SELECT action, actor_user_id FROM role_audit WHERE grant_id = ?");
            $audit->execute([$grants[0]['grant_id']]);
            $this->assertSame([['action' => 'grant', 'actor_user_id' => null]], $audit->fetchAll(PDO::FETCH_ASSOC));

            $this->assertTrue($this->rbac->authorizer->can((int) $userId, Permission::RESULTADO_EDIT, ScopeNode::evento('26BR')));
        }
    }

    /**
     * Nobody else: PropelMigration_1793080000 gave the platform admin role
     * to the legacy admins only. Its grants are the ones whose audit row
     * names it; every one is a platform admin grant to a user with 'admin'.
     */
    public function testThePhase2MigrationGaveNothingToAnyoneElse(): void
    {
        $rows = $this->con->query(
            "SELECT r.code, u.permissions FROM user_role g
             JOIN role_audit a ON a.grant_id = g.grant_id AND a.action = 'grant'
             JOIN role r ON r.role_id = g.role_id
             JOIN user u ON u.user_id = g.user_id
             WHERE JSON_UNQUOTE(JSON_EXTRACT(a.details, '$.source')) = 'PropelMigration_1793080000: legacy admin code'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('platform_admin', $row['code']);
            $this->assertStringContainsString('| admin |', $row['permissions']);
        }
    }

    /**
     * The seed's judges got their provas from the switch-over
     * (12-rbac-switchover.sql): juiz1 judges the Enduro and Manobrabilidade,
     * juiz2 the Enduro but not Manobrabilidade, as their legacy codes said.
     */
    public function testTheSeedsJudgesGotTheirLegacyProvas(): void
    {
        $id = fn(string $name) => (int) $this->con->query("SELECT user_id FROM user WHERE username = '$name'")->fetchColumn();
        $this->assertTrue($this->rbac->authorizer->can($id('juiz1'), Permission::PROVA_ENTER_SCORES, ScopeNode::prova('26BR', 'END')));
        $this->assertTrue($this->rbac->authorizer->can($id('juiz1'), Permission::PROVA_ENTER_SCORES, ScopeNode::prova('26BR', 'MAN')));
        $this->assertTrue($this->rbac->authorizer->can($id('juiz2'), Permission::PROVA_ENTER_SCORES, ScopeNode::prova('26BR', 'END')));
        $this->assertFalse($this->rbac->authorizer->can($id('juiz2'), Permission::PROVA_ENTER_SCORES, ScopeNode::prova('26BR', 'MAN')));
    }
}
