<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\Clock;

/**
 * "How far has this counter uploaded?" when a counter has several phones.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The engine must tell "this counter missed the car" from "this counter's
 * phone has not uploaded yet". Each phone therefore reports, whenever its
 * queue is empty, "I have sent everything up to now": its "synced through"
 * mark. A SOURCE (one counter position, say "Contagem 1") can have several
 * phones over a race: the relief counter's during a handover, a replacement
 * for a dead battery.
 *
 * The rule: a source is synced through the EARLIEST mark among its OPEN
 * phones. Earliest, because one phone's empty queue says nothing about what
 * another phone still holds. Only open phones, because a phone that will
 * not come back must not block the race for ever. A phone stops being open
 * when a controller revokes it, when its counter presses "Encerrar meu
 * turno", or when it has been silent for ten minutes.
 */
final class DeviceSyncTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    /** The "synced through" mark the engine is given for Contagem 1. */
    private function markOfSource1(): ?int
    {
        $this->newRequest();
        foreach ($this->service->loadInput()->sources as $source) {
            if ($source->id === (int) $this->fontes[0]->getFonteId()) {
                return $source->sincronizadoAteMs;
            }
        }
        $this->fail('Contagem 1 is missing from the engine input.');
    }

    /** A phone says "my queue is empty" at the given second of the race. */
    private function heartbeatAt(string $token, int $seconds, array $extra = []): array
    {
        Clock::freezeAt($this->at($seconds));
        return $this->upload($token, [], ['queue_empty' => true] + $extra);
    }

    /**
     * SITUATION: a handover on Contagem 1. Ana's phone last said "all
     * uploaded" at minute 5 and has had no signal since (it may be holding
     * entries). Bruno's phone, the relief, says "all uploaded" at minute 10.
     *
     * EXPECTED: the source counts as synced through minute 5 only. Bruno's
     * empty queue cannot vouch for whatever Ana typed after minute 5.
     */
    public function testWithTwoPhonesOnOneSourceTheEarliestMarkCounts(): void
    {
        $anasPhone = $this->issueToken($this->fontes[0], 'zzt_ana');
        $brunosPhone = $this->issueToken($this->fontes[0], 'zzt_bruno');

        $this->heartbeatAt($anasPhone, 300);
        $this->heartbeatAt($brunosPhone, 600);

        $this->assertSame($this->at(300), $this->markOfSource1());
    }

    /**
     * SITUATION: the same handover, done properly: before leaving, Ana
     * presses "Encerrar meu turno" at minute 6. Her phone sends everything
     * it has and reports that it is done. Bruno carries on.
     *
     * EXPECTED: Ana's phone is no longer waited for, so the source is
     * synced through Bruno's mark (minute 10).
     */
    public function testAPhoneWhoseCounterEndedTheirShiftIsNoLongerWaitedFor(): void
    {
        $anasPhone = $this->issueToken($this->fontes[0], 'zzt_ana');
        $brunosPhone = $this->issueToken($this->fontes[0], 'zzt_bruno');

        $this->heartbeatAt($anasPhone, 360, ['encerrando' => true]);
        $this->heartbeatAt($brunosPhone, 600);

        $this->assertSame($this->at(600), $this->markOfSource1());
    }

    /**
     * SITUATION: Ana ended her shift at minute 6, then came back at minute
     * 8 to cover for Bruno and her phone is counting again.
     *
     * EXPECTED: any ordinary contact reopens the phone, so the source is
     * again held to the earliest of the two marks (Ana's, minute 8).
     */
    public function testAPhoneThatComesBackAfterEndingItsShiftIsOpenAgain(): void
    {
        $anasPhone = $this->issueToken($this->fontes[0], 'zzt_ana');
        $brunosPhone = $this->issueToken($this->fontes[0], 'zzt_bruno');

        $this->heartbeatAt($anasPhone, 360, ['encerrando' => true]);
        $this->heartbeatAt($anasPhone, 480);
        $this->heartbeatAt($brunosPhone, 600);

        $this->assertSame($this->at(480), $this->markOfSource1());
    }

    /**
     * SITUATION: Ana's phone died at minute 5 without a word. Nobody
     * revoked it. Bruno took over on another phone. It is now minute 16.
     *
     * EXPECTED: for the first ten minutes of silence the source is held at
     * Ana's mark, in case her phone is merely out of signal. After that the
     * phone is treated as gone and Bruno's mark counts. Without this, one
     * dead phone would silence every review flag and every "car is late"
     * warning for the rest of the race.
     */
    public function testAPhoneSilentForTenMinutesIsNoLongerWaitedFor(): void
    {
        $anasPhone = $this->issueToken($this->fontes[0], 'zzt_ana');
        $brunosPhone = $this->issueToken($this->fontes[0], 'zzt_bruno');
        $this->heartbeatAt($anasPhone, 300);

        $this->heartbeatAt($brunosPhone, 840);
        $this->assertSame($this->at(300), $this->markOfSource1(), 'nine minutes of silence: still waited for');

        $this->heartbeatAt($brunosPhone, 960);
        $this->assertSame($this->at(960), $this->markOfSource1(), 'eleven minutes of silence: given up on');
    }

    /**
     * SITUATION: a controller revokes Ana's phone (she was removed from the
     * source). Bruno's phone keeps counting.
     *
     * EXPECTED: the revoked phone is ignored and Bruno's mark counts. (It
     * used to be the other way round: revoking a phone made the whole
     * source look as if it had never synced.)
     */
    public function testARevokedPhoneDoesNotHoldItsSourceBack(): void
    {
        $anasPhone = $this->issueToken($this->fontes[0], 'zzt_ana');
        $brunosPhone = $this->issueToken($this->fontes[0], 'zzt_bruno');
        $this->heartbeatAt($anasPhone, 300);
        $this->heartbeatAt($brunosPhone, 600);

        $this->executeSql(
            'UPDATE contagem_dispositivo SET revogado_em_ms = ? WHERE fonte_id = ? AND usuario = ?',
            [$this->at(600), $this->fontes[0]->getFonteId(), 'zzt_ana']
        );

        $this->assertSame($this->at(600), $this->markOfSource1());
    }

    /**
     * SITUATION: nobody has ever opened Contagem 1 on any phone.
     *
     * EXPECTED: there is nobody whose upload could still arrive, so the
     * engine is told there is nothing to wait for. A counter position that
     * was created and never staffed must not hold the review back.
     */
    public function testASourceWithNoPhoneAtAllHasNothingToWaitFor(): void
    {
        $this->assertSame(Source::NOTHING_TO_WAIT_FOR, $this->markOfSource1());
    }

    /**
     * SITUATION: a phone has just opened the count page (it has a token)
     * and has sent a first heartbeat, but with entries still in its queue,
     * so it has never said "all uploaded".
     *
     * EXPECTED: the source is "unknown" (no mark at all): we know a phone
     * is there and we know nothing about what it holds.
     */
    public function testAnOpenPhoneThatNeverReportedAnEmptyQueueLeavesTheSourceUnknown(): void
    {
        $phone = $this->issueToken($this->fontes[0]);
        $this->upload($phone, [], ['queue_empty' => false]);

        $this->assertNull($this->markOfSource1());
    }

    /**
     * SITUATION: a counter closes the browser tab. Browsers allow a page
     * one last short message while closing, but that message cannot carry
     * the usual Authorization header, so the page puts its token in the
     * body together with "encerrando".
     *
     * EXPECTED: that goodbye is accepted and closes the device. The same
     * body WITHOUT "encerrando" is refused: a token in the body is accepted
     * for this one purpose only, never for uploading entries.
     */
    public function testTheGoodbyeOfAClosingPageIsAcceptedWithItsTokenInTheBody(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $this->heartbeatAt($token, 300);

        $goodbye = $this->upload(null, [], ['queue_empty' => true, 'encerrando' => true, 'token' => $token]);
        $this->assertSame(200, $goodbye['status']);
        $this->assertSame(1, $this->countRows('contagem_dispositivo', 'fonte_id = ? AND fechado_em_ms IS NOT NULL', [$this->fontes[0]->getFonteId()]));

        $notAGoodbye = $this->upload(null, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(290))], ['token' => $token]);
        $this->assertSame(401, $notAGoodbye['status']);
        $this->assertSame('token_ausente', $notAGoodbye['body']['erro']);
    }

    /**
     * SITUATION: a phone says "I am done" but admits its queue is not empty.
     *
     * EXPECTED: it is not closed. A phone that still holds entries is the
     * very thing the engine must keep waiting for.
     */
    public function testAPhoneWithUnsentEntriesCannotEndItsShift(): void
    {
        $token = $this->issueToken($this->fontes[0]);

        $this->upload($token, [], ['queue_empty' => false, 'encerrando' => true]);

        $this->assertSame(0, $this->countRows('contagem_dispositivo', 'fonte_id = ? AND fechado_em_ms IS NOT NULL', [$this->fontes[0]->getFonteId()]));
    }
}
