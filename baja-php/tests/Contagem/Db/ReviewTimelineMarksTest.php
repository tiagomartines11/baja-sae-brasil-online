<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Review\CsvExport;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;

/**
 * What round 3 added to the timeline a reviewer sees: passes typed before
 * the start, entries whose time is estimated or unknown, and the lanes of
 * the pit lane and the fueling station.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The timeline is the picture behind a flag or a car: one lane per counter,
 * one chip per entry. Its rule is that NOTHING a counter typed is hidden.
 * An entry that does not count is still there, with the reason.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ReviewTimelineMarksTest extends ReviewDbTestCase
{
    /**
     * SITUATION: "Largar agora" is pressed a few seconds late. The pack
     * had already gone: car A's first crossing was typed by all three
     * counters three seconds BEFORE the recorded start. It crosses again
     * at 1:40.
     *
     * WHAT WENT WRONG: an entry before the start was dropped before
     * anything else looked at it. It was on no timeline and raised no
     * flag. Car A's crossing at 1:40 became its pass zero: one lap lost.
     *
     * EXPECTED: the three early entries are on car A's timeline as one
     * pass marked "antes_da_largada", not counted. A flag of that type
     * suggests confirming it. Once the reviewer accepts, the pass is car
     * A's pass zero and the crossing at 1:40 is lap 1 (so car A goes from
     * 1 lap to 2).
     *
     * Since round 4 the suggestion is only made once the field has shown
     * what a first lap looks like (three cars with one), which is why the
     * three cars each cross twice here.
     */
    public function testAPassTypedJustBeforeTheStartIsShownFlaggedAndCanBeCounted(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $early = $this->allCountersSee($car, -3);
        $this->allCountersSee($car, 100);
        $this->allCountersSee($car, 200);
        $this->allCountersSee(self::CAR_B, 20);
        $this->allCountersSee(self::CAR_B, 120);
        $this->allCountersSee(self::CAR_C, 25);
        $this->allCountersSee(self::CAR_C, 125);
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));

        $state = $this->freshScreen();
        $entries = $this->entriesById(TimelineWindow::forCar($this->service, $car));

        foreach ($early as $entryId) {
            $this->assertSame('antes_da_largada', $entries[$entryId]['grupo']['papel']);
            $this->assertFalse($entries[$entryId]['grupo']['contado']);
            $this->assertFalse($entries[$entryId]['fora_de_uso'], 'it is in a pass group, not thrown away');
        }
        $this->assertSame(1, $this->carRow($state, $car)['voltas']);
        $flag = $this->flagOfType($state, 'antes_da_largada');
        $this->assertSame('confirmar', $flag['sugestao']['tipo']);

        $answer = $this->decide($flag['sugestao'], $flag['passagem_ids'], $flag['assinatura']);

        $this->assertSame(200, $answer['status']);
        $this->assertSame(2, $this->carRow($answer['body']['estado'], $car)['voltas']);
        $entries = $this->entriesById(TimelineWindow::forCar($this->service, $car));
        $this->assertTrue($entries[$early[0]]['grupo']['contado'], 'confirmed: it is the pass zero now');
    }

    /**
     * SITUATION: counter 3's phone was offline and was restarted. Two of
     * the entries it uploads afterwards could not be stamped with the
     * official time:
     *   - one was stamped from a time the phone had saved before the
     *     restart ("estimado"): close, but the phone's own clock took part;
     *   - one has no usable time at all ("desconhecido"): what it carries
     *     is whatever the phone's clock said, here three days ago.
     *
     * EXPECTED: both are on car A's timeline, each saying what it is. The
     * estimated one is matched into its pass like any other. The unknown
     * one is in no pass, is marked out of use with the reason
     * "tempo_desconhecido", and can still be acted on (it has a signature).
     */
    public function testEntriesOfEstimatedAndUnknownTimeAreOnTheTimelineSayingWhatTheyAre(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $first = $this->allCountersSee($car, 60);
        $second = $this->allCountersSee($car, 160);
        $this->markTimeQuality($second[2], 'estimado');
        $unknown = $this->addEntry($this->fontes[2], $car, $this->at(0) - 3 * 24 * 3600 * 1000);
        $this->markTimeQuality($unknown, 'desconhecido');
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));

        $state = $this->freshScreen();
        $entries = $this->entriesById(TimelineWindow::forCar($this->service, $car));

        $this->assertSame('exato', $entries[$first[0]]['qualidade_tempo']);
        $this->assertSame('estimado', $entries[$second[2]]['qualidade_tempo']);
        $this->assertSame('volta', $entries[$second[2]]['grupo']['papel'], 'an estimated time is matched like any other');

        $this->assertSame('desconhecido', $entries[$unknown]['qualidade_tempo']);
        $this->assertNull($entries[$unknown]['grupo']);
        $this->assertTrue($entries[$unknown]['fora_de_uso']);
        $this->assertSame('tempo_desconhecido', $entries[$unknown]['motivo_fora_de_uso']);
        $this->assertNotSame('', $entries[$unknown]['assinatura']);

        $this->assertSame(1, $this->carRow($state, $car)['voltas'], 'the entry of unknown time added nothing');
        $this->assertSame([$unknown], $this->flagOfType($state, 'tempo_desconhecido')['passagem_ids']);
        $this->assertContains($second[2], $this->flagOfType($state, 'tempo_estimado')['passagem_ids']);
    }

    /**
     * SITUATION: the prova uses the fueling area. During the race:
     *   - car A goes through the pit lane: in at 2:00, out at 3:00;
     *   - car A enters the fueling station at 5:00 and is still there;
     *   - car B's EXIT from the pit lane is logged at 4:00, but nobody
     *     logged it going in (the person at the entrance missed it).
     *
     * EXPECTED on a window that covers all of it:
     *   - every event is listed, each with its area ("box" or
     *     "abastecimento");
     *   - the two stays the engine could pair are there as stays: car A in
     *     the pit lane from 2:00 to 3:00, car A fueling from 5:00 with no end;
     *   - car B's lone exit is kept and marked "sem_par". It is not a stay.
     * And the review state lists that unpaired exit, so the hole in the log
     * is seen without opening car B.
     */
    public function testPitAndFuelingEventsAreShownByAreaAndAnUnpairedOneIsMarked(): void
    {
        $this->changeSettings(['abastecimento' => true]);
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->allCountersSee(self::CAR_B, 70);
        $this->logStop(Ocorrencia::BOX_ENTRADA, self::CAR_A, 120);
        $this->logStop(Ocorrencia::BOX_SAIDA, self::CAR_A, 180);
        $this->logStop(Ocorrencia::BOX_SAIDA, self::CAR_B, 240);
        $this->logStop(Ocorrencia::ABASTECIMENTO_ENTRADA, self::CAR_A, 300);
        Clock::freezeAt($this->at(400));

        $state = $this->freshScreen();
        $window = TimelineWindow::forWindow($this->service, $this->at(0), $this->at(400));

        $this->assertTrue($window['abastecimento']);
        $events = [];
        foreach ($window['ocorrencias'] as $event) {
            $events[] = [$event['tipo'], $event['carro'], $event['area'], $event['sem_par']];
        }
        $this->assertSame([
            ['largada', null, null, false],
            ['box_entrada', self::CAR_A, 'box', false],
            ['box_saida', self::CAR_A, 'box', false],
            ['box_saida', self::CAR_B, 'box', true],
            ['abastecimento_entrada', self::CAR_A, 'abastecimento', false],
        ], $events);
        $this->assertSame([
            ['area' => 'box', 'carro' => self::CAR_A, 'de_ms' => $this->at(120), 'ate_ms' => $this->at(180)],
            ['area' => 'abastecimento', 'carro' => self::CAR_A, 'de_ms' => $this->at(300), 'ate_ms' => null],
        ], $window['estadias']);

        $this->assertSame(
            [['area' => 'box', 'tipo' => 'box_saida', 'carro' => self::CAR_B, 'ts_ms' => $this->at(240)]],
            $state['paradas_sem_par']
        );
        $this->assertSame('abastecendo', $this->carRow($state, self::CAR_A)['status']);

        // A car's own timeline shows that car's stops only.
        $ofCarB = TimelineWindow::forCar($this->service, self::CAR_B);
        $this->assertSame([], $ofCarB['estadias']);
        $this->assertSame([self::CAR_B], array_values(array_unique(array_filter(array_column($ofCarB['ocorrencias'], 'carro')))));
    }

    /**
     * SITUATION: after the race the organisers want the stops in a
     * spreadsheet, and the raw entries with how each was timed.
     *
     * EXPECTED: the export of stops has one line per car with the pit lane
     * and fueling figures; the export of entries has the final time and
     * its quality, and no longer the two columns that were removed from
     * the table (asking for them made the download fail).
     */
    public function testTheStopsAndTheEntriesCanBeExportedAsCsv(): void
    {
        $this->changeSettings(['abastecimento' => true]);
        $this->startRace(self::RACE_START_MS);
        $this->allCountersSee(self::CAR_A, 60);
        $this->logStop(Ocorrencia::BOX_ENTRADA, self::CAR_A, 120);
        $this->logStop(Ocorrencia::BOX_SAIDA, self::CAR_A, 180);
        Clock::freezeAt($this->at(400));
        $this->recompute();

        $stops = $this->download(CsvExport::PARADAS);
        $this->assertCount(1 + 3, $stops, 'a header and one line per car of the event');
        $lineOfCarA = implode(';', $stops[1]);
        $this->assertStringStartsWith((string) self::CAR_A . ';', $lineOfCarA);
        $this->assertStringContainsString('60', $lineOfCarA, 'the one-minute stay in the pit lane is in the line');

        $entries = $this->download(CsvExport::PASSAGENS);
        $this->assertContains('ts_ms', $entries[0]);
        $this->assertContains('qualidade_tempo', $entries[0]);
        $this->assertNotContains('desvio_relogio_ms', $entries[0]);
        $this->assertCount(1 + 3, $entries);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** The pit log records one event, as a tap on the pit page does. */
    private function logStop(string $tipo, int $car, int $secondsAfterStart): void
    {
        $this->newRequest();
        $this->service->withLock(function () use ($tipo, $car, $secondsAfterStart): void {
            $this->service->recordOcorrencia($tipo, $this->at($secondsAfterStart), $car, self::USER_PREFIX . 'box');
        });
    }

    /** Sets how an entry was timed, as the upload would have stored it. */
    private function markTimeQuality(int $entryId, string $quality): void
    {
        $this->executeSql('UPDATE contagem_passagem SET qualidade_tempo = ? WHERE passagem_id = ?', [$quality, $entryId]);
    }

    /**
     * @param array<string, mixed> $timeline
     * @return array<int, array<string, mixed>> entry id => entry
     */
    private function entriesById(array $timeline): array
    {
        $byId = [];
        foreach ($timeline['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                $byId[$entry['id']] = $entry;
            }
        }
        return $byId;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function flagOfType(array $state, string $type): array
    {
        foreach ($state['alertas_abertos'] as $flag) {
            if ($flag['tipo'] === $type) {
                return $flag;
            }
        }
        $this->fail('no open flag of type ' . $type);
    }

    /**
     * Runs a CSV export and returns its lines as lists of cells.
     *
     * @return array<int, string[]>
     */
    private function download(string $type): array
    {
        $this->newRequest();
        ob_start();
        // The export sends download headers; under the test runner output
        // has already started, and PHP would only warn about it.
        @CsvExport::stream($this->prova, $type);
        $csv = (string) ob_get_clean();

        $lines = [];
        foreach (array_filter(explode("\n", substr($csv, 3))) as $line) {
            $lines[] = str_getcsv($line, ';', '"', '');
        }
        return $lines;
    }
}
