<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Service\Clock;

/**
 * The controller's pages added or changed in round 3, through the REAL web
 * server: the hub's "mark everybody as fit" button, the entry page with
 * publishing switched off, the stops export and the simulation banner.
 *
 * See HttpAccessTest for why some tests go through HTTP and how the test
 * logs in. In short: a page's own code can be right and the page still be
 * broken on the website (a missing permission check, a form without its
 * secret token, a warning printed into a download). Only a request through
 * the web server shows that.
 *
 * Forms that change something carry a secret token (CSRF) that the page
 * prints into the form; the test reads it out of the page like a browser
 * would and sends it back.
 *
 * Needs the dev stack running: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class HttpReviewPagesTest extends ReviewDbTestCase
{
    use HttpBrowser;

    private const HUB = '/00NE/contagem.php?p=ZZL';

    /**
     * SITUATION: the prova names APTO as its classification field. Sixty
     * cars are at the event and nobody has filled APTO in: by the rule
     * "blank means not classified", no car would take part. The controller
     * opens the hub.
     *
     * EXPECTED: the hub says how many cars are classified (none of three)
     * and has the button "Marcar todos os presentes como aptos". Pressing
     * it sets APTO = SIM for every present car and says how many were
     * changed. The same request without the page's secret token changes
     * nothing (somebody else's web page must not be able to press it).
     */
    public function testTheHubMarksEveryPresentCarAsFitInOneClick(): void
    {
        $this->addInputFieldToTheProva('APTO');
        $this->changeSettings(['campo_classificacao' => 'APTO']);
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);

        $hub = $this->get(self::HUB, $cookies);
        $this->assertSame(200, $hub['status']);
        $this->assertStringContainsString('Marcar todos os presentes como aptos', $hub['body']);
        $this->assertStringContainsString('<strong>0</strong> classificado(s)', $hub['body']);
        // The question before pressing names the field, the value and how many cars.
        $this->assertStringContainsString('Grava APTO = SIM em 3 carro(s)', $hub['body']);

        $withoutToken = $this->postForm(self::HUB, $cookies, ['acao' => 'marcar_aptos']);
        $this->assertSame(403, $withoutToken['status']);
        $this->assertNull($this->publishedData(self::CAR_A), 'nothing was written');

        $pressed = $this->postForm(self::HUB, $cookies, ['acao' => 'marcar_aptos', '_csrf' => $this->csrfTokenIn($hub['body'])]);
        $this->assertSame(302, $pressed['status']);
        $this->assertStringContainsString('aptos=3', $pressed['location']);
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $car) {
            $this->assertSame('SIM', $this->publishedData($car)['APTO']);
        }

        $after = $this->get(self::HUB, $cookies);
        $this->assertStringContainsString('<strong>3</strong> classificado(s)', $after['body']);
    }

    /**
     * SITUATION: the 26SE setup: the classification field is DESCLAS,
     * which the Enduro formula reads with SIM = disqualified. The
     * controller opens the hub and the Fontes page.
     *
     * EXPECTED: both pages say loudly that the field is read by the scoring
     * formula, and the hub's "Marcar todos os presentes como aptos" is
     * disabled.
     */
    public function testTheHubAndTheFontesPageWarnAboutAClassificationFieldTheFormulaReads(): void
    {
        $this->changeSettings(['campo_classificacao' => 'DESCLAS']);
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);

        $hub = $this->get(self::HUB, $cookies);
        $this->assertStringContainsString('Campo de classificação errado: DESCLAS', $hub['body']);
        $this->assertMatchesRegularExpression('/<button type="submit"\s+disabled\s*>Marcar todos os presentes como aptos/', $hub['body']);

        $fontes = $this->get('/00NE/contagem_fontes.php?p=ZZL', $cookies);
        $this->assertStringContainsString('O campo de classificação (DESCLAS) é lido pela fórmula de pontuação', $fontes['body']);
    }

    /**
     * SITUATION: the count runs beside the spreadsheet (publish mode
     * "desligada"). A judge opens the ordinary entry page of car A to copy
     * the spreadsheet's numbers.
     *
     * WHAT WENT WRONG: on a laps prova the entry page always showed VOLTAS,
     * LAST and BEST as plain text and its save script always dropped them.
     * In "desligada" the engine does not write them either, so nobody could.
     *
     * EXPECTED, through the real pages: in "desligada" the entry page has
     * an input for VOLTAS and saving 7 stores 7. With publishing on, the
     * page shows VOLTAS as text ("Calculado pela contagem de voltas") and
     * a value sent for it anyway is ignored.
     */
    public function testWithPublishingOffTheEntryPageLetsVoltasBeTyped(): void
    {
        $this->changeSettings(['modo_publicacao' => 'desligada']);
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);
        $entryPage = '/00NE/entry.php?p=ZZL&t=' . self::CAR_A;
        $savePage = '/00NE/save_entry.php?p=ZZL&t=' . self::CAR_A;

        $page = $this->get($entryPage, $cookies);
        $this->assertSame(200, $page['status']);
        $this->assertMatchesRegularExpression('/<input[^>]+name="VOLTAS"/', $page['body']);

        $saved = $this->postForm($savePage, $cookies, ['submit' => 'Salvar', 'VOLTAS' => '7', 'LAST' => '', 'BEST' => '', 'DESCLAS' => 'NÃO']);
        $this->assertSame(302, $saved['status']);
        $this->assertEquals(7, $this->publishedData(self::CAR_A)['VOLTAS']);

        $this->changeSettings(['modo_publicacao' => 'confirmadas']);
        $page = $this->get($entryPage, $cookies);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="VOLTAS"/', $page['body']);
        $this->assertStringContainsString('Calculado pela contagem de voltas', $page['body']);

        $this->postForm($savePage, $cookies, ['submit' => 'Salvar', 'VOLTAS' => '99', 'DESCLAS' => 'SIM']);
        $this->assertNotEquals(99, $this->publishedData(self::CAR_A)['VOLTAS']);
        $this->assertSame('SIM', $this->publishedData(self::CAR_A)['DESCLAS']);
    }

    /**
     * SITUATION: the simulation is allowed on this prova for a rehearsal,
     * and car A made one pit stop. The controller opens race control and
     * downloads the stops.
     *
     * EXPECTED: while the setting is on, the controller's pages carry a
     * "SIMULAÇÃO" banner that cannot be overlooked (a rehearsal left
     * switched on would let made-up passes into the real race); with the
     * setting off there is none. The stops export arrives as a CSV file
     * with a line for car A, and a counter cannot download it.
     */
    public function testTheSimulationBannerAndTheStopsExportReachTheBrowser(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $this->grant(self::COUNTER, '00NE_ZZL_CONT');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);
        $this->startRace(self::RACE_START_MS);
        $this->newRequest();
        $this->service->withLock(function (): void {
            $this->service->recordOcorrencia(Ocorrencia::BOX_ENTRADA, $this->at(120), self::CAR_A, 'zzt_box');
            $this->service->recordOcorrencia(Ocorrencia::BOX_SAIDA, $this->at(180), self::CAR_A, 'zzt_box');
        });
        Clock::freezeAt(null);

        foreach (['/00NE/contagem_controle.php?p=ZZL', '/00NE/contagem_revisao.php?p=ZZL', '/00NE/contagem_fontes.php?p=ZZL'] as $page) {
            $this->assertDoesNotMatchRegularExpression('/class="contagem-simulacao"/', $this->get($page, $cookies)['body'], $page);
        }
        $this->changeSettings(['permitir_simulacao' => true]);
        foreach (['/00NE/contagem_controle.php?p=ZZL', '/00NE/contagem_revisao.php?p=ZZL', '/00NE/contagem_fontes.php?p=ZZL'] as $page) {
            $this->assertMatchesRegularExpression('/class="contagem-simulacao"[^>]*>\s*<strong>SIMULAÇÃO/', $this->get($page, $cookies)['body'], $page);
        }

        $export = $this->get('/00NE/contagem_exportar.php?p=ZZL&tipo=paradas', $cookies);
        $this->assertSame(200, $export['status']);
        $this->assertStringContainsString('text/csv', $export['contentType']);
        $this->assertMatchesRegularExpression('/^' . self::CAR_A . ';/m', $export['body']);
        $this->assertStringNotContainsString('Warning', $export['body']);

        $asCounter = $this->get('/00NE/contagem_exportar.php?p=ZZL&tipo=paradas', $this->loginAndGetCookies(self::COUNTER));
        $this->assertStringNotContainsString(';', $asCounter['body'], 'a counter gets no data');
    }

    /**
     * SITUATION: car A has 2 laps and is marked fit to race (APTO = SIM,
     * the prova's classification field). A request reaches the old save
     * page carrying BOTH buttons: "delete" and "submit". A browser never
     * sends that (it sends the button that was clicked), but a script or a
     * tampered form can.
     *
     * WHAT WENT WRONG: the page only took the safe path for "delete
     * without submit". With both, it fell through to the old code and
     * deleted the car's whole score row: its laps and its classification,
     * with no recount.
     *
     * EXPECTED: on a laps prova the row survives whatever buttons the
     * request carries. The lap count is still 2. The fields people type
     * are emptied (that is what "delete" means there), and because the
     * classification field is one of them the race was recounted: the car
     * no longer takes part.
     */
    public function testTheOldSavePageNeverDeletesTheRowOfALapsProva(): void
    {
        $this->addInputFieldToTheProva('APTO');
        $this->changeSettings(['campo_classificacao' => 'APTO']);
        $this->setManualField(self::CAR_A, 'APTO', 'SIM');
        $this->startRace(self::RACE_START_MS);
        foreach ([60, 160, 260] as $seconds) {
            $this->allCountersSee(self::CAR_A, $seconds);
        }
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $this->recompute();
        $this->assertEquals(2, $this->publishedData(self::CAR_A)['VOLTAS']);
        Clock::freezeAt(null);
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);

        $answer = $this->postForm(
            '/00NE/save_entry.php?p=ZZL&t=' . self::CAR_A,
            $cookies,
            ['delete' => 'Deletar Nota', 'submit' => 'Salvar', 'DESCLAS' => 'NÃO']
        );

        $this->assertSame(302, $answer['status']);
        $row = $this->publishedData(self::CAR_A);
        $this->assertNotNull($row, 'the row of a laps prova must never be deleted');
                $this->assertNull($row['APTO'], 'the typed fields were emptied');
        $this->newRequest();
        $this->assertSame([], array_keys($this->service->carRoster()->classified()));
        $this->assertEquals(0, $row['VOLTAS'], 'the race was recounted: the car left it, so its count went to zero');
    }

    /**
     * SITUATION: the count runs beside the spreadsheet on the event's
     * Enduro (prova "END", publish mode "desligada"). The spreadsheet has
     * sent 9 laps for car A, and a judge marked car A disqualified. The
     * spreadsheet then sends its "truncate" request to juiz/remote.php.
     *
     * WHAT WENT WRONG: every score row of the prova was deleted, the
     * judge's field with them.
     *
     * EXPECTED, through the real endpoint: the answer is "OK", the row is
     * still there, VOLTAS is empty and DESCLAS is still "SIM". With the
     * count publishing instead, the same request is refused (409) and
     * changes nothing.
     */
    public function testTheSpreadsheetEndpointTruncatesWithoutDeletingRows(): void
    {
        $key = (string) getenv('REMOTE_KEY');
        if ($key === '') {
            $this->markTestSkipped('REMOTE_KEY não está definido neste ambiente.');
        }
        // remote.php only ever speaks to the prova called END.
        $params = json_decode((string) $this->fetchValue(
            'SELECT params FROM prova WHERE evento_id = ? AND prova_id = ?',
            [self::EVENTO_ID, self::PROVA_ID]
        ), true);
        $params['contagem'] = ['modo_publicacao' => 'desligada'];
        $this->executeSql(
            'INSERT INTO prova (evento_id, prova_id, nome, status, params) VALUES (?, ?, ?, 0, ?)',
            [self::EVENTO_ID, 'END', 'Enduro (teste do remote)', json_encode($params)]
        );
        $this->executeSql(
            'INSERT INTO input (evento_id, prova_id, equipe_id, dados) VALUES (?, ?, ?, ?)',
            [self::EVENTO_ID, 'END', self::CAR_A, json_encode(['VOLTAS' => 9, 'LAST' => 100, 'BEST' => 50, 'DESCLAS' => 'SIM'])]
        );
        $rowOfEnd = fn(): ?array => ($json = $this->fetchValue(
            'SELECT dados FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?',
            [self::EVENTO_ID, 'END', self::CAR_A]
        )) === null ? null : (array) json_decode((string) $json, true);
        $truncate = fn(): array => $this->request(
            'POST', '/00NE/remote.php', [], (string) json_encode(['key' => $key, 'truncate' => 'all']),
            self::JUIZ_SITE, ['Content-Type: application/json']
        );

        $answer = $truncate();

        $this->assertSame(200, $answer['status']);
        $this->assertSame('OK', trim($answer['body']));
        $row = $rowOfEnd();
        $this->assertNotNull($row, 'the row must not be deleted');
        $this->assertNull($row['VOLTAS']);
        $this->assertSame('SIM', $row['DESCLAS']);

        // The count takes the prova over: from then on VOLTAS is not the spreadsheet's to empty.
        $params['contagem'] = ['modo_publicacao' => 'confirmadas'];
        $this->executeSql('UPDATE prova SET params = ? WHERE evento_id = ? AND prova_id = ?', [json_encode($params), self::EVENTO_ID, 'END']);
        $this->executeSql(
            'UPDATE input SET dados = ? WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?',
            [json_encode(['VOLTAS' => 4, 'DESCLAS' => 'SIM']), self::EVENTO_ID, 'END', self::CAR_A]
        );
        $refused = $truncate();
        $this->assertSame(409, $refused['status']);
        $this->assertEquals(4, $rowOfEnd()['VOLTAS']);
    }

    /**
     * SITUATION: the event's Enduro (prova "END") is a laps prova. The old
     * spreadsheet is still running somewhere and keeps sending its totals
     * to juiz/remote.php: "cars" (laps, best lap and last pass of each car)
     * and "raceTime" (how long the race has been running, from which the
     * endpoint sets the race clock of the results page).
     *
     * WHAT WENT WRONG: the endpoint wrote them whatever the publish mode.
     * While the lap counting published the prova, the spreadsheet's 9 laps
     * replaced the count's 4 until the car's next pass, and the race clock
     * jumped to the spreadsheet's idea of the start.
     *
     * EXPECTED, through the real endpoint:
     *   - while the count publishes ("confirmadas"): each of the two is
     *     answered 409 and changes nothing, also when both come in one
     *     request;
     *   - with publishing off ("desligada"): both work as they always did,
     *     and a field a judge typed (DESCLAS) stays in the row.
     */
    public function testTheSpreadsheetEndpointWritesTotalsAndClockOnlyWhilePublishingIsOff(): void
    {
        $key = (string) getenv('REMOTE_KEY');
        if ($key === '') {
            $this->markTestSkipped('REMOTE_KEY não está definido neste ambiente.');
        }
        $params = json_decode((string) $this->fetchValue(
            'SELECT params FROM prova WHERE evento_id = ? AND prova_id = ?',
            [self::EVENTO_ID, self::PROVA_ID]
        ), true);
        $setMode = function (string $mode) use (&$params): void {
            $params['contagem'] = ['modo_publicacao' => $mode];
            $this->executeSql('UPDATE prova SET params = ? WHERE evento_id = ? AND prova_id = ?', [json_encode($params), self::EVENTO_ID, 'END']);
        };
        // remote.php only ever speaks to the prova called END.
        $this->executeSql(
            'INSERT INTO prova (evento_id, prova_id, nome, status, params, tempo) VALUES (?, ?, ?, 0, ?, ?)',
            [self::EVENTO_ID, 'END', 'Enduro (teste do remote)', json_encode($params), 1700000000]
        );
        $this->executeSql(
            'INSERT INTO input (evento_id, prova_id, equipe_id, dados) VALUES (?, ?, ?, ?)',
            [self::EVENTO_ID, 'END', self::CAR_A, json_encode(['VOLTAS' => 4, 'LAST' => 100, 'BEST' => 50, 'DESCLAS' => 'SIM'])]
        );
        $row = fn(): array => (array) json_decode((string) $this->fetchValue(
            'SELECT dados FROM input WHERE evento_id = ? AND prova_id = ? AND equipe_id = ?',
            [self::EVENTO_ID, 'END', self::CAR_A]
        ), true);
        $clock = fn(): int => (int) $this->fetchValue('SELECT tempo FROM prova WHERE evento_id = ? AND prova_id = ?', [self::EVENTO_ID, 'END']);
        $send = fn(array $body): array => $this->request(
            'POST', '/00NE/remote.php', [], (string) json_encode(['key' => $key] + $body),
            self::JUIZ_SITE, ['Content-Type: application/json']
        );
        $cars = ['cars' => [['number' => self::CAR_A, 'laps' => 9, 'bestLapTime' => 61, 'lastPass' => 700]]];
        $raceTime = ['raceTime' => 600];

        // The count publishes: the spreadsheet is refused, whatever it sends.
        $setMode('confirmadas');
        foreach ([$cars, $raceTime, $cars + $raceTime] as $body) {
            $answer = $send($body);
            $this->assertSame(409, $answer['status'], json_encode(array_keys($body)));
            $this->assertStringContainsString('REFUSED', $answer['body']);
        }
        $this->assertEquals(4, $row()['VOLTAS'], 'the count\'s laps were not overwritten');
        $this->assertEquals(50, $row()['BEST']);
        $this->assertSame(1700000000, $clock(), 'the race clock was not moved');

        // Publishing off: the spreadsheet owns these values, as before.
        $setMode('desligada');
        $answer = $send($raceTime);
        $this->assertSame(200, $answer['status']);
        $this->assertEqualsWithDelta(time() - 600, $clock(), 5, 'the race clock follows the spreadsheet');

        $answer = $send($cars);
        $this->assertSame(200, $answer['status']);
        $this->assertSame('OK', trim($answer['body']));
        $this->assertEquals(9, $row()['VOLTAS']);
        $this->assertEquals(61, $row()['BEST']);
        $this->assertEquals(700, $row()['LAST']);
        $this->assertSame('SIM', $row()['DESCLAS'], 'what the judge typed is still there');
    }

    /**
     * SITUATION: APTO is the classification field. Car A is blank, car B
     * was marked NÃO by the inspection, car C is SIM. The controller opens
     * the hub and presses "Marcar todos os presentes como aptos".
     *
     * WHAT WENT WRONG: the hub listed A and B together as "sem
     * classificação", and the button's question counted both. Nobody could
     * see that B had been refused on purpose.
     *
     * EXPECTED: the hub lists the car marked NÃO apart from the blank one.
     * The button fills only the blank one, and the page then names the car
     * it left alone.
     */
    public function testTheHubListsCarsMarkedNoApartAndLeavesThemAlone(): void
    {
        $this->addInputFieldToTheProva('APTO');
        $this->changeSettings(['campo_classificacao' => 'APTO']);
        $this->setManualField(self::CAR_B, 'APTO', 'NÃO');
        $this->setManualField(self::CAR_C, 'APTO', 'SIM');
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);

        $hub = $this->get(self::HUB, $cookies);
        $this->assertMatchesRegularExpression('/<strong>1<\/strong> presente\(s\) com o campo em branco \(' . self::CAR_A . '\)/', $hub['body']);
        $this->assertMatchesRegularExpression('/marcado\(s\) como NÃO apto\(s\)[^<]*:\s*<strong>' . self::CAR_B . '<\/strong>/', $hub['body']);

        $pressed = $this->postForm(self::HUB, $cookies, ['acao' => 'marcar_aptos', '_csrf' => $this->csrfTokenIn($hub['body'])]);
        $this->assertSame(302, $pressed['status']);
        $this->assertStringContainsString('aptos=1', $pressed['location']);
        $this->assertSame('SIM', $this->publishedData(self::CAR_A)['APTO']);
        $this->assertSame('NÃO', $this->publishedData(self::CAR_B)['APTO'], 'a car marked NÃO is left alone');

        $after = $this->get('/00NE/' . ltrim($pressed['location'], '/'), $cookies);
        $this->assertMatchesRegularExpression('/Não alterado\(s\)[^<]*: ' . self::CAR_B . '\./', $after['body']);
    }

    /**
     * SITUATION: every present car is already APTO = SIM. The controller
     * opens the hub; the prova does not log the fueling station.
     *
     * EXPECTED: the question behind the (disabled) button has no empty
     * "()"; the hub and the users page name the pit log as every other
     * page does, "Registro de box", not "Box e abastecimento".
     */
    public function testTheHubQuestionAndTheUsersPageReadRightWithNothingBlankAndNoFueling(): void
    {
        $this->addInputFieldToTheProva('APTO');
        $this->changeSettings(['campo_classificacao' => 'APTO', 'abastecimento' => false]);
        foreach ([self::CAR_A, self::CAR_B, self::CAR_C] as $car) {
            $this->setManualField($car, 'APTO', 'SIM');
        }
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);

        $hub = $this->get(self::HUB, $cookies)['body'];
        $this->assertStringContainsString('em 0 carro(s) presente(s) com o campo em branco?', html_entity_decode($hub, ENT_QUOTES));
        $this->assertStringContainsString('quem faz o registro de box</span>', $hub);

        $users = $this->get('/00NE/contagem_contadores.php?p=ZZL', $cookies)['body'];
        $this->assertStringContainsString('<h2>Registro de box</h2>', $users);
        $this->assertStringNotContainsString('Box e abastecimento', $users);
    }

    /**
     * SITUATION: the test preset was applied for a rehearsal and is still
     * in place. The controller opens the pages of the lap counting.
     *
     * EXPECTED: every controller page carries a banner that says so (the
     * time settings are a rehearsal's) for as long as the preset is
     * applied, and none without it. The race control page sends no
     * confirmation along with "Largar agora": whatever must be asked is
     * asked by the server, which then shows a button to confirm.
     */
    public function testThePresetBannerIsOnEveryControllerPageAndLargarAgoraSendsNoConfirmationAhead(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $cookies = $this->loginAndGetCookies(self::CONTROLLER);
        $pages = ['/00NE/contagem.php?p=ZZL', '/00NE/contagem_controle.php?p=ZZL', '/00NE/contagem_fontes.php?p=ZZL', '/00NE/contagem_contadores.php?p=ZZL'];
        foreach ($pages as $page) {
            $this->assertStringNotContainsString('PREDEFINIÇÃO DE TESTE APLICADA', $this->get($page, $cookies)['body'], $page);
        }
        $this->assertMatchesRegularExpression('/id="faixa-predefinicao"/', $this->get('/00NE/contagem_revisao.php?p=ZZL', $cookies)['body']);
        $this->assertMatchesRegularExpression('/class="[^"]*oculto[^"]*" id="faixa-predefinicao"/', $this->get('/00NE/contagem_revisao.php?p=ZZL', $cookies)['body']);

        $this->newRequest();
        $this->service->applyTestPreset(60);
        Clock::freezeAt(null);

        foreach ($pages as $page) {
            $this->assertStringContainsString('PREDEFINIÇÃO DE TESTE APLICADA', $this->get($page, $cookies)['body'], $page);
        }
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*oculto[^"]*" id="faixa-predefinicao"/', $this->get('/00NE/contagem_revisao.php?p=ZZL', $cookies)['body']);

        // "Largar agora": the form carries the action and the token, nothing that says "yes" ahead.
        $control = $this->get('/00NE/contagem_controle.php?p=ZZL', $cookies);
        $this->assertSame(1, preg_match('/<form[^>]*>(?:(?!<\/form>).)*value="largar_agora"(?:(?!<\/form>).)*<\/form>/s', $control['body'], $form));
        $this->assertStringNotContainsString('name="confirmar', $form[0]);
        $this->assertStringNotContainsString('onsubmit', $form[0]);

        // Pressing it: nothing is stored, and the page asks, with a button that carries the instant of the click.
        $pressed = $this->postForm('/00NE/contagem_controle.php?p=ZZL', $cookies, ['acao' => 'largar_agora', '_csrf' => $this->csrfTokenIn($control['body'])]);
        $this->assertSame(200, $pressed['status']);
        $this->assertStringContainsString('Nada foi alterado ainda', $pressed['body']);
        $this->assertStringContainsString('name="confirmar_predefinicao" value="1"', $pressed['body']);
        $this->assertMatchesRegularExpression('/name="instante_ms" value="\d{13}"/', $pressed['body']);
        $this->assertSame(0, $this->countRows('contagem_ocorrencia', 'evento_id = ? AND prova_id = ?', [self::EVENTO_ID, self::PROVA_ID]));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Sends a form the way a browser does.
     *
     * @param array<string, string> $cookies
     * @param array<string, string> $fields
     * @return array{status: int, body: string, location: string, contentType: string, cookies: array<string, string>}
     */
    private function postForm(string $path, array $cookies, array $fields): array
    {
        return $this->request('POST', $path, $cookies, http_build_query($fields), self::JUIZ_SITE, ['Content-Type: application/x-www-form-urlencoded']);
    }

    /** The secret token a page printed into its forms. */
    private function csrfTokenIn(string $html): string
    {
        $this->assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $found), 'the page has no form token');
        return $found[1];
    }

    /** Adds an enum SIM/NÃO field to the test prova, as an organiser would in its parameters. */
    private function addInputFieldToTheProva(string $code): void
    {
        $params = json_decode((string) $this->fetchValue(
            'SELECT params FROM prova WHERE evento_id = ? AND prova_id = ?',
            [self::EVENTO_ID, self::PROVA_ID]
        ), true);
        $params['inputs'][] = ['code' => $code, 'name' => 'Apto para o enduro', 'type' => 'enum', 'options' => ['SIM', 'NÃO']];
        $this->executeSql(
            'UPDATE prova SET params = ? WHERE evento_id = ? AND prova_id = ?',
            [json_encode($params), self::EVENTO_ID, self::PROVA_ID]
        );
        $this->newRequest();
    }
}
