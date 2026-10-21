<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;

/**
 * The "timeline": the picture a reviewer gets after clicking a flag or a
 * car, with one lane per counter and one chip per entry that counter typed.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The timeline shows the RAW entries, including the ones that do not count
 * (voided, repeated, typed outside the race), because a reviewer has to see
 * what each counter really did. For every entry it says which crossing
 * ("pass group") it belongs to and what that crossing is to the count: a
 * lap, the pass zero, or a pass the rules of the race leave out.
 *
 * It is asked for on every click, so it must be cheap: only the cars on
 * screen are recounted, never the whole race. That quick recount has to
 * agree exactly with the full calculation, or the fingerprints
 * ("signatures") would differ and every click would be refused.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewTimelineTest extends ReviewDbTestCase
{
    /**
     * SITUATION: car A crosses the line five times, each crossing recorded
     * by all three counters:
     *     1 min    first crossing
     *     2:40     second
     *     4:20     third
     *     5:00     the race is ended (chequered flag)
     *     6:00     fourth: the car's crossing of the finish
     *     7:40     fifth: the car went round once more by mistake
     *
     * EXPECTED on the car's timeline:
     *   - the first crossing is marked as the pass zero ("inicial"): it is
     *     official but completes no lap;
     *   - the second and third are laps 1 and 2;
     *   - the fourth, the first one after the finish, still counts: lap 3;
     *   - the fifth is STILL ON THE TIMELINE, marked "apos_encerramento" and
     *     not counted. It must stay visible: if the team protests, the
     *     reviewer has to be able to show that the pass was recorded and
     *     why it is not a lap.
     * And the car table says 3 laps.
     */
    public function testAPassAfterTheFinishStaysVisibleAndIsMarkedAsNotCounting(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $first = $this->allCountersSee($car, 60);
        $second = $this->allCountersSee($car, 160);
        $third = $this->allCountersSee($car, 260);
        $this->endRace($this->at(300));
        $fourth = $this->allCountersSee($car, 360);
        $fifth = $this->allCountersSee($car, 460);
        $this->syncAll($this->at(500));
        Clock::freezeAt($this->at(600));

        $state = $this->freshScreen();
        $entries = $this->entriesById(TimelineWindow::forCar($this->service, $car));

        $this->assertCount(15, $entries, 'every entry of the car is on its timeline, counted or not');
        $this->assertGroupRole('inicial', 0, true, $entries, $first);
        $this->assertGroupRole('volta', 1, true, $entries, $second);
        $this->assertGroupRole('volta', 2, true, $entries, $third);
        $this->assertGroupRole('volta', 3, true, $entries, $fourth);
        $this->assertGroupRole('apos_encerramento', null, false, $entries, $fifth);
        $this->assertSame(3, $this->carRow($state, $car)['voltas']);
    }

    /**
     * SITUATION: car A crosses at 1:00, 2:40 and 4:20, each crossing
     * recorded by all three counters; at 5:30 one counter alone types it.
     *
     * WHAT WAS MISSING: a chip said "volta 2" and nothing else. At 26SE a
     * reviewer had to open the car table to see that a lap confirmed on a
     * chip took 1:15 instead of the usual 3:40.
     *
     * EXPECTED: each lap's chips carry the lap's duration ("volta_ms"), the
     * same number the car table shows for the last lap (100 s here). The
     * pass zero and the lone entry, which end no counted lap, carry none.
     */
    public function testALapsChipsCarryTheLapTimeTheCarTableShows(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $first = $this->allCountersSee($car, 60);
        $second = $this->allCountersSee($car, 160);
        $third = $this->allCountersSee($car, 260);
        $lone = $this->addEntry($this->fontes[0], $car, $this->at(330));
        $this->syncAll($this->at(400));
        Clock::freezeAt($this->at(410));

        $state = $this->freshScreen();
        $entries = $this->entriesById(TimelineWindow::forCar($this->service, $car));

        $this->assertSame(100000, $this->carRow($state, $car)['ultima_volta_ms']);
        foreach ($third as $entryId) {
            $this->assertSame(100000, $entries[$entryId]['grupo']['volta_ms']);
        }
        $this->assertSame(100000, $entries[$second[0]]['grupo']['volta_ms']);
        $this->assertNull($entries[$first[0]]['grupo']['volta_ms'], 'the pass zero ends no lap');
        $this->assertSame('pendente', $entries[$lone]['grupo']['papel']);
        $this->assertNull($entries[$lone]['grupo']['volta_ms'], 'a pass that does not count ends no lap');

        // A window of time says the same as the car's whole race.
        $window = $this->entriesById(TimelineWindow::forWindow($this->service, $this->at(250), $this->at(270)));
        $this->assertSame(100000, $window[$third[1]]['grupo']['volta_ms']);
    }

    /**
     * SITUATION: the organisers chose the other rule for the start: the
     * start itself is every car's pass zero ("passagem_inicial = largada"),
     * so counters are told not to type the start pack. Because somebody
     * always types it anyway, entries in the first 30 seconds are treated
     * as that initial crossing ("janela_largada_s = 30").
     * Car A is typed 10 s after the start, and again at 1:40.
     *
     * EXPECTED: the entries typed at 10 s are on the timeline, marked
     * "janela_largada" and not counted; the crossing at 1:40 is lap 1.
     */
    public function testEntriesInsideTheStartWindowAreMarkedAndDoNotCount(): void
    {
        $car = self::CAR_A;
        $this->service->saveSettings(Settings::fromArray([
            'passagem_inicial' => Settings::INICIAL_LARGADA,
            'janela_largada_s' => 30,
        ]));
        $this->startRace(self::RACE_START_MS);
        $startPack = $this->allCountersSee($car, 10);
        $firstLap = $this->allCountersSee($car, 100);
        $this->syncAll($this->at(200));
        Clock::freezeAt($this->at(300));

        $state = $this->freshScreen();
        $entries = $this->entriesById(TimelineWindow::forCar($this->service, $car));

        foreach ($startPack as $entryId) {
            $this->assertSame('janela_largada', $entries[$entryId]['grupo']['papel']);
            $this->assertFalse($entries[$entryId]['grupo']['contado']);
        }
        $this->assertGroupRole('volta', 1, true, $entries, $firstLap);
        $this->assertSame(1, $this->carRow($state, $car)['voltas']);
    }

    /**
     * SITUATION: one minute into the race only counter 1 types car A; the
     * other two were at their posts and typed nothing. That raises a flag.
     * The reviewer opens the flag, looks at the timeline, clicks the chip
     * and presses "anular".
     *
     * The flag comes from the full calculation (stored in the snapshot).
     * The timeline comes from the quick recount of that one car. Each
     * carries a fingerprint of the entry.
     *
     * EXPECTED: the two fingerprints are identical, and a decision sent
     * with the timeline's fingerprint is accepted. If the quick recount
     * described the entry any differently from the full calculation, every
     * click on a timeline would be refused with "os dados mudaram".
     */
    public function testTheQuickRecountGivesTheSameFingerprintAsTheFullCalculation(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $entryId = $this->addEntry($this->fontes[0], $car, $this->at(60));
        $this->syncAll($this->at(120));
        Clock::freezeAt($this->at(180));

        $state = $this->freshScreen();
        $flag = null;
        foreach ($state['alertas_abertos'] as $openFlag) {
            if ($openFlag['passagem_ids'] === [$entryId]) {
                $flag = $openFlag;
            }
        }
        $this->assertNotNull($flag, 'a pass seen by one counter only raises a flag');

        $onTimeline = $this->entriesById(TimelineWindow::forCar($this->service, $car))[$entryId];
        $this->assertSame($flag['assinatura'], $onTimeline['assinatura']);

        $answer = $this->decide(['tipo' => 'anular', 'passagem_id' => $entryId], [$entryId], $onTimeline['assinatura']);
        $this->assertSame(200, $answer['status']);
    }

    /**
     * SITUATION: counters 1 and 2 type car A; counter 3 types car B at the
     * same moment (a slip of the finger). A reviewer reassigns counter 3's
     * entry to car A. Later somebody opens the timeline of car B.
     *
     * The reassigned entry was TYPED as B, so it belongs on B's timeline;
     * but it now sits in a pass group of car A, together with the entries
     * of the other two counters. To describe it, the recount has to look
     * at car A as well, although only B was asked for.
     *
     * EXPECTED: on B's timeline the entry is shown as moved to car A, in a
     * group of three entries, and its fingerprint is the one the full
     * calculation gives.
     */
    public function testAnEntryMovedToAnotherCarIsShownInTheGroupItNowBelongsTo(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        $this->addEntry($this->fontes[1], self::CAR_A, $this->at(61));
        $slipId = $this->addEntry($this->fontes[2], self::CAR_B, $this->at(62));
        $this->syncAll($this->at(120));
        Clock::freezeAt($this->at(180));
        $this->recompute();

        $answer = $this->decideAboutEntry(
            ['tipo' => 'reatribuir', 'passagem_id' => $slipId, 'equipe_numero' => self::CAR_A],
            $slipId
        );
        $this->assertSame(200, $answer['status']);

        $this->newRequest();
        $onTimelineOfB = $this->entriesById(TimelineWindow::forCar($this->service, self::CAR_B))[$slipId];

        $this->assertTrue($onTimelineOfB['reatribuida']);
        $this->assertSame(self::CAR_A, $onTimelineOfB['carro']);
        $this->assertSame(self::CAR_B, $onTimelineOfB['carro_digitado']);
        $this->assertCount(3, $onTimelineOfB['grupo']['passagem_ids']);
        $this->assertSame($this->currentSignatureOf([$slipId]), $onTimelineOfB['assinatura']);
    }

    /**
     * SITUATION: at minute 1 counters 1 and 2 type car B and counter 3
     * types car A (a slip, moved to car B by a reviewer). At minute 20
     * counter 3 types car A once more, also a slip, moved to car C.
     *
     * EXPECTED: the piece around minute 1 recounts A (typed there) and B
     * (where the slip of minute 1 now counts), and shows the slip in B's
     * group. Car C, whose only link to the piece is a fix made twenty
     * minutes later, is not recounted: that second round cost every piece
     * nearly a whole engine run.
     */
    public function testAPieceRecountsOnlyTheCarsItsOwnEntriesCountFor(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->addEntry($this->fontes[0], self::CAR_B, $this->at(60));
        $this->addEntry($this->fontes[1], self::CAR_B, $this->at(61));
        $slipNow = $this->addEntry($this->fontes[2], self::CAR_A, $this->at(62));
        $slipLater = $this->addEntry($this->fontes[2], self::CAR_A, $this->at(1200));
        $this->syncAll($this->at(1300));
        Clock::freezeAt($this->at(1400));
        $this->recompute();
        foreach ([[$slipNow, self::CAR_B], [$slipLater, self::CAR_C]] as [$entryId, $car]) {
            $answer = $this->decideAboutEntry(['tipo' => 'reatribuir', 'passagem_id' => $entryId, 'equipe_numero' => $car], $entryId);
            $this->assertSame(200, $answer['status']);
        }

        $this->newRequest();
        $recount = \Baja\Contagem\Review\CarsRecount::load($this->service, [self::CAR_A, self::CAR_B], [$this->at(0), $this->at(120)]);
        $this->assertEqualsCanonicalizing([self::CAR_A, self::CAR_B], $recount->cars);

        $slip = $this->entriesById(TimelineWindow::forWindow($this->service, $this->at(0), $this->at(120)))[$slipNow];
        $this->assertSame(self::CAR_B, $slip['carro']);
        $this->assertCount(3, $slip['grupo']['passagem_ids']);
        $this->assertSame($this->currentSignatureOf([$slipNow]), $slip['assinatura']);
    }

    /**
     * SITUATION: around minute 1 of the race:
     *   - counter 1 double-taps car A (two entries two seconds apart);
     *   - counter 2 types car A once, and a reviewer later voids that entry;
     *   - a reviewer adds a pass for car B that nobody recorded;
     *   - much later (minute 30) car C passes.
     *
     * EXPECTED, for the one-minute window around minute 1:
     *   - the second tap is there, marked as dropped by the engine
     *     ("anulada_auto"), and the first is in use;
     *   - the voided entry is there, marked "anulada", with the decision
     *     that voided it;
     *   - the added pass is there, in its own lane "Revisor", with an id
     *     below zero (minus the id of the decision that added it);
     *   - car C's entry of minute 30 is NOT there.
     */
    public function testAWindowShowsVoidedRepeatedAndAddedEntriesOfThatMinuteOnly(): void
    {
        $this->startRace(self::RACE_START_MS);
        $firstTap = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        $secondTap = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(62));
        $toBeVoided = $this->addEntry($this->fontes[1], self::CAR_A, $this->at(61));
        $muchLater = $this->addEntry($this->fontes[0], self::CAR_C, $this->at(1800));
        $this->syncAll($this->at(1900));
        Clock::freezeAt($this->at(2000));
        $this->recompute();

        $this->assertSame(200, $this->decideAboutEntry(['tipo' => 'anular', 'passagem_id' => $toBeVoided], $toBeVoided)['status']);
        $added = $this->decide(['tipo' => 'adicionar', 'equipe_numero' => self::CAR_B, 'ts_ms' => $this->at(70)]);
        $this->assertSame(200, $added['status']);
        $addedEntryId = -$added['body']['decisao_id'];

        $this->newRequest();
        $window = TimelineWindow::forWindow($this->service, $this->at(30), $this->at(90));
        $entries = $this->entriesById($window);

        $this->assertArrayNotHasKey($muchLater, $entries);
        $this->assertFalse($entries[$firstTap]['anulada_auto']);
        $this->assertNotNull($entries[$firstTap]['grupo']);
        $this->assertTrue($entries[$secondTap]['anulada_auto'], 'the double tap is shown as dropped by the engine');
        $this->assertTrue($entries[$toBeVoided]['anulada']);
        $this->assertSame('anular', $entries[$toBeVoided]['decisoes'][0]['tipo']);

        $this->assertArrayHasKey($addedEntryId, $entries);
        $this->assertTrue($entries[$addedEntryId]['adicionada_por_revisor']);
        $this->assertSame(self::CAR_B, $entries[$addedEntryId]['carro']);
        $laneNames = array_column($window['raias'], 'nome');
        $this->assertContains('Revisor', $laneNames);
    }

    /** Race control ends the race at $tsMs, as the "Encerrar corrida" button does. */
    private function endRace(int $tsMs): void
    {
        $this->newRequest();
        $this->service->withLock(function () use ($tsMs): void {
            $this->service->recordOcorrencia(Ocorrencia::ENCERRAMENTO, $tsMs, null, self::USER_PREFIX . 'controle');
        });
    }

    /**
     * @param array<string, mixed> $timeline what TimelineWindow answered
     * @return array<int, array<string, mixed>> entry id => the entry as described on the timeline
     */
    /**
     * SITUATION: three review pages follow the race. Each asks every few
     * seconds for the same two-minute pieces, and each piece costs a
     * recount of nearly the whole field.
     *
     * EXPECTED: while nothing changed, the second page gets the first
     * page's answer (an entry slipped into the table without telling
     * anybody stays unseen, which shows the answer was shared). As soon
     * as the change count moves, as it does on every upload or decision,
     * the next request computes afresh and shows the entry.
     */
    public function testPagesAskingForTheSamePieceShareOneAnswerUntilSomethingChanges(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->syncAll($this->at(100));
        $this->newRequest();
        $this->service->markChanged();
        [$from, $until] = [$this->at(0), $this->at(120) - 1];

        $first = TimelineWindow::forWindowShared($this->service, $from, $until);
        $this->assertCount(3, $this->entriesById($first));

        $sneaked = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(90));
        $this->newRequest();
        $this->assertSame($first, TimelineWindow::forWindowShared($this->service, $from, $until));

        $this->service->markChanged();
        $fresh = $this->entriesById(TimelineWindow::forWindowShared($this->service, $from, $until));
        $this->assertArrayHasKey($sneaked, $fresh);
    }

    private function entriesById(array $timeline): array
    {
        $entries = [];
        foreach ($timeline['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                $entries[$entry['id']] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Checks what the timeline says about the crossing a set of entries
     * belongs to.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param int[] $entryIds the entries of one crossing
     */
    private function assertGroupRole(string $role, ?int $lapNumber, bool $counted, array $entries, array $entryIds): void
    {
        foreach ($entryIds as $entryId) {
            $group = $entries[$entryId]['grupo'];
            $this->assertNotNull($group, 'the entry is part of a crossing');
            $this->assertSame($role, $group['papel']);
            $this->assertSame($lapNumber, $group['numero_volta']);
            $this->assertSame($counted, $group['contado']);
        }
    }
}
