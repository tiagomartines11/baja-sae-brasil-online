<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Service\Clock;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Contagem\Sync\SyncHandler;

/**
 * The upload endpoint: how entries typed on a phone reach the server.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * A counter stands at the finish line with a phone and types the number of
 * every car that passes. The phone saves each entry locally first, then
 * tries to send it to the server. The network at a race track is bad, so
 * the phone often does not get the server's answer and cannot know whether
 * the entry arrived. Its only safe move is to send it again. The server
 * therefore has to accept the same entry any number of times and store it
 * exactly once. Each entry carries a "uuid" (a long random identifier the
 * phone made up) for that purpose: an entry whose uuid the server already
 * has is simply acknowledged again.
 *
 * The phone identifies itself with a secret "token" it received when the
 * counter opened the count page. It does not need the counter to still be
 * logged in to the forum: logins expire after a while, and a race lasts
 * four hours.
 *
 * Every test here calls the endpoint's logic directly (SyncHandler) through
 * the upload() helper, which is the same code the real URL runs.
 */
final class SyncUploadTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // "Now" is fixed at ten minutes after the start, so every run of
        // these tests sees exactly the same times.
        Clock::freezeAt($this->at(600));
        $this->startRace($this->at(0));
    }

    /**
     * SITUATION: a counter recorded two cars. The phone uploads them, the
     * server stores them, but the answer is lost on the way back (no
     * signal). The phone, not knowing better, uploads the same two entries
     * again.
     *
     * EXPECTED: both uploads are answered with success and both name the
     * two entries as stored, so the phone can stop resending. The database
     * holds two entries, not four. A duplicate here would give a car a lap
     * it never ran.
     */
    public function testTheSameBatchUploadedTwiceIsStoredOnce(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $firstUuid = self::newUuid();
        $secondUuid = self::newUuid();
        $batch = [
            $this->entryPayload($firstUuid, self::CAR_A, $this->at(120)),
            $this->entryPayload($secondUuid, self::CAR_B, $this->at(130)),
        ];

        $firstAnswer = $this->upload($token, $batch);
        $secondAnswer = $this->upload($token, $batch);

        $this->assertSame(200, $firstAnswer['status']);
        $this->assertSame(200, $secondAnswer['status']);
        $this->assertEqualsCanonicalizing([$firstUuid, $secondUuid], $firstAnswer['body']['stored_uuids']);
        $this->assertEqualsCanonicalizing([$firstUuid, $secondUuid], $secondAnswer['body']['stored_uuids']);
        $this->assertSame(2, $this->entriesStoredFor($this->fontes[0]->getFonteId()));
    }

    /**
     * SITUATION: the phone's first upload (one entry) got through but its
     * answer was lost. Meanwhile the counter typed another car. The next
     * upload carries both: the old entry again, and the new one.
     *
     * EXPECTED: the server stores only the entry it did not have yet and
     * acknowledges both.
     */
    public function testAResendTogetherWithANewEntryStoresOnlyTheNewOne(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $oldEntry = $this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120));
        $newEntry = $this->entryPayload(self::newUuid(), self::CAR_B, $this->at(130));

        $this->upload($token, [$oldEntry]);
        $answer = $this->upload($token, [$oldEntry, $newEntry]);

        $this->assertSame(200, $answer['status']);
        $this->assertEqualsCanonicalizing([$oldEntry['uuid'], $newEntry['uuid']], $answer['body']['stored_uuids']);
        $this->assertSame(2, $this->entriesStoredFor($this->fontes[0]->getFonteId()));
    }

    /**
     * SITUATION: somebody calls the upload address without any token, for
     * instance a curious person who found the URL.
     *
     * EXPECTED: HTTP 401 ("you did not say who you are") with the error
     * code "token_ausente", and nothing is stored.
     */
    public function testAnUploadWithoutATokenIsRefused(): void
    {
        $answer = $this->upload(null, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))]);

        $this->assertSame(401, $answer['status']);
        $this->assertSame('token_ausente', $answer['body']['erro']);
        $this->assertSame(0, $this->entriesStoredFor($this->fontes[0]->getFonteId()));
    }

    /**
     * SITUATION: an upload arrives with a token the server never issued
     * (a guess, or a token from another installation).
     *
     * EXPECTED: HTTP 401 with "token_invalido", and nothing is stored.
     */
    public function testAnUploadWithAnUnknownTokenIsRefused(): void
    {
        $answer = $this->upload('this-token-was-never-issued', [
            $this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120)),
        ]);

        $this->assertSame(401, $answer['status']);
        $this->assertSame('token_invalido', $answer['body']['erro']);
        $this->assertSame(0, $this->entriesStoredFor($this->fontes[0]->getFonteId()));
    }

    /**
     * SITUATION: a phone was counting normally and had uploaded one entry.
     * Then the person in charge revoked that phone (for example, the
     * counter was replaced and took the phone home). The phone tries to
     * upload one more entry.
     *
     * EXPECTED: the upload is refused with HTTP 401 and "token_invalido",
     * the same answer an unknown token gets. The entry stored before the
     * revocation is still there; the new one is not stored. (The phone
     * keeps it locally and offers it for export, which is tested with the
     * browser code.)
     */
    public function testARevokedTokenIsRefusedAndNothingMoreIsStored(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $this->upload($token, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))]);

        DeviceTokens::revoke(DeviceTokens::findActiveByToken($token));
        $answer = $this->upload($token, [$this->entryPayload(self::newUuid(), self::CAR_B, $this->at(130))]);

        $this->assertSame(401, $answer['status']);
        $this->assertSame('token_invalido', $answer['body']['erro']);
        $this->assertSame(1, $this->entriesStoredFor($this->fontes[0]->getFonteId()));
    }

    /**
     * SITUATION: the counter logged in at eight in the morning. By the
     * third hour of the race the forum login has expired, so the browser
     * no longer has a valid session cookie. The phone keeps uploading.
     *
     * EXPECTED: the upload works. The token alone is enough.
     *
     * HOW THE TEST SHOWS IT: nobody is logged in and there are no cookies.
     * If the endpoint asked "who is logged in?", the application would try
     * to redirect to the login page and stop the program, and this test
     * would not reach its assertions.
     */
    public function testAnUploadWorksWithoutAnyLoginSession(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $this->logout();
        $_COOKIE = [];
        $uuid = self::newUuid();

        $answer = $this->upload($token, [$this->entryPayload($uuid, self::CAR_A, $this->at(120))]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame([$uuid], $answer['body']['stored_uuids']);
        $this->assertSame(1, $this->entriesStoredFor($this->fontes[0]->getFonteId()));
    }

    /**
     * SITUATION: the body of the upload is not valid JSON (a broken client,
     * or a connection cut in the middle).
     *
     * EXPECTED: HTTP 400 ("the request makes no sense") with
     * "json_invalido". The token was fine, so this is not a 401.
     */
    public function testABodyThatIsNotJsonIsRejected(): void
    {
        $token = $this->issueToken($this->fontes[0]);

        $answer = SyncHandler::handle('Bearer ' . $token, '{"entries": [');

        $this->assertSame(400, $answer['status']);
        $this->assertSame('json_invalido', $answer['body']['erro']);
    }

    /**
     * SITUATION: the counter typed car 11 by mistake and pressed "desfazer"
     * (undo) on it. The phone sends that as a "void". As with entries, the
     * answer is lost and the phone sends the same void again.
     *
     * EXPECTED: the entry itself is never deleted (raw data is kept
     * forever). Instead ONE correction record of type "anular" is created,
     * marked as coming from the counter. The second copy of the void adds
     * nothing.
     */
    public function testAVoidSentTwiceCreatesASingleCorrection(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $uuid = self::newUuid();
        $this->upload($token, [$this->entryPayload($uuid, self::CAR_A, $this->at(120))]);

        $firstAnswer = $this->upload($token, [], ['voids' => [['uuid' => $uuid]]]);
        $secondAnswer = $this->upload($token, [], ['voids' => [['uuid' => $uuid]]]);

        $this->assertSame([$uuid], $firstAnswer['body']['voided_uuids']);
        $this->assertSame([$uuid], $secondAnswer['body']['voided_uuids']);
        $this->assertSame(1, $this->entriesStoredFor($this->fontes[0]->getFonteId()), 'the raw entry is kept');
        $this->assertSame(1, $this->countRows(
            'contagem_decisao',
            'evento_id = ? AND prova_id = ? AND tipo = ? AND origem = ?',
            [self::EVENTO_ID, self::PROVA_ID, 'anular', 'contador']
        ));
    }

    /**
     * SITUATION: a phone's clock is 5 seconds behind the server's. The
     * phone finds that out by comparing clocks with the server, but only
     * after its first successful contact. The counter typed a car BEFORE
     * that, while the phone had no idea how wrong its clock was. That
     * entry is uploaded with "clock offset unknown" (null). On the next
     * upload the phone reports its offset: +5000 ms.
     *
     * EXPECTED: the early entry ends up with the corrected time (phone
     * time + 5 seconds), because the server applies the first offset the
     * device ever reports to the entries that came without one. The stored
     * entry itself is not rewritten: its corrected time stays empty in the
     * table and is worked out whenever the data is read.
     */
    public function testAnEntryRecordedBeforeTheClockOffsetWasKnownIsCorrectedLater(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $phoneTime = $this->at(120);

        $this->upload(
            $token,
            [$this->entryPayload(self::newUuid(), self::CAR_A, $phoneTime, null)],
            ['clock_offset_ms' => null]
        );
        $this->upload($token, [], ['clock_offset_ms' => 5000]);

        $this->assertSame(1, $this->countRows(
            'contagem_passagem',
            'fonte_id = ? AND ts_ms IS NULL',
            [$this->fontes[0]->getFonteId()]
        ), 'the stored row keeps "time unknown"');

        $entries = $this->service->loadInput()->entries;
        $this->assertCount(1, $entries);
        $this->assertSame($phoneTime + 5000, $entries[0]->tsMs);
    }

    /**
     * SITUATION: a phone uploads and says "my queue is empty": it has
     * nothing left to send.
     *
     * EXPECTED: the server notes that this device is "synced through" the
     * phone's current time. The engine uses that mark to tell a counter
     * who missed a car from a phone that simply has not uploaded yet. A
     * phone that still has entries waiting must NOT move the mark.
     */
    public function testAnEmptyQueueMovesTheSyncedThroughMark(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $phoneNow = $this->at(500);

        $this->upload($token, [], ['device_now_ms' => $phoneNow, 'queue_empty' => false]);
        $this->assertNull($this->syncedThroughOf($token), 'entries still waiting: no mark yet');

        $this->upload($token, [], ['device_now_ms' => $phoneNow, 'queue_empty' => true]);
        $this->assertSame($phoneNow, $this->syncedThroughOf($token));
    }

    private function entriesStoredFor(int $fonteId): int
    {
        return $this->countRows('contagem_passagem', 'fonte_id = ?', [$fonteId]);
    }

    private function syncedThroughOf(string $token): ?int
    {
        $mark = DeviceTokens::findActiveByToken($token)->getSincronizadoAteMs();
        return $mark === null ? null : (int) $mark;
    }
}
