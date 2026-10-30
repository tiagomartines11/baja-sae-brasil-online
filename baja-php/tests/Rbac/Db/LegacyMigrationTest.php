<?php
declare(strict_types=1);

namespace Baja\Tests\Rbac\Db;

use Baja\Rbac\Legacy\LegacyGrantPlan;
use Baja\Rbac\Permission\Permission;
use Baja\Rbac\Scope\ScopeNode;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The switch-over's one-time data step (PropelMigration_1793110000,
 * BAJAAPP-76): every legacy code in user.permissions becomes a grant.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The migration works in two steps, both tested here on made-up users
 * (zzrbac_*) in the made-up world of RbacDbTestCase:
 *
 *   1. LegacyGrantPlan::build() reads every user's codes and works out the
 *      grants, without writing anything. It also lists what it could NOT
 *      turn into a grant, for a person to check.
 *   2. statements() gives the SQL that writes them: one user_role row and
 *      one role_audit row per grant.
 *
 * The rules being checked:
 *
 *   - a grant inside an event ends 7 days after the event's last day
 *     (data_fim), at 23:59:59, like any grant made by hand;
 *   - an event with no data_fim gives grants with NO end, and the event is
 *     listed so a person sets the date;
 *   - codes that point at nothing are listed, never dropped silently;
 *   - granted_by is NULL (the migration), with an audit row naming the code;
 *   - user.permissions is left exactly as it was (the way back);
 *   - running it twice gives nothing new.
 */
#[Group('rbac')]
final class LegacyMigrationTest extends RbacDbTestCase
{
    /** Puts legacy codes in a made-up user's row, as the old pages stored them. */
    private function withCodes(string $name, array $codes): int
    {
        $id = $this->user($name);
        $this->con->prepare('UPDATE user SET permissions = ? WHERE user_id = ?')
            ->execute(['| ' . implode(' | ', $codes) . ' |', $id]);
        return $id;
    }

    private function plan(): LegacyGrantPlan
    {
        return LegacyGrantPlan::build($this->con, $this->clock->now(), 7, 'zzrbac\\_%');
    }

    private function migrate(LegacyGrantPlan $plan): void
    {
        foreach ($plan->statements(fn(string $v) => $this->con->quote($v), $this->clock->now()) as $sql) {
            $this->con->exec($sql);
        }
        $this->newRequest();
    }

    /**
     * SITUATION: a judge of 96BR/ZZA (96BR ends on 12 March 2099).
     * EXPECTED: one judge grant on the prova, ending 19 March 2099 at
     * 23:59:59 (seven days later), made by nobody (the migration), with an
     * audit row that names the legacy code. And it works: the judge may
     * enter scores there, and not on the other prova.
     */
    public function testAJudgeCodeBecomesAJudgeGrantEndingAWeekAfterTheEvent(): void
    {
        $this->withCodes('ana', ['index', '96BR_ZZA']);

        $this->migrate($this->plan());

        $grants = $this->grantRows('ana');
        $this->assertCount(1, $grants);
        $this->assertSame(['prova', '96BR/ZZA'], [$grants[0]['scope_type'], $grants[0]['scope_id']]);
        $this->assertSame('2099-03-19 23:59:59', $grants[0]['valid_until']);
        $this->assertNull($grants[0]['granted_by']);
        $this->assertNull($grants[0]['revoked_at']);

        $audit = $this->auditRows();
        $this->assertCount(1, $audit);
        $this->assertSame('grant', $audit[0]['action']);
        $this->assertNull($audit[0]['actor_user_id']);
        $this->assertSame((string) $grants[0]['grant_id'], (string) $audit[0]['grant_id']);
        $details = json_decode((string) $audit[0]['details'], true);
        $this->assertSame('judge', $details['role']);
        $this->assertSame('96BR_ZZA', $details['legacy_code']);
        $this->assertSame(LegacyGrantPlan::SOURCE, $details['source']);

        $this->assertTrue($this->can('ana', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZA')));
        $this->assertFalse($this->can('ana', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96BR', 'ZZB')));
    }

    /**
     * SITUATION: a judge of 96SE/ZZA. 96SE has no data_fim yet.
     * EXPECTED: the grant is given with NO end (nobody is locked out), and
     * the plan lists 96SE and the code, so a person can set the date.
     */
    public function testAnEventWithNoEndDateGivesAGrantWithNoEndAndIsListed(): void
    {
        $this->withCodes('bia', ['index', '96SE_ZZA']);

        $plan = $this->plan();
        $this->migrate($plan);

        $this->assertSame(['96SE' => ['zzrbac_bia: 96SE_ZZA']], $plan->noEndDate);
        $this->assertStringContainsString('NO END DATE', $plan->report());
        $this->assertNull($this->grantRows('bia')[0]['valid_until']);
        $this->assertTrue($this->can('bia', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96SE', 'ZZA')));
    }

    /**
     * SITUATION: codes that point at nothing: a deleted event, a prova that
     * is gone, a queue that never existed, a typo.
     * EXPECTED: no grant for any of them, each listed with its user and a
     * reason; 'index' is counted as dropped on purpose, and a team's queue
     * code as kept.
     */
    public function testCodesThatMapToNothingAreListedNotDropped(): void
    {
        $this->withCodes('caio', ['index', '91XX_ZZA', '96BR_ZZQ', '96BR_FILA_9_ADMIN', 'juiz', '96BR_FILA_1_42']);

        $plan = $this->plan();
        $this->migrate($plan);

        $this->assertSame([], $this->grantRows('caio'));
        $this->assertSame(['91XX_ZZA', '96BR_ZZQ', '96BR_FILA_9_ADMIN', 'juiz'], array_column($plan->unmapped, 'code'));
        foreach ($plan->unmapped as $row) {
            $this->assertSame('zzrbac_caio', $row['username']);
            $this->assertNotSame('', $row['reason']);
        }
        $this->assertSame(['index'], array_column($plan->dropped, 'code'));
        $this->assertSame(['96BR_FILA_1_42'], array_column($plan->kept, 'code'));
        $report = $plan->report();
        $this->assertStringContainsString('UNMAPPED', $report);
        $this->assertStringContainsString('zzrbac_caio  96BR_ZZQ', $report);
    }

    /**
     * SITUATION: one user with many codes (the shape of a busy volunteer).
     * EXPECTED: each becomes its own role on its own place, ends follow
     * each place's event, and 'certificados' becomes a certificate manager
     * on every top-level program, with no end.
     */
    public function testEveryKindOfCodeBecomesItsRole(): void
    {
        $this->withCodes('davi', [
            'index', 'certificados', '96BR_ZZA_CONT', '96BR_ZZA_BOX', '96BR_PREMIACAO',
            '96BR_TIMER_ADMIN', '96BR_FILA_2_ADMIN',
        ]);

        $this->migrate($this->plan());

        $rows = $this->con->prepare(
            "SELECT r.code, g.scope_type, g.scope_id, g.valid_until FROM user_role g JOIN role r ON r.role_id = g.role_id
             WHERE g.user_id = ? ORDER BY r.code, g.scope_id"
        );
        $rows->execute([$this->user('davi')]);
        $got = array_map(fn($r) => implode(' ', [$r['code'], $r['scope_type'] . ':' . $r['scope_id'], $r['valid_until'] ?? 'no-end']), $rows->fetchAll(PDO::FETCH_ASSOC));

        $topPrograms = $this->con->query('SELECT programa_id FROM programa WHERE parent_id IS NULL ORDER BY programa_id')->fetchAll(PDO::FETCH_COLUMN);
        $expected = array_map(fn($p) => "certificate_manager programa:$p no-end", $topPrograms);
        $expected = array_merge(
            ['awards_viewer evento:96BR 2099-03-19 23:59:59'],
            $expected,
            [
                'lap_counter prova:96BR/ZZA 2099-03-19 23:59:59',
                'pit_logger prova:96BR/ZZA 2099-03-19 23:59:59',
                'queue_manager fila:96BR/2 2099-03-19 23:59:59',
                'timer_manager evento:96BR 2099-03-19 23:59:59',
            ]
        );
        $this->assertSame($expected, $got);
    }

    /**
     * SITUATION: a legacy admin who already got the platform admin role in
     * phase 2, and the migration being run a second time.
     * EXPECTED: nothing is written twice. The second plan finds every grant
     * already held and has nothing to write.
     */
    public function testWhatIsAlreadyHeldIsNotWrittenAgain(): void
    {
        $eva = $this->withCodes('eva', ['index', 'admin', '96BR_ZZA']);
        $this->giveDirectly($eva, 'platform_admin', ScopeNode::platform());

        $first = $this->plan();
        $this->assertSame(['admin'], array_column($first->alreadyHeld, 'code'));
        $this->migrate($first);
        $this->assertCount(2, $this->grantRows('eva'), 'platform admin (phase 2) + judge');

        $second = $this->plan();
        $this->assertSame([], $second->grants);
        $this->assertSame([], $second->statements(fn(string $v) => $this->con->quote($v), $this->clock->now()));
        $this->assertCount(2, $second->alreadyHeld);
    }

    /**
     * SITUATION: a judge of an event that ended long ago (its end date is
     * in the past of the migration's clock).
     * EXPECTED: the grant is still written, so the record of who judged
     * stays, but it is born ended, gives nothing, and the plan lists it.
     */
    public function testAGrantForAnEventLongOverIsWrittenEndedAndListed(): void
    {
        $this->con->exec("UPDATE evento SET data_fim = '2099-02-01' WHERE evento_id = '96AR'");
        $this->withCodes('fabi', ['index', '96AR_ZZA']);

        $plan = $this->plan();
        $this->migrate($plan);

        $this->assertSame([['username' => 'zzrbac_fabi', 'code' => '96AR_ZZA', 'valid_until' => '2099-02-08 23:59:59']], $plan->expired);
        $this->assertSame('2099-02-08 23:59:59', $this->grantRows('fabi')[0]['valid_until']);
        $this->assertFalse($this->can('fabi', Permission::PROVA_ENTER_SCORES, ScopeNode::prova('96AR', 'ZZA')));
    }

    /**
     * user.permissions is the way back: the migration must not change it.
     */
    public function testTheLegacyColumnIsLeftAsItWas(): void
    {
        $this->withCodes('gil', ['index', '96BR_ZZA', '96BR_TIMER_ADMIN', 'nonsense']);
        $before = $this->con->query("SELECT permissions FROM user WHERE username = 'zzrbac_gil'")->fetchColumn();

        $this->migrate($this->plan());

        $this->assertSame($before, $this->con->query("SELECT permissions FROM user WHERE username = 'zzrbac_gil'")->fetchColumn());
    }

    /**
     * The roles the migration creates are also in the dev seed
     * (12-rbac-switchover.sql). The two copies must say the same, or dev
     * and production would have different roles.
     */
    public function testTheMigrationAndTheDevSeedCreateTheSameRoles(): void
    {
        require_once __DIR__ . '/../../../generated-migrations/PropelMigration_1793110000.php';
        $migrationSql = (new \PropelMigration_1793110000())->getUpSQL()['resultados'];
        $seedFile = __DIR__ . '/../../../../baja-infra/mysql/init/12-rbac-switchover.sql';
        if (!is_file($seedFile)) {
            // In CI the whole repository is checked out and this runs.
            $this->markTestSkipped('baja-infra/ is not visible from here (the app container mounts baja-php/ only).');
        }
        $seedSql = (string) file_get_contents($seedFile);

        $rolesBlock = fn(string $sql) => substr($sql, strpos($sql, 'INSERT IGNORE INTO `role`'), strpos($sql, "FROM `role` WHERE `code` IN ('platform_admin', 'program_manager', 'event_manager');") - strpos($sql, 'INSERT IGNORE INTO `role`'));
        $this->assertSame($rolesBlock($migrationSql), $rolesBlock($seedSql));
    }
}
