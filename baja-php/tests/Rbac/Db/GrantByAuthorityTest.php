<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use PHPUnit\Framework\Attributes\Group;

/**
 * The second door of RoleGranting (BAJAAPP-76): grantByAuthority and
 * revokeByAuthority.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Normally, to give someone a role you need role.grant there, and you can
 * only hand out permissions you hold yourself. A race controller (the
 * judge role on a lap counted prova) has neither: they cannot give roles,
 * and they do not count laps themselves. Yet on race morning they must add
 * the volunteers who count (lap_counter) and who log the pit lane
 * (pit_logger), as they could before RBAC.
 *
 * So the lap counting page uses this door: "you may give this role here
 * because you hold contagem.control here". It still refuses a role that
 * cannot be given on that place, a system role, and, most important, a
 * role that carries the authority itself or role.grant: a controller adds
 * counters, never other controllers. The end date is capped by the
 * controller's own, and every grant and revoke is in the audit trail with
 * the controller's name.
 *
 * The made-up world is RbacDbTestCase's (96BR ends 12 March 2099; the
 * clock is stopped on 1 March 2099).
 */
#[Group('rbac')]
final class GrantByAuthorityTest extends RbacDbTestCase
{
    private ScopeNode $prova;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prova = ScopeNode::prova('96BR', 'ZZA');
        // Carla controls prova ZZA: the judge role, until 15 March.
        $this->giveDirectly($this->user('carla'), 'judge', $this->prova, '2099-03-15 23:59:59');
        $this->newRequest();
    }

    private function giveAs(string $granter, string $user, string $role, ?ScopeNode $node = null): int
    {
        return $this->rbac->granting->grantByAuthority(Permission::CONTAGEM_CONTROL, $this->user($granter), $this->user($user), $role, $node ?? $this->prova);
    }

    private function refusalOf(callable $attempt): ?string
    {
        try {
            $attempt();
        } catch (GrantRefused $refused) {
            return $refused->reason;
        }
        return null;
    }

    /**
     * SITUATION: the controller adds Davi as a lap counter.
     * EXPECTED: Davi may count there; the grant names Carla; it ends when
     * HER grant ends (15 March), not 7 days after the event (19 March),
     * because a grant never outlives the right that made it; the audit row
     * says by which authority.
     */
    public function testTheControllerGivesTheCounterRoleCappedByTheirOwnEnd(): void
    {
        $this->giveAs('carla', 'davi', 'lap_counter');
        $this->newRequest();

        $this->assertTrue($this->can('davi', Permission::CONTAGEM_COUNT, $this->prova));
        $this->assertFalse($this->can('davi', Permission::CONTAGEM_CONTROL, $this->prova), 'counting only');
        $grant = $this->grantRows('davi')[0];
        $this->assertSame((string) $this->user('carla'), (string) $grant['granted_by']);
        $this->assertSame('2099-03-15 23:59:59', $grant['valid_until']);
        $audit = $this->auditRows();
        $last = end($audit);
        $this->assertSame('grant', $last['action']);
        $this->assertSame('contagem.control', json_decode((string) $last['details'], true)['authority']);
    }

    /**
     * SITUATION: the controller tries to make Davi a judge, a head judge,
     * or a platform admin; or to add a counter on ANOTHER prova.
     * EXPECTED: all refused, and nothing written.
     */
    public function testTheControllerCannotHandOnMoreThanTheTwoRoles(): void
    {
        $this->assertSame(GrantRefused::ESCALATION, $this->refusalOf(fn() => $this->giveAs('carla', 'davi', 'judge')));
        $this->assertSame(GrantRefused::ESCALATION, $this->refusalOf(fn() => $this->giveAs('carla', 'davi', 'head_judge')));
        $this->assertSame(GrantRefused::ROLE_NOT_ALLOWED_HERE, $this->refusalOf(fn() => $this->giveAs('carla', 'davi', 'platform_admin')));
        $this->assertSame(GrantRefused::NO_ROLE_GRANT, $this->refusalOf(fn() => $this->giveAs('carla', 'davi', 'lap_counter', ScopeNode::prova('96BR', 'ZZB'))));
        $this->assertSame([], $this->grantRows('davi'));
    }

    /**
     * SITUATION: somebody who does not control the prova (a lap counter)
     * tries to add another counter.
     * EXPECTED: refused.
     */
    public function testSomeoneWithoutTheAuthorityIsRefused(): void
    {
        $this->giveAs('carla', 'davi', 'lap_counter');
        $this->newRequest();

        $this->assertSame(GrantRefused::NO_ROLE_GRANT, $this->refusalOf(fn() => $this->giveAs('davi', 'eli', 'lap_counter')));
    }

    /**
     * SITUATION: the controller removes Davi. Then tries, by the same
     * door, to remove a judge.
     * EXPECTED: the counter is revoked (by Carla, in the audit trail); the
     * judge is not: this door only takes away the role the page gives.
     */
    public function testTheControllerTakesTheCounterRoleBackButNothingElse(): void
    {
        $grantId = $this->giveAs('carla', 'davi', 'lap_counter');
        $judgeGrant = $this->giveDirectly($this->user('eli'), 'judge', $this->prova);
        $this->newRequest();

        $this->rbac->granting->revokeByAuthority(Permission::CONTAGEM_CONTROL, 'lap_counter', $this->user('carla'), $grantId);
        $this->newRequest();
        $this->assertFalse($this->can('davi', Permission::CONTAGEM_COUNT, $this->prova));
        $this->assertSame((string) $this->user('carla'), (string) $this->grantRows('davi')[0]['revoked_by']);

        $this->assertSame(GrantRefused::ROLE_NOT_ALLOWED_HERE, $this->refusalOf(
            fn() => $this->rbac->granting->revokeByAuthority(Permission::CONTAGEM_CONTROL, 'lap_counter', $this->user('carla'), $judgeGrant)
        ));
        $this->assertTrue($this->can('eli', Permission::CONTAGEM_CONTROL, $this->prova), 'the judge keeps the role');
    }
}
