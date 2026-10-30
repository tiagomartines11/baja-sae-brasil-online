<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Giving and taking away roles (BAJAAPP-12): delegation.
 *
 * BACKGROUND
 *
 * Roles are not only given by the platform admin. An event manager gives
 * judges their provas; a head judge adds a judge to their own prova. That
 * is what keeps the platform admin from being a bottleneck on event day,
 * and it is also where access control usually breaks: if handing out
 * roles is too loose, anyone who can hand out anything can hand
 * themselves everything.
 *
 * THE RULES (RoleGranting)
 *
 * Someone may give role R on node N only when:
 *   1. N exists;
 *   2. R may be given on that kind of node;
 *   3. they hold role.grant on N or above;
 *   4. they hold, on N, EVERY permission R carries (no escalation: you
 *      can only hand out what you have yourself);
 *   5. if R is a system role, they hold role.manage.
 * Revoking asks the same. And the new grant never ends later than the
 * granter's own right to grant there.
 *
 * Every refused attempt is checked to have written NOTHING: no grant, no
 * audit row (AuditTrailTest checks the audit rows of allowed ones).
 */
#[Group('rbac')]
final class DelegationTest extends RbacDbTestCase
{
    /** Asserts the call is refused for exactly this reason, and wrote nothing. */
    private function assertRefused(string $reason, callable $attempt): void
    {
        $grantsBefore = (int) $this->con->query('SELECT COUNT(*) FROM user_role')->fetchAll(\PDO::FETCH_COLUMN)[0];
        $auditBefore = count($this->auditRows());
        try {
            $attempt();
            $this->fail("Expected a refusal ($reason), but it was allowed.");
        } catch (GrantRefused $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
        $this->assertSame($grantsBefore, (int) $this->con->query('SELECT COUNT(*) FROM user_role')->fetchAll(\PDO::FETCH_COLUMN)[0], 'A refused attempt wrote a grant.');
        $this->assertCount($auditBefore, $this->auditRows(), 'A refused attempt wrote an audit row.');
    }

    private function grant(string $granter, string $user, string $role, ScopeNode $node, ?string $validUntil = null): int
    {
        return $this->rbac->granting->grant($this->user($granter), $this->user($user), $role, $node, $validUntil === null ? null : self::at($validUntil));
    }

    // --- Allowed within scope ---------------------------------------------------------

    /** An event manager gives a judge one of their event's provas; the judge can then enter scores there. */
    public function testAnEventManagerCanMakeAJudgeOfTheirOwnProva(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $this->grant('evt', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /**
     * An event manager can make another event manager of the same event.
     * This needs the "holds" rule, not the "can" one: event_manager carries
     * prova.enter_scores, which is never ASKED about an event, but the
     * granter holds it on the event and so on all its provas.
     */
    public function testAnEventManagerCanAddAnotherOnTheSameEvent(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $this->grant('evt', 'evt2', 'event_manager', ScopeNode::evento('96BR'));

        $this->assertTrue($this->can('evt2', Permission::RESULTADO_EDIT, ScopeNode::evento('96BR')));
    }

    /** A program manager makes event managers in their program, and an AERO one in the forum's events. */
    public function testAProgramManagerCanMakeEventManagersBelow(): void
    {
        $this->giveDirectly($this->user('aero'), 'program_manager', ScopeNode::programa('AERO'));

        $this->grant('aero', 'evt', 'event_manager', ScopeNode::evento('96FA'));

        $this->assertTrue($this->can('evt', Permission::EVENTO_MANAGE, ScopeNode::evento('96FA')));
    }

    // --- Refused above scope ----------------------------------------------------------------

    /** An event manager cannot give roles on the program above, or on another event. */
    public function testAnEventManagerCannotGiveRolesAboveOrBesideTheirEvent(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('evt', 'x', 'certificate_manager', ScopeNode::programa('BAJA')));
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('evt', 'x', 'event_manager', ScopeNode::evento('96SE')));
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('evt', 'x', 'judge', ScopeNode::prova('96SE', 'ZZA')));
    }

    /** A Baja program manager cannot give anything in AeroDesign. */
    public function testAProgramManagerCannotGiveRolesInAnotherProgram(): void
    {
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));

        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('baja', 'x', 'event_manager', ScopeNode::evento('96AR')));
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('baja', 'x', 'judge', ScopeNode::prova('96FA', 'ZZA')));
    }

    /** Somebody with no role at all can give nothing. */
    public function testSomebodyWithoutRolesCannotGiveAny(): void
    {
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('nobody', 'nobody', 'judge', ScopeNode::prova('96BR', 'ZZA')));
    }

    // --- Refused escalation ------------------------------------------------------------------

    /**
     * Escalation: an event manager tries to give a role that also carries
     * certificado.manage, which they do not have. Refused, even though they
     * may give roles there and the role may be given there.
     */
    public function testARoleWithAPermissionTheGranterLacksIsRefused(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        $this->createRole('zz_evt_plus_cert', [ScopeType::EVENTO], [Permission::EVENTO_MANAGE, Permission::CERTIFICADO_MANAGE]);

        $this->assertRefused(GrantRefused::ESCALATION, fn() => $this->grant('evt', 'x', 'zz_evt_plus_cert', ScopeNode::evento('96BR')));
    }

    /** Escalation by a head judge: a prova role that also edits results. */
    public function testAHeadJudgeCannotGiveMoreThanTheyHave(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));
        $this->createRole('zz_judge_plus', [ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES, Permission::PROVA_CONFIGURE]);

        $this->assertRefused(GrantRefused::ESCALATION, fn() => $this->grant('head', 'x', 'zz_judge_plus', ScopeNode::prova('96BR', 'ZZA')));
    }

    /** Nobody can give themselves more, either. */
    public function testNobodyCanEscalateThemselves(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        $this->createRole('zz_evt_plus_cert', [ScopeType::EVENTO], [Permission::CERTIFICADO_MANAGE]);

        $this->assertRefused(GrantRefused::ESCALATION, fn() => $this->grant('evt', 'evt', 'zz_evt_plus_cert', ScopeNode::evento('96BR')));
        $this->assertFalse($this->can('evt', Permission::CERTIFICADO_MANAGE, ScopeNode::evento('96BR')));
    }

    // --- Refused system role -------------------------------------------------------------------

    /**
     * A system role made for the test, that an event manager would pass
     * every other rule for (it carries only evento.manage, on an event).
     * Refused: system roles need role.manage.
     */
    public function testASystemRoleIsRefusedWithoutRoleManage(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        $this->createRole('zz_system', [ScopeType::EVENTO], [Permission::EVENTO_MANAGE], true);

        $this->assertRefused(GrantRefused::SYSTEM_ROLE, fn() => $this->grant('evt', 'x', 'zz_system', ScopeNode::evento('96BR')));
    }

    /** The platform admin (who holds role.manage) can give it. */
    public function testASystemRoleCanBeGivenWithRoleManage(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $this->createRole('zz_system', [ScopeType::EVENTO], [Permission::EVENTO_MANAGE], true);

        $this->grant('admin', 'x', 'zz_system', ScopeNode::evento('96BR'));

        $this->assertTrue($this->can('x', Permission::EVENTO_MANAGE, ScopeNode::evento('96BR')));
    }

    /** The real system role: a program manager cannot make a platform admin. */
    public function testAProgramManagerCannotMakeAPlatformAdmin(): void
    {
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));

        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('baja', 'x', 'platform_admin', ScopeNode::platform()));
    }

    /** A platform admin can make another. */
    public function testAPlatformAdminCanMakeAnother(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->grant('admin', 'admin2', 'platform_admin', ScopeNode::platform());

        $this->assertTrue($this->can('admin2', Permission::ROLE_MANAGE, ScopeNode::platform()));
    }

    // --- A role only where it may be given ------------------------------------------------------

    /** A judge is for a prova, not a whole event, even for the platform admin. */
    public function testARoleCannotBeGivenOnTheWrongKindOfNode(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->assertRefused(GrantRefused::ROLE_NOT_ALLOWED_HERE, fn() => $this->grant('admin', 'x', 'judge', ScopeNode::evento('96BR')));
        $this->assertRefused(GrantRefused::ROLE_NOT_ALLOWED_HERE, fn() => $this->grant('admin', 'x', 'event_manager', ScopeNode::programa('BAJA')));
        $this->assertRefused(GrantRefused::ROLE_NOT_ALLOWED_HERE, fn() => $this->grant('admin', 'x', 'queue_manager', ScopeNode::evento('96BR')));
    }

    // --- The head judge ---------------------------------------------------------------------------

    /** A head judge adds a judge to their own prova, and the judge can enter scores there. */
    public function testAHeadJudgeCanAddAJudgeToTheirProva(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /** ...and nowhere else: not the next prova, not the same prova code at another event, not the event. */
    public function testAHeadJudgeCanAddJudgesToTheirProvaOnly(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('head', 'x', 'judge', ScopeNode::prova('96BR', 'ZZB')));
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('head', 'x', 'judge', ScopeNode::prova('96SE', 'ZZA')));
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('head', 'x', 'event_manager', ScopeNode::evento('96BR')));
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('head', 'x', 'queue_manager', ScopeNode::fila('96BR', 1)));
    }

    /** A judge (no role.grant) cannot add anyone, even to their own prova. */
    public function testAJudgeCannotAddJudges(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('judge', 'x', 'judge', ScopeNode::prova('96BR', 'ZZA')));
    }

    /** An expired head judge can no longer add anyone. */
    public function testAnExpiredHeadJudgeCannotAddJudges(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-02-01 00:00:00');

        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->grant('head', 'x', 'judge', ScopeNode::prova('96BR', 'ZZA')));
    }

    // --- Unknown things ------------------------------------------------------------------------------

    public function testUnknownNodeRoleAndUserAreRefused(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->assertRefused(GrantRefused::UNKNOWN_NODE, fn() => $this->grant('admin', 'x', 'judge', ScopeNode::prova('96BR', 'NOP')));
        $this->assertRefused(GrantRefused::UNKNOWN_ROLE, fn() => $this->grant('admin', 'x', 'no_such_role', ScopeNode::prova('96BR', 'ZZA')));
        $this->assertRefused(
            GrantRefused::UNKNOWN_USER,
            fn() => $this->rbac->granting->grant($this->user('admin'), 999999999, 'judge', ScopeNode::prova('96BR', 'ZZA'))
        );
    }

    // --- Expiry when granting -------------------------------------------------------------------------

    /** With no end given, a grant on a prova ends 7 days after the event (12 March 2099). */
    public function testAGrantInsideAnEventGetsTheDefaultEnd(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $this->grant('evt', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertSame('2099-03-19 23:59:59', $this->grantRows('judge')[0]['valid_until']);
    }

    /** An event with no end date (data_fim) yet: refused without an end, accepted with one. */
    public function testAnEventWithoutAnEndDateNeedsAnExplicitEnd(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->assertRefused(GrantRefused::VALID_UNTIL_REQUIRED, fn() => $this->grant('admin', 'judge', 'judge', ScopeNode::prova('96SE', 'ZZA')));

        $this->grant('admin', 'judge', 'judge', ScopeNode::prova('96SE', 'ZZA'), '2099-06-30 23:59:59');
        $this->assertSame('2099-06-30 23:59:59', $this->grantRows('judge')[0]['valid_until']);
    }

    /** An end that is not in the future is refused. */
    public function testAnEndInThePastIsRefused(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->assertRefused(GrantRefused::VALID_UNTIL_IN_PAST, fn() => $this->grant('admin', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-02-01 00:00:00'));
    }

    /** Above an event, no end unless one is given. */
    public function testAGrantAboveAnEventHasNoDefaultEnd(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->grant('admin', 'baja', 'program_manager', ScopeNode::programa('BAJA'));

        $this->assertNull($this->grantRows('baja')[0]['valid_until']);
    }

    // --- A grant never outlives the granter's right to grant -----------------------------------------
    //
    // WHY: without this, someone whose right to hand out roles ends in five
    // days could make a judge for a year, and through that judge's friend
    // (if the judge could grant) keep access going after their own ended.
    // The limit is the end of the granter's "covering" grant: the
    // latest-ending of their active grants carrying role.grant on the node
    // or above it.

    /**
     * SITUATION: a head judge whose own role ends on 5 March, 18:00, asks to
     * make a judge until 1 April. EXPECTED: refused, nothing written, and
     * the message says until when they may grant.
     */
    public function testAGrantCannotBeAskedToOutliveTheGranter(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');

        $this->assertRefused(
            GrantRefused::VALID_UNTIL_AFTER_GRANTER,
            fn() => $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-04-01 00:00:00')
        );
        try {
            $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-04-01 00:00:00');
            $this->fail('The grant was allowed to outlive the head judge.');
        } catch (GrantRefused $e) {
            $this->assertStringContainsString('2099-03-05 18:00:00', $e->getMessage(), 'The message says until when the head judge may grant.');
        }
    }

    /** Ending exactly when the granter's right ends is allowed. */
    public function testAGrantMayEndExactlyWhenTheGranterDoes(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');

        $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');

        $this->assertSame('2099-03-05 18:00:00', $this->grantRows('judge')[0]['valid_until']);
    }

    /**
     * With no end asked for, the default (19 March) would outlive the head
     * judge (5 March): it is brought forward to 5 March, 18:00.
     */
    public function testTheDefaultEndIsBroughtForwardToTheGranterEnd(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');

        $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertSame('2099-03-05 18:00:00', $this->grantRows('judge')[0]['valid_until']);
    }

    /**
     * Of several covering grants, the one that ends LAST sets the limit: a
     * head judge until 5 March who is also event manager until 25 March may
     * make a judge until 20 March.
     */
    public function testTheLatestEndingCoveringGrantSetsTheLimit(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');
        $this->giveDirectly($this->user('head'), 'event_manager', ScopeNode::evento('96BR'), '2099-03-25 00:00:00');

        $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-20 00:00:00');

        $this->assertSame('2099-03-20 00:00:00', $this->grantRows('judge')[0]['valid_until']);
    }

    /**
     * Only grants that carry role.grant count. Being a judge there until
     * December does not stretch a head judge role that ends on 5 March: the
     * judge role cannot give roles at all.
     */
    public function testAGrantWithoutRoleGrantDoesNotStretchTheLimit(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');
        $this->giveDirectly($this->user('head'), 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-12-31 00:00:00');

        $this->assertRefused(
            GrantRefused::VALID_UNTIL_AFTER_GRANTER,
            fn() => $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-20 00:00:00')
        );
    }

    /**
     * Only grants that reach the node count. Being head judge of the other
     * prova until December says nothing about how long they may grant on
     * this one.
     */
    public function testAGrantElsewhereDoesNotStretchTheLimit(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZB'), '2099-12-31 00:00:00');

        $this->assertRefused(
            GrantRefused::VALID_UNTIL_AFTER_GRANTER,
            fn() => $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-20 00:00:00')
        );
    }

    /** A revoked grant does not count either, however long it would have lasted. */
    public function testARevokedGrantDoesNotStretchTheLimit(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-05 18:00:00');
        $this->giveDirectly($this->user('head'), 'event_manager', ScopeNode::evento('96BR'), null, '2099-02-01 00:00:00');

        $this->assertRefused(
            GrantRefused::VALID_UNTIL_AFTER_GRANTER,
            fn() => $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-20 00:00:00')
        );
    }

    /**
     * Above an event there is no default end. A program manager whose role
     * ends on 30 June who makes another program manager: that grant ends on
     * 30 June too, it does not last until revoked.
     */
    public function testAGrantWithNoDefaultEndTakesTheGranterEnd(): void
    {
        $this->giveDirectly($this->user('pm'), 'program_manager', ScopeNode::programa('BAJA'), '2099-06-30 23:59:59');

        $this->grant('pm', 'pm2', 'program_manager', ScopeNode::programa('BAJA'));

        $this->assertSame('2099-06-30 23:59:59', $this->grantRows('pm2')[0]['valid_until']);
    }

    /** A platform admin whose grant never ends has no limit: any future end, or none above an event. */
    public function testAPlatformAdminWithoutAnEndIsNotLimited(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->grant('admin', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2199-01-01 00:00:00');
        $this->grant('admin', 'baja', 'program_manager', ScopeNode::programa('BAJA'));

        $this->assertSame('2199-01-01 00:00:00', $this->grantRows('judge')[0]['valid_until']);
        $this->assertNull($this->grantRows('baja')[0]['valid_until']);
    }

    /** A platform admin whose own grant ends is limited like anyone else. */
    public function testAPlatformAdminWithAnEndIsLimitedToo(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform(), '2099-12-31 00:00:00');

        $this->assertRefused(
            GrantRefused::VALID_UNTIL_AFTER_GRANTER,
            fn() => $this->grant('admin', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'), '2199-01-01 00:00:00')
        );
    }

    // --- Revoking ---------------------------------------------------------------------------------------

    /** The head judge removes the judge they added; the judge loses access at once (same request). */
    public function testAHeadJudgeCanRemoveAJudgeFromTheirProva(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));
        $grantId = $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->rbac->granting->revoke($this->user('head'), $grantId);

        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
        $row = $this->grantRows('judge')[0];
        $this->assertSame(self::NOW, $row['revoked_at']);
        $this->assertSame($this->user('head'), (int) $row['revoked_by']);
    }

    /** Someone who could not give a grant cannot take it away either. */
    public function testRevokingNeedsTheSameRightsAsGranting(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        $this->giveDirectly($this->user('otherhead'), 'head_judge', ScopeNode::prova('96BR', 'ZZB'));
        $grantId = $this->grant('evt', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->rbac->granting->revoke($this->user('otherhead'), $grantId));
        $this->assertRefused(GrantRefused::NO_ROLE_GRANT, fn() => $this->rbac->granting->revoke($this->user('judge'), $grantId));
        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /** A system role's grant can only be taken away by a holder of role.manage. */
    public function testRevokingASystemRoleNeedsRoleManage(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));
        $this->createRole('zz_system', [ScopeType::EVENTO], [Permission::EVENTO_MANAGE], true);
        $grantId = $this->giveDirectly($this->user('x'), 'zz_system', ScopeNode::evento('96BR'));

        $this->assertRefused(GrantRefused::SYSTEM_ROLE, fn() => $this->rbac->granting->revoke($this->user('evt'), $grantId));
    }

    /** Twice is refused, and the second attempt writes nothing. */
    public function testAGrantCannotBeRevokedTwice(): void
    {
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));
        $grantId = $this->grant('head', 'judge', 'judge', ScopeNode::prova('96BR', 'ZZA'));
        $this->rbac->granting->revoke($this->user('head'), $grantId);

        $this->assertRefused(GrantRefused::ALREADY_REVOKED, fn() => $this->rbac->granting->revoke($this->user('head'), $grantId));
        $this->assertRefused(GrantRefused::UNKNOWN_GRANT, fn() => $this->rbac->granting->revoke($this->user('head'), 999999999999));
    }

    /** Refusals are logged, so an attempt to escalate leaves a trace in the logs. */
    public function testARefusalIsLogged(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));
        $this->warnings = [];

        try {
            $this->grant('judge', 'judge', 'head_judge', ScopeNode::prova('96BR', 'ZZA'));
        } catch (GrantRefused) {
        }

        $this->assertSame('RBAC: grant refused', $this->warnings[0][0] ?? null);
        $this->assertSame(GrantRefused::NO_ROLE_GRANT, $this->warnings[0][1]['reason']);
    }
}
