<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Review\ConfirmationNeeded;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Review\TimelineWindow;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use InvalidArgumentException;

/**
 * Machine vision on trial, on the "Fontes e configurações" page and through
 * the upload endpoint: an automatic source that starts as an OBSERVER (what
 * it sends is compared with the count, never counted), its promotion to
 * votante, the source the count depends on, switching a source on again,
 * and the pictures a system may link to its entries.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class ObserverSourceAdminTest extends ReviewDbTestCase
{
    /**
     * SITUATION: a controller creates an automatic source for a camera on
     * trial and gives it a token. During the race the camera and counter 1
     * both record car A twice; counters 2 and 3 miss it.
     *
     * EXPECTED: the new automatic source is an observer. Counter 1 alone is
     * not enough for a pass (three counters are counting), and the camera
     * does not make a second voice: car A has no official pass. The
     * camera's comparison with the count is there for the review screen.
     */
    public function testANewAutomaticSourceStartsAsAnObserverAndCountsNothing(): void
    {
        $camera = $this->createCamera(Source::TEMPO_HUMANA);
        $this->assertSame(Source::PAPEL_OBSERVADORA, $camera->getPapel());
        $this->assertNull($camera->getPapelDesdeMs(), 'an observer since it exists');

        $this->startRace(self::RACE_START_MS);
        foreach ([60, 160] as $seconds) {
            $this->addEntry($this->fontes[0], self::CAR_A, $this->at($seconds));
            $this->addEntry($camera, self::CAR_A, $this->at($seconds));
        }
        $this->syncAll($this->at(590));
        Clock::freezeAt($this->at(600));
        $this->recompute();

        $result = $this->service->compute();
        $this->assertSame(0, $result->lapCount(self::CAR_A));
        $this->assertNotNull($result->sourceStats[(int) $camera->getFonteId()]->observacao);
    }

    /**
     * SITUATION: the camera observed from the start. Counters 1 and 2 saw
     * car A's pass zero at 0:30. At 1:00 counter 1 and the camera saw car A;
     * at 2:00 the controller promotes the camera to votante; at 3:00 counter
     * 1 and the camera saw car A again.
     *
     * EXPECTED: the promotion is asked first, saying it does not reach back.
     * Once confirmed it counts from 2:00 on: the 1:00 pass still has one
     * voice (pending), the 3:00 one has two and completes lap 1. On the
     * timeline its 1:00 entry is still "observed", not "from a source
     * switched off".
     */
    public function testAPromotionIsAskedFirstAndCountsFromThatMomentOn(): void
    {
        $camera = $this->createCamera(Source::TEMPO_HUMANA);
        $this->startRace(self::RACE_START_MS);
        $this->recordCountedPass(self::CAR_A, $this->at(30));
        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        $this->addEntry($camera, self::CAR_A, $this->at(60));

        Clock::freezeAt($this->at(120));
        try {
            $this->submitSourceForm($camera, ['papel' => Source::PAPEL_VOTANTE]);
            $this->fail('the promotion was not asked');
        } catch (ConfirmationNeeded $question) {
            $this->assertSame(SourceAdmin::CAMPO_CONFIRMAR_VOTANTE, $question->campo);
            $this->assertStringContainsString('não vale para trás', $question->getMessage());
        }
        $this->assertSame(Source::PAPEL_OBSERVADORA, $this->reload($camera)->getPapel(), 'nothing changed before the answer');

        $this->submitSourceForm($camera, ['papel' => Source::PAPEL_VOTANTE, SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1']);
        $stored = $this->reload($camera);
        $this->assertSame(Source::PAPEL_VOTANTE, $stored->getPapel());
        $this->assertSame($this->at(120), (int) $stored->getPapelDesdeMs());
        $this->assertSame([[null, $this->at(120), Source::PERIODO_OBSERVADORA]], Source::periodsFromJson($stored->getPeriodosFora()));

        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(180));
        $this->addEntry($camera, self::CAR_A, $this->at(180));
        $this->syncAll($this->at(590));
        Clock::freezeAt($this->at(600));
        $this->recompute();
        $this->assertEquals(1, $this->publishedData(self::CAR_A)['VOLTAS']);

        $observed = array_values(array_filter(
            $this->entriesOnTheTimeline(),
            fn(array $entry) => $entry['fonte_id'] === (int) $camera->getFonteId() && $entry['ts_ms'] === $this->at(60)
        ));
        $this->assertCount(1, $observed);
        $this->assertSame('fonte_observadora', $observed[0]['motivo_fora_de_uso']);
    }

    /**
     * SITUATION: the camera has precise timing. A precise source that
     * counts sets the pass time of every pass it is in.
     *
     * EXPECTED: creating it is not asked (an observer counts nothing), but
     * promoting it asks about the precise timing too, after the promotion.
     */
    public function testAPreciseSourceIsAskedAboutBeforeItStartsToCount(): void
    {
        $camera = $this->createCamera(Source::TEMPO_PRECISA);
        try {
            $this->submitSourceForm($camera, ['papel' => Source::PAPEL_VOTANTE, SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1']);
            $this->fail('the precise timing was not asked');
        } catch (ConfirmationNeeded $question) {
            $this->assertSame(SourceAdmin::CAMPO_CONFIRMAR_PRECISA, $question->campo);
        }
        $this->assertSame(Source::PAPEL_OBSERVADORA, $this->reload($camera)->getPapel());

        $this->submitSourceForm($camera, [
            'papel' => Source::PAPEL_VOTANTE,
            SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1',
            SourceAdmin::CAMPO_CONFIRMAR_PRECISA => '1',
        ]);
        $this->assertSame(Source::PAPEL_VOTANTE, $this->reload($camera)->getPapel());
    }

    /**
     * SITUATION: the controller picks the observer camera as the primary
     * source. Later the camera is promoted, made the primary source in
     * "fonte principal" mode, and somebody sets it back to observer.
     *
     * EXPECTED: both are refused. Nothing an observer records counts, so as
     * the primary source it would take every lap away from every car.
     */
    public function testAnObserverCannotBeThePrimarySource(): void
    {
        $camera = $this->createCamera(Source::TEMPO_HUMANA);
        try {
            $this->saveSettingsForm(['modo_publicacao' => 'fonte_principal', 'fonte_principal_id' => (string) $camera->getFonteId()]);
            $this->fail('an observer was accepted as the primary source');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('observadora', $refusal->getMessage());
        }

        $this->submitSourceForm($camera, ['papel' => Source::PAPEL_VOTANTE, SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1']);
        $this->newRequest();
        $this->service->saveSettings(Settings::fromArray([
            'modo_publicacao' => Settings::MODO_FONTE_PRINCIPAL,
            'fonte_principal_id' => (int) $camera->getFonteId(),
        ]));
        try {
            $this->submitSourceForm($camera, ['papel' => Source::PAPEL_OBSERVADORA]);
            $this->fail('the primary source was made an observer');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('observadora', $refusal->getMessage());
        }
        $this->assertSame(Source::PAPEL_VOTANTE, $this->reload($camera)->getPapel());
    }

    /**
     * SITUATION: the publishing mode is "confirmadas", where the primary
     * source has no effect. The promoted camera is the stored primary and
     * is set back to observer. Later the controller changes only the
     * matching window; the form posts the stored primary again.
     *
     * WHAT WENT WRONG: every save of the settings form was refused ("ela é
     * observadora"), whatever it changed.
     *
     * EXPECTED: the save goes through. Choosing the "fonte principal" mode
     * with the observer as primary is still refused.
     */
    public function testAnObserverStoredAsPrimaryDoesNotBlockTheSettingsInOtherModes(): void
    {
        $camera = $this->createCamera(Source::TEMPO_HUMANA);
        $this->submitSourceForm($camera, ['papel' => Source::PAPEL_VOTANTE, SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1']);
        $this->newRequest();
        $this->service->saveSettings(Settings::fromArray([
            'modo_publicacao' => Settings::MODO_CONFIRMADAS,
            'fonte_principal_id' => (int) $camera->getFonteId(),
        ]));
        $this->submitSourceForm($camera, ['papel' => Source::PAPEL_OBSERVADORA]);
        $this->assertSame(Source::PAPEL_OBSERVADORA, $this->reload($camera)->getPapel(), 'the primary has no effect in this mode');

        $window = $this->service->getSettings()->janelaMs + 1000;
        $this->saveSettingsForm(['janela_ms' => (string) $window]);
        $this->newRequest();
        $this->assertSame($window, $this->service->getSettings()->janelaMs);

        try {
            $this->saveSettingsForm(['modo_publicacao' => Settings::MODO_FONTE_PRINCIPAL]);
            $this->fail('an observer was accepted as the primary source');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('observadora', $refusal->getMessage());
        }
    }

    /**
     * SITUATION: Contagem 3 is switched off at 5:00. Its phone, still open
     * in a pocket, sends car A at 6:00, which counter 1 also recorded. At
     * 8:00 it is switched on again.
     *
     * WHAT WENT WRONG: switching it on cleared the moment it was switched
     * off, and every entry it had sent while off suddenly counted: here the
     * 6:00 pass got a second voice and a lap appeared.
     *
     * EXPECTED: switching it on again keeps the time it was off as a period
     * off: the 6:00 entry stays out. Asking explicitly ("contar também")
     * brings it back.
     */
    public function testSwitchingASourceOnAgainDoesNotBringBackWhatItSentWhileOff(): void
    {
        $counter3 = $this->fontes[2];
        $this->startRace(self::RACE_START_MS);
        $this->recordCountedPass(self::CAR_A, $this->at(30));
        Clock::freezeAt($this->at(300));
        $this->submitSourceForm($counter3, ['ativa' => '']);

        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(360));
        $this->addEntry($counter3, self::CAR_A, $this->at(360));
        Clock::freezeAt($this->at(480));
        $this->submitSourceForm($counter3, ['ativa' => '1']);

        $stored = $this->reload($counter3);
        $this->assertTrue((bool) $stored->getAtiva());
        $this->assertSame([[$this->at(300), $this->at(480)]], Source::periodsFromJson($stored->getPeriodosFora()));
        $this->syncAll($this->at(590));
        Clock::freezeAt($this->at(600));
        $this->recompute();
        $this->assertSame(0, $this->service->compute()->lapCount(self::CAR_A), 'the 6:00 entry was sent while off');

        $this->submitSourceForm($counter3, ['ativa' => '']);
        Clock::freezeAt($this->at(610));
        $this->submitSourceForm($counter3, ['ativa' => '1', SourceAdmin::CAMPO_CONTAR_PERIODO_DESATIVADA => '1']);
        $this->assertCount(1, Source::periodsFromJson($this->reload($counter3)->getPeriodosFora()), 'asked for: no new period off');
    }

    /**
     * SITUATION: a camera is plugged in before anyone checked it. The
     * controller creates its source switched off.
     *
     * EXPECTED: it exists, switched off, and never took part: nothing it
     * sends counts until it is switched on (and then only from that moment).
     */
    public function testASourceCanBeCreatedSwitchedOff(): void
    {
        $this->newRequest();
        SourceAdmin::create($this->service, [
            'nome' => 'Câmera desligada', 'tipo' => Source::TIPO_MANUAL, 'qualidade_tempo' => Source::TEMPO_HUMANA,
            SourceAdmin::CAMPO_CRIAR_DESATIVADA => '1',
        ]);
        $created = ContagemFonteQuery::create()->filterByProvaId(self::PROVA_ID)->filterByEventoId(self::EVENTO_ID)
            ->filterByNome('Câmera desligada')->findOne();
        $this->assertFalse((bool) $created->getAtiva());
        $this->assertNull($created->getDesativadaEmMs());
    }

    /**
     * SITUATION: the camera's system sends, with each entry, the id of the
     * picture it read the number from and how sure it was. One id is not
     * a plain id ("../segredo"). A counter's phone sends the same fields.
     *
     * EXPECTED: stored for the system's entries only, the bad id dropped
     * (the entry itself is stored). The timeline shows no picture link while
     * the source has no image address; once it has one, each entry with a
     * picture gets address + id, and its confidence.
     */
    public function testPicturesOfAnAutomaticSourceAreLinkedOnlyOnceItHasAnImageAddress(): void
    {
        $camera = $this->createCamera(Source::TEMPO_HUMANA);
        $this->startRace(self::RACE_START_MS);
        Clock::freezeAt($this->at(200));
        $token = DeviceTokens::issue($camera, null, 'API');

        $good = $this->entryPayload(self::newUuid(), self::CAR_A, $this->at(60)) + ['imagem' => 'cam1-000123.jpg', 'confianca' => 0.8734];
        $good['mode'] = 'api';
        $bad = $this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120)) + ['imagem' => '../segredo', 'confianca' => 7];
        $bad['mode'] = 'api';
        $this->assertSame(200, $this->upload($token, [$good, $bad])['status']);

        $phone = $this->entryPayload(self::newUuid(), self::CAR_A, $this->at(61)) + ['imagem' => 'abc', 'confianca' => 0.5];
        $this->assertSame(200, $this->upload($this->issueToken($this->fontes[0]), [$phone])['status']);

        $ofThisRace = 'fonte_id IN (?, ?) AND ';
        $sources = [$camera->getFonteId(), $this->fontes[0]->getFonteId()];
        $this->assertSame(3, $this->countRows('contagem_passagem', 'fonte_id IN (?, ?)', $sources), 'every entry is stored');
        $this->assertSame(1, $this->countRows('contagem_passagem', $ofThisRace . 'imagem_ref IS NOT NULL', $sources));
        $this->assertSame(1, $this->countRows('contagem_passagem', $ofThisRace . 'confianca IS NOT NULL', $sources));

        foreach ($this->entriesOnTheTimeline() as $entry) {
            $this->assertArrayNotHasKey('imagem_url', $entry, 'no image address: no picture anywhere');
        }

        $this->submitSourceForm($camera, ['imagem_base_url' => 'http://camera.local:8080/img/']);
        $withPicture = array_values(array_filter($this->entriesOnTheTimeline(), fn(array $entry) => isset($entry['imagem_url'])));
        $this->assertCount(1, $withPicture);
        $this->assertSame('http://camera.local:8080/img/cam1-000123.jpg', $withPicture[0]['imagem_url']);
        $this->assertEqualsWithDelta(0.873, $withPicture[0]['confianca'], 0.0001);

        $this->expectException(InvalidArgumentException::class);
        $this->submitSourceForm($camera, ['imagem_base_url' => 'javascript:alert(1)']);
    }

    /** @return array<int, array<string, mixed>> every entry of car A's timeline */
    private function entriesOnTheTimeline(): array
    {
        $this->newRequest();
        $entries = [];
        foreach (TimelineWindow::forCar($this->service, self::CAR_A)['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    private function createCamera(string $timing): ContagemFonte
    {
        $this->newRequest();
        SourceAdmin::create($this->service, ['nome' => 'Câmera', 'tipo' => Source::TIPO_AUTOMATICA, 'qualidade_tempo' => $timing]);
        TestWorld::forgetLoadedRows();
        return ContagemFonteQuery::create()->filterByProvaId(self::PROVA_ID)->filterByEventoId(self::EVENTO_ID)
            ->filterByNome('Câmera')->findOne();
    }

    /**
     * Submits the "Salvar" form of one source; fields not given keep their
     * value, as the page sends them.
     *
     * @param array<string, string> $changes
     */
    private function submitSourceForm(ContagemFonte $fonte, array $changes): void
    {
        $current = $this->reload($fonte);
        $form = $changes + [
            'fonte_id' => (string) $current->getFonteId(),
            'nome' => (string) $current->getNome(),
            'tipo' => (string) $current->getTipo(),
            'qualidade_tempo' => (string) $current->getQualidadeTempo(),
            'ordem' => (string) $current->getOrdem(),
            'ativa' => $current->getAtiva() ? '1' : '',
        ];
        if ($current->getTipo() === Source::TIPO_AUTOMATICA) {
            $form += ['papel' => (string) $current->getPapel(), 'imagem_base_url' => (string) $current->getImagemBaseUrl()];
        }
        $this->newRequest();
        SourceAdmin::update($this->service, $form);
    }

    /** @param array<string, string> $changes */
    private function saveSettingsForm(array $changes): void
    {
        $this->newRequest();
        $form = [];
        foreach ($this->service->getSettings()->toArray() as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $form[$key] = '1';
                }
                continue;
            }
            $form[$key] = $value === null ? '' : str_replace('.', ',', (string) $value);
        }
        SourceAdmin::saveSettings($this->service, $changes + $form);
    }

    private function reload(ContagemFonte $fonte): ContagemFonte
    {
        TestWorld::forgetLoadedRows();
        return ContagemFonteQuery::create()->findPk($fonte->getFonteId());
    }
}
