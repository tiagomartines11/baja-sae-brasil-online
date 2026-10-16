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
