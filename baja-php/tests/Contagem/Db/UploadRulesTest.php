<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Access\CounterLinks;
use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Sync\DeviceTokens;

/**
 * Smaller rules of the upload endpoint and of handing out device tokens.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A "device" is one phone (or browser) bound to one counter position (a
 * "source"). It proves who it is with a secret token. A "void" is a counter
 * pressing "desfazer" on an entry they typed by mistake; the entry is never
 * deleted, a correction record of type "anular" is added instead, and such
 * a record can itself be undone by a reviewer ("desfazer").
 */
final class UploadRulesTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    private function syncedThroughOf(string $token): ?int
    {
        $this->newRequest();
        $mark = DeviceTokens::findActiveByToken($token)->getSincronizadoAteMs();
        return $mark === null ? null : (int) $mark;
    }

    private function voidsInForceFor(int $entryId): int
    {
        // "In force" means: not undone by a later record of type "desfazer".
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM contagem_decisao d
              WHERE d.tipo = ? AND d.passagem_id = ?
                AND NOT EXISTS (SELECT 1 FROM contagem_decisao u WHERE u.tipo = ? AND u.desfaz_decisao_id = d.decisao_id)',
            [Decision::ANULAR, $entryId, Decision::DESFAZER]
        );
    }

    /**
     * SITUATION: a phone on a bad connection has two requests in the air at
     * once. The one sent LATER (saying "everything uploaded up to 10:00")
     * arrives first; the one sent EARLIER (saying "up to 9:00") arrives
     * after it.
     *
     * WHAT WENT WRONG BEFORE: each request wrote the mark it carried, so
     * the late arrival moved the mark back to 9:00. For a while the engine
     * believed the phone had not uploaded the last minute.
     *
     * EXPECTED: the mark never moves backwards. It stays at 10:00.
     */
    public function testAnOlderRequestArrivingLateDoesNotMoveTheSyncMarkBack(): void
    {
        $token = $this->issueToken($this->fontes[0]);

        $this->upload($token, [], ['now_ms' => $this->at(600), 'queue_empty' => true]);
        $this->upload($token, [], ['now_ms' => $this->at(540), 'queue_empty' => true]);

        $this->assertSame($this->at(600), $this->syncedThroughOf($token));
    }

    /**
     * SITUATION: a counter presses "Encerrar meu turno". The page must only
     * say "Turno encerrado" when the server has really closed the device;
     * if entries are still waiting on the phone, the server refuses to
     * close it.
     *
     * EXPECTED: the answer says `encerrado: true` only for the request that
     * closed the device (queue empty), and `false` for the one that still
     * had entries waiting, and for any ordinary heartbeat.
     */
    public function testTheAnswerSaysWhetherTheShiftWasReallyEnded(): void
    {
        $token = $this->issueToken($this->fontes[0]);

        $ordinary = $this->upload($token, [], ['queue_empty' => true]);
        $withEntriesWaiting = $this->upload($token, [], ['queue_empty' => false, 'encerrando' => true]);
        $done = $this->upload($token, [], ['queue_empty' => true, 'encerrando' => true]);

        $this->assertFalse($ordinary['body']['encerrado']);
        $this->assertFalse($withEntriesWaiting['body']['encerrado']);
        $this->assertTrue($done['body']['encerrado']);
    }

    /**
     * SITUATION: a counter typed car 11 by mistake. A reviewer notices and
     * cancels the entry on the review screen. A moment later the counter
     * notices too and presses "desfazer" on the phone.
     *
     * WHAT WENT WRONG BEFORE: the phone's void was stored as a second
     * cancellation on top of the reviewer's. When the reviewer later
     * changed their mind and undid their own cancellation, the entry stayed
     * cancelled by the hidden second one, and nobody could see why.
     *
     * EXPECTED: the phone's void is acknowledged (so the phone stops
     * sending it) but adds nothing: one cancellation is in force, the
     * reviewer's. Undoing it brings the entry back.
     */
    public function testACountersVoidDoesNotStackOnAReviewersVoid(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $uuid = self::newUuid();
        $this->upload($token, [$this->entryPayload($uuid, self::CAR_A, $this->at(120))]);
        $entryId = (int) $this->fetchValue('SELECT passagem_id FROM contagem_passagem WHERE uuid_cliente = ?', [$uuid]);

        $this->newRequest();
        $reviewersVoid = $this->service->withLock(
            fn() => $this->service->storeDecision(Decision::anular(0, $entryId), self::USER_PREFIX . 'revisor', null)
        );
        $answer = $this->upload($token, [], ['voids' => [['uuid' => $uuid]]]);

        $this->assertSame([$uuid], $answer['body']['voided_uuids']);
        $this->assertSame(1, $this->voidsInForceFor($entryId));

        $this->newRequest();
        $this->service->withLock(
            fn() => $this->service->storeDecision(Decision::desfazer(0, $reviewersVoid), self::USER_PREFIX . 'revisor', null)
        );
        $this->assertSame(0, $this->voidsInForceFor($entryId), 'undoing the reviewer\'s void brings the entry back');
    }

    /**
     * SITUATION: somebody runs the race simulation against a prova that is
     * NOT set up for it (`permitir_simulacao` is off, as it always is for a
     * real race). The simulation marks its entries with the entry mode
     * "simulacao". One real entry travels in the same upload.
     *
     * EXPECTED: the real entry is stored. The simulated one is not, and the
     * answer lists it under "refused" with the reason, so the page can show
     * it as refused instead of sending it for ever. Made-up passes must
     * never be able to reach a real race.
     */
    public function testSimulatedEntriesAreRefusedUnlessTheProvaAllowsSimulation(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $real = $this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120));
        $simulated = ['mode' => 'simulacao'] + $this->entryPayload(self::newUuid(), self::CAR_B, $this->at(130));

        $answer = $this->upload($token, [$real, $simulated]);

        $this->assertSame([$real['uuid']], $answer['body']['stored_uuids']);
        $this->assertSame([['uuid' => $simulated['uuid'], 'motivo' => 'simulacao_nao_permitida']], $answer['body']['refused']);
        $this->assertFalse($answer['body']['simulacao']);
        $this->assertSame(0, $this->countRows('contagem_passagem', 'uuid_cliente = ?', [$simulated['uuid']]));
    }

    /**
     * SITUATION: the same upload, on a prova prepared for a rehearsal:
     * `permitir_simulacao` is on.
     *
     * EXPECTED: the simulated entry is stored, and stays recognisable for
     * ever by its entry mode. The answer tells the page that simulation is
     * on, which is what makes it show the permanent "SIMULAÇÃO" banner.
     */
    public function testSimulatedEntriesAreStoredAndMarkedWhenTheProvaAllowsSimulation(): void
    {
        $this->changeSettings(['permitir_simulacao' => true]);
        $token = $this->issueToken($this->fontes[0]);
        $simulated = ['mode' => 'simulacao'] + $this->entryPayload(self::newUuid(), self::CAR_B, $this->at(130));

        $answer = $this->upload($token, [$simulated]);

        $this->assertSame([$simulated['uuid']], $answer['body']['stored_uuids']);
        $this->assertSame([], $answer['body']['refused']);
        $this->assertTrue($answer['body']['simulacao']);
        $this->assertSame('simulacao', $this->fetchValue('SELECT modo_entrada FROM contagem_passagem WHERE uuid_cliente = ?', [$simulated['uuid']]));
    }

    /**
     * SITUATION: a volunteer is misbehaving and the controller presses
     * "revogar" on their phone. The volunteer is still linked to the
     * counter position.
     *
     * WHAT WENT WRONG BEFORE: the count page, told that its token was no
     * longer valid, simply asked for a new one half a minute later and got
     * it. "Revogar" stopped nobody.
     *
     * EXPECTED: after the revocation that user is blocked on that source:
     * no new token. A user who was never revoked is not affected. The block
     * ends when the controller presses "liberar".
     */
    public function testAUserWhoseDeviceWasRevokedGetsNoNewTokenUntilReleased(): void
    {
        $this->createUser('zzt_ana', ['index']);
        CounterLinks::link($this->fontes[0], 'zzt_ana', self::USER_PREFIX . 'controle');
        $token = $this->issueToken($this->fontes[0], 'zzt_ana');
        $this->assertFalse(DeviceTokens::isBlocked($this->fontes[0], 'zzt_ana'));

        Clock::freezeAt($this->at(610));
        DeviceTokens::revoke(DeviceTokens::findActiveByToken($token));

        $this->assertTrue(DeviceTokens::isBlocked($this->fontes[0], 'zzt_ana'));
        $this->assertFalse(DeviceTokens::isBlocked($this->fontes[0], 'zzt_bruno'), 'somebody else is not affected');
        $this->assertFalse(DeviceTokens::isBlocked($this->fontes[1], 'zzt_ana'), 'nor is the same user on another source');

        Clock::freezeAt($this->at(620));
        DeviceTokens::release($this->fontes[0], 'zzt_ana');
        $this->assertFalse(DeviceTokens::isBlocked($this->fontes[0], 'zzt_ana'));
    }

    /**
     * SITUATION: the same revoked volunteer. This time the controller does
     * not press "liberar" but adds the user to the source again on the
     * "Contadores" page, which is the natural thing to do.
     *
     * EXPECTED: linking them again lets them back in, whether they were
     * removed first or were still linked.
     */
    public function testLinkingTheUserAgainAlsoEndsTheBlock(): void
    {
        $this->createUser('zzt_ana', ['index']);
        CounterLinks::link($this->fontes[0], 'zzt_ana', self::USER_PREFIX . 'controle');
        $token = $this->issueToken($this->fontes[0], 'zzt_ana');
        Clock::freezeAt($this->at(610));
        DeviceTokens::revoke(DeviceTokens::findActiveByToken($token));
        $this->assertTrue(DeviceTokens::isBlocked($this->fontes[0], 'zzt_ana'));

        Clock::freezeAt($this->at(620));
        CounterLinks::link($this->fontes[0], 'zzt_ana', self::USER_PREFIX . 'controle');

        $this->assertFalse(DeviceTokens::isBlocked($this->fontes[0], 'zzt_ana'));
    }

    /**
     * SITUATION: a controller (who may count on any source without being
     * linked to it) had a device revoked. There is no link on which a
     * release could be recorded.
     *
     * EXPECTED: "liberar" still works for them.
     */
    public function testReleasingAlsoWorksForAUserWithoutALink(): void
    {
        $token = $this->issueToken($this->fontes[0], 'zzt_controle');
        Clock::freezeAt($this->at(610));
        DeviceTokens::revoke(DeviceTokens::findActiveByToken($token));
        $this->assertTrue(DeviceTokens::isBlocked($this->fontes[0], 'zzt_controle'));

        Clock::freezeAt($this->at(620));
        DeviceTokens::release($this->fontes[0], 'zzt_controle');

        $this->assertFalse(DeviceTokens::isBlocked($this->fontes[0], 'zzt_controle'));
    }
}
