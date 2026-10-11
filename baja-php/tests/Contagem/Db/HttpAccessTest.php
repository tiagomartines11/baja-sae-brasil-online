<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Model\UserQuery;

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
 * username and password to the forum's login address. It uses two accounts
 * that every development database has (password 123456):
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
    /** The web server as the test container reaches it. */
    private const WEB_SERVER = 'http://nginx';
    /** The site names; the web server picks the site from this "Host" header. */
    private const JUIZ_SITE = 'juiz.baja.local';
    private const FORUM_SITE = 'forum.baja.local';

    private const CONTROLLER = 'juiz1';
    private const COUNTER = 'equipe1';
    private const SEEDED_PASSWORD = '123456';

    private const COUNTERS_PAGE = '/00NE/contagem_contadores.php?p=ZZL';
    /** Text that only the fully drawn "Contadores" page contains. */
    private const COUNTERS_PAGE_MARKER = 'Adicionar usuário';
    /** What every page of the site says to somebody who may not see it. */
    private const REFUSAL = 'Você não tem acesso a essa página';

    /** @var array<string, string[]> username => permission codes this test added and must remove */
    private array $grantedByThisTest = [];

    protected function tearDown(): void
    {
        foreach ($this->grantedByThisTest as $username => $codes) {
            $this->changePermissions($username, [], $codes);
        }
        $this->grantedByThisTest = [];
        parent::tearDown();
    }

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

    // ------------------------------------------------------------------
    // Playing the browser
    // ------------------------------------------------------------------

    /**
     * Logs in at the forum, as a person does, and returns the cookies the
     * forum hands out.
     *
     * @return array<string, string> cookie name => value
     */
    private function loginAndGetCookies(string $username): array
    {
        $response = $this->request(
            'POST',
            '/app.php/baja/login',
            [],
            http_build_query([
                'username' => $username,
                'password' => self::SEEDED_PASSWORD,
                'redirect' => 'http://' . self::JUIZ_SITE . '/index.php',
            ]),
            self::FORUM_SITE,
            ['Content-Type: application/x-www-form-urlencoded']
        );

        $cookies = $response['cookies'];
        $userCookie = '';
        foreach ($cookies as $name => $value) {
            // The forum's cookies are named <prefix>_sid (the session) and
            // <prefix>_u (the user id; 1 means "anonymous").
            if (str_ends_with($name, '_u')) {
                $userCookie = $value;
            }
        }
        if ($userCookie === '' || $userCookie === '1') {
            $this->markTestSkipped(
                'Não foi possível entrar no fórum como "' . $username . '" (senha de desenvolvimento). '
                . 'Este teste precisa do stack de desenvolvimento com os usuários semeados.'
            );
        }
        return $cookies;
    }

    /**
     * @param array<string, string> $cookies
     * @return array{status: int, body: string, location: string, contentType: string, cookies: array<string, string>}
     */
    private function get(string $path, array $cookies): array
    {
        return $this->request('GET', $path, $cookies);
    }

    /**
     * One HTTP request to the web server. Redirects are NOT followed: where
     * the server wants to send the browser is part of what is being tested.
     *
     * @param array<string, string> $cookies
     * @param string[] $extraHeaders
     * @return array{status: int, body: string, location: string, contentType: string, cookies: array<string, string>}
     */
    private function request(
        string $method,
        string $path,
        array $cookies,
        ?string $body = null,
        string $site = self::JUIZ_SITE,
        array $extraHeaders = []
    ): array {
        $cookiePairs = [];
        foreach ($cookies as $name => $value) {
            $cookiePairs[] = $name . '=' . $value;
        }
        $headers = array_merge(['Host: ' . $site], $extraHeaders);
        if ($cookiePairs !== []) {
            $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
        }

        $received = ['location' => '', 'contentType' => '', 'cookies' => []];
        $curl = curl_init(self::WEB_SERVER . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            // Called once per header line of the answer.
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$received): int {
                if (stripos($line, 'Location:') === 0) {
                    $received['location'] = trim(substr($line, 9));
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $received['contentType'] = trim(substr($line, 13));
                } elseif (stripos($line, 'Set-Cookie:') === 0) {
                    // "name=value; expires=...": only the first part matters.
                    // A cookie set twice in one answer keeps its last value,
                    // as in a browser.
                    $pair = explode(';', trim(substr($line, 11)), 2)[0];
                    [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                    $received['cookies'][trim($name)] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);
            $this->markTestSkipped('O servidor web (' . self::WEB_SERVER . ') não respondeu: ' . $error);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => $status,
            'body' => (string) $responseBody,
            'location' => $received['location'],
            'contentType' => $received['contentType'],
            'cookies' => $received['cookies'],
        ];
    }

    // ------------------------------------------------------------------
    // Permissions of the seeded accounts
    // ------------------------------------------------------------------

    /** Gives a seeded account one more permission until the end of the test. */
    private function grant(string $username, string $code): void
    {
        if (in_array($code, $this->permissionsOf($username), true)) {
            return;
        }
        $this->grantedByThisTest[$username][] = $code;
        $this->changePermissions($username, [$code], []);
    }

    /**
     * @param string[] $add
     * @param string[] $remove
     */
    private function changePermissions(string $username, array $add, array $remove): void
    {
        TestWorld::forgetLoadedRows();
        $user = UserQuery::create()->findOneByUsername($username);
        if ($user === null) {
            return;
        }
        // Only these codes are touched; whatever else the account holds stays.
        $permissions = array_diff(array_merge((array) $user->getPermissions(), $add), $remove);
        $user->setPermissions(array_values(array_unique($permissions)));
        $user->save();
    }

    /** @return string[] */
    private function permissionsOf(string $username): array
    {
        TestWorld::forgetLoadedRows();
        $user = UserQuery::create()->findOneByUsername($username);
        if ($user === null) {
            $this->markTestSkipped(
                'A conta "' . $username . '" não existe na tabela user. Este teste precisa do banco de desenvolvimento semeado.'
            );
        }
        return array_values((array) $user->getPermissions());
    }
}
