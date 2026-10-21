<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ScorePublisher;

/**
 * Publishing: how the lap count becomes points on the results page.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The results pages know nothing about lap counting. They read one "score
 * row" per car (table `input`), whose `dados` column is a small JSON object
 * such as {"VOLTAS": 43, "LAST": 14320, "BEST": 187, "DESCLAS": "NÃO"}.
 * A formula stored with the prova turns those fields into points.
 *
 * The "publisher" copies three numbers per car into that row:
 *
 *   VOLTAS  how many laps the car has officially completed
 *   LAST    when it last crossed the line, in whole seconds since the start
 *   BEST    its best lap in whole seconds (0 when it has none that qualifies)
 *
 * Two rules to keep in mind while reading:
 *
 * - A pass is official when at least two of the three counters recorded it.
 *   "A counted pass" below always means: two counters typed the same car at
 *   the same moment.
 * - LAPS = OFFICIAL PASSES - 1. The counting line is after the start line,
 *   so a car's first crossing ("pass zero") completes no lap; its second
 *   crossing completes lap 1.
 *
 * Two things can go wrong here, and both would be visible to every team:
 * wiping a field a judge typed by hand (DESCLAS, "disqualified"), and
 * showing a lap count whose points have not been recalculated yet.
 */
final class ScorePublishingTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // "Now" is fixed at ten minutes after the start.
        Clock::freezeAt($this->at(600));
    }

    /**
     * SITUATION: before the race a judge opened car 11's score row and set
     * DESCLAS to "NÃO". Then the race starts; car 11 crosses the line one
     * minute in (its pass zero) and again at four minutes (lap 1).
     *
     * EXPECTED: the row now says one lap, last pass at 240 seconds, and
     * DESCLAS is still "NÃO". The publisher sets its own fields inside the
     * object; it never replaces the object.
     */
    public function testPublishingKeepsFieldsThatPeopleTyped(): void
    {
        $this->setManualField(self::CAR_A, 'DESCLAS', 'NÃO');
        $this->startRace($this->at(0));

        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->recompute();

        $dados = $this->publishedData(self::CAR_A);
        $this->assertNotNull($dados);
        $this->assertSame('NÃO', $dados['DESCLAS']);
        $this->assertEquals(1, $dados['VOLTAS']);
        $this->assertEquals(240, $dados['LAST']);
    }

    /**
     * SITUATION: car 11 crosses the line for the first time, one minute
     * into the race, and two counters record it.
     *
     * EXPECTED: no lap yet, and no score row either. The first crossing is
     * pass zero. A row with zero laps would list the car on the results
     * page as if it had completed something.
     */
    public function testTheFirstCrossingIsPassZeroAndPublishesNothing(): void
    {
        $this->startRace($this->at(0));

        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recompute();

        $this->assertNull($this->publishedData(self::CAR_A));
    }

    /**
     * SITUATION: the race is running and car 11 already has its pass zero.
     * Counter 1's phone uploads "car 11 passed" at four minutes. Only one
     * counter has said so, so the pass is not official yet. Then counter
     * 2's phone uploads the same pass, which makes it official: lap 1.
     *
     * EXPECTED: points are NOT recalculated after the first upload (no
     * published number changed) and ARE recalculated, once, in the second
     * upload. Recalculating is the expensive step, which is why it must not
     * run for nothing; and it must run in the same request that changed the
     * count, so the results page never shows a count and points that
     * disagree.
     */
    public function testPointsAreRecalculatedExactlyWhenACountChanges(): void
    {
        $this->startRace($this->at(0));
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recompute();
        $tokenOfCounter1 = $this->issueToken($this->fontes[0]);
        $tokenOfCounter2 = $this->issueToken($this->fontes[1]);
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->upload($tokenOfCounter1, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(240))]);

        $this->assertSame($recalculationsBefore, ScorePublisher::refreshCount(), 'one counter alone changes nothing');
        $this->assertNull($this->publishedData(self::CAR_A), 'no lap yet, so no score row either');

        $this->upload($tokenOfCounter2, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(240))]);

        $this->assertSame($recalculationsBefore + 1, ScorePublisher::refreshCount());
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: car 11 already has its lap. Now the phones do what they do
     * most of the time: send a "heartbeat" every few seconds with nothing
     * new in it, and occasionally resend a batch the server already has.
     *
     * EXPECTED: no recalculation of points at all. With several phones
     * sending a heartbeat every five seconds for four hours, recalculating
     * each time would be thousands of pointless rewrites of every score.
     */
    public function testPointsAreNotRecalculatedWhenNothingChanged(): void
    {
        $this->startRace($this->at(0));
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $tokenOfCounter1 = $this->issueToken($this->fontes[0]);
        $tokenOfCounter2 = $this->issueToken($this->fontes[1]);
        $batch = [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(240))];
        $this->upload($tokenOfCounter1, $batch);
        $this->upload($tokenOfCounter2, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(240))]);
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->upload($tokenOfCounter1);
        $this->upload($tokenOfCounter2);
        $this->upload($tokenOfCounter1, $batch);

        $this->assertSame($recalculationsBefore, ScorePublisher::refreshCount());
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: two counters upload a short race: car 11 crosses the line
     * three times (pass zero and two laps), car 22 twice (pass zero and
     * one lap).
     *
     * EXPECTED: as soon as the upload that completed the picture returns,
     * the points are already right. With the endurance formula the leader
     * gets 400 and everyone else 400 x (own laps / leader's laps): car 11
     * has 400, car 22 has 400 x 1/2 = 200. Nobody has to press "refresh".
     */
    public function testPointsMatchTheCountAsSoonAsTheUploadReturns(): void
    {
        $this->startRace($this->at(0));
        $passes = [
            [self::CAR_A, $this->at(60)],
            [self::CAR_B, $this->at(70)],
            [self::CAR_A, $this->at(240)],
            [self::CAR_B, $this->at(250)],
            [self::CAR_A, $this->at(420)],
        ];

        foreach ([$this->fontes[0], $this->fontes[1]] as $fonte) {
            $batch = [];
            foreach ($passes as [$car, $tsMs]) {
                $batch[] = $this->entryPayload(self::newUuid(), $car, $tsMs);
            }
            $this->upload($this->issueToken($fonte), $batch);
        }

        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertEquals(1, $this->publishedData(self::CAR_B)['VOLTAS']);
        $this->assertEquals(400, $this->publishedPoints(self::CAR_A)['ENDPTS']);
        $this->assertEquals(200, $this->publishedPoints(self::CAR_B)['ENDPTS']);
    }

    /**
     * SITUATION: car 11 has completed a lap. Car 33 is at the event but has
     * not crossed the line yet.
     *
     * EXPECTED: car 33 gets no score row.
     */
    public function testACarWithoutLapsGetsNoScoreRow(): void
    {
        $this->startRace($this->at(0));

        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->recompute();

        $this->assertNotNull($this->publishedData(self::CAR_A));
        $this->assertNull($this->publishedData(self::CAR_C));
    }

    /**
     * SITUATION: two counters typed "11" at four minutes, which gave car 11
     * its first lap. Watching the video, the reviewer finds that counter 1
     * was wrong, and voids that entry. One counter alone is not enough for
     * a pass, so car 11 is back to zero laps.
     *
     * EXPECTED: the score row that already exists is corrected down to zero
     * laps (it is not left showing 1), its "last pass" goes back to the
     * pass zero at 60 seconds, and points are recalculated. This is the one
     * case where a row with zero laps is written.
     */
    public function testACorrectionBringsAnExistingRowBackDownToZero(): void
    {
        $this->startRace($this->at(0));
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        [$entryOfCounter1] = $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->recompute();
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
        $recalculationsBefore = ScorePublisher::refreshCount();

        // The decision's own id (first argument) is assigned by the database when it is stored.
        $this->service->storeDecision(Decision::anular(0, $entryOfCounter1), self::USER_PREFIX . 'revisor', 'Não era o carro 11');
        $this->recompute();

        $dados = $this->publishedData(self::CAR_A);
        $this->assertNotNull($dados, 'the row stays');
        $this->assertEquals(0, $dados['VOLTAS']);
        $this->assertEquals(60, $dados['LAST']);
        $this->assertSame($recalculationsBefore + 1, ScorePublisher::refreshCount());
    }

    /**
     * SITUATION: this prova used to be scored from a spreadsheet, and car
     * 11 already has 43 laps imported from it. The prova has now been
     * switched to the new lap counting, but nobody has started a race yet.
     * Counters are trying the count page and typing test entries.
     *
     * EXPECTED: nothing is published. The 43 stays. Until race control
     * starts the race the engine counts no laps, and publishing "zero" at
     * that point would wipe the imported totals.
     */
    public function testNothingIsPublishedBeforeTheRaceHasStarted(): void
    {
        $this->setManualField(self::CAR_A, 'VOLTAS', 43);
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->recompute();

        $this->assertEquals(43, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertSame($recalculationsBefore, ScorePublisher::refreshCount());
        $this->assertTrue($this->service->lastPublishOutcome()->skippedNoStart);
    }

    /**
     * SITUATION: everything is already published and correct. The person
     * in charge presses "Republicar tudo" anyway, for example after fixing
     * the points formula of the prova.
     *
     * EXPECTED: points are recalculated even though no lap count changed.
     * That button exists precisely for the cases the automatic check
     * cannot see.
     */
    public function testRepublishAllAlwaysRecalculatesPoints(): void
    {
        $this->startRace($this->at(0));
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->recompute();
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->newRequest();
        $this->service->republishAll();

        $this->assertSame($recalculationsBefore + 1, ScorePublisher::refreshCount());
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: the organisers are trying the new lap counting at a real
     * event, beside the spreadsheets they have always used. The spreadsheet
     * is still the official source and has already written "17 laps" for
     * car 11. To keep the two from fighting over that number, the publish
     * mode of the prova is set to "desligada" (off). The race is started on
     * the new system too, and car 11 crosses the line three times.
     *
     * EXPECTED: the new system counts (its own review screen would show two
     * laps), but the score row still says 17 and DESCLAS is untouched,
     * points are not recalculated, and the start time the results page
     * shows (prova.tempo) is left alone. Nothing leaks into the official
     * results.
     */
    public function testWithPublishingOffTheOfficialResultsAreLeftAlone(): void
    {
        $this->setManualField(self::CAR_A, 'VOLTAS', 17);
        $this->setManualField(self::CAR_A, 'DESCLAS', 'NÃO');
        $settings = new Settings();
        $settings->modoPublicacao = Settings::MODO_DESLIGADA;
        $this->service->saveSettings($settings);
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->startRace($this->at(0));
        $token1 = $this->issueToken($this->fontes[0]);
        $token2 = $this->issueToken($this->fontes[1]);
        foreach ([60, 240, 420] as $seconds) {
            $this->upload($token1, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at($seconds))]);
            $this->upload($token2, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at($seconds))]);
        }
        $this->recompute();

        $this->assertSame(2, $this->service->compute()->lapCount(self::CAR_A), 'the new system did count');
        $dados = $this->publishedData(self::CAR_A);
        $this->assertEquals(17, $dados['VOLTAS']);
        $this->assertSame('NÃO', $dados['DESCLAS']);
        $this->assertArrayNotHasKey('LAST', $dados);
        $this->assertSame($recalculationsBefore, ScorePublisher::refreshCount());
        $this->assertTrue($this->service->lastPublishOutcome()->skippedPublishingOff);
        $this->assertSame(0, $this->countRows('prova', 'evento_id = ? AND prova_id = ? AND tempo IS NOT NULL', [self::EVENTO_ID, self::PROVA_ID]));
    }

    /**
     * SITUATION: a judge has the entry page of car 11 open and saves
     * DESCLAS = "SIM" at the very moment a counter's upload gives car 11 a
     * lap. In the database that means: the judge's change lands between the
     * moment the publisher looked at the row and the moment it writes.
     *
     * WHY THIS MATTERS: a publisher that read the whole object, changed its
     * three fields and wrote the whole object back would put the OLD
     * DESCLAS back, silently un-disqualifying a car.
     *
     * EXPECTED: the publisher's write only touches its own keys inside the
     * JSON (one JSON_SET statement), so the judge's "SIM" survives whatever
     * the timing.
     *
     * WHAT THIS TEST CAN AND CANNOT SHOW: it cannot make two requests
     * collide in the same millisecond. It checks the property that makes a
     * collision harmless: a field changed behind the publisher's back, with
     * no lock and after the previous publish, is still there after the next
     * publish, and the points follow it.
     */
    public function testAJudgeSavingAManualFieldAtTheSameMomentIsNotOverwritten(): void
    {
        $this->setManualField(self::CAR_A, 'DESCLAS', 'NÃO');
        $this->startRace($this->at(0));
        $this->recordCountedPass(self::CAR_A, $this->at(60));
        $this->recordCountedPass(self::CAR_A, $this->at(240));
        $this->recompute();

        // The judge's save, straight into the row.
        $this->setManualField(self::CAR_A, 'DESCLAS', 'SIM');
        // Another lap arrives and is published.
        $this->recordCountedPass(self::CAR_A, $this->at(420));
        $this->newRequest();
        $this->service->withLock(fn() => $this->service->recountCars([self::CAR_A]));

        $dados = $this->publishedData(self::CAR_A);
        $this->assertEquals(2, $dados['VOLTAS']);
        $this->assertSame('SIM', $dados['DESCLAS']);
        $this->assertEquals(0, $this->publishedPoints(self::CAR_A)['ENDPTS'], 'a disqualified car scores 0');
    }
}
