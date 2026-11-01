<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Service\Clock;
use Baja\Model\UserQuery;
use Propel\Runtime\Propel;

/**
 * Who may log the pit lane and the fueling station.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The people at the pit lane are often not the counters. So the pit log has
 * a role of its own, pit_logger on the prova (before RBAC, BAJAAPP-76, the
 * code "{evento}_{prova}_BOX", here 00NE_ZZL_BOX). The counter role does
 * not open the pit page, and the pit role does not open a count page. The
 * controller (the judge role, legacy 00NE_ZZL) has both.
 *
 * The tests below name the legacy codes; createUser() gives the user what
 * the migration made of each (DbTestCase).
 *
 * A pit phone keeps its token after its user's access is removed, and the
 * browser keeps an offline copy of the page. So the upload endpoint, which
 * sees only the token, checks the permission of the device's user on every
 * upload of stops.
 */
final class PitLogAccessTest extends DbTestCase
{
    private const PIT_CODE = self::EVENTO_ID . '_' . self::PROVA_ID . '_BOX';
    private const COUNTER_CODE = self::EVENTO_ID . '_' . self::PROVA_ID . '_CONT';
    private const CONTROLLER_CODE = self::EVENTO_ID . '_' . self::PROVA_ID;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    private function mayOpenThePitPage(string $username, array $permissions): bool
    {
        $this->createUser($username, $permissions);
        $this->loginAs($username);
        return ContagemAccess::canUsePitLog($this->prova);
    }

    private function boxEntry(int $car, int $seconds): array
    {
        return [
            'uuid' => self::newUuid(),
            'car' => $car,
            'tipo' => 'box_entrada',
            'ts_ms' => $this->at($seconds),
            'time_quality' => 'exato',
            'ts_device_ms' => $this->at($seconds),
        ];
    }

    /**
     * SITUATION: four users. One has only "index" (logged in, nothing
     * else), one is a counter, one has the pit code, one is the controller.
     *
     * WHAT WENT WRONG BEFORE: the pit page opened for every counter, and an
     * administrator could not give the pit page to somebody without also
     * making them a counter.
     *
     * EXPECTED: the pit page opens for the pit code and the controller,
     * and for nobody else; in particular not for a counter.
     */
    public function testThePitPageOpensForThePitCodeAndTheControllerOnly(): void
    {
        $this->assertFalse($this->mayOpenThePitPage(self::USER_PREFIX . 'so_index', ['index']));
        $this->assertFalse($this->mayOpenThePitPage(self::USER_PREFIX . 'contador_so', ['index', self::COUNTER_CODE]), 'a counter does not get the pit page any more');
        $this->assertTrue($this->mayOpenThePitPage(self::USER_PREFIX . 'so_box', ['index', self::PIT_CODE]));
        $this->assertTrue($this->mayOpenThePitPage(self::USER_PREFIX . 'chefe', ['index', self::CONTROLLER_CODE]));
    }

    /**
     * SITUATION: a pit volunteer logs stops from their phone. During the
     * race an administrator removes their pit code (they left, or it was
     * given to the wrong person). Their phone still holds its token, and
     * the browser still has the page.
     *
     * WHAT WENT WRONG BEFORE: the token was the only thing the upload
     * endpoint looked at, so the phone went on logging stops.
     *
     * EXPECTED: while the code is held, the stop is stored. Once it is
     * removed, the next upload is refused with 403 "sem_permissao" and
     * nothing of it is stored; what was stored before stays.
     */
    public function testAPitPhoneWhoseUserLostTheCodeCanNoLongerLogStops(): void
    {
        $username = self::USER_PREFIX . 'box';
        $token = $this->issuePitLogToken($username);

        $first = $this->boxEntry(self::CAR_A, 120);
        $allowed = $this->upload($token, [], ['pit_events' => [$first]]);
        $this->assertSame([$first['uuid']], $allowed['body']['stored_uuids']);

        // Her pit logger role is taken away (an administrator revoked it on
        // admin_usuario.php). The phone keeps its token.
        $user = UserQuery::create()->findOneByUsername($username);
        Propel::getWriteConnection('resultados')
            ->prepare('UPDATE user_role SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')
            ->execute([(int) $user->getUserId()]);

        $second = $this->boxEntry(self::CAR_B, 180);
        $refused = $this->upload($token, [], ['pit_events' => [$second]]);

        $this->assertSame(403, $refused['status']);
        $this->assertSame('sem_permissao', $refused['body']['erro']);
        $this->assertSame(1, $this->countRows(
            'contagem_ocorrencia',
            'evento_id = ? AND prova_id = ? AND tipo = ?',
            [self::EVENTO_ID, self::PROVA_ID, 'box_entrada']
        ), 'the first stop stays, the second was not stored');
    }

    /**
     * SITUATION: the same pit phone, but this time the pit logger role was
     * not taken away: it ENDED (its valid_until passed, as every grant on
     * an event does some days after the event).
     *
     * EXPECTED: refused just the same: 403 "sem_permissao", nothing stored.
     */
    public function testAPitPhoneWhoseRoleEndedCanNoLongerLogStops(): void
    {
        $username = self::USER_PREFIX . 'box';
        $token = $this->issuePitLogToken($username);

        $user = UserQuery::create()->findOneByUsername($username);
        Propel::getWriteConnection('resultados')
            ->prepare('UPDATE user_role SET valid_until = NOW() - INTERVAL 1 MINUTE WHERE user_id = ?')
            ->execute([(int) $user->getUserId()]);

        $refused = $this->upload($token, [], ['pit_events' => [$this->boxEntry(self::CAR_A, 120)]]);

        $this->assertSame(403, $refused['status']);
        $this->assertSame('sem_permissao', $refused['body']['erro']);
    }

    /**
     * SITUATION: the controller (who has no pit code, but holds the prova's
     * own code) logs stops from a pit phone while a volunteer is away.
     *
     * EXPECTED: stored. The controller can do everything in the prova.
     */
    public function testTheControllerMayLogStopsWithoutThePitCode(): void
    {
        $username = self::USER_PREFIX . 'chefe_box';
        $this->createUser($username, ['index', self::CONTROLLER_CODE]);
        $token = $this->issuePitLogToken($username);

        $event = $this->boxEntry(self::CAR_A, 120);
        $answer = $this->upload($token, [], ['pit_events' => [$event]]);

        $this->assertSame([$event['uuid']], $answer['body']['stored_uuids']);
    }
}
