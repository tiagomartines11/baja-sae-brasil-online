<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * "May this user do this, here?" on the real tables (BAJAAPP-12).
 *
 * BACKGROUND
 *
 * A user may do something on a node when they have an active grant, on
 * that node or on a node ABOVE it, of a role that carries the permission.
 * Everything else is no. These tests are the proof of each part of that
 * sentence, because each part, got wrong, gives someone access they
 * should not have:
 *
 *   - "default deny": without a grant, nothing;
 *   - "on that node or above": a grant reaches down the tree, never up,
 *     never sideways. A judge of one prova cannot touch the next prova; an
 *     event manager cannot touch the program; a Baja manager cannot touch
 *     AeroDesign, while an AeroDesign manager does reach the Fórum
 *     AeroDesign events, which are part of AeroDesign;
 *   - "active": revoked and expired grants count for nothing;
 *   - "a role that carries the permission": a judge cannot edit results;
 *   - and the permission must make sense for the node: entering scores is
 *     asked about a prova, never about a whole event;
 *   - a node that does not exist denies, and is logged;
 *   - nobody is special: the platform admin is just a role on the platform
 *     node, and the legacy 'admin' code means nothing here.
 *
 * The world (events 96BR, 96SE, 96AR, 96FA and their provas and queues) is
 * described in RbacDbTestCase.
 */
#[Group('rbac')]
final class AuthorizationTest extends RbacDbTestCase
{
    /** Every node of the made-up world, top first. */
    private function everyNode(): array
    {
        return [
            ScopeNode::platform(),
            ScopeNode::programa('BAJA'), ScopeNode::programa('AERO'), ScopeNode::programa('AERO_FORUM'),
            ScopeNode::evento('96BR'), ScopeNode::evento('96AR'), ScopeNode::evento('96FA'),
            ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB'), ScopeNode::prova('96FA', 'ZZA'),
            ScopeNode::fila('96BR', 1), ScopeNode::fila('96BR', 2),
        ];
    }

    /** True when some permission that applies to the node's type is allowed there. */
    private function canDoAnythingAt(string $user, ScopeNode $node): bool
    {
        foreach (Permission::cases() as $permission) {
            if ($this->can($user, $permission, $node)) {
                return true;
            }
        }
        return false;
    }

    // --- Default deny -----------------------------------------------------------

    /** A user with no grant may do nothing, anywhere. */
    public function testWithoutAGrantEverythingIsDenied(): void
    {
        foreach ($this->everyNode() as $node) {
            foreach (Permission::cases() as $permission) {
                $this->assertFalse($this->can('nobody', $permission, $node), "{$permission->value} on {$node->key()}");
            }
        }
    }

    // --- A grant on each kind of node reaches down, never up or sideways ----------

    /** The platform admin role on the platform reaches every node. */
    public function testAGrantOnThePlatformReachesEverything(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        foreach ($this->everyNode() as $node) {
            $this->assertTrue($this->canDoAnythingAt('admin', $node), $node->key());
        }
        $this->assertTrue($this->can('admin', Permission::RESULTADO_EDIT, ScopeNode::evento('96AR')));
        $this->assertTrue($this->can('admin', Permission::ROLE_MANAGE, ScopeNode::platform()));
    }

    /** A program manager of BAJA reaches BAJA's events, provas and queues, and nothing else. */
    public function testAGrantOnAProgramReachesItsEventsAndNotOthersOrThePlatform(): void
    {
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));

        $this->assertTrue($this->can('baja', Permission::EVENTO_MANAGE, ScopeNode::programa('BAJA')));
        $this->assertTrue($this->can('baja', Permission::EVENTO_MANAGE, ScopeNode::evento('96BR')));
        $this->assertTrue($this->can('baja', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
        $this->assertTrue($this->can('baja', Permission::FILA_MANAGE, ScopeNode::fila('96BR', 2)));

        // Not up: the platform.
        $this->assertFalse($this->canDoAnythingAt('baja', ScopeNode::platform()));
        // Not sideways: the other programs and their events.
        $this->assertFalse($this->canDoAnythingAt('baja', ScopeNode::programa('AERO')));
        $this->assertFalse($this->canDoAnythingAt('baja', ScopeNode::evento('96AR')));
    }

    /** An event manager of 96BR reaches its provas and queues, and not the program, nor another event. */
    public function testAGrantOnAnEventReachesItsProvasAndQueuesOnly(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $this->assertTrue($this->can('evt', Permission::RESULTADO_EDIT, ScopeNode::evento('96BR')));
        $this->assertTrue($this->can('evt', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
        $this->assertTrue($this->can('evt', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZB')));
        $this->assertTrue($this->can('evt', Permission::FILA_MANAGE, ScopeNode::fila('96BR', 1)));

        $this->assertFalse($this->canDoAnythingAt('evt', ScopeNode::programa('BAJA')), 'Not up to the program.');
        $this->assertFalse($this->canDoAnythingAt('evt', ScopeNode::evento('96SE')), 'Not sideways to another event of the same program.');
        $this->assertFalse($this->can('evt', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96SE', 'ZZA')));
    }

    /** A judge of prova ZZA reaches ZZA, not its sibling ZZB, not the event. */
    public function testAGrantOnAProvaReachesThatProvaOnly(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));

        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));

        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZB')), 'Not the sibling prova.');
        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96SE', 'ZZA')), 'Not the prova with the same code at another event.');
        $this->assertFalse($this->canDoAnythingAt('judge', ScopeNode::evento('96BR')), 'Not up to the event.');
        $this->assertFalse($this->canDoAnythingAt('judge', ScopeNode::fila('96BR', 1)), 'Not sideways to a queue.');
    }

    /** A queue manager of queue 1 runs queue 1, not queue 2, not the event. */
    public function testAGrantOnAQueueReachesThatQueueOnly(): void
    {
        $this->giveDirectly($this->user('queue'), 'queue_manager', ScopeNode::fila('96BR', 1));

        $this->assertTrue($this->can('queue', Permission::FILA_MANAGE, ScopeNode::fila('96BR', 1)));

        $this->assertFalse($this->can('queue', Permission::FILA_MANAGE, ScopeNode::fila('96BR', 2)), 'Not the sibling queue.');
        $this->assertFalse($this->can('queue', Permission::FILA_MANAGE, ScopeNode::evento('96BR')), 'Not up: creating queues on the event.');
        $this->assertFalse($this->canDoAnythingAt('queue', ScopeNode::prova('96BR', 'ZZA')));
    }

    // --- Programs under programs ----------------------------------------------------

    /** AeroDesign contains the Fórum AeroDesign: an AERO grant reaches the forum's events. */
    public function testAnAeroGrantReachesTheForumEvents(): void
    {
        $this->giveDirectly($this->user('aero'), 'program_manager', ScopeNode::programa('AERO'));

        $this->assertTrue($this->can('aero', Permission::EVENTO_MANAGE, ScopeNode::programa('AERO_FORUM')));
        $this->assertTrue($this->can('aero', Permission::EVENTO_MANAGE, ScopeNode::evento('96FA')));
        $this->assertTrue($this->can('aero', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96FA', 'ZZA')));
        $this->assertTrue($this->can('aero', Permission::EVENTO_MANAGE, ScopeNode::evento('96AR')));
    }

    /** The other way does not hold: a forum grant does not reach AeroDesign's own events. */
    public function testAForumGrantDoesNotReachAeroDesign(): void
    {
        $this->giveDirectly($this->user('forum'), 'program_manager', ScopeNode::programa('AERO_FORUM'));

        $this->assertTrue($this->can('forum', Permission::EVENTO_MANAGE, ScopeNode::evento('96FA')));
        $this->assertFalse($this->canDoAnythingAt('forum', ScopeNode::programa('AERO')));
        $this->assertFalse($this->canDoAnythingAt('forum', ScopeNode::evento('96AR')));
    }

    /** A Baja SAE grant reaches no AeroDesign event at all, forum included. */
    public function testABajaGrantReachesNoAeroEvent(): void
    {
        $this->giveDirectly($this->user('baja'), 'program_manager', ScopeNode::programa('BAJA'));

        foreach ([ScopeNode::programa('AERO'), ScopeNode::programa('AERO_FORUM'), ScopeNode::evento('96AR'),
                  ScopeNode::evento('96FA'), ScopeNode::prova('96AR', 'ZZA'), ScopeNode::prova('96FA', 'ZZA')] as $node) {
            $this->assertFalse($this->canDoAnythingAt('baja', $node), $node->key());
        }
    }

    // --- Only active grants count ------------------------------------------------------

    /** A grant past its valid_until gives nothing. */
    public function testAnExpiredGrantDenies(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-02-01 00:00:00');

        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /** A grant that ends exactly now has ended. One second before, it still worked. */
    public function testAGrantEndsAtItsValidUntil(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'), self::NOW);
        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));

        $this->clock->moveTo('2099-03-01 09:59:59');
        $this->newRequest();
        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /** A grant that ends in the future works until then. */
    public function testAGrantThatEndsLaterWorksNow(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-19 23:59:59');

        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    /** A revoked grant gives nothing, even though its row is still there. */
    public function testARevokedGrantDenies(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'), null, '2099-02-15 12:00:00');

        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
        $this->assertCount(1, $this->grantRows('judge'), 'The row stays.');
    }

    /**
     * One request that goes on past a grant's end stops using it: expiry is
     * checked against the clock on every question, not only when the
     * grants were first read.
     */
    public function testExpiryIsCheckedOnEveryQuestionNotOnlyTheFirst(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'), '2099-03-01 10:30:00');
        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));

        $this->clock->moveTo('2099-03-01 10:30:00');
        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }

    // --- The role must carry the permission ----------------------------------------------

    /** A judge can enter scores but not edit results, configure the prova or give roles. */
    public function testARoleGivesOnlyItsOwnPermissions(): void
    {
        $this->giveDirectly($this->user('judge'), 'judge', ScopeNode::prova('96BR', 'ZZA'));
        $prova = ScopeNode::prova('96BR', 'ZZA');

        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, $prova));
        $this->assertFalse($this->can('judge', Permission::PROVA_CONFIGURE, $prova));
        $this->assertFalse($this->can('judge', Permission::ROLE_GRANT, $prova));
        $this->assertFalse($this->can('judge', Permission::CONTAGEM_COUNT, $prova));
    }

    /** An event manager has no certificates: those belong to the program. */
    public function testAnEventManagerCannotManageCertificates(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('96BR'));

        $this->assertFalse($this->can('evt', Permission::CERTIFICADO_MANAGE, ScopeNode::evento('96BR')));
    }

    // --- The permission must apply to the node's type -------------------------------------

    /**
     * Even the platform admin is refused "enter scores" asked about a whole
     * event, or "edit roles" asked about a program: those questions are
     * mistakes in the calling code, and a mistake must deny.
     */
    public function testAPermissionAskedAboutTheWrongKindOfNodeDenies(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->assertFalse($this->can('admin', Permission::PROVA_ENTER_SCORES, ScopeNode::evento('96BR')));
        $this->assertFalse($this->can('admin', Permission::RESULTADO_EDIT, ScopeNode::prova('96BR', 'ZZA')));
        $this->assertFalse($this->can('admin', Permission::ROLE_MANAGE, ScopeNode::programa('BAJA')));
        $this->assertFalse($this->can('admin', Permission::EVENTO_MANAGE, ScopeNode::platform()));
    }

    // --- Unknown nodes -------------------------------------------------------------------

    /** Nodes that do not exist: denied for the platform admin too, and logged. */
    public function testANodeThatDoesNotExistDeniesAndIsLogged(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        foreach ([
            ScopeNode::evento('00XX'),
            ScopeNode::prova('96BR', 'NOP'),
            ScopeNode::prova('00XX', 'ZZA'),
            ScopeNode::fila('96BR', 99),
            ScopeNode::programa('NAO_EXISTE'),
            new ScopeNode(ScopeType::PROVA, 'malformed'),
        ] as $node) {
            $this->warnings = [];
            $this->assertFalse($this->canDoAnythingAt('admin', $node), $node->key());
            $this->assertNotEmpty($this->warnings, "Nothing was logged for {$node->key()}.");
        }
    }

    /**
     * MySQL would find event 96BR when asked for "96br". The tree does not:
     * one event has one spelling, so a grant stored under another spelling
     * can never be confused with it.
     */
    public function testAnIdSpelledDifferentlyIsUnknown(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());

        $this->assertFalse($this->canDoAnythingAt('admin', ScopeNode::evento('96br')));
        $this->assertFalse($this->canDoAnythingAt('admin', ScopeNode::prova('96BR', 'zza')));
        $this->assertFalse($this->canDoAnythingAt('admin', ScopeNode::fila('96BR', '01')));
    }

    /** A grant whose event was deleted reaches nothing. */
    public function testAGrantOnANodeThatNoLongerExistsReachesNothing(): void
    {
        $this->giveDirectly($this->user('evt'), 'event_manager', ScopeNode::evento('00XX'));

        $this->assertFalse($this->canDoAnythingAt('evt', ScopeNode::evento('96BR')));
    }

    // --- Nobody is special ----------------------------------------------------------------

    /**
     * The legacy 'admin' code is not looked at: a user who has it but no
     * grant may do nothing through RBAC. (Their legacy pages still work
     * through Session::hasPermission until phase 3.)
     */
    public function testTheLegacyAdminCodeMeansNothingHere(): void
    {
        $id = $this->user('legacy');
        $this->con->prepare("UPDATE user SET permissions = '| index | admin |' WHERE user_id = ?")->execute([$id]);

        foreach ($this->everyNode() as $node) {
            $this->assertFalse($this->canDoAnythingAt('legacy', $node), $node->key());
        }
    }

    /**
     * The platform admin is a role like any other: take its permission away
     * and the admin loses it, on the next request.
     */
    public function testThePlatformAdminIsJustARole(): void
    {
        $this->giveDirectly($this->user('admin'), 'platform_admin', ScopeNode::platform());
        $this->assertTrue($this->can('admin', Permission::RESULTADO_EDIT, ScopeNode::evento('96BR')));

        $this->con->beginTransaction();
        try {
            $this->con->exec("DELETE rp FROM role_permission rp JOIN role r ON r.role_id = rp.role_id WHERE r.code = 'platform_admin' AND rp.permission = 'resultado.edit'");
            $this->newRequest();
            $this->assertFalse($this->can('admin', Permission::RESULTADO_EDIT, ScopeNode::evento('96BR')));
        } finally {
            // The real role is shared with the database: put it back as it was.
            $this->con->rollBack();
        }
    }

    // --- Per-request caching --------------------------------------------------------------

    /**
     * Within one request the first answer stands; the next request sees a
     * grant made meanwhile. Nothing is cached anywhere else.
     */
    public function testAGrantMadeElsewhereShowsOnTheNextRequest(): void
    {
        $prova = ScopeNode::prova('96BR', 'ZZA');
        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, $prova));

        $this->giveDirectly($this->user('judge'), 'judge', $prova);
        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, $prova), 'Same request: still the first answer.');

        $this->newRequest();
        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, $prova), 'Next request: the grant counts.');
    }

    /** The same for a revoke made elsewhere. */
    public function testARevokeMadeElsewhereShowsOnTheNextRequest(): void
    {
        $prova = ScopeNode::prova('96BR', 'ZZA');
        $grantId = $this->giveDirectly($this->user('judge'), 'judge', $prova);
        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, $prova));

        $this->con->prepare("UPDATE user_role SET revoked_at = '2099-03-01 09:00:00' WHERE grant_id = ?")->execute([$grantId]);
        $this->newRequest();

        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, $prova));
    }

    /**
     * A role whose allowed node types were narrowed after it was given: the
     * grant on a type it may no longer be given on does nothing.
     */
    public function testAGrantThatCouldNotBeMadeTodayDoesNothing(): void
    {
        $this->createRole('zz_any', [ScopeType::EVENTO, ScopeType::PROVA], [Permission::PROVA_ENTER_SCORES]);
        $this->giveDirectly($this->user('judge'), 'zz_any', ScopeNode::evento('96BR'));
        $this->assertTrue($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));

        $this->con->exec("UPDATE role SET allowed_scope_types = 'prova' WHERE code = 'zz_any'");
        $this->newRequest();

        $this->assertFalse($this->can('judge', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
    }
}
