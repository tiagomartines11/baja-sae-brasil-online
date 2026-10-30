<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Rbac;

use Baja\Rbac\Legacy\LegacyCode;
use Baja\Rbac\Legacy\LegacyGrantPlan;
use Baja\Rbac\Legacy\LegacyMapping;
use Baja\Rbac\Legacy\LegacyWorld;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What each legacy permission code becomes in the switch-over to RBAC
 * (BAJAAPP-76).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * Before RBAC, what a person could do was a list of short codes stored in
 * their row of the `user` table: "admin", "26BR_END" (judge of the Enduro
 * of event 26BR), "26BR_TIMER_ADMIN" and so on. The migration reads every
 * code once and gives the person a ROLE on a PLACE instead (a "grant"):
 * "judge on prova 26BR/END".
 *
 * LegacyCode::map() is the table that says which code becomes which grant.
 * It needs to know whether the event, prova, queue or timer in the code
 * still exists; here a small made-up world answers that, so no database
 * is needed:
 *
 *     events     26BR (provas END, MAN; queues 1, 2; timers 1, 2), 26SE
 *     programs   BAJA, AERO, FORMULA at the top (AERO_FORUM is under AERO)
 *
 * WHAT IS TESTED
 *
 * Every kind of code the site ever checked, the codes that must NOT become
 * a grant (and how they are reported), and the reading of the stored list.
 */
final class LegacyCodeTest extends TestCase
{
    private function world(): LegacyWorld
    {
        return new class implements LegacyWorld {
            public function eventExists(string $eventoId): bool
            {
                return in_array($eventoId, ['26BR', '26SE'], true);
            }

            public function provaExists(string $eventoId, string $provaId): bool
            {
                return $eventoId === '26BR' && in_array($provaId, ['END', 'MAN'], true);
            }

            public function filaExists(string $eventoId, int $filaId): bool
            {
                return $eventoId === '26BR' && in_array($filaId, [1, 2], true);
            }

            public function timerExists(string $eventoId, int $timerId): bool
            {
                return $eventoId === '26BR' && in_array($timerId, [1, 2], true);
            }

            public function topLevelProgramas(): array
            {
                return ['AERO', 'BAJA', 'FORMULA'];
            }
        };
    }

    /**
     * Each code that becomes exactly one grant: [code, role, node key].
     * A node key is "type:id", e.g. "prova:26BR/END".
     */
    public static function oneGrant(): array
    {
        return [
            'admin is the platform admin' => ['admin', 'platform_admin', 'platform:'],
            'a prova code is a judge on that prova' => ['26BR_END', 'judge', 'prova:26BR/END'],
            'the counter code is a lap counter on the prova' => ['26BR_END_CONT', 'lap_counter', 'prova:26BR/END'],
            'the pit code is a pit logger on the prova' => ['26BR_END_BOX', 'pit_logger', 'prova:26BR/END'],
            'PREMIACAO lets you see the prizes of the event' => ['26BR_PREMIACAO', 'awards_viewer', 'evento:26BR'],
            'TIMER_ADMIN manages the timers of the event' => ['26BR_TIMER_ADMIN', 'timer_manager', 'evento:26BR'],
            'one timer becomes every timer of its event' => ['26BR_TIMER_2', 'timer_operator', 'evento:26BR'],
            'a queue admin manages that queue' => ['26BR_FILA_1_ADMIN', 'queue_manager', 'fila:26BR/1'],
        ];
    }

    #[DataProvider('oneGrant')]
    public function testACodeBecomesItsGrant(string $code, string $role, string $node): void
    {
        $mapping = LegacyCode::map($code, $this->world());

        $this->assertSame(LegacyMapping::GRANT, $mapping->outcome);
        $this->assertCount(1, $mapping->grants);
        [$gotRole, $gotNode] = $mapping->grants[0];
        $this->assertSame($role, $gotRole);
        $this->assertSame($node, $gotNode->key());
    }

    /**
     * 'certificados' was global: it let you issue certificates for ANY
     * event. The certificate manager role can only be given on a program,
     * so the migration gives it on every program at the top of the tree,
     * which together hold every event.
     */
    public function testCertificadosBecomesACertificateManagerOnEveryTopProgram(): void
    {
        $mapping = LegacyCode::map('certificados', $this->world());

        $this->assertSame(LegacyMapping::GRANT, $mapping->outcome);
        $this->assertSame(
            ['programa:AERO', 'programa:BAJA', 'programa:FORMULA'],
            array_map(fn($g) => $g[1]->key(), $mapping->grants)
        );
        foreach ($mapping->grants as [$role]) {
            $this->assertSame('certificate_manager', $role);
        }
    }

    /** 'index' only opened the start page; it becomes nothing, on purpose. */
    public function testIndexIsDroppedOnPurpose(): void
    {
        $this->assertSame(LegacyMapping::DROPPED, LegacyCode::map('index', $this->world())->outcome);
    }

    /**
     * "26BR_FILA_1_42" is team 42's access to queue 1. A team is not staff,
     * so this is not a role: the code is KEPT where it is, and the queue
     * pages still read it (the teams package will move it).
     */
    public function testATeamsQueueCodeIsKept(): void
    {
        $mapping = LegacyCode::map('26BR_FILA_1_42', $this->world());

        $this->assertSame(LegacyMapping::KEPT, $mapping->outcome);
        $this->assertSame([], $mapping->grants);
    }

    /**
     * Codes that should have meant something but point at nothing: they are
     * UNMAPPED, with a reason, so the migration can list them for a person
     * to check. None of them may silently become a grant.
     */
    public static function unmapped(): array
    {
        return [
            'an event that does not exist' => ['99XX_END'],
            'a prova that does not exist' => ['26BR_ZZZ'],
            'the counter code of a missing prova' => ['26BR_ZZZ_CONT'],
            'the pit code of a missing prova' => ['26BR_ZZZ_BOX'],
            'a timer that does not exist' => ['26BR_TIMER_9'],
            'a queue that does not exist' => ['26BR_FILA_7_ADMIN'],
            'a queue id that is not a number' => ['26BR_FILA_X_ADMIN'],
            'a prova of the wrong case (ids are exact)' => ['26BR_end'],
            'something that never was a code' => ['juiz'],
            'an empty code' => [''],
        ];
    }

    #[DataProvider('unmapped')]
    public function testACodeThatPointsAtNothingIsUnmappedWithAReason(string $code): void
    {
        $mapping = LegacyCode::map($code, $this->world());

        $this->assertSame(LegacyMapping::UNMAPPED, $mapping->outcome, "'$code'");
        $this->assertSame([], $mapping->grants);
        $this->assertNotSame('', $mapping->reason);
    }

    /**
     * The `permissions` column stores the list as "| a | b |" (Propel's
     * format). Blanks and repeats are ignored; an empty or missing column
     * is an empty list.
     */
    public function testTheStoredListIsReadAsPropelWritesIt(): void
    {
        $this->assertSame(['index', '26BR_END'], LegacyGrantPlan::codes('| index | 26BR_END |'));
        $this->assertSame(['index'], LegacyGrantPlan::codes('| index | index |'));
        $this->assertSame([], LegacyGrantPlan::codes(null));
        $this->assertSame([], LegacyGrantPlan::codes(''));
    }
}
