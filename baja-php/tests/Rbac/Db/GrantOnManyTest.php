<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Audit\AuditLog;
use Baja\Rbac\Audit\DatabaseAuditLog;
use Baja\Rbac\Delegation\BatchRefused;
use Baja\Rbac\Delegation\GrantBatch;
use Baja\Rbac\Delegation\GrantRefused;
use Baja\Rbac\Delegation\RoleGranting;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\RbacServices;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Giving one role on several places at once (BAJAAPP-91):
 * RoleGranting::grantOnMany().
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The role page (juiz/admin_usuario.php) used to give a role on ONE place
 * per submission. Making someone a judge of three provas meant filling in
 * the form three times. Now the form has a checkbox per place, and the page
 * hands the ticked places to grantOnMany().
 *
 * A "grant" is one row of user_role: this user has this role on this place
 * (a prova, an event, a program, or the whole platform), until some date.
 * Every grant also leaves one row in role_audit, the record of who gave
 * what to whom.
 *
 * WHAT grantOnMany() PROMISES, and what each test below checks:
 *
 *   - each place is treated exactly as grant() treats one place: the same
 *     five delegation rules (DelegationTest explains them), the same end
 *     date, one grant row and one audit row;
 *   - ALL OR NOTHING: if even one place is refused, nothing at all is
 *     written, and the refusal lists every refused place with its reason;
 *   - an event ticked together with one of its provas gives the role on
 *     the event only. A grant on the event already reaches all its provas,
 *     so a second grant on the prova would add nothing;
 *   - it never lets you reach a place you could not reach one at a time;
 *   - at most RoleGranting::MAX_PLACES_AT_ONCE (200) places per call.
 *
 * The made-up events, provas and users are described in RbacDbTestCase.
 * In short: 96BR (provas ZZA, ZZB) and 96SE (prova ZZA, no end date) are
 * Baja SAE events; 96AR (prova ZZA) is an AeroDesign one.
 */
#[Group('rbac')]
final class GrantOnManyTest extends RbacDbTestCase
{
    /**
     * Calls grantOnMany() as $granter, giving $role to $user on $nodes.
     *
     * @param ScopeNode[] $nodes
     */
    private function grantOnMany(string $granter, string $user, string $role, array $nodes, ?string $validUntil = null): GrantBatch
    {
        return $this->rbac->granting->grantOnMany($this->user($granter), $this->user($user), $role, $nodes, $validUntil === null ? null : self::at($validUntil));
    }

    /**
     * Runs $attempt, which must be refused place by place, and returns the
     * refused places as "key => reason". Also checks that NOTHING was
     * written: no grant and no audit row, anywhere.
     *
     * @return array<string, string>
     */
    private function refusedPlaces(callable $attempt): array
    {
        $grantsBefore = $this->countAll('user_role');
        $auditBefore = $this->countAll('role_audit');
        try {
            $attempt();
            $this->fail('Expected some places to be refused, but everything was allowed.');
        } catch (BatchRefused $e) {
            $refused = [];
            foreach ($e->refusals as $r) {
                $refused[$r['node']->key()] = $r['refused']->reason;
            }
        }
        $this->assertSame($grantsBefore, $this->countAll('user_role'), 'A refused batch wrote a grant.');
        $this->assertSame($auditBefore, $this->countAll('role_audit'), 'A refused batch wrote an audit row.');
        return $refused;
    }

    private function countAll(string $table): int
    {
        return (int) $this->con->query("SELECT COUNT(*) FROM $table")->fetchAll(PDO::FETCH_COLUMN)[0];
    }

    /** @return string[] "type:id" of every grant the user has, in the order written */
    private function placesOf(string $user): array
    {
        return array_map(fn(array $g) => $g['scope_type'] . ':' . $g['scope_id'], $this->grantRows($user));
    }

    // --- Several places, one submission ---------------------------------------------

    /**
     * The everyday case: a platform admin makes someone a judge of three
     * provas (in two events) at once. Three grants, three audit rows, and
     * each grant ends when grant() would end it: 7 days after ITS event.
     */
    public function testThreePlacesGiveThreeGrantsAndThreeAuditRows(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $places = [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB'), ScopeNode::prova('96AR', 'ZZA')];

        $batch = $this->grantOnMany('admin', 'judge', 'judge', $places);

        $this->assertSame(['prova:96BR/ZZA', 'prova:96BR/ZZB', 'prova:96AR/ZZA'], $this->placesOf('judge'));
        $this->assertCount(3, $batch->granted);
        $this->assertSame([], $batch->covered);
        foreach ($this->grantRows('judge') as $row) {
            // Both events end on 12 March 2099; + 7 days, at the end of the day.
            $this->assertSame('2099-03-19 23:59:59', $row['valid_until']);
            $this->assertSame($this->user('admin'), (int) $row['granted_by']);
        }

        $audit = $this->auditRows();
        $this->assertCount(3, $audit);
        $this->assertSame($batch->grantIds(), array_map(fn(array $r) => (int) $r['grant_id'], $audit), 'one audit row per grant, naming it');
        foreach ($audit as $row) {
            $this->assertSame(AuditLog::GRANT, $row['action']);
            // Exactly the details a single grant() writes.
            $this->assertSame(['role' => 'judge', 'valid_until' => '2099-03-19 23:59:59'], json_decode($row['details'], true));
        }

        foreach ($places as $place) {
            $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, $place));
        }
    }

    /** An end date given on the form is the end of every grant of the batch. */
    public function testAnEndDateAppliesToEveryPlace(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->grantOnMany('admin', 'judge', 'judge', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96SE', 'ZZA')], '2099-06-30 23:59:59');

        $this->assertSame(['2099-06-30 23:59:59', '2099-06-30 23:59:59'], array_column($this->grantRows('judge'), 'valid_until'));
    }

    // --- All or nothing --------------------------------------------------------------

    /**
     * An event manager of 96BR ticks two provas of 96BR and one of 96AR,
     * an event they have nothing to do with. The 96AR one is refused, and
     * so NOTHING is given, not even the two they could give. The refusal
     * names 96AR's prova, and only it.
     */
    public function testOneRefusedPlaceAmongThreeGivesNothingAndNamesIt(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('evt', 'judge', 'judge', [
            ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB'), ScopeNode::prova('96AR', 'ZZA'),
        ]));

        $this->assertSame(['prova:96AR/ZZA' => GrantRefused::NO_ROLE_GRANT], $refused);
        $this->assertSame([], $this->grantRows('judge'));
        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /**
     * Every refused place is listed, each with its OWN reason: here 96SE's
     * prova because 96SE has no end date (so no default end), and 96AR's
     * because a Baja program manager has no say in AeroDesign.
     */
    public function testEveryRefusedPlaceIsListedWithItsOwnReason(): void
    {
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));

        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('baja', 'judge', 'judge', [
            ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96SE', 'ZZA'), ScopeNode::prova('96AR', 'ZZA'),
        ]));

        $this->assertSame([
            'prova:96SE/ZZA' => GrantRefused::VALID_UNTIL_REQUIRED,
            'prova:96AR/ZZA' => GrantRefused::NO_ROLE_GRANT,
        ], $refused);
    }

    /**
     * The writing itself fails half way (here: the audit log works for the
     * first grant and breaks on the second). The transaction is rolled
     * back: none of the three grants is left, not even the first.
     */
    public function testAFailureWhileWritingLeavesNoGrantAtAll(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $breaksOnTheSecond = new class(new DatabaseAuditLog($this->con)) implements AuditLog {
            private int $calls = 0;

            public function __construct(private AuditLog $real)
            {
            }

            public function record(string $action, ?int $actorUserId, DateTimeImmutable $occurredAt, ?int $grantId = null, ?int $roleId = null, ?int $targetUserId = null, ?ScopeNode $scope = null, array $details = []): void
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('The audit table is unavailable.');
                }
                $this->real->record($action, $actorUserId, $occurredAt, $grantId, $roleId, $targetUserId, $scope, $details);
            }
        };
        $rbac = RbacServices::create($this->con, $this->clock, fn() => null, $breaksOnTheSecond);

        try {
            $rbac->granting->grantOnMany($this->user('admin'), $this->user('judge'), 'judge', [
                ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB'), ScopeNode::prova('96AR', 'ZZA'),
            ]);
            $this->fail('The batch went through although an audit row could not be written.');
        } catch (RuntimeException $e) {
            $this->assertSame('The audit table is unavailable.', $e->getMessage());
        }

        $this->assertSame([], $this->grantRows('judge'), 'the first grant was rolled back with the rest');
        $this->assertSame([], $this->auditRows(), "and so was the first grant's audit row");
    }

    // --- A place and one below it ------------------------------------------------------

    /**
     * An event and one of its provas, both ticked: only the event gets a
     * grant, and the result says the prova was left out because of it.
     * The person still ends up able to work on the prova, through the event.
     *
     * The role must be one that may be given on an event AND on a prova for
     * both to be fair choices, so the test makes one (zz_scorer).
     */
    public function testAnEventAndOneOfItsProvasGrantOnlyTheEvent(): void
    {
        $this->createRole('zz_scorer', [ScopeType::EVENTO, ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES]);
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $batch = $this->grantOnMany('admin', 'scorer', 'zz_scorer', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::evento('96BR')]);

        $this->assertSame(['evento:96BR'], $this->placesOf('scorer'));
        $this->assertCount(1, $this->auditRows());
        $this->assertCount(1, $batch->covered);
        $this->assertSame('prova:96BR/ZZA', $batch->covered[0]['node']->key());
        $this->assertSame('evento:96BR', $batch->covered[0]['by']->key());
        $this->assertTrue($this->can('scorer', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /**
     * Three levels ticked (the program, an event of it, a prova of that
     * event): only the program is granted. Both lower places are reported
     * as covered by the PROGRAM, the place that was actually granted.
     */
    public function testWithThreeLevelsTickedOnlyTheTopOneIsGranted(): void
    {
        $this->createRole('zz_scorer', [ScopeType::PROGRAMA, ScopeType::EVENTO, ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES]);
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $batch = $this->grantOnMany('admin', 'scorer', 'zz_scorer', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::evento('96BR'), ScopeNode::programa('BAJA')]);

        $this->assertSame(['programa:BAJA'], $this->placesOf('scorer'));
        $this->assertSame(
            ['prova:96BR/ZZA' => 'programa:BAJA', 'evento:96BR' => 'programa:BAJA'],
            array_combine(
                array_map(fn(array $c) => $c['node']->key(), $batch->covered),
                array_map(fn(array $c) => $c['by']->key(), $batch->covered)
            )
        );
    }

    /** Two provas of the same event are siblings, not one above the other: both are granted. */
    public function testSiblingsAreBothGranted(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $batch = $this->grantOnMany('admin', 'judge', 'judge', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB')]);

        $this->assertCount(2, $batch->granted);
        $this->assertSame([], $batch->covered);
    }

    /** The same place sent twice (a forged form) is granted once. */
    public function testTheSamePlaceTwiceIsGrantedOnce(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->grantOnMany('admin', 'judge', 'judge', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZA')]);

        $this->assertSame(['prova:96BR/ZZA'], $this->placesOf('judge'));
    }

    // --- No way around the rules -------------------------------------------------------

    /**
     * A head judge of 96BR/ZZA may make judges on ZZA, and nowhere else.
     * Putting ZZA in the same batch as other places does not lend them any
     * more reach: the batch refuses exactly the places that grant() refuses
     * one at a time, for the same reasons.
     *
     * The places tried: their own prova, the sibling prova, the event above
     * it, a prova of another event, and the platform. zz_anywhere is a role
     * that may be given on any of those and carries only prova.enter_scores,
     * which the head judge holds on ZZA, so that the only thing in the way
     * elsewhere is where they stand, not the role.
     */
    public function testTheBatchReachesNoPlaceThatASingleGrantCouldNot(): void
    {
        $this->createRole('zz_anywhere', [ScopeType::PLATFORM, ScopeType::EVENTO, ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES]);
        $this->giveDirectly($this->user('head'), 'head_judge', ScopeNode::prova('96BR', 'ZZA'));
        $places = [
            ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB'), ScopeNode::evento('96BR'),
            ScopeNode::prova('96AR', 'ZZA'), ScopeNode::platform(),
        ];

        // One at a time, through grant(): which places are refused, and why.
        $singly = [];
        foreach ($places as $place) {
            try {
                $this->rbac->granting->grant($this->user('head'), $this->user('probe'), 'zz_anywhere', $place);
            } catch (GrantRefused $e) {
                $singly[$place->key()] = $e->reason;
            }
        }
        // Only their own prova went through singly. Take that grant away
        // again (test rows only) so the batch starts from nothing.
        $this->assertSame(['prova:96BR/ZZA'], $this->placesOf('probe'));
        $this->con->prepare('DELETE FROM user_role WHERE user_id = ?')->execute([$this->user('probe')]);

        // Leave out the places with ZZA under them: those would make the
        // batch skip ZZA instead of checking it alongside the others.
        $batchPlaces = [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB'), ScopeNode::prova('96AR', 'ZZA')];
        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('head', 'probe', 'zz_anywhere', $batchPlaces));
        $this->assertSame(
            array_intersect_key($singly, $refused),
            $refused,
            'the same places refused, for the same reasons'
        );
        $this->assertSame(['prova:96BR/ZZB', 'prova:96AR/ZZA'], array_keys($refused));

        // With the event (above ZZA) in the batch, ZZA is left out and the
        // event is checked instead, and refused: ticking a place you hold
        // under a place you do not hold does not get you the latter.
        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('head', 'probe', 'zz_anywhere', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::evento('96BR')]));
        $this->assertSame(['evento:96BR' => $singly['evento:96BR']], $refused);

        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('head', 'probe', 'zz_anywhere', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::platform()]));
        $this->assertSame(['platform:' => $singly['platform:']], $refused);
    }

    /**
     * Holding the role somewhere does not let you hand on more: a judge
     * (no role.grant) can give nothing, batch or not.
     */
    public function testSomebodyWhoMayNotGiveRolesGetsEveryPlaceRefused(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('judge', 'x', 'judge', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB')]));

        $this->assertSame(['prova:96BR/ZZA' => GrantRefused::NO_ROLE_GRANT, 'prova:96BR/ZZB' => GrantRefused::NO_ROLE_GRANT], $refused);
    }

    // --- A place where the user already has the role -------------------------------

    /**
     * What grant() does today when the user already holds the role on the
     * place: it makes ANOTHER grant (grants are never merged; each has its
     * own end and its own audit row). The batch does the same, place by
     * place. This test pins both, so that changing one without the other
     * shows up.
     */
    public function testAPlaceWhereTheUserAlreadyHasTheRoleGetsAnotherGrantAsWithGrant(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->rbac->granting->grant($this->user('admin'), $this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));
        $this->assertSame(['prova:96BR/ZZA', 'prova:96BR/ZZA'], $this->placesOf('judge'), 'grant(): a second grant on the same place');

        $batch = $this->grantOnMany('admin', 'judge', 'judge', [ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB')]);
        $this->assertCount(2, $batch->granted);
        $this->assertSame(['prova:96BR/ZZA', 'prova:96BR/ZZA', 'prova:96BR/ZZA', 'prova:96BR/ZZB'], $this->placesOf('judge'), 'the batch: one more on each place');
    }

    // --- The request as a whole ---------------------------------------------------------

    /**
     * At most 200 places at once. 201 is refused as a whole, before any
     * place is looked at, and nothing is written. 200 is not stopped by the
     * cap (these made-up provas do not exist, so each is then refused as
     * unknown, which shows the cap let them through).
     */
    public function testAtMost200PlacesAtOnce(): void
    {
        $this->assertSame(200, RoleGranting::MAX_PLACES_AT_ONCE);
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $places = fn(int $n) => array_map(fn(int $i) => ScopeNode::prova('96BR', sprintf('Z%02d', $i)), range(1, $n));

        $auditBefore = $this->countAll('role_audit');
        try {
            $this->grantOnMany('admin', 'judge', 'judge', $places(201));
            $this->fail('201 places were accepted.');
        } catch (GrantRefused $e) {
            $this->assertSame(GrantRefused::TOO_MANY_PLACES, $e->reason);
        }
        $this->assertSame([], $this->grantRows('judge'));
        $this->assertSame($auditBefore, $this->countAll('role_audit'));

        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('admin', 'judge', 'judge', $places(200)));
        $this->assertCount(200, $refused);
        $this->assertSame([GrantRefused::UNKNOWN_NODE], array_values(array_unique($refused)));

        // The same place twice counts once: 200 different places and one
        // repeated is still 200.
        $refused = $this->refusedPlaces(fn() => $this->grantOnMany('admin', 'judge', 'judge', [...$places(200), ScopeNode::prova('96BR', 'Z01')]));
        $this->assertCount(200, $refused);
    }

    /** No place at all is refused, with its own reason. */
    public function testNoPlaceIsRefused(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        try {
            $this->grantOnMany('admin', 'judge', 'judge', []);
            $this->fail('An empty batch was accepted.');
        } catch (GrantRefused $e) {
            $this->assertSame(GrantRefused::NO_PLACES, $e->reason);
        }
    }

    /** An unknown role is refused for the whole request, not place by place. */
    public function testAnUnknownRoleIsRefusedAsAWhole(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        try {
            $this->grantOnMany('admin', 'judge', 'zz_no_such_role', [ScopeNode::prova('96BR', 'ZZA')]);
            $this->fail('An unknown role was accepted.');
        } catch (GrantRefused $e) {
            $this->assertSame(GrantRefused::UNKNOWN_ROLE, $e->reason);
        }
        $this->assertSame([], $this->grantRows('judge'));
    }
}
