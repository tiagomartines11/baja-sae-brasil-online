<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Access\CounterLinks;
use Baja\Model\UserQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use PDO;
use Propel\Runtime\Propel;

/**
 * Counter links: who is allowed to count on which source.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Three volunteers count the race, each on their own "source" (Contagem 1,
 * 2 and 3). They must stay independent: if a volunteer could open any
 * source, two of them might end up typing into the same one and the
 * cross-check between sources would be worthless. So a volunteer only gets
 * into a source the person in charge of the prova (the "controller")
 * linked them to.
 *
 * The controller is usually a judge, not a system administrator, and has
 * to be able to do this alone on race morning. Linking somebody therefore
 * also takes care of the paperwork:
 *
 *   - if the volunteer has a forum account but has never used this system,
 *     their row in the `user` table is created;
 *   - they receive the role "lap_counter" on the prova 00NE/ZZL, which is
 *     what lets them into the count area (before RBAC, BAJAAPP-76, this
 *     was the code "00NE_ZZL_CONT"). The controller gives it on their own
 *     authority (contagem.control), with no right to give roles in general;
 *   - when their last link in the prova is removed, that role is revoked,
 *     and the phones they were using stop being accepted.
 *
 * Users in these tests are called "zzt_..." so they can be deleted
 * afterwards. Whether a username exists in the forum is normally looked
 * up in the forum's own database; here that lookup is replaced by a tiny
 * stand-in that knows two names, zzt_ana and zzt_bruno.
 */
final class CounterLinksTest extends DbTestCase
{
    private const CONTROLLER = 'zzt_controlador';
    /** What a linked counter holds: the lap_counter role on the test prova. */
    private const COUNTER_ROLE = 'lap_counter@prova:00NE/ZZL';

    protected function setUp(): void
    {
        parent::setUp();

        // The controller of the prova: the judge role on it (the legacy code
        // 00NE_ZZL) and, on purpose, NOT the platform admin role.
        $this->createUser(self::CONTROLLER, ['index', '00NE_ZZL']);

        // Stand-in for the forum: these two people have a forum account,
        // nobody else does.
        CounterLinks::setForumLookup(function (string $username): ?array {
            $knownInForum = ['zzt_ana', 'zzt_bruno'];
            return in_array(strtolower($username), $knownInForum, true) ? ['username' => $username] : null;
        });
    }

    /**
     * SITUATION: the controller of the prova, who is not an administrator,
     * adds volunteer Ana to Contagem 1 and later removes her.
     *
     * EXPECTED: both work. The list of counters of Contagem 1 shows Ana
     * after the first step and is empty after the second. Being an
     * administrator is not required for any of it.
     */
    public function testAControllerWhoIsNotAnAdministratorCanLinkAndUnlinkCounters(): void
    {
        $this->loginAs(self::CONTROLLER);
        $this->assertTrue(ContagemAccess::isController($this->prova), 'the test user controls this prova');
        $this->assertFalse(
            Authorization::currentUserCan(Permission::ROLE_GRANT, ScopeNode::prova(self::EVENTO_ID, self::PROVA_ID)),
            'and may not give roles in general'
        );

        CounterLinks::link($this->fontes[0], 'zzt_ana', self::CONTROLLER);
        $this->assertSame(['zzt_ana'], $this->usernamesLinkedTo(0));

        CounterLinks::unlink($this->fontes[0], 'zzt_ana', self::CONTROLLER);
        $this->assertSame([], $this->usernamesLinkedTo(0));
    }

    /**
     * SITUATION: Ana has a forum account but has never used this system,
     * so there is no row for her in the `user` table. The controller links
     * her to Contagem 1.
     *
     * EXPECTED: her row now exists, and she holds the lap_counter role on
     * the prova, given by the controller (the audit trail says who). Logged
     * in, she counts as a counter of the prova and NOT as its controller.
     */
    public function testLinkingCreatesTheUserRowAndGrantsTheCounterPermission(): void
    {
        $this->assertNull(UserQuery::create()->findOneByUsername('zzt_ana'), 'Ana is new to this system');

        CounterLinks::link($this->fontes[0], 'zzt_ana', self::CONTROLLER);

        $this->assertSame([self::COUNTER_ROLE], $this->activeRolesOf('zzt_ana'));
        $this->assertSame([self::CONTROLLER], $this->grantedBy('zzt_ana'), 'the grant names the controller');

        $this->loginAs('zzt_ana');
        $this->assertTrue(ContagemAccess::isCounter($this->prova));
        $this->assertFalse(ContagemAccess::isController($this->prova));
    }

    /**
     * SITUATION: Bruno is already a judge of another prova (he holds
     * "00NE_XYZ"). The controller links him to Contagem 1 as well.
     *
     * EXPECTED: he gains the counter role and keeps what he had. Linking
     * only ever adds that one role.
     */
    public function testLinkingAnExistingUserKeepsTheirOtherPermissions(): void
    {
        $this->createUser('zzt_bruno', ['index', '00NE_XYZ']);

        CounterLinks::link($this->fontes[0], 'zzt_bruno', self::CONTROLLER);

        // 00NE_XYZ is not a prova of the test event, so it gave him nothing
        // (the migration lists such codes); he only gains the counter role.
        $this->assertSame([self::COUNTER_ROLE], $this->activeRolesOf('zzt_bruno'));
        // And a second link to another source does not give it twice.
        CounterLinks::link($this->fontes[1], 'zzt_bruno', self::CONTROLLER);
        $this->assertSame([self::COUNTER_ROLE], $this->activeRolesOf('zzt_bruno'));
    }

    /**
     * SITUATION: the controller mistypes a name: "zzt_anna" (two n's).
     * Nobody with that name exists in the forum.
     *
     * EXPECTED: the link is refused with a message for the controller, and
     * nothing is created: no user row for a person who could never log in,
     * and no link.
     */
    public function testAnUnknownForumUsernameIsRefused(): void
    {
        $refusalMessage = null;
        try {
            CounterLinks::link($this->fontes[0], 'zzt_anna', self::CONTROLLER);
        } catch (\InvalidArgumentException $refusal) {
            $refusalMessage = $refusal->getMessage();
        }

        $this->assertNotNull($refusalMessage, 'the link must be refused');
        $this->assertStringContainsString('não existe no fórum', $refusalMessage);
        $this->assertNull(UserQuery::create()->findOneByUsername('zzt_anna'));
        $this->assertSame([], $this->usernamesLinkedTo(0));
    }

    /**
     * SITUATION: Ana is linked to Contagem 1 and Contagem 2 and has opened
     * the count page of each on her phone, so the phone holds one upload
     * token per source. The controller removes her from Contagem 1, and
     * later from Contagem 2 too.
     *
     * EXPECTED, after the first removal: her phone is no longer accepted
     * on Contagem 1 (HTTP 401), still accepted on Contagem 2, and she keeps
     * the counter role because she still has a source.
     *
     * EXPECTED, after the second removal: the phone is refused on Contagem
     * 2 as well, and the counter role is revoked (by the controller). Her
     * user row is NOT deleted; she just holds nothing.
     */
    public function testRemovingTheLastLinkRemovesThePermissionAndRevokesTheDevices(): void
    {
        CounterLinks::link($this->fontes[0], 'zzt_ana', self::CONTROLLER);
        CounterLinks::link($this->fontes[1], 'zzt_ana', self::CONTROLLER);
        $tokenForSource1 = $this->issueToken($this->fontes[0], 'zzt_ana');
        $tokenForSource2 = $this->issueToken($this->fontes[1], 'zzt_ana');
        $this->assertSame(200, $this->upload($tokenForSource1)['status']);
        $this->assertSame(200, $this->upload($tokenForSource2)['status']);

        CounterLinks::unlink($this->fontes[0], 'zzt_ana', self::CONTROLLER);

        $this->assertSame(401, $this->upload($tokenForSource1)['status'], 'the phone is cut off from Contagem 1');
        $this->assertSame(200, $this->upload($tokenForSource2)['status'], 'and still works on Contagem 2');
        $this->assertSame([self::COUNTER_ROLE], $this->activeRolesOf('zzt_ana'));

        CounterLinks::unlink($this->fontes[1], 'zzt_ana', self::CONTROLLER);

        $this->assertSame(401, $this->upload($tokenForSource2)['status']);
        $this->assertSame([], $this->activeRolesOf('zzt_ana'), 'the counter role is gone');
        $this->assertNotNull(UserQuery::create()->findOneByUsername('zzt_ana'), 'the row stays');
    }

    /**
     * SITUATION: Ana is linked to Contagem 2 only. She logs in.
     *
     * EXPECTED: the system knows exactly one source for her, Contagem 2,
     * which is where the start page sends her without asking. If she
     * changes the address by hand to open Contagem 1, she is refused.
     */
    public function testALinkedCounterIsSentToTheirSourceAndCannotOpenAnother(): void
    {
        CounterLinks::link($this->fontes[1], 'zzt_ana', self::CONTROLLER);
        $this->loginAs('zzt_ana');

        $herSources = ContagemAccess::linkedSources($this->prova, 'zzt_ana');

        $this->assertCount(1, $herSources);
        $this->assertSame($this->fontes[1]->getFonteId(), $herSources[0]->getFonteId());
        $this->assertTrue(ContagemAccess::canCountOn($this->fontes[1]));
        $this->assertFalse(ContagemAccess::canCountOn($this->fontes[0]));
    }

    /**
     * SITUATION: Bruno takes over from Ana at half time, so the controller
     * linked him to Contagem 1 and, as a reserve, to Contagem 3.
     *
     * EXPECTED: he is offered those two sources to choose from, in order,
     * and not Contagem 2.
     */
    public function testACounterLinkedToSeveralSourcesIsOfferedOnlyThose(): void
    {
        CounterLinks::link($this->fontes[2], 'zzt_bruno', self::CONTROLLER);
        CounterLinks::link($this->fontes[0], 'zzt_bruno', self::CONTROLLER);
        $this->loginAs('zzt_bruno');

        $names = [];
        foreach (ContagemAccess::linkedSources($this->prova, 'zzt_bruno') as $fonte) {
            $names[] = $fonte->getNome();
        }

        $this->assertSame(['Contagem 1', 'Contagem 3'], $names);
        $this->assertFalse(ContagemAccess::canCountOn($this->fontes[1]));
    }

    /**
     * SITUATION: the controller opens the count page of a source to help
     * out ("Contar como...") without being linked to it.
     *
     * EXPECTED: allowed. A controller may count on any source of the prova.
     */
    public function testTheControllerMayCountOnAnySourceWithoutALink(): void
    {
        $this->loginAs(self::CONTROLLER);

        $this->assertSame([], ContagemAccess::linkedSources($this->prova, self::CONTROLLER));
        $this->assertTrue(ContagemAccess::canCountOn($this->fontes[0]));
        $this->assertTrue(ContagemAccess::canCountOn($this->fontes[2]));
    }

    /**
     * The usernames linked to one of the three sources, in alphabetical order.
     *
     * @param int $sourceIndex 0 for Contagem 1, 1 for Contagem 2, 2 for Contagem 3
     * @return string[]
     */
    private function usernamesLinkedTo(int $sourceIndex): array
    {
        return array_column(CounterLinks::listForSource($this->fontes[$sourceIndex]), 'usuario');
    }

    /**
     * Who gave the user's active grants (usernames).
     *
     * @return string[]
     */
    private function grantedBy(string $username): array
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT b.username FROM user_role g JOIN user u ON u.user_id = g.user_id JOIN user b ON b.user_id = g.granted_by
             WHERE u.username = ? AND g.revoked_at IS NULL ORDER BY 1'
        );
        $statement->execute([$username]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
