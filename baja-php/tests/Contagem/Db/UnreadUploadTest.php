<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Flag;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Review\ConfirmationNeeded;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use InvalidArgumentException;

/**
 * Passes recorded without a number ("??") through the upload endpoint: the
 * counter's "??", an automatic system's unread plate, a timing sensor's
 * marks, and the counter saying afterwards, on the phone, which car a "??"
 * was (`fixes`).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * An entry with `"car": null` is stored with no car. The engine works out
 * which car it most likely was from what the other counters read at that
 * moment (UnreadAssigner); where that is not clear, a reviewer is asked.
 * The counter can also settle it on the phone: a fix is stored as a
 * "reatribuir" decision whose origin is the counter, like the counter's own
 * voids. Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class UnreadUploadTest extends ReviewDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(900));
        $this->startRace($this->at(0));
    }

    /**
     * SITUATION: a counter sees a car cross and presses "??". The page sends
     * the entry with `"car": null`.
     *
     * EXPECTED: it is stored, with no car, and acknowledged. An entry with
     * no "car" at all is still not an entry: not stored, not acknowledged.
     */
    public function testACountersUnreadPassIsStoredWithoutACar(): void
    {
        $token = $this->issueToken($this->fontes[1]);
        $unread = self::newUuid();
        $broken = self::newUuid();
        $withoutCar = $this->entryPayload($broken, self::CAR_A, $this->at(60));
        unset($withoutCar['car']);

        $answer = $this->upload($token, [$this->unreadPayload($unread, $this->at(60)), $withoutCar]);

        $this->assertSame([$unread], $answer['body']['stored_uuids']);
        $this->assertNull($this->fetchValue('SELECT equipe_numero FROM contagem_passagem WHERE uuid_cliente = ?', [$unread]));
        $this->assertSame(0, $this->countRows('contagem_passagem', 'uuid_cliente = ?', [$broken]));
    }

    /**
     * SITUATION: an automatic system (a camera) could not read a plate and
     * sends `"car": null` with "mode": "api".
     *
     * EXPECTED: stored with no car, like a counter's "??".
     */
    public function testAnAutomaticSystemMaySendAnUnreadPass(): void
    {
        $camera = $this->newSource('Câmera', Source::TIPO_AUTOMATICA);
        $token = $this->apiToken($camera);
        $uuid = self::newUuid();
        $payload = $this->unreadPayload($uuid, $this->at(60));
        $payload['mode'] = 'api';

        $answer = $this->upload($token, [$payload]);

        $this->assertSame([$uuid], $answer['body']['stored_uuids']);
        $this->assertNull($this->fetchValue('SELECT equipe_numero FROM contagem_passagem WHERE uuid_cliente = ?', [$uuid]));
    }

    /**
     * SITUATION: the controller creates a timing sensor on the Fontes page,
     * with "humana" chosen in the timing field by mistake, and gives it a
     * token. The sensor's system sends one mark with no car and one with
     * car 22 (its software fills the field anyway).
     *
     * EXPECTED: the source is a sensor, precise whatever was chosen, and an
     * observer to begin with, like an automatic source. Both marks are
     * stored with no car: a sensor never knows the car, so the number it
     * sent is ignored (not refused: the mark is still good).
     */
    public function testASensorIsCreatedAsAnObserverAndNeverStoresACar(): void
    {
        $this->newRequest();
        SourceAdmin::create($this->service, ['nome' => 'Sensor', 'tipo' => Source::TIPO_SENSOR, 'qualidade_tempo' => Source::TEMPO_HUMANA]);
        $sensor = $this->sourceNamed('Sensor');
        $this->assertSame(Source::TIPO_SENSOR, $sensor->getTipo());
        $this->assertSame(Source::TEMPO_PRECISA, $sensor->getQualidadeTempo());
        $this->assertSame(Source::PAPEL_OBSERVADORA, $sensor->getPapel());

        $token = $this->apiToken($sensor);
        $withoutCar = self::newUuid();
        $withCar = self::newUuid();
        $answer = $this->upload($token, [
            $this->unreadPayload($withoutCar, $this->at(60)) + ['mode' => 'api'],
            ['mode' => 'api'] + $this->entryPayload($withCar, self::CAR_B, $this->at(61)),
        ]);

        $this->assertEqualsCanonicalizing([$withoutCar, $withCar], $answer['body']['stored_uuids']);
        $this->assertSame(0, $this->countRows('contagem_passagem', 'fonte_id = ? AND equipe_numero IS NOT NULL', [$sensor->getFonteId()]));
        $this->assertSame(2, $this->countRows('contagem_passagem', 'fonte_id = ?', [$sensor->getFonteId()]));
    }

    /**
     * SITUATION: the controller opens the Fontes page with a timing sensor
     * that takes part and has marked one pass the counters agree on.
     *
     * EXPECTED: the page offers the type with its help text, a token for
     * the sensor's system (it posts like an automatic source), no timing
     * choice (a sensor is precise), the role, and the sensor's numbers.
     */
    public function testTheFontesPageShowsATimingSensor(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $this->recordCountedPass(self::CAR_A, $this->at(30));
        $this->upload($this->apiToken($sensor), [$this->unreadPayload(self::newUuid(), $this->at(29)) + ['mode' => 'api']]);
        $this->recompute();
        $this->createUser(self::USER_PREFIX . 'controle', ['index', self::EVENTO_ID . '_' . self::PROVA_ID]);

        $page = $this->renderJuizPage('contagem_fontes.php', self::USER_PREFIX . 'controle', ['p' => self::PROVA_ID]);

        $this->assertStringContainsString(SourceAdmin::TYPE_LABELS[Source::TIPO_SENSOR], $page);
        $this->assertStringContainsString('marca só o horário da passagem, nunca o número', $page);
        $this->assertSame(1, substr_count($page, 'Criar token de API'), 'only the sensor has one');
        $this->assertStringContainsString('Marcas do sensor:', $page);
        $this->assertStringContainsString('1 de 1 dão o horário de uma passagem', $page);
    }

    /**
     * SITUATION: the sensor is promoted to votante.
     *
     * EXPECTED: asked first, in words about a sensor (its marks time laps;
     * it never counts a pass); and, with precise timing, asked about that
     * too, in the same words. A sensor cannot be the primary source of
     * "fonte principal" mode.
     */
    public function testPromotingASensorSaysWhatASensorDoes(): void
    {
        $this->newRequest();
        SourceAdmin::create($this->service, ['nome' => 'Sensor', 'tipo' => Source::TIPO_SENSOR, 'qualidade_tempo' => Source::TEMPO_PRECISA]);
        $sensor = $this->sourceNamed('Sensor');
        $form = [
            'fonte_id' => (string) $sensor->getFonteId(), 'nome' => 'Sensor', 'tipo' => Source::TIPO_SENSOR,
            'qualidade_tempo' => Source::TEMPO_PRECISA, 'ordem' => '4', 'ativa' => '1', 'papel' => Source::PAPEL_VOTANTE,
        ];
        try {
            $this->newRequest();
            SourceAdmin::update($this->service, $form);
            $this->fail('the promotion must be asked first');
        } catch (ConfirmationNeeded $question) {
            $this->assertStringContainsString('horário das voltas', $question->getMessage());
            $this->assertStringContainsString('sem contar passagem', $question->getMessage());
        }
        try {
            $this->newRequest();
            SourceAdmin::update($this->service, $form + [SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1']);
            $this->fail('precise timing must be asked too');
        } catch (ConfirmationNeeded $question) {
            $this->assertSame(SourceAdmin::SENSOR_TIMING_WARNING, $question->getMessage());
        }
        $this->newRequest();
        SourceAdmin::update($this->service, $form + [SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1', SourceAdmin::CAMPO_CONFIRMAR_PRECISA => '1']);
        $this->assertSame(Source::PAPEL_VOTANTE, $this->sourceNamed('Sensor')->getPapel());

        $this->newRequest();
        $settings = $this->service->getSettings()->toArray();
        $settings['modo_publicacao'] = 'fonte_principal';
        $settings['fonte_principal_id'] = (string) $sensor->getFonteId();
        try {
            SourceAdmin::saveSettings($this->service, array_map(fn($value) => is_bool($value) ? ($value ? '1' : '') : (string) $value, $settings));
            $this->fail('a sensor cannot be the primary source');
        } catch (InvalidArgumentException $refused) {
            $this->assertStringContainsString('sensor de tempo nunca conta', $refused->getMessage());
        }
    }

    /**
     * SITUATION: car A's pass zero is seen by counters 1 and 2 at 0:30. At
     * 3:40 counter 1 types A and counter 2 presses "??" (counter 3 missed
     * it): one reading only, so the engine does not apply car A by itself
     * and car A has no lap yet. Then counter 2 remembers ("era o 11") and
     * fixes it on the phone.
     *
     * EXPECTED: the fix is stored as a "reatribuir" by the counter and
     * acknowledged; car A's lap counts and is published at once. Sending the
     * same fix again stores nothing. Fixing it to car B instead stores a
     * second fix, and the newest counts: car A is back to no lap.
     */
    public function testACounterFixesTheirOwnUnreadPass(): void
    {
        $token = $this->issueToken($this->fontes[1]);
        $this->recordCountedPass(self::CAR_A, $this->at(30));
        $this->recompute();
        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(220));
        $uuid = self::newUuid();
        $this->upload($token, [$this->unreadPayload($uuid, $this->at(220) + 500)]);
        $this->assertSame(0, (int) ($this->publishedData(self::CAR_A)['VOLTAS'] ?? 0), 'one reading and a "??" wait for a person');

        $answer = $this->upload($token, [], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_A]]]);

        $this->assertSame([$uuid], $answer['body']['fixed_uuids']);
        $this->assertSame([], $answer['body']['fixes_recusados']);
        $this->assertSame(1, $this->countRows('contagem_decisao', 'evento_id = ? AND tipo = ? AND origem = ?', [self::EVENTO_ID, Decision::REATRIBUIR, Decision::ORIGEM_CONTADOR]));
        $this->assertSame(1, (int) $this->publishedData(self::CAR_A)['VOLTAS']);

        $again = $this->upload($token, [], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_A]]]);
        $this->assertSame([$uuid], $again['body']['fixed_uuids']);
        $this->assertSame(1, $this->countRows('contagem_decisao', 'evento_id = ? AND tipo = ?', [self::EVENTO_ID, Decision::REATRIBUIR]), 'resending changes nothing');

        $this->upload($token, [], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_B]]]);
        $this->assertSame(2, $this->countRows('contagem_decisao', 'evento_id = ? AND tipo = ?', [self::EVENTO_ID, Decision::REATRIBUIR]));
        $this->assertSame(0, (int) $this->publishedData(self::CAR_A)['VOLTAS'], 'the newest fix counts');
    }

    /**
     * SITUATION: fixes a counter's phone has no business making: of an entry
     * typed with a number, of a "??" the counter voided, of a "??" a
     * reviewer already reassigned, with a car that is no number, and of
     * another counter's entry.
     *
     * EXPECTED: nothing is stored. The first four are acknowledged (the
     * phone stops sending them) and listed in `fixes_recusados`, so the page
     * can say so. The last is not acknowledged: for this phone that entry
     * has not reached the server (it cannot fix what is not its own).
     */
    public function testFixesTheCounterMayNotMakeAreAcknowledgedAndRefused(): void
    {
        $token = $this->issueToken($this->fontes[1]);
        $typed = self::newUuid();
        $voided = self::newUuid();
        $reassigned = self::newUuid();
        $noNumber = self::newUuid();
        $this->upload($token, [
            $this->entryPayload($typed, self::CAR_A, $this->at(100)),
            $this->unreadPayload($voided, $this->at(200)),
            $this->unreadPayload($reassigned, $this->at(300)),
            $this->unreadPayload($noNumber, $this->at(400)),
        ]);
        $this->upload($token, [], ['voids' => [['uuid' => $voided]]]);
        $reassignedId = (int) $this->fetchValue('SELECT passagem_id FROM contagem_passagem WHERE uuid_cliente = ?', [$reassigned]);
        $this->assertSame(200, $this->decideAboutEntry(['tipo' => 'reatribuir', 'passagem_id' => $reassignedId, 'equipe_numero' => self::CAR_C], $reassignedId)['status']);
        $someoneElses = self::newUuid();
        $this->upload($this->issueToken($this->fontes[2], self::USER_PREFIX . 'outro'), [$this->unreadPayload($someoneElses, $this->at(500))]);
        $decisionsBefore = $this->countDecisions();

        $answer = $this->upload($token, [], ['fixes' => [
            ['uuid' => $typed, 'car' => self::CAR_B],
            ['uuid' => $voided, 'car' => self::CAR_B],
            ['uuid' => $reassigned, 'car' => self::CAR_B],
            ['uuid' => $noNumber, 'car' => 'onze'],
            ['uuid' => $someoneElses, 'car' => self::CAR_B],
        ]]);

        $this->assertEqualsCanonicalizing([$typed, $voided, $reassigned, $noNumber], $answer['body']['fixed_uuids']);
        $this->assertEqualsCanonicalizing([$typed, $voided, $reassigned, $noNumber], $answer['body']['fixes_recusados']);
        $this->assertSame($decisionsBefore, $this->countDecisions());
    }

    /**
     * SITUATION: a timing sensor's system sends a "fix" for one of its marks.
     *
     * EXPECTED: refused: a sensor never knows the car. Only a reviewer can
     * say which car a mark was.
     */
    public function testASensorCannotFixItsMarks(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $token = $this->apiToken($sensor);
        $uuid = self::newUuid();
        $this->upload($token, [$this->unreadPayload($uuid, $this->at(60)) + ['mode' => 'api']]);

        $answer = $this->upload($token, [], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_A]]]);

        $this->assertSame([$uuid], $answer['body']['fixes_recusados']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: car B crosses at 3:40. Counter 1 types it at 3:40.0,
     * counter 3 at 3:41.8, counter 2 presses "??" at 3:40.2: two readings
     * and nothing else near, so the "??" is car B, and its pass is timed by
     * the median of three (3:40.2) instead of the middle of two (3:40.9):
     * LAST is 220, not 221. The "??" is uploaded FIRST, before either
     * reading; each upload is counted by the quick per-car path. Later
     * counter 2 fixes the "??" to car C.
     *
     * EXPECTED: at every step what the quick count published is what a
     * rebuild from scratch gives: the readings' uploads (car B) take the
     * "??" near them along, and the fix (car C) recounts car B too, which
     * the "??" no longer counts for.
     */
    public function testTheQuickCountAroundAnUnreadPassEqualsARebuild(): void
    {
        $tokens = [];
        foreach ($this->fontes as $index => $fonte) {
            $tokens[$index] = $this->issueToken($fonte, self::USER_PREFIX . 'contador' . $index);
        }
        foreach ([0, 1, 2] as $index) {
            $this->upload($tokens[$index], [$this->entryPayload(self::newUuid(), self::CAR_B, $this->at(30))]);
        }
        $unread = self::newUuid();
        $this->upload($tokens[1], [$this->unreadPayload($unread, $this->at(220) + 200)]);
        $this->upload($tokens[0], [$this->entryPayload(self::newUuid(), self::CAR_B, $this->at(220))]);
        $this->assertARebuildChangesNothing('after the first reading');
        $this->upload($tokens[2], [$this->entryPayload(self::newUuid(), self::CAR_B, $this->at(221) + 800)]);
        $this->assertSame(220, (int) $this->publishedData(self::CAR_B)['LAST'], 'timed with the "??"');
        $this->assertARebuildChangesNothing('after the second reading');

        $this->upload($tokens[1], [], ['fixes' => [['uuid' => $unread, 'car' => self::CAR_C]]]);
        $this->assertSame(221, (int) $this->publishedData(self::CAR_B)['LAST'], 'the "??" was car C after all');
        $this->assertARebuildChangesNothing('after the fix');
    }

    /**
     * SITUATION: as above without the fix: the "??" counts for car B by the
     * engine's own reckoning. A reviewer then says it was car C, through the
     * review screen, with the "??" in view.
     *
     * EXPECTED: the decision is accepted (the "??" is an entry of this prova
     * whatever car it counts for), and it wins over the engine's guess.
     * Confirming the engine's car is accepted as well: it makes it a
     * person's word.
     */
    public function testAReviewerReassignsAnUnreadPass(): void
    {
        $this->recordCountedPass(self::CAR_B, $this->at(30));
        $this->addEntry($this->fontes[0], self::CAR_B, $this->at(220));
        $this->addEntry($this->fontes[2], self::CAR_B, $this->at(221));
        $token = $this->issueToken($this->fontes[1]);
        $unread = self::newUuid();
        $this->upload($token, [$this->unreadPayload($unread, $this->at(220) + 400)]);
        $unreadId = (int) $this->fetchValue('SELECT passagem_id FROM contagem_passagem WHERE uuid_cliente = ?', [$unread]);
        $this->recompute();
        $this->assertSame(self::CAR_B, $this->service->compute()->effectiveEntries[$unreadId]->carro, 'the engine gave it car B');

        $same = $this->decideAboutEntry(['tipo' => 'reatribuir', 'passagem_id' => $unreadId, 'equipe_numero' => self::CAR_B], $unreadId);
        $this->assertSame(200, $same['status'], json_encode($same['body']));
        $other = $this->decideAboutEntry(['tipo' => 'reatribuir', 'passagem_id' => $unreadId, 'equipe_numero' => self::CAR_C], $unreadId);
        $this->assertSame(200, $other['status'], json_encode($other['body']));

        $this->newRequest();
        $entry = $this->service->compute()->effectiveEntries[$unreadId];
        $this->assertSame(self::CAR_C, $entry->carro);
        $this->assertFalse($entry->atribuidoAutomaticamente);
    }

    /**
     * SITUATION: a "??" with nothing near it, and the review screen asks
     * for the state and for the timeline around it.
     *
     * EXPECTED: the flag is there for the reviewer, and the "??" is on the
     * timeline, with no car, marked as unread.
     */
    public function testAnUnreadPassIsOnTheReviewScreen(): void
    {
        $token = $this->issueToken($this->fontes[1]);
        $uuid = self::newUuid();
        $this->upload($token, [$this->unreadPayload($uuid, $this->at(300))]);
        $this->syncAll($this->at(890));

        $state = $this->freshScreen();
        $this->assertContains(Flag::NUMERO_ILEGIVEL, array_column($state['alertas_abertos'], 'tipo'));

        $this->newRequest();
        $window = \Baja\Contagem\Review\TimelineWindow::forWindow($this->service, $this->at(240), $this->at(360));
        $shown = [];
        foreach ($window['raias'] as $lane) {
            foreach ($lane['entradas'] as $entry) {
                $shown[] = [$entry['carro'], $entry['ilegivel'], $entry['motivo_fora_de_uso']];
            }
        }
        $this->assertSame([[null, true, 'ilegivel']], $shown);
    }

    // ----- helpers -----

    /** @return array<string, mixed> a "??" as the page sends it */
    private function unreadPayload(string $uuid, int $tsMs): array
    {
        $payload = $this->entryPayload($uuid, 0, $tsMs);
        $payload['car'] = null;
        return $payload;
    }

    private function newSource(string $name, string $type): ContagemFonte
    {
        $fonte = new ContagemFonte();
        $fonte->setEventoId(self::EVENTO_ID);
        $fonte->setProvaId(self::PROVA_ID);
        $fonte->setNome($name);
        $fonte->setTipo($type);
        $fonte->setQualidadeTempo(Source::TEMPO_PRECISA);
        $fonte->setAtiva(true);
        $fonte->setOrdem(9);
        $fonte->setPapel(Source::PAPEL_VOTANTE);
        $fonte->save();
        return $fonte;
    }

    private function sourceNamed(string $name): ContagemFonte
    {
        TestWorld::forgetLoadedRows();
        return ContagemFonteQuery::create()->filterByEventoId(self::EVENTO_ID)->filterByProvaId(self::PROVA_ID)->filterByNome($name)->findOne();
    }

    private function apiToken(ContagemFonte $fonte): string
    {
        $this->newRequest();
        return SourceAdmin::createApiToken($this->service, ['fonte_id' => (string) $fonte->getFonteId(), 'rotulo' => 'API']);
    }

    private function assertARebuildChangesNothing(string $when): void
    {
        $quick = $this->countedState();
        $this->newRequest();
        $this->service->rebuildLaps();
        $this->assertSame($quick, $this->countedState(), 'quick and rebuilt counts differ ' . $when);
    }

    /** @return array<string, mixed> per car the published laps and last pass, and the laps table's count columns */
    private function countedState(): array
    {
        $published = [];
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $car) {
            $dados = $this->publishedData($car);
            $published[$car] = $dados === null ? null : [(int) $dados['VOLTAS'], (int) $dados['LAST']];
        }
        $statement = \Propel\Runtime\Propel::getWriteConnection('resultados')->prepare(
            'SELECT equipe_numero, numero_volta, ts_ms, fontes, estado FROM contagem_volta
              WHERE evento_id = ? AND prova_id = ? ORDER BY equipe_numero, numero_volta'
        );
        $statement->execute([self::EVENTO_ID, self::PROVA_ID]);
        return ['published' => $published, 'laps' => array_map(fn(array $row): string => implode(' ', $row), $statement->fetchAll(\PDO::FETCH_NUM))];
    }
}
