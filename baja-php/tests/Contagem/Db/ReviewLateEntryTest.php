<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;

/**
 * What the review screen gets and may send about the 26SE mistakes:
 *
 * - a crossing typed late by one counter ("registro_tardio") and the
 *   decision that settles it, "usar_tempo" ("Usar o tempo de Contagem 2");
 * - a void the COUNTER made on their own phone, which a reviewer undid
 *   without knowing: the timeline now says who voided an entry, when, and
 *   why, so the screen can ask first;
 * - a lap too short to be real that counts on a confirmation: marked on
 *   the car's row and on the timeline;
 * - the history rows say which car and which counter each entry decision
 *   was about.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewLateEntryTest extends ReviewDbTestCase
{
    /**
     * Car A crosses at 1:00, 2:40 and 6:00 with every counter recording it.
     * At 4:20 Contagem 2 records it, and Contagem 1 only 23 s later.
     *
     * @return array{0: int, 1: int} [Contagem 2's entry, Contagem 1's late entry]
     */
    private function latePair(): array
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->allCountersSee(self::CAR_A, 160);
        $onTime = $this->addEntry($this->fontes[1], self::CAR_A, $this->at(260));
        $late = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(283));
        $this->allCountersSee(self::CAR_A, 360);
        $this->syncAll($this->at(400));
        Clock::freezeAt($this->at(420));
        return [$onTime, $late];
    }

    /** @param array<string, mixed> $state */
    private function flagOfType(array $state, string $type): array
    {
        foreach ($state['alertas_abertos'] as $flag) {
            if ($flag['tipo'] === $type) {
                return $flag;
            }
        }
        $this->fail('no open flag of type ' . $type . ': ' . implode(', ', array_column($state['alertas_abertos'], 'id')));
    }

    /**
     * EXPECTED: the screen gets one "registro_tardio" flag offering both
     * times; "usar_tempo" with the other entry is stored, the car gains
     * exactly one lap, and the pass is timed by Contagem 2. Sending it
     * again is "já decidido".
     */
    public function testUsingOneTimeOfALatePairCountsThePassOnce(): void
    {
        [$onTime, $late] = $this->latePair();
        $state = $this->freshScreen();
        $flag = $this->flagOfType($state, 'registro_tardio');
        $this->assertSame([$onTime, $late], $flag['passagem_ids']);
        $this->assertSame([$onTime, $late], array_column($flag['tempos_possiveis'], 'passagem_id'));
        $this->assertSame(2, $this->carRow($state, self::CAR_A)['voltas']);

        $decision = ['tipo' => 'usar_tempo', 'passagem_id' => $onTime, 'passagem_alvo_id' => $late];
        $answer = $this->decide($decision, $flag['passagem_ids'], $flag['assinatura']);

        $this->assertSame(200, $answer['status'], json_encode($answer['body']));
        $after = $this->freshScreen();
        $this->assertSame(3, $this->carRow($after, self::CAR_A)['voltas']);
        $entries = $this->entriesOf(TimelineWindow::forCar($this->service, self::CAR_A));
        $this->assertSame($entries[$onTime]['grupo']['passagem_ids'], $entries[$late]['grupo']['passagem_ids'], 'one pass');
        $this->assertSame($this->at(260), $entries[$late]['grupo']['ts_ms']);
        $this->assertSame($onTime, $entries[$late]['grupo']['tempo_escolhido_id']);
        $stored = $this->fetchValue('SELECT passagem_alvo_id FROM contagem_decisao WHERE decisao_id = ?', [$answer['body']['decisao_id']]);
        $this->assertSame($late, (int) $stored);

        $again = $this->decide($decision, [$onTime, $late], $this->currentSignatureOf([$onTime, $late]));
        $this->assertSame(409, $again['status']);
        $this->assertSame('ja_decidido', $again['body']['motivo']);
    }

    /**
     * EXPECTED: what makes no sense is refused with a sentence and nothing
     * is stored: the other entry from the same counter, the other entry in
     * a pass the counters agree on, a voided entry, the other entry not
     * among the watched ones, "usar_tempo" alone on a lone entry (that
     * is a plain "Contar esta passagem"), and two entries further apart
     * than the engine pairs a crossing typed late.
     */
    public function testWhatCannotBeOnePassIsRefused(): void
    {
        [$onTime, $late] = $this->latePair();
        $sameCounter = $this->addEntry($this->fontes[1], self::CAR_A, $this->at(300));
        // A minute before Contagem 2's entry: two passes, not one typed late.
        $farAway = $this->addEntry($this->fontes[2], self::CAR_A, $this->at(200));
        $agreed = $this->allCountersSee(self::CAR_A, 520);
        $this->syncAll($this->at(580));
        Clock::freezeAt($this->at(600));
        $this->recompute();
        $before = $this->countDecisions();

        $cases = [
            'same counter' => [['tipo' => 'usar_tempo', 'passagem_id' => $onTime, 'passagem_alvo_id' => $sameCounter], [$onTime, $sameCounter]],
            'agreed pass' => [['tipo' => 'usar_tempo', 'passagem_id' => $late, 'passagem_alvo_id' => $agreed[1]], [$late, $agreed[1]]],
            'lone entry alone' => [['tipo' => 'usar_tempo', 'passagem_id' => $onTime], [$onTime]],
            'too far apart' => [['tipo' => 'usar_tempo', 'passagem_id' => $onTime, 'passagem_alvo_id' => $farAway], [$onTime, $farAway]],
        ];
        foreach ($cases as $name => [$decision, $watched]) {
            $answer = $this->decide($decision, $watched, $this->currentSignatureOf($watched));
            $this->assertSame(400, $answer['status'], $name . ': ' . json_encode($answer['body']));
        }

        $notWatched = $this->decide(['tipo' => 'usar_tempo', 'passagem_id' => $onTime, 'passagem_alvo_id' => $late], [$onTime], $this->currentSignatureOf([$onTime]));
        $this->assertSame(400, $notWatched['status']);

        $this->service->storeDecision(Decision::anular(0, $late), 'zzt_revisor', null);
        $this->recompute();
        $voided = $this->decide(['tipo' => 'usar_tempo', 'passagem_id' => $onTime, 'passagem_alvo_id' => $late], [$onTime, $late], $this->currentSignatureOf([$onTime, $late]));
        $this->assertSame(400, $voided['status']);
        $this->assertSame($before + 1, $this->countDecisions(), 'only the void the test stored itself');
    }

    /**
     * SITUATION: Contagem 1 voided its own entry from the phone, with a
     * reason ("digitei errado").
     *
     * EXPECTED: the timeline's chip carries who voided it, when, from
     * where and why, which is what the screen needs to ask before
     * "Restaurar". The history line says which car and counter it was.
     */
    public function testACountersOwnVoidSaysWhoWhenAndWhy(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $entry = $this->addEntry($this->fontes[0], self::CAR_B, $this->at(100));
        Clock::freezeAt($this->at(200));
        $voidId = $this->service->storeDecision(Decision::anular(0, $entry, Decision::ORIGEM_CONTADOR), 'zzt_contador1', 'digitei errado');

        $state = $this->freshScreen();
        $chip = $this->entriesOf(TimelineWindow::forCar($this->service, self::CAR_B))[$entry];

        $this->assertTrue($chip['anulada']);
        $this->assertSame([[
            'id' => $voidId, 'tipo' => 'anular', 'origem' => 'contador',
            'usuario' => 'zzt_contador1', 'criado_em_ms' => $this->at(200), 'motivo' => 'digitei errado',
        ]], $chip['decisoes']);
        $row = $state['historico'][0];
        $this->assertSame($voidId, $row['id']);
        $this->assertSame('contador', $row['origem']);
        $this->assertSame(self::CAR_B, $row['carro_digitado']);
        $this->assertSame(self::CAR_B, $row['carro']);
        $this->assertSame((int) $this->fontes[0]->getFonteId(), $row['fonte_id']);
        $this->assertSame('Contagem 1', $row['fonte_nome']);
    }

    /**
     * SITUATION: an entry typed as car A is moved to car B, confirmed, and
     * the confirmation undone.
     *
     * EXPECTED: every one of the three history lines names the entry's
     * typed car (A), the car it counts for now (B) and its counter; the
     * undo follows the decision it undid. A decision about no entry (an
     * added pass) has none of it.
     */
    public function testHistoryRowsNameTheEntrysCarAndCounter(): void
    {
        $this->startRace(self::RACE_START_MS);
        $entry = $this->addEntry($this->fontes[2], self::CAR_A, $this->at(100));
        Clock::freezeAt($this->at(600));
        $moved = $this->service->storeDecision(Decision::reatribuir(0, $entry, self::CAR_B), 'zzt_revisor', null);
        $confirmed = $this->service->storeDecision(Decision::confirmar(0, $entry), 'zzt_revisor', null);
        $undone = $this->service->storeDecision(Decision::desfazer(0, $confirmed), 'zzt_revisor', null);
        $added = $this->service->storeDecision(Decision::adicionar(0, self::CAR_C, $this->at(300)), 'zzt_revisor', null);

        $rows = [];
        foreach ($this->freshScreen()['historico'] as $row) {
            $rows[$row['id']] = $row;
        }

        foreach ([$moved, $confirmed, $undone] as $id) {
            $this->assertSame(self::CAR_A, $rows[$id]['carro_digitado'], 'decision #' . $id);
            $this->assertSame(self::CAR_B, $rows[$id]['carro'], 'decision #' . $id);
            $this->assertSame('Contagem 3', $rows[$id]['fonte_nome'], 'decision #' . $id);
        }
        $this->assertNull($rows[$added]['carro_digitado']);
        $this->assertNull($rows[$added]['fonte_nome']);
    }

    /**
     * SITUATION: car A crosses at 1:00, 2:40 and 4:20 with everybody
     * recording it; Contagem 1 types a lone A at 4:40, which the reviewer
     * counts on the chip ("Contar esta passagem").
     *
     * EXPECTED: before, the chip says the lap counting it would make (20 s),
     * for the question. After, nothing is flagged again, but the car's row
     * lists that 20 s lap, and its pass on the timeline is marked.
     */
    public function testAShortLapCountedOnAConfirmationIsMarkedOnTheRowAndTheTimeline(): void
    {
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->allCountersSee(self::CAR_A, 160);
        $this->allCountersSee(self::CAR_A, 260);
        $stray = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(281));
        $this->syncAll($this->at(400));
        Clock::freezeAt($this->at(420));
        $this->recompute();

        $chip = $this->entriesOf(TimelineWindow::forCar($this->service, self::CAR_A))[$stray];
        $this->assertTrue($chip['grupo']['soma_ao_confirmar']);
        $this->assertSame(20000, $chip['grupo']['volta_resultante_ms']);

        $answer = $this->decideAboutEntry(['tipo' => 'confirmar', 'passagem_id' => $stray], $stray);
        $this->assertSame(200, $answer['status'], json_encode($answer['body']));
        $state = $this->freshScreen();

        $row = $this->carRow($state, self::CAR_A);
        $this->assertSame(3, $row['voltas']);
        $this->assertCount(1, $row['voltas_curtas_confirmadas']);
        $this->assertSame(3, $row['voltas_curtas_confirmadas'][0]['numero_volta']);
        $this->assertSame($stray, $row['voltas_curtas_confirmadas'][0]['ancora']);
        $this->assertSame(20000, $row['voltas_curtas_confirmadas'][0]['volta_ms']);
        $entries = $this->entriesOf(TimelineWindow::forCar($this->service, self::CAR_A));
        $this->assertTrue($entries[$stray]['grupo']['volta_curta_confirmada']);
        $this->assertFalse($entries[array_key_first($entries)]['grupo']['volta_curta_confirmada']);
    }

    /**
     * @param array<string, mixed> $timeline
     * @return array<int, array<string, mixed>> entry id => entry
     */
    private function entriesOf(array $timeline): array
    {
        $entries = [];
        foreach ($timeline['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                $entries[$entry['id']] = $entry;
            }
        }
        ksort($entries);
        return $entries;
    }
}
