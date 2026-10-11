<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Model\UserQuery;

/**
 * What a test needs to behave like a browser against the REAL web server:
 * logging in at the forum, sending requests with the cookies it got, and
 * lending a seeded account a permission for the length of one test.
 *
 * Shared by the tests named Http*. See HttpAccessTest for why some tests go
 * through HTTP at all and how the login works.
 */
trait HttpBrowser
{
    /** The web server as the test container reaches it. */
    protected const WEB_SERVER = 'http://nginx';
    /** The site names; the web server picks the site from this "Host" header. */
    protected const JUIZ_SITE = 'juiz.baja.local';
    protected const FORUM_SITE = 'forum.baja.local';

    protected const CONTROLLER = 'juiz1';
    protected const COUNTER = 'equipe1';
    protected const SEEDED_PASSWORD = '123456';

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
