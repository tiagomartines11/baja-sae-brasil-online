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
     * EXPECTED: asked first, in words about a sensor (its marks become the
     * time of the passes they match, the last pass published included; it
     * never counts a pass); and, with precise timing, asked about that too,
     * in the same words. A sensor cannot be the primary source of
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
            $this->assertStringContainsString('horário oficial das passagens', $question->getMessage());
            $this->assertStringContainsString('sem contar passagem', $question->getMessage());
        }
        try {
            $this->newRequest();
            SourceAdmin::update($this->service, $form + [SourceAdmin::CAMPO_CONFIRMAR_VOTANTE => '1']);
            $this->fail('precise timing must be asked too');
        } catch (ConfirmationNeeded $question) {
            $this->assertSame(SourceAdmin::SENSOR_TIMING_WARNING, $question->getMessage());
            $this->assertStringContainsString('última passagem publicada', $question->getMessage());
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
     * SITUATION: the controller edits a sensor that has marked a pass, and
     * picks "manual" as its type; then edits a counter that has typed a car
     * and picks "sensor de tempo".
     *
     * EXPECTED: both refused, with the reason: the rows already recorded
     * would change meaning (every mark a "??" to review; every number
     * ignored). A sensor with nothing recorded yet may still change type.
     */
    public function testASourceWithRecordsCannotTurnIntoOrOutOfASensor(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $this->upload($this->apiToken($sensor), [$this->unreadPayload(self::newUuid(), $this->at(60)) + ['mode' => 'api']]);
        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        $empty = $this->newSource('Sensor novo', Source::TIPO_SENSOR);
        $form = fn(ContagemFonte $fonte, string $tipo): array => [
            'fonte_id' => (string) $fonte->getFonteId(), 'nome' => (string) $fonte->getNome(), 'tipo' => $tipo,
            'qualidade_tempo' => Source::TEMPO_PRECISA, 'ordem' => '4', 'ativa' => '1', 'papel' => Source::PAPEL_VOTANTE,
        ];

        foreach ([[$sensor, Source::TIPO_MANUAL], [$this->fontes[0], Source::TIPO_SENSOR]] as [$fonte, $tipo]) {
            try {
                $this->newRequest();
                SourceAdmin::update($this->service, $form($fonte, $tipo));
                $this->fail('a source with records cannot change to or from a sensor');
            } catch (InvalidArgumentException $refused) {
                $this->assertStringContainsString('já tem registros', $refused->getMessage());
            }
        }
        $this->newRequest();
        SourceAdmin::update($this->service, $form($empty, Source::TIPO_AUTOMATICA));
        $this->assertSame(Source::TIPO_AUTOMATICA, $this->sourceNamed('Sensor novo')->getTipo());
        $this->assertSame(Source::TIPO_SENSOR, $this->sourceNamed('Sensor')->getTipo());
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
     * SITUATION: as above, but counter 2 changes their mind twice: "era o
     * 11", then "não, o 22", then "era o 11 mesmo".
     *
     * EXPECTED: each change is stored and the newest counts, so car A has
     * its lap again at the end. Only resending the CURRENT car is the
     * harmless repeat: a car the counter named before is not.
     */
    public function testACounterGoingBackToTheirFirstFixIsStored(): void
    {
        $token = $this->issueToken($this->fontes[1]);
        $this->recordCountedPass(self::CAR_A, $this->at(30));
        $this->recompute();
        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(220));
        $uuid = self::newUuid();
        $this->upload($token, [$this->unreadPayload($uuid, $this->at(220) + 500)]);

        $this->upload($token, [], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_A]]]);
        $this->upload($token, [], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_B]]]);
        $this->assertSame(0, (int) ($this->publishedData(self::CAR_A)['VOLTAS'] ?? 0));
        $answer = $this->upload($token, [], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_A]]]);

        $this->assertSame([$uuid], $answer['body']['fixed_uuids']);
        $this->assertSame([], $answer['body']['fixes_recusados']);
        $this->assertSame(3, $this->countRows('contagem_decisao', 'evento_id = ? AND tipo = ?', [self::EVENTO_ID, Decision::REATRIBUIR]));
        $this->assertSame(1, (int) $this->publishedData(self::CAR_A)['VOLTAS'], 'the newest fix counts');
        $this->assertARebuildChangesNothing('after going back');
    }

    /**
     * SITUATION: a rehearsal phone pointed at a real race (no simulation
     * allowed) sends a simulated "??" and, in the same request, its fix.
     *
     * EXPECTED: the entry is refused, as any simulated entry there, and the
     * fix is acknowledged and refused with it: its entry will never reach
     * the server, so the phone must not keep sending it.
     */
    public function testAFixOfARefusedEntryIsRefusedWithIt(): void
    {
        $token = $this->issueToken($this->fontes[1]);
        $uuid = self::newUuid();

        $answer = $this->upload($token, [['mode' => 'simulacao'] + $this->unreadPayload($uuid, $this->at(60))], ['fixes' => [['uuid' => $uuid, 'car' => self::CAR_A]]]);

        $this->assertSame([['uuid' => $uuid, 'motivo' => 'simulacao_nao_permitida']], $answer['body']['refused']);
        $this->assertSame([$uuid], $answer['body']['fixed_uuids']);
        $this->assertSame([$uuid], $answer['body']['fixes_recusados']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: a timing sensor marks every crossing, and nobody pressed
     * "??". A counter's reading of car A lies a second from a mark, and car
     * B was read by everyone two seconds from it.
     *
     * EXPECTED: the quick count of that upload recounts car A alone. A
     * sensor's marks change no count by lying near (they only time passes),
     * so nothing around them is loaded or recounted: with a sensor at the
     * line, that would be every upload of the race.
     */
    public function testASensorsMarksAddNoCarsToAnUploadsRecount(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $this->upload($this->apiToken($sensor), [$this->unreadPayload(self::newUuid(), $this->at(219)) + ['mode' => 'api']]);
        foreach ($this->fontes as $fonte) {
            $this->addEntry($fonte, self::CAR_B, $this->at(221));
        }
        $this->addEntry($this->fontes[0], self::CAR_A, $this->at(220));

        $this->newRequest();
        $loader = new \Baja\Contagem\Service\EngineInputLoader($this->prova);
        $this->assertSame([], $loader->carsNearUnread($this->service->getSettings(), [$this->at(220)]));
    }

    /**
     * SITUATION: a timing sensor at the line. The three counters read car B
     * at 3:40.0, 3:40.4 and 3:41.2 (LAST 220: the median), each uploading
     * at once. The sensor's system uploads its mark a little later: 3:38.3.
     *
     * EXPECTED: the mark's upload, which has no car, recounts car B and its
     * pass time is the mark: LAST 218 and the lap row's time, at once, as a
     * rebuild from scratch says too.
     */
    public function testASensorsMarkIsThePassTimeAsSoonAsItIsUploaded(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $sensorToken = $this->apiToken($sensor);
        $tokens = [];
        foreach ($this->fontes as $index => $fonte) {
            $tokens[$index] = $this->issueToken($fonte, self::USER_PREFIX . 'contador' . $index);
            $this->upload($tokens[$index], [$this->entryPayload(self::newUuid(), self::CAR_B, $this->at(30))]);
        }
        foreach ([0 => 220000, 1 => 220400, 2 => 221200] as $index => $offsetMs) {
            $this->upload($tokens[$index], [$this->entryPayload(self::newUuid(), self::CAR_B, $this->at(0) + $offsetMs)]);
        }
        $this->assertSame(220, (int) $this->publishedData(self::CAR_B)['LAST'], 'timed by the counters');

        $this->upload($sensorToken, [$this->unreadPayload(self::newUuid(), $this->at(0) + 218300) + ['mode' => 'api']]);

        $this->assertSame(218, (int) $this->publishedData(self::CAR_B)['LAST'], 'timed by the sensor');
        $this->assertSame(1, $this->countRows(
            'contagem_volta',
            'evento_id = ? AND prova_id = ? AND equipe_numero = ? AND numero_volta = 1 AND ts_ms = ?',
            [self::EVENTO_ID, self::PROVA_ID, self::CAR_B, $this->at(0) + 218300]
        ));
        $this->assertARebuildChangesNothing('after the mark');
    }

    /**
     * SITUATION: the sensor of the test above marked car B's pass at 3:38.3,
     * and something that was no car at 6:40 (nothing near it). The reviewer
     * opens the timeline of those minutes, of car B, and of the half second
     * around the first mark only (the counters' entries of that pass are
     * outside it).
     *
     * EXPECTED: in the sensor's lane, the first mark is shown with car B's
     * pass: marked as the sensor's mark, with car B, in the same pass group
     * as the counters' entries of that pass, and that group says the
     * sensor's mark is its time. The second mark is out of use, as a mark
     * that times no pass. Car B's own timeline and the half-second window
     * show the first mark with its pass too.
     */
    public function testTheTimelineShowsWhichPassASensorsMarkTimes(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $sensorToken = $this->apiToken($sensor);
        foreach ($this->fontes as $index => $fonte) {
            $token = $this->issueToken($fonte, self::USER_PREFIX . 'contador' . $index);
            $this->upload($token, [
                $this->entryPayload(self::newUuid(), self::CAR_B, $this->at(30)),
                $this->entryPayload(self::newUuid(), self::CAR_B, $this->at(0) + [220000, 220400, 221200][$index]),
            ]);
        }
        $timing = self::newUuid();
        $noise = self::newUuid();
        $this->upload($sensorToken, [
            $this->unreadPayload($timing, $this->at(0) + 218300) + ['mode' => 'api'],
            $this->unreadPayload($noise, $this->at(400)) + ['mode' => 'api'],
        ]);
        $timingId = (int) $this->fetchValue('SELECT passagem_id FROM contagem_passagem WHERE uuid_cliente = ?', [$timing]);
        $sensorId = (int) $sensor->getFonteId();

        $this->newRequest();
        $window = \Baja\Contagem\Review\TimelineWindow::forWindow($this->service, $this->at(200), $this->at(420));
        $marks = $this->entriesOfLane($window, $sensorId);
        $this->assertCount(2, $marks);
        [$first, $second] = $marks;
        $this->assertTrue($first['marca_do_sensor']);
        $this->assertSame(self::CAR_B, $first['carro']);
        $this->assertFalse($first['fora_de_uso']);
        $this->assertSame($this->at(0) + 218300, $first['grupo']['ts_ms']);
        $this->assertTrue($first['grupo']['tempo_do_sensor']);
        $this->assertSame($timingId, $first['grupo']['marca_sensor_id']);
        $counterEntry = $this->entriesOfLane($window, (int) $this->fontes[0]->getFonteId())[0];
        $this->assertSame($counterEntry['grupo'], $first['grupo'], 'the same pass group as the counters\' entries of that pass');
        $this->assertFalse($second['marca_do_sensor']);
        $this->assertTrue($second['fora_de_uso']);
        $this->assertSame('marca_do_sensor', $second['motivo_fora_de_uso']);
        $this->assertNull($second['carro']);

        $this->newRequest();
        $ofCarB = $this->entriesOfLane(\Baja\Contagem\Review\TimelineWindow::forCar($this->service, self::CAR_B), $sensorId);
        $this->assertSame([$timingId], array_column($ofCarB, 'id'));
        $this->assertTrue($ofCarB[0]['grupo']['tempo_do_sensor']);

        $this->newRequest();
        $narrow = $this->entriesOfLane(\Baja\Contagem\Review\TimelineWindow::forWindow($this->service, $this->at(218), $this->at(218) + 500), $sensorId);
        $this->assertSame([$timingId], array_column($narrow, 'id'));
        $this->assertSame(self::CAR_B, $narrow[0]['carro']);
        $this->assertSame($timingId, $narrow[0]['grupo']['marca_sensor_id']);
    }

    /**
     * SITUATION: the same race, exported from race control: the raw
     * entries ("Passagens") and the laps ("Voltas").
     *
     * EXPECTED: each export has one more column, the last (the others keep
     * their places): in Passagens, for the sensor's mark, the car whose pass
     * it times (empty for the mark that times none, and for the counters'
     * entries); in Voltas, whether the row's time is a sensor's mark: "sim"
     * for car B's lap 1, "não" for its pass zero.
     */
    public function testTheExportsSayWhichPassesTheSensorTimes(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $sensorToken = $this->apiToken($sensor);
        foreach ($this->fontes as $index => $fonte) {
            $this->upload($this->issueToken($fonte, self::USER_PREFIX . 'contador' . $index), [
                $this->entryPayload(self::newUuid(), self::CAR_B, $this->at(30)),
                $this->entryPayload(self::newUuid(), self::CAR_B, $this->at(0) + [220000, 220400, 221200][$index]),
            ]);
        }
        $timing = self::newUuid();
        $this->upload($sensorToken, [
            $this->unreadPayload($timing, $this->at(0) + 218300) + ['mode' => 'api'],
            $this->unreadPayload(self::newUuid(), $this->at(400)) + ['mode' => 'api'],
        ]);

        $entries = $this->download(\Baja\Contagem\Review\CsvExport::PASSAGENS);
        $this->assertSame(\Baja\Contagem\Review\CsvExport::COLUNA_MARCA_CRONOMETRA, end($entries[0]));
        $byUuid = [];
        $uuidColumn = array_search('uuid_cliente', $entries[0], true);
        foreach (array_slice($entries, 1) as $line) {
            $byUuid[$line[$uuidColumn]] = end($line);
        }
        $this->assertSame((string) self::CAR_B, $byUuid[$timing]);
        $this->assertSame(['' => 7], array_count_values(array_diff_key($byUuid, [$timing => true])), 'the other mark and the six entries of the counters');

        $laps = $this->download(\Baja\Contagem\Review\CsvExport::VOLTAS);
        $this->assertSame(\Baja\Contagem\Review\CsvExport::COLUNA_TEMPO_DO_SENSOR, end($laps[0]));
        $this->assertSame([['0', 'não'], ['1', 'sim']], array_map(fn(array $line) => [$line[1], end($line)], array_slice($laps, 1)));
    }

    /**
     * SITUATION: in the first 30 seconds, counter 1 typed car A, counter 2
     * pressed "??", and the timing sensor marked two crossings. The
     * controller then moves the start to 0:30.
     *
     * EXPECTED: the question counts 2 entries of 1 car left before the
     * start: car A's and the "??" (a crossing a counter saw, which the new
     * start leaves out like any other), not the sensor's marks (a mark
     * alone never counts, before the start or after).
     */
    public function testMovingTheStartCountsTheUnreadPassesButNotTheSensorsMarks(): void
    {
        $sensor = $this->newSource('Sensor', Source::TIPO_SENSOR);
        $sensorToken = $this->apiToken($sensor);
        $this->upload($this->issueToken($this->fontes[0]), [$this->entryPayload(self::newUuid(), self::CAR_A, $this->at(10))]);
        $this->upload($this->issueToken($this->fontes[1]), [$this->unreadPayload(self::newUuid(), $this->at(11))]);
        $this->upload($sensorToken, [
            $this->unreadPayload(self::newUuid(), $this->at(9)) + ['mode' => 'api'],
            $this->unreadPayload(self::newUuid(), $this->at(20)) + ['mode' => 'api'],
        ]);

        $this->newRequest();
        $affected = \Baja\Contagem\Review\RaceEventRules::entriesLeftBeforeStart($this->prova, $this->at(0), $this->at(30));

        $this->assertSame(['registros' => 2, 'carros' => 1], $affected);
    }

    /**
     * SITUATION: a prova with no timing sensor, with a counter's "??" and
     * without.
     *
     * EXPECTED: once the one look-up an upload already makes ("any entry
     * with no car?") has been made, asking which cars a sensor's marks move
     * costs no query at all.
     */
    public function testWithoutASensorTheMarksCostAnUploadNoQuery(): void
    {
        foreach ([false, true] as $withUnread) {
            if ($withUnread) {
                $this->upload($this->issueToken($this->fontes[1]), [$this->unreadPayload(self::newUuid(), $this->at(100))]);
            }
            $this->newRequest();
            $loader = new \Baja\Contagem\Service\EngineInputLoader($this->prova);
            $settings = $this->service->getSettings();
            $loader->carsNearUnread($settings, [$this->at(100)]);
            $connection = \Propel\Runtime\Propel::getWriteConnection('resultados');
            $connection->useDebug(true);
            $before = $connection->getQueryCount();
            $cars = $loader->carsAroundSensorMarks($settings, [$this->at(100)], [self::CAR_A]);
            $queries = $connection->getQueryCount() - $before;
            $connection->useDebug(false);
            $this->assertSame([], $cars);
            $this->assertSame(0, $queries, $withUnread ? 'with a "??"' : 'with no entry without a car');
        }
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
        @\Baja\Contagem\Review\CsvExport::stream($this->prova, $type);
        $csv = (string) ob_get_clean();
        $lines = [];
        foreach (array_filter(explode("\n", substr($csv, 3))) as $line) {
            $lines[] = str_getcsv($line, ';', '"', '');
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $timeline
     * @return array<int, array<string, mixed>> the entries of one source's lane, in time order
     */
    private function entriesOfLane(array $timeline, int $fonteId): array
    {
        foreach ($timeline['raias'] as $lane) {
            if ($lane['fonte_id'] === $fonteId) {
                return $lane['entradas'];
            }
        }
        $this->fail('No lane for source ' . $fonteId);
    }

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
