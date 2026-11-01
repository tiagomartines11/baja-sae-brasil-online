<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Auth\StaffCookie;
use Baja\Certificado\Insercao\Acesso;
use Baja\Certificado\Requerimento\Destinatarios;
use Baja\Contagem\Access\ContagemAccess;
use Baja\Fila\Fila;
use Baja\Juiz\TimerAcesso;
use Baja\Model\ContagemFonte;
use Baja\Model\ProvaQuery;
use Baja\Model\TimerQuery;
use Baja\Model\User;
use Baja\Model\UserQuery;
use Baja\Rbac\Authorization;
use Baja\Rbac\Legacy\LegacyGrantPlan;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use Baja\Rbac\Scope\ScopeType;
use Baja\Session;
use Baja\Tests\Support\LegacyOracle;
use PHPUnit\Framework\Attributes\Group;

/**
 * Parity: the switch-over to RBAC (BAJAAPP-76) changes HOW the site decides
 * who may do what, and must not change WHAT it decides.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Before the switch-over, each staff page checked short codes in the
 * user's row ("96BR_ZZA" = judge of prova ZZA of event 96BR). Now each page
 * asks Authorization::can(user, permission, place), and the answer comes
 * from the user's grants (roles on places). The migration turned every
 * code into a grant (Baja\Rbac\Legacy\LegacyCode).
 *
 * So, for every check a page makes ("call site"), this test asks two
 * questions and expects the same answer:
 *
 *   BEFORE   what the old check said for a user holding the legacy code.
 *            tests/Support/LegacyOracle.php is a copy of every old check.
 *   NOW      what the new check says for the same user, holding only the
 *            grant the migration gave them. The migration is the real one:
 *            LegacyGrantPlan, run on these users, writing real grants.
 *
 * Each question is asked about a MATCHING place (the event, prova, queue
 * or timer of the code) and NON-MATCHING ones (another prova of the event,
 * the same prova id in another event, another queue, another event's
 * timer). A wrong answer either way is a bug: a judge locked out, or a
 * judge let in where they never were.
 *
 * THE MADE-UP WORLD (RbacDbTestCase, plus timers)
 *
 *     96BR  (ends 12 March 2099)  provas ZZA, ZZB   queues 1, 2   timers 1, 2
 *     96SE  (no end date)         prova ZZA
 *     96AR                        prova ZZA                       timer 1
 *
 * One made-up user per legacy code, each also holding 'index' as every
 * judge did (except the team member, who never had it):
 *
 *     admin       admin                 certs      certificados
 *     judge       96BR_ZZA              counter    96BR_ZZA_CONT
 *     pit         96BR_ZZA_BOX          prizes     96BR_PREMIACAO
 *     timeradmin  96BR_TIMER_ADMIN      timer1     96BR_TIMER_1
 *     queue1      96BR_FILA_1_ADMIN     team       96BR_FILA_1_42 (no index)
 *     nothing     index only            judgeSE    96SE_ZZA (event with no end date)
 *
 * THE FEW DIFFERENCES, ON PURPOSE (docs/rbac.md, "Decided differences")
 *
 * Where the answer was meant to change, the test says so explicitly
 * (DIFFERENCES below): it checks the old answer AND the new answer, so a
 * difference nobody decided can never hide among them.
 */
#[Group('rbac')]
final class CallSiteParityTest extends RbacDbTestCase
{
    private const USERS = [
        'admin' => ['index', 'admin'],
        'certs' => ['index', 'certificados'],
        'judge' => ['index', '96BR_ZZA'],
        'counter' => ['index', '96BR_ZZA_CONT'],
        'pit' => ['index', '96BR_ZZA_BOX'],
        'prizes' => ['index', '96BR_PREMIACAO'],
        'timeradmin' => ['index', '96BR_TIMER_ADMIN'],
        'timer1' => ['index', '96BR_TIMER_1'],
        'queue1' => ['index', '96BR_FILA_1_ADMIN'],
        'team' => ['96BR_FILA_1_42'],
        'nothing' => ['index'],
        'judgeSE' => ['index', '96SE_ZZA'],
    ];

    /** Requests are made "in" one of these events (REDIRECT_EVENT). */
    private const EVENTS = ['96BR', '96AR', '96SE'];

    /** [event, prova]: the code's prova, another prova of the event, the same id elsewhere. */
    private const PROVAS = [['96BR', 'ZZA'], ['96BR', 'ZZB'], ['96AR', 'ZZA'], ['96SE', 'ZZA']];

    /**
     * The decided differences: "call site|user|place" => [before, now].
     * Every other comparison must be equal.
     */
    private const DIFFERENCES = [
        // 1. The judge start page. Before, 'index' opened it in ANY event
        //    (with nothing on it); now it opens for whoever holds a grant
        //    in that event or above (the plan's rule).
        'startPage|judge|96AR' => [true, false],
        'startPage|judge|96SE' => [true, false],
        'startPage|counter|96AR' => [true, false],
        'startPage|counter|96SE' => [true, false],
        'startPage|pit|96AR' => [true, false],
        'startPage|pit|96SE' => [true, false],
        'startPage|prizes|96AR' => [true, false],
        'startPage|prizes|96SE' => [true, false],
        'startPage|timeradmin|96AR' => [true, false],
        'startPage|timeradmin|96SE' => [true, false],
        'startPage|timer1|96AR' => [true, false],
        'startPage|timer1|96SE' => [true, false],
        'startPage|queue1|96AR' => [true, false],
        'startPage|queue1|96SE' => [true, false],
        'startPage|judgeSE|96BR' => [true, false],
        'startPage|judgeSE|96AR' => [true, false],
        'startPage|nothing|96BR' => [true, false],
        'startPage|nothing|96AR' => [true, false],
        'startPage|nothing|96SE' => [true, false],
        // 2. The results-cache cookie: 'index' alone made you staff; now a
        //    grant does, and 'index' alone is no grant.
        'isStaff|nothing|-' => [true, false],
        // 3. A timer operator runs every timer of their event, not one
        //    (timers are not scope nodes; decided in phase 2).
        'timerPodeUsar|timer1|96BR/2' => [false, true],
        // 4. A platform admin manages every queue. Before, 'admin' was not
        //    enough for the queue pages (fila/), which read only the
        //    queue's own code.
        'queueForTeam|admin|96BR/1' => [false, true],
        'queueForTeam|admin|96BR/2' => [false, true],
        'filasUsuario|admin|-' => [false, true],
    ];

    /** @var array<string, int> how many comparisons each call site made */
    private array $compared = [];

    /** @var array<string, true> the differences actually met */
    private array $differencesSeen = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Timers 1 and 2 of 96BR, timer 1 of 96AR. They go with their event.
        // Each needs its own access and view keys (six characters, unique).
        $timer = $this->con->prepare("INSERT INTO timer (evento_id, timer_id, config, access_key, view_key) VALUES (?, ?, '{}', ?, ?)");
        foreach ([['96BR', 1, 'ZZRA01', 'ZZRV01'], ['96BR', 2, 'ZZRA02', 'ZZRV02'], ['96AR', 1, 'ZZRA03', 'ZZRV03']] as $t) {
            $timer->execute($t);
        }

        // Each made-up user, with their legacy codes in the old column...
        $set = $this->con->prepare('UPDATE user SET permissions = ? WHERE user_id = ?');
        foreach (self::USERS as $name => $codes) {
            $set->execute(['| ' . implode(' | ', $codes) . ' |', $this->user($name)]);
        }

        // ...then the REAL migration, on these users only: work out the
        // grants (LegacyGrantPlan) and run the SQL it writes.
        $plan = LegacyGrantPlan::build($this->con, $this->clock->now(), null, 'zzrbac\\_%');
        foreach ($plan->statements(fn(string $v) => $this->con->quote($v), $this->clock->now()) as $sql) {
            $this->con->exec($sql);
        }

        $this->newRequest();
    }

    protected function tearDown(): void
    {
        Authorization::useServices(null);
        unset($_SERVER['REDIRECT_EVENT']);
        parent::tearDown();
    }

    protected function newRequest(): void
    {
        parent::newRequest();
        // The pages ask Authorization, which must use this test's objects
        // (its connection, its clock stopped on 1 March 2099).
        Authorization::useServices($this->rbac);
    }

    /**
     * The one big check: every call site, every user, every place.
     */
    public function testEveryCallSiteAnswersAsBeforeForTheMigratedGrant(): void
    {
        foreach (self::USERS as $name => $codes) {
            $old = new LegacyOracle($codes);
            $user = $this->model($name);
            Session::setForcedSession('zzrbac_' . $name);

            // -- The judge start page (index.php, contagem.php) ----------
            foreach (self::EVENTS as $ev) {
                $this->same('startPage', $name, $ev, $old->startPage($ev), Authorization::hasAnyGrantInEvent($user, $ev));
            }

            // -- Entering scores: index.php's list and the six entry pages
            foreach (self::PROVAS as [$ev, $prova]) {
                $this->request($ev);
                $this->same('entersScores', $name, "$ev/$prova", $old->entersScores($ev, $prova),
                    Authorization::currentUserCan(Permission::PROVA_ENTER_SCORES, Authorization::prova($prova)));
            }

            foreach (['96BR', '96AR'] as $ev) {
                $this->request($ev);
                $event = Authorization::currentEvent();

                // -- Prizes: the link, the three pages, and the drafts ----
                $this->same('seesPrizes', $name, $ev, $old->seesPrizes($ev), Authorization::currentUserCan(Permission::PREMIACAO_VIEW, $event));
                $this->same('seesPrizeDrafts', $name, $ev, $old->seesPrizeDrafts(), Authorization::currentUserCan(Permission::PREMIACAO_MANAGE, $event));

                // -- Timers: the start page link, the three admin pages,
                //    TimerAcesso -------------------------------------------
                $this->same('seesTimersLink', $name, $ev, $old->seesTimersLink($ev),
                    Authorization::currentUserCan(Permission::TIMER_MANAGE, $event) || Authorization::currentUserCan(Permission::TIMER_OPERATE, $event));
                $this->same('managesTimersPage', $name, $ev, $old->managesTimersPage($ev), Authorization::currentUserCan(Permission::TIMER_MANAGE, $event));
                $this->same('timerPodeConfigurar', $name, $ev, $old->timerPodeConfigurar($ev), TimerAcesso::podeConfigurar($ev, $user));

                // -- The pages that were 'admin' only, each with the
                //    permission it asks now ---------------------------------
                $adminPages = [
                    'admin.php, index.php link, admin_timers.php back link' => Authorization::currentUserCan(Permission::EVENTO_MANAGE, $event),
                    'admin_equipes.php, equipe.php' => Authorization::currentUserCan(Permission::EQUIPE_MANAGE, $event),
                    'admin_provas.php, ajuda_prova.php, prova.php?nova' => Authorization::currentUserCan(Permission::PROVA_CONFIGURE, $event),
                    'prova.php?id, prova_log.php, torneio.php' => Authorization::currentUserCan(Permission::PROVA_CONFIGURE, Authorization::prova('ZZA')),
                    'admin_resultados.php, resultado.php, ajuda_resultado.php' => Authorization::currentUserCan(Permission::RESULTADO_EDIT, $event),
                    'admin_filas.php, fila.php' => Authorization::currentUserCan(Permission::FILA_MANAGE, $event),
                    'admin_premiacoes.php, premiacao_admin.php' => Authorization::currentUserCan(Permission::PREMIACAO_MANAGE, $event),
                    'admin_push.php' => Authorization::currentUserCan(Permission::NOTIFICACAO_SEND, $event),
                    'admin_users.php, user.php' => Authorization::currentUserCan(Permission::ROLE_GRANT, $event),
                    'contagem_controle.php wipe' => Authorization::currentUserCan(Permission::PROVA_CONFIGURE, Authorization::prova('ZZA')),
                ];
                foreach ($adminPages as $site => $now) {
                    $this->same($site, $name, $ev, $old->isAdmin($ev), $now);
                }

                // -- Lap counting (ContagemAccess), for provas of this and
                //    of other events ----------------------------------------
                foreach (self::PROVAS as [$provaEv, $provaId]) {
                    $prova = ProvaQuery::create()->findPk([$provaEv, $provaId]);
                    $place = "$ev:$provaEv/$provaId";
                    $this->same('isController', $name, $place, $old->isController($ev, $provaEv, $provaId), ContagemAccess::isController($prova));
                    $this->same('isCounter', $name, $place, $old->isCounter($ev, $provaEv, $provaId), ContagemAccess::isCounter($prova));
                    $this->same('canUsePitLog', $name, $place, $old->canUsePitLog($ev, $provaEv, $provaId), ContagemAccess::canUsePitLog($prova));
                    $this->same('mayEnter', $name, $place, $old->mayEnter($ev, $provaEv, $provaId), ContagemAccess::mayEnter($prova));
                    $this->same('canCountOn', $name, $place, $old->canCountOnUnlinkedSource($ev, $provaEv, $provaId),
                        ContagemAccess::canCountOn($this->unlinkedManualSource($provaEv, $provaId)));
                }
            }

            // -- Pages that are not about the request's event ------------
            $this->same('admin_eventos.php, evento.php?novo, clonar_evento.php, ajuda_menu.php', $name, '-', $old->rowHas('admin'),
                Authorization::canAnywhere($user, Permission::EVENTO_MANAGE));
            $this->same('evento.php / clonar_evento.php save in BAJA', $name, '-', $old->rowHas('admin'),
                Authorization::can($user, Permission::EVENTO_MANAGE, ScopeNode::programa('BAJA')));
            $this->same('admin_usuarios.php, admin_usuario.php', $name, '-', $old->rowHas('admin'),
                Authorization::canAnywhere($user, Permission::ROLE_GRANT));
            $this->same('admin_fila_acesso.php', $name, '-', $old->rowHas('admin'),
                array_filter(Authorization::nodesGiving($user, Permission::FILA_MANAGE), fn($n) => $n->type !== ScopeType::FILA) !== []);
            $this->same('certificates', $name, '-', $old->certificates(), Acesso::usuarioPode($user));
            $this->same('certificateReports', $name, '-', $old->certificates(), in_array('zzrbac_' . $name, Destinatarios::usuariosComPermissao(), true));
            $this->same('isStaff', $name, '-', $old->isStaff(), StaffCookie::isStaff($user));

            // -- Timers by id, this event's and another's ----------------
            foreach ([['96BR', 1], ['96BR', 2], ['96AR', 1]] as [$ev, $timerId]) {
                $timer = TimerQuery::create()->filterByEventoId($ev)->findOneByTimerId($timerId);
                $this->same('timerPodeUsar', $name, "$ev/$timerId", $old->timerPodeUsar($ev, $timerId), TimerAcesso::podeUsar($timer, $user));
            }

            // -- The pit phone's upload (no session: by username) --------
            foreach (self::PROVAS as [$ev, $prova]) {
                $this->same('mayStillLogStops', $name, "$ev/$prova", $old->mayStillLogStops($ev, $prova),
                    ContagemAccess::userMayStillLogStops('zzrbac_' . $name, $ev, $prova));
            }

            // -- Queues (fila/): run the queue, act for team 42 ----------
            foreach ([1, 2] as $fila) {
                $this->same('queueForTeam', $name, "96BR/$fila", $old->queueForTeam('96BR', $fila, '42'),
                    Fila::checkPermissaoFila('zzrbac_' . $name, '96BR', $fila, '42'));
            }
            $oldAdminQueues = [];
            foreach ([1, 2] as $fila) {
                if ($old->managesQueue('96BR', $fila)) {
                    $oldAdminQueues[] = "96BR/$fila";
                }
            }
            $nowAdminQueues = [];
            foreach (Fila::getFilasUsuario('zzrbac_' . $name) ?: [] as $f) {
                if ($f['permissao'] === 'ADMIN' && $f['evento_id'] === '96BR') {
                    $nowAdminQueues[] = $f['evento_id'] . '/' . $f['fila_id'];
                }
            }
            if ($name === 'admin') {
                // Decided difference 4: before, none listed; now, all of them.
                $this->same('filasUsuario', $name, '-', $oldAdminQueues !== [], $nowAdminQueues !== []);
                $this->assertSame(['96BR/1', '96BR/2'], $nowAdminQueues);
            } else {
                // The same queues listed to run, before and now.
                $this->same('filasUsuario', $name, '-', true, $oldAdminQueues === $nowAdminQueues);
            }
        }

        // Every decided difference was met: none of them is stale.
        $this->assertEqualsCanonicalizing(array_keys(self::DIFFERENCES), array_keys($this->differencesSeen));
        // And the test really looked at every call site, many times.
        $this->assertGreaterThan(30, count($this->compared));
        $this->assertGreaterThan(1100, array_sum($this->compared));
    }

    /**
     * A team keeps its own queue code (not a role): the queue pages still
     * read it, and list the queue for the team. Unchanged by the migration.
     */
    public function testATeamStillUsesItsQueueCode(): void
    {
        $this->assertTrue(Fila::checkPermissaoFila('zzrbac_team', '96BR', 1, '42'));
        $this->assertFalse(Fila::checkPermissaoFila('zzrbac_team', '96BR', 1, '43'), 'another team');
        $this->assertFalse(Fila::checkPermissaoFila('zzrbac_team', '96BR', 2, '42'), 'another queue');
        $this->assertSame(
            [['evento_id' => '96BR', 'fila_id' => '1', 'permissao' => '42']],
            Fila::getFilasUsuario('zzrbac_team')
        );
    }

    /**
     * The old queue-admin code stays in the column (for rollback), and must
     * open nothing: not even through the team check, with a team called
     * "ADMIN".
     */
    public function testAFrozenQueueAdminCodeOpensNothingOnceTheGrantIsGone(): void
    {
        $this->con->prepare('UPDATE user_role SET revoked_at = ? WHERE user_id = ?')
            ->execute([self::NOW, $this->user('queue1')]);
        $this->newRequest();

        $this->assertFalse(Fila::checkPermissaoFila('zzrbac_queue1', '96BR', 1, '42'));
        $this->assertFalse(Fila::checkPermissaoFila('zzrbac_queue1', '96BR', 1, 'ADMIN'));
        $this->assertSame([], Fila::getFilasUsuario('zzrbac_queue1'));
    }

    // ------------------------------------------------------------------
    // Expired, revoked, another event, platform admin
    // ------------------------------------------------------------------

    /**
     * SITUATION: the judge's grant ended (valid_until passed: the event is
     * over). EXPECTED: every page that let them in now says no.
     */
    public function testAnExpiredGrantDenies(): void
    {
        $this->assertTrue($this->judgeMayWork(), 'the starting point: the judge may work');

        $this->con->prepare('UPDATE user_role SET valid_until = ? WHERE user_id = ?')
            ->execute(['2099-02-28 23:59:59', $this->user('judge')]);
        $this->newRequest();

        $this->assertFalse($this->judgeMayWork());
        $this->assertFalse(Authorization::hasAnyGrantInEvent($this->model('judge'), '96BR'), 'not even the start page');
        $this->assertFalse(StaffCookie::isStaff($this->model('judge')), 'nor the staff cookie');
    }

    /**
     * SITUATION: an administrator revoked the judge's grant.
     * EXPECTED: the same: every page says no.
     */
    public function testARevokedGrantDenies(): void
    {
        $this->assertTrue($this->judgeMayWork());

        $this->con->prepare('UPDATE user_role SET revoked_at = ? WHERE user_id = ?')
            ->execute([self::NOW, $this->user('judge')]);
        $this->newRequest();

        $this->assertFalse($this->judgeMayWork());
        $this->assertFalse(Authorization::hasAnyGrantInEvent($this->model('judge'), '96BR'));
    }

    /**
     * SITUATION: the judge of 96BR/ZZA asks about prova ZZA of ANOTHER
     * event (96AR has a ZZA too), with the request in that event.
     * EXPECTED: no, everywhere. The prova id alone gives nothing.
     */
    public function testAGrantInAnotherEventDenies(): void
    {
        $this->request('96AR');
        Session::setForcedSession('zzrbac_judge');
        $other = ProvaQuery::create()->findPk(['96AR', 'ZZA']);

        $this->assertFalse(Authorization::currentUserCan(Permission::PROVA_ENTER_SCORES, Authorization::prova('ZZA')));
        $this->assertFalse(ContagemAccess::isController($other));
        $this->assertFalse(ContagemAccess::mayEnter($other));
        $this->assertFalse(ContagemAccess::userMayStillLogStops('zzrbac_judge', '96AR', 'ZZA'));
        $this->assertFalse(Authorization::hasAnyGrantInEvent($this->model('judge'), '96AR'));
    }

    /**
     * SITUATION: the platform admin (the legacy 'admin' code).
     * EXPECTED: every permission, on every place of the world where it
     * can be asked, says yes; and every call site lets them in.
     */
    public function testThePlatformAdminStillSeesEverything(): void
    {
        $admin = $this->model('admin');
        $places = [
            ScopeNode::platform(), ScopeNode::programa('BAJA'), ScopeNode::programa('AERO'), ScopeNode::programa('AERO_FORUM'),
            ScopeNode::evento('96BR'), ScopeNode::evento('96SE'), ScopeNode::evento('96AR'), ScopeNode::evento('96FA'),
            ScopeNode::prova('96BR', 'ZZA'), ScopeNode::prova('96BR', 'ZZB'), ScopeNode::prova('96SE', 'ZZA'), ScopeNode::prova('96AR', 'ZZA'),
            ScopeNode::fila('96BR', 1), ScopeNode::fila('96BR', 2),
        ];
        $asked = 0;
        foreach (Permission::cases() as $permission) {
            foreach ($places as $place) {
                if ($permission->appliesToType($place->type)) {
                    $this->assertTrue(Authorization::can($admin, $permission, $place), "{$permission->value} on {$place->key()}");
                    $asked++;
                }
            }
        }
        $this->assertGreaterThan(60, $asked);

        foreach (['96BR', '96SE', '96AR', '96FA'] as $ev) {
            $this->assertTrue(Authorization::hasAnyGrantInEvent($admin, $ev), "start page of $ev");
        }
        $this->assertTrue(Acesso::usuarioPode($admin));
        $this->assertTrue(StaffCookie::isStaff($admin));
        $this->assertTrue(Authorization::canEverywhere($admin, Permission::CERTIFICADO_MANAGE));
        foreach ([1, 2] as $fila) {
            $this->assertTrue(Fila::checkPermissaoFila('zzrbac_admin', '96BR', $fila, '1'));
        }
    }

    // ------------------------------------------------------------------

    /** What the judge of 96BR/ZZA can do on their prova, by every route. */
    private function judgeMayWork(): bool
    {
        $this->request('96BR');
        Session::setForcedSession('zzrbac_judge');
        $prova = ProvaQuery::create()->findPk(['96BR', 'ZZA']);
        return Authorization::currentUserCan(Permission::PROVA_ENTER_SCORES, Authorization::prova('ZZA'))
            && ContagemAccess::isController($prova)
            && ContagemAccess::mayEnter($prova)
            && ContagemAccess::userMayStillLogStops('zzrbac_judge', '96BR', 'ZZA');
    }

    /** Compares one answer, or checks a decided difference. */
    private function same(string $site, string $user, string $place, bool $before, bool $now): void
    {
        $this->compared[$site] = ($this->compared[$site] ?? 0) + 1;
        $key = "$site|$user|$place";
        if (isset(self::DIFFERENCES[$key])) {
            $this->differencesSeen[$key] = true;
            $this->assertSame(self::DIFFERENCES[$key], [$before, $now], "decided difference $key: [before, now]");
            return;
        }
        $this->assertSame($before, $now, "$site, user '$user', at $place: before said " . var_export($before, true) . ', now ' . var_export($now, true));
    }

    /** A request in event $ev: what bootstrap.php sets from the URL /96BR/... */
    private function request(string $ev): void
    {
        $_SERVER['REDIRECT_EVENT'] = $ev;
    }

    private function model(string $name): User
    {
        return UserQuery::create()->findPk($this->user($name));
    }

    /** A manual source of the prova that nobody is linked to (never saved: canCountOn only reads it). */
    private function unlinkedManualSource(string $ev, string $prova): ContagemFonte
    {
        $fonte = new ContagemFonte();
        $fonte->setEventoId($ev);
        $fonte->setProvaId($prova);
        $fonte->setTipo('manual');
        return $fonte;
    }
}
