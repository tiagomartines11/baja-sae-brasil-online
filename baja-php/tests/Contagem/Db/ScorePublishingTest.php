<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
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
 * After every upload the engine recounts the race and the "publisher"
 * copies three numbers per car into that row:
 *
 *   VOLTAS  how many laps the car has officially completed
 *   LAST    when it last crossed the line, in whole seconds since the start
 *   BEST    its best lap in whole seconds (0 when it has none that qualifies)
 *
 * A lap is official when at least two of the three counters recorded it.
 * In these tests "a counted pass" therefore always means: two counters
 * typed the same car at the same moment.
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
     * DESCLAS to "NÃO". Then the race starts and two counters record car 11
     * crossing the line two minutes in.
     *
     * EXPECTED: the row now says one lap, last pass at 120 seconds, and
     * DESCLAS is still "NÃO". The publisher adds its fields to the object;
     * it never replaces the object.
     */
    public function testPublishingKeepsFieldsThatPeopleTyped(): void
    {
        $this->setManualField(self::CAR_A, 'DESCLAS', 'NÃO');
        $this->startRace($this->at(0));

        $this->recordCountedPass(self::CAR_A, $this->at(120));
        $this->recompute();

        $dados = $this->publishedData(self::CAR_A);
        $this->assertNotNull($dados);
        $this->assertSame('NÃO', $dados['DESCLAS']);
        $this->assertEquals(1, $dados['VOLTAS']);
        $this->assertEquals(120, $dados['LAST']);
        $this->assertEquals(0, $dados['BEST'], 'the first lap never qualifies as a best lap');
    }

    /**
     * SITUATION: the race is running. Counter 1's phone uploads "car 11
     * passed". At this point only one counter has said so, so the pass is
     * not official yet and car 11 still has zero laps. Then counter 2's
     * phone uploads the same pass, which makes it official.
     *
     * EXPECTED: points are NOT recalculated after the first upload (no
     * published number changed) and ARE recalculated, once, in the second
     * upload (car 11 went from 0 to 1 lap). Recalculating is the expensive
     * step, which is why it must not run for nothing, and it must run in
     * the same request that changed the count, so the results page never
     * shows a count and points that disagree.
     */
    public function testPointsAreRecalculatedExactlyWhenACountChanges(): void
    {
        $this->startRace($this->at(0));
        $tokenOfCounter1 = $this->issueToken($this->fontes[0]);
        $tokenOfCounter2 = $this->issueToken($this->fontes[1]);
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->upload($tokenOfCounter1, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))]);

        $this->assertSame($recalculationsBefore, ScorePublisher::refreshCount(), 'one counter alone changes nothing');
        $this->assertNull($this->publishedData(self::CAR_A), 'no laps yet, so no score row either');

        $this->upload($tokenOfCounter2, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))]);

        $this->assertSame($recalculationsBefore + 1, ScorePublisher::refreshCount());
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: car 11 already has its lap. Now the phones do what they
     * do most of the time: send a "heartbeat" every few seconds with
     * nothing new in it, and occasionally resend a batch the server
     * already has.
     *
     * EXPECTED: no recalculation of points at all. With several phones
     * sending a heartbeat every five seconds for four hours, recalculating
     * each time would be thousands of pointless rewrites of every score.
     */
    public function testPointsAreNotRecalculatedWhenNothingChanged(): void
    {
        $this->startRace($this->at(0));
        $tokenOfCounter1 = $this->issueToken($this->fontes[0]);
        $tokenOfCounter2 = $this->issueToken($this->fontes[1]);
        $batch = [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))];
        $this->upload($tokenOfCounter1, $batch);
        $this->upload($tokenOfCounter2, [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))]);
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->upload($tokenOfCounter1);
        $this->upload($tokenOfCounter2);
        $this->upload($tokenOfCounter1, $batch);

        $this->assertSame($recalculationsBefore, ScorePublisher::refreshCount());
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * SITUATION: two counters have recorded car 11 crossing the line twice
     * and car 22 once.
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
            [self::CAR_A, $this->at(120)],
            [self::CAR_B, $this->at(130)],
            [self::CAR_A, $this->at(240)],
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
     * SITUATION: car 11 has completed a lap. Car 33 is at the event but
     * has not crossed the line yet.
     *
     * EXPECTED: car 33 gets no score row. A row with zero laps would list
     * the car on the results page as if it had taken part.
     */
    public function testACarWithoutLapsGetsNoScoreRow(): void
    {
        $this->startRace($this->at(0));

        $this->recordCountedPass(self::CAR_A, $this->at(120));
        $this->recompute();

        $this->assertNotNull($this->publishedData(self::CAR_A));
        $this->assertNull($this->publishedData(self::CAR_C));
    }

    /**
     * SITUATION: two counters typed "11" at the same moment, so car 11 was
     * given a lap. Watching the video, the reviewer finds that counter 1
     * was wrong, and voids that entry. One counter alone is not enough for
     * a lap, so car 11 is back to zero.
     *
     * EXPECTED: the score row that already exists is corrected down to
     * zero laps (it is not left showing 1), and points are recalculated.
     * This is the one case where a row with zero laps is written.
     */
    public function testACorrectionBringsAnExistingRowBackDownToZero(): void
    {
        $this->startRace($this->at(0));
        [$entryOfCounter1] = $this->recordCountedPass(self::CAR_A, $this->at(120));
        $this->recompute();
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
        $recalculationsBefore = ScorePublisher::refreshCount();

        // The decision's own id (first argument) is assigned by the database when it is stored.
        $this->service->storeDecision(Decision::anular(0, $entryOfCounter1), self::USER_PREFIX . 'revisor', 'Não era o carro 11');
        $this->recompute();

        $dados = $this->publishedData(self::CAR_A);
        $this->assertNotNull($dados, 'the row stays');
        $this->assertEquals(0, $dados['VOLTAS']);
        $this->assertEquals(0, $dados['LAST']);
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

        $this->recordCountedPass(self::CAR_A, $this->at(120));
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
        $this->recordCountedPass(self::CAR_A, $this->at(120));
        $this->recompute();
        $recalculationsBefore = ScorePublisher::refreshCount();

        $this->service->republishAll();

        $this->assertSame($recalculationsBefore + 1, ScorePublisher::refreshCount());
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);
    }

    /**
     * Counters 1 and 2 both record $car crossing the line at $tsMs, which
     * is what makes a pass official. Stored directly; the engine has not
     * run yet when this returns.
     *
     * @return int[] the ids of the two entries: [counter 1's, counter 2's]
     */
    private function recordCountedPass(int $car, int $tsMs): array
    {
        return [
            $this->addEntry($this->fontes[0], $car, $tsMs),
            $this->addEntry($this->fontes[1], $car, $tsMs),
        ];
    }
}
