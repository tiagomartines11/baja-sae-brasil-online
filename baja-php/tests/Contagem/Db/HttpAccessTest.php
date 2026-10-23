<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

/**
 * Who is let into the controller's pages, tested through the REAL web
 * server.
 *
 * WHY THIS TEST GOES THROUGH HTTP
 *
 * The other tests call the application's classes directly. That is fast,
 * but it skips everything that happens before a page's own code runs: the
 * web server choosing the event from the address (/00NE/...), the bootstrap
 * file, the forum login being read from the cookies, the permission check
 * at the top of the page. A page can be perfect in a unit test and still
 * be open to everybody (or closed to everybody) on the website.
 *
 * So this test behaves like a browser. It asks the web server ("nginx",
 * reachable from inside the test container) for a page and looks at what
 * comes back.
 *
 * HOW IT LOGS IN
 *
 * "Being logged in" on this site means: the browser holds the forum's
 * cookies, and the forum's database has a session row that matches them.
 * The test obtains real cookies the way a person does, by posting a
 * username and password to the forum's login address, together with the
 * two things the forum demands as proof that the post came from one of our
 * own login forms: an Origin header and a CSRF token sent both as a cookie
 * and as a form field (the details are in HttpBrowser::loginAndGetCookies).
 * It uses two accounts that every development database has (password
 * 123456):
 *
 *   juiz1     plays the controller of the test prova
 *   equipe1   plays a counter
 *
 * What an account may DO is decided by its row in this application's
 * `user` table. The test adds the permission it needs to that row before
 * the request and takes it away again afterwards (also when an assertion
 * fails), leaving the account's other permissions untouched.
 *
 * The test prova is "ZZL" of the made-up event "00NE" (see DbTestCase), so
 * the permission codes are "00NE_ZZL" (controller) and "00NE_ZZL_CONT"
 * (counter).
 *
 * Needs the dev stack running: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class HttpAccessTest extends ReviewDbTestCase
{
    use HttpBrowser;

    private const COUNTERS_PAGE = '/00NE/contagem_contadores.php?p=ZZL';
    /** Text that only the fully drawn "Contadores" page contains. */
    private const COUNTERS_PAGE_MARKER = 'Adicionar usuário';
    /** What every page of the site says to somebody who may not see it. */
    private const REFUSAL = 'Você não tem acesso a essa página';

    /**
     * SITUATION: on race morning the person in charge of the prova (a
     * judge, NOT a system administrator) has to link the volunteers to the
     * counters. That is done on the "Contadores" page.
     *
     * WHAT WENT WRONG: nothing yet; this is the guard. The page used to
     * live in the administrators' area, and "works for me" from an
     * administrator proves nothing about the judge who will be alone with
     * it on race day.
     *
     * EXPECTED: the page is served, complete with its forms and the three
     * counters of the test prova, to an account that holds the prova's
     * permission and does not hold "admin".
     */
    public function testTheContadoresPageIsServedToTheControllerWhoIsNotAnAdministrator(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $this->assertNotContains('admin', $this->permissionsOf(self::CONTROLLER), 'the account must not be an administrator, or the test proves nothing');

        $response = $this->get(self::COUNTERS_PAGE, $this->loginAndGetCookies(self::CONTROLLER));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString(self::COUNTERS_PAGE_MARKER, $response['body']);
        $this->assertStringContainsString('Contagem 1', $response['body']);
        $this->assertStringContainsString('Contagem 3', $response['body']);
        $this->assertStringNotContainsString(self::REFUSAL, $response['body']);
    }

    /**
     * SITUATION: a volunteer counter, logged in, types the address of the
     * "Contadores" page by hand.
     *
     * EXPECTED: refused. A counter who could link people to sources could
     * link themselves to a second source, and then two of the three
     * "independent" counts would be the same person.
     */
    public function testTheContadoresPageIsRefusedToACounter(): void
    {
        $this->grant(self::COUNTER, '00NE_ZZL_CONT');

        $response = $this->get(self::COUNTERS_PAGE, $this->loginAndGetCookies(self::COUNTER));

        $this->assertStringContainsString(self::REFUSAL, $response['body']);
        $this->assertStringNotContainsString(self::COUNTERS_PAGE_MARKER, $response['body']);
    }

    /**
     * SITUATION: somebody who is not logged in at all opens the address.
     *
     * EXPECTED: they are sent to the login page (HTTP 302 with a
     * "Location: login.php" header) and receive nothing of the page.
     */
    public function testTheContadoresPageSendsALoggedOutVisitorToTheLogin(): void
    {
        $response = $this->get(self::COUNTERS_PAGE, []);

        $this->assertSame(302, $response['status']);
        $this->assertStringContainsString('login.php', $response['location']);
        $this->assertStringNotContainsString(self::COUNTERS_PAGE_MARKER, $response['body']);
    }

    /**
     * SITUATION: a volunteer opens the prova from the start page: first
     * holding only the pit code, then also linked to Contagem 1 as a
     * counter. (EntryChooserTest covers every combination in-process; this
     * checks the page the web server actually serves.)
     *
     * WHAT WENT WRONG AT 26SE: the pit volunteer was refused here, and a
     * counter with one source was redirected to it and never saw the pit
     * page.
     *
     * EXPECTED: both times a page, never a redirect: the start page lists
     * the prova, and contagem.php shows a chooser with the pit log, and
     * then with the source too.
     */
    public function testTheEntryPageShowsTheChooserToThePitLogAndToACounterWithOneSource(): void
    {
        $this->grant(self::COUNTER, '00NE_ZZL_BOX');
        $cookies = $this->loginAndGetCookies(self::COUNTER);

        $start = $this->get('/00NE/index.php', $cookies);
        $this->assertStringContainsString('contagem.php?p=ZZL', $start['body']);

        $pitOnly = $this->get('/00NE/contagem.php?p=ZZL', $cookies);
        $this->assertSame(200, $pitOnly['status']);
        $this->assertStringNotContainsString(self::REFUSAL, $pitOnly['body']);
        $this->assertStringContainsString('href="contagem_box.php?p=ZZL"', $pitOnly['body']);
        $this->assertStringNotContainsString('contagem_contar.php', $pitOnly['body']);

        $this->grant(self::COUNTER, '00NE_ZZL_CONT');
        $this->executeSql(
            'INSERT INTO contagem_fonte_usuario (fonte_id, usuario, adicionado_por, criado_em_ms) VALUES (?, ?, ?, ?)',
            [$this->fontes[0]->getFonteId(), self::COUNTER, self::CONTROLLER, $this->at(0)]
        );
        $both = $this->get('/00NE/contagem.php?p=ZZL', $cookies);
        $this->assertSame(200, $both['status'], 'one source is no longer a redirect');
        $this->assertSame('', $both['location']);
        $this->assertStringContainsString('contagem_contar.php?p=ZZL&amp;f=' . (int) $this->fontes[0]->getFonteId(), $both['body']);
        $this->assertStringContainsString('href="contagem_box.php?p=ZZL"', $both['body']);
    }

    /**
     * SITUATION: the review screen talks to the server through small
     * addresses that answer in JSON (a format programs read). The screen
     * decides what to tell the reviewer from what kind of answer it gets.
     *
     * EXPECTED, through the real web server:
     *   - the controller gets the state of the race as JSON, with a
     *     "versao" that is a text token;
     *   - asking again with that token gets the short "inalterado" answer;
     *   - a counter is refused IN JSON with status 403 (not with an HTML
     *     page the screen could not read);
     *   - a decision sent without the page's secret token (CSRF) is refused
     *     with 403 and stores nothing.
     */
    public function testTheJsonAddressesOfTheReviewScreenAnswerInJsonAndCheckWhoAsks(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $this->grant(self::COUNTER, '00NE_ZZL_CONT');
        $controllerCookies = $this->loginAndGetCookies(self::CONTROLLER);

        $state = $this->get('/00NE/contagem_estado.php?p=ZZL', $controllerCookies);
        $this->assertSame(200, $state['status']);
        $this->assertStringContainsString('application/json', $state['contentType']);
        $decoded = json_decode($state['body'], true);
        $this->assertIsArray($decoded, 'the body must be valid JSON, with nothing printed around it');
        $this->assertIsString($decoded['versao']);
        $this->assertArrayHasKey('carros', $decoded);

        $again = $this->get('/00NE/contagem_estado.php?p=ZZL&versao=' . rawurlencode($decoded['versao']), $controllerCookies);
        $this->assertTrue(json_decode($again['body'], true)['inalterado']);

        $asCounter = $this->get('/00NE/contagem_estado.php?p=ZZL', $this->loginAndGetCookies(self::COUNTER));
        $this->assertSame(403, $asCounter['status']);
        $this->assertStringContainsString('application/json', $asCounter['contentType']);

        $withoutToken = $this->request('POST', '/00NE/contagem_decidir.php?p=ZZL', $controllerCookies, '{"decisao":{"tipo":"adicionar"}}');
        $this->assertSame(403, $withoutToken['status']);
        $this->assertSame('csrf', json_decode($withoutToken['body'], true)['erro']);
        $this->assertSame(0, $this->countDecisions());
    }
}
