<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac;

use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeType;
use PHPUnit\Framework\TestCase;

/**
 * The permission catalogue (BAJAAPP-12): the fixed list of everything a
 * role can allow, and where each one can be asked about.
 *
 * WHY IT MATTERS
 *
 * The catalogue is the vocabulary of every access check. Two things in it
 * guard against mistakes:
 *
 *   - each permission names the node types it APPLIES TO. Asking "may X
 *     enter scores on the whole event 26BR?" is a mistake (scores belong
 *     to a prova), and the answer is no even for a platform admin;
 *   - role.manage, the power to redefine roles (and so what everyone may
 *     do), applies ONLY to the platform. Nothing given on a program or an
 *     event can be asked about it there.
 *
 * WHAT IS TESTED
 *
 * That the catalogue has the plan's permissions, with the codes the
 * database stores; that every one applies somewhere; the two guards
 * above; and the types of the main ones.
 */
final class PermissionCatalogueTest extends TestCase
{
    /** The ten the plan starts with. */
    public function testThePlansPermissionsAreAllThere(): void
    {
        $codes = array_map(fn(Permission $p) => $p->value, Permission::cases());
        foreach ([
            'evento.manage', 'prova.configure', 'prova.enter_scores', 'resultado.edit', 'fila.manage',
            'timer.manage', 'certificado.manage', 'premiacao.manage', 'role.grant', 'role.manage',
        ] as $expected) {
            $this->assertContains($expected, $codes);
        }
    }

    /** Codes are stored in role_permission: lower case "thing.action", unique. */
    public function testCodesAreWellFormedAndUnique(): void
    {
        $codes = array_map(fn(Permission $p) => $p->value, Permission::cases());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $code);
        }
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    /** A permission that applied nowhere could never be granted any meaning. */
    public function testEveryPermissionAppliesSomewhere(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertNotEmpty($permission->appliesTo(), $permission->value);
        }
    }

    public function testRoleManageAppliesOnlyToThePlatform(): void
    {
        $this->assertSame([ScopeType::PLATFORM], Permission::ROLE_MANAGE->appliesTo());
    }

    /** Only role.grant and role.manage can be asked about the platform itself. */
    public function testOnlyRolePermissionsApplyToThePlatformItself(): void
    {
        $atPlatform = array_filter(Permission::cases(), fn(Permission $p) => $p->appliesToType(ScopeType::PLATFORM));
        $this->assertEqualsCanonicalizing([Permission::ROLE_GRANT, Permission::ROLE_MANAGE], array_values($atPlatform));
    }

    /** Scores are entered on a prova, not on an event or anything above. */
    public function testEnteringScoresAppliesOnlyToAProva(): void
    {
        $this->assertSame([ScopeType::PROVA], Permission::PROVA_ENTER_SCORES->appliesTo());
        $this->assertFalse(Permission::PROVA_ENTER_SCORES->appliesToType(ScopeType::EVENTO));
    }

    /** A queue can be managed on itself, or on its event (to create queues). */
    public function testManagingQueuesAppliesToAQueueAndToItsEvent(): void
    {
        $this->assertEqualsCanonicalizing([ScopeType::EVENTO, ScopeType::FILA], Permission::FILA_MANAGE->appliesTo());
    }

    /** Giving roles can be asked about any node: everyone who may give roles does so somewhere. */
    public function testGivingRolesAppliesEverywhere(): void
    {
        $this->assertEqualsCanonicalizing(ScopeType::cases(), Permission::ROLE_GRANT->appliesTo());
    }
}
