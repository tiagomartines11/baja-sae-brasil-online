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
