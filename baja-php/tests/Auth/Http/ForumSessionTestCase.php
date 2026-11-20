<?php
declare(strict_types=1);

namespace Baja\Tests\Auth\Http;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base of the tests that follow a forum login through the REAL web server
 * (BAJAAPP-86).
 *
 * WHAT THESE TESTS ARE ABOUT
 *
 * Logging in, here, happens on the forum (phpBB), which hands the browser
 * three cookies, for every site under the parent domain:
 *
 *   phpbb3_baja_sid  the session: a row in phpbb_sessions. phpBB deletes it
 *                    after an hour without activity (session_length).
 *   phpbb3_baja_u    the user id; 1 means anonymous (logged out).
 *   phpbb3_baja_k    the "remember me" key: a row in phpbb_sessions_keys.
 *                    Only when the user ticked "remember me".
 *
 * Once the session row is gone, the forum's next page turns a valid _k into a
 * brand-new session: the user stays logged in. The judge area used to read
 * only _sid, and showed its login form instead. These tests open real pages
 * with a browser-like cookie jar (Browser) and check that the judge area now
 * gets the session back through the forum, refuses to when it should, and
 * keeps the forum session alive.
 *
 * HOW TO RUN
 *
 * Inside the dev app container, with the dev stack up:
 *
 *     docker compose exec -e BAJA_TEST_DB=1 -e BAJA_TEST_DB_USER=root \
 *         -e BAJA_TEST_DB_PASS=<MYSQL_ROOT_PASSWORD> baja-app \
 *         vendor/bin/phpunit --testsuite auth-http --fail-on-skipped
 *
 * BAJA_TEST_DB_USER is needed because "an hour has passed" has to be made
 * true by hand: these tests delete or age a session row, the way phpBB's own
 * clean-up would. The app's MySQL user may only read the forum's tables (and
 * one of the tests checks that it still may only read), so the test uses an
 * account that may write. Without these variables every test is skipped.
 * Never point them at production.
 *
 * Every test logs in as the seeded "juiz1" (password 123456) and removes the
 * sessions and keys it created at the end.
 */
abstract class ForumSessionTestCase extends TestCase
{
    protected const JUIZ = 'http://juiz.baja.local';
    protected const FORUM = 'http://forum.baja.local';
    /** The forum's default redirect target in dev (BAJA_AUTH_DEFAULT_REDIRECT). */
    protected const DEFAULT_REDIRECT = 'http://baja.local/';
    protected const USERNAME = 'juiz1';
    protected const PASSWORD = '123456';
    /** What only a logged-in judge's start page shows. */
    protected const LOGGED_IN_MARKER = 'login.php?act=logout';
    /** What only the judge area's login form shows. */
    protected const LOGIN_FORM_MARKER = 'name="password"';

    protected Browser $browser;
    protected string $prefix;
    private ?PDO $forumDb = null;
    private ?\Redis $redis = null;

    protected function setUp(): void
    {
        if (getenv('BAJA_TEST_DB') !== '1') {
            $this->markTestSkipped('Precisa do stack de desenvolvimento: rode com BAJA_TEST_DB=1 (veja ForumSessionTestCase).');
        }
        if (!getenv('BAJA_TEST_DB_USER')) {
            $this->markTestSkipped('Precisa de BAJA_TEST_DB_USER / BAJA_TEST_DB_PASS, uma conta MySQL que escreva no phpbb_baja (veja ForumSessionTestCase).');
        }
        $this->prefix = (string)getenv('PHPBB_COOKIE_PREFIX');
        $this->browser = new Browser();
    }

    protected function tearDown(): void
    {
        if (!isset($this->browser)) {
            return; // skipped in setUp
        }
        // Every session and key juiz1 has. In a dev database those are this
        // suite's and the other HTTP tests' (which log in afresh each time);
        // a developer logged in as juiz1 in a browser is logged out too.
        $db = $this->forumDb();
        $userId = $this->userId();
        $db->prepare('DELETE FROM phpbb_sessions_keys WHERE user_id = ?')->execute([$userId]);
        foreach ($db->query('SELECT session_id FROM phpbb_sessions WHERE session_user_id = ' . $userId)->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $this->forgetCached((string)$sid);
        }
        $db->prepare('DELETE FROM phpbb_sessions WHERE session_user_id = ?')->execute([$userId]);
    }

    // ------------------------------------------------------------------
    // Logging in
    // ------------------------------------------------------------------

    /**
     * Logs in the way a judge does: opens the judge area's login form (which
     * plants the CSRF cookie and puts the same token in the form) and posts
     * it to the forum, with the Origin header a browser adds.
     *
     * $rememberMe adds autologin=1, what the forum's own login form sends when
     * "remember me" is ticked. The judge area's form has no such box, so a
     * judge only has a _k when they logged in on the forum itself; the forum's
     * /baja/login handles both the same way, through $auth->login().
     */
    protected function logIn(bool $rememberMe): void
    {
        $form = $this->browser->request('GET', self::JUIZ . '/login.php');
        $this->assertSame(200, $form['status'], 'the login form did not render');
        $this->assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form['body'], $m), 'no CSRF token in the login form');

        $fields = ['username' => self::USERNAME, 'password' => self::PASSWORD, 'csrf' => html_entity_decode($m[1])];
        if ($rememberMe) {
            $fields['autologin'] = '1';
        }
        $response = $this->browser->request(
            'POST',
            self::FORUM . '/app.php/baja/login?redirect=' . rawurlencode(self::JUIZ . '/index.php'),
            http_build_query($fields),
            ['Content-Type: application/x-www-form-urlencoded', 'Origin: ' . self::JUIZ]
        );
        $this->assertStringNotContainsString('error=', $response['location'], 'the forum refused the login');
        $this->assertNotSame('', (string)$this->sid(), 'no session cookie after logging in');
        $this->assertNotSame('1', $this->cookie('_u'), 'still anonymous after logging in');
        if ($rememberMe) {
            $this->assertNotSame('', (string)$this->cookie('_k'), 'no "remember me" key after logging in with it');
        }
    }

    // ------------------------------------------------------------------
    // Making time pass, and looking at the forum's tables
    // ------------------------------------------------------------------

    /**
     * The session row is gone, as phpBB's clean-up (session_gc) does an hour
     * after the last activity. The browser keeps its cookies, as it does.
     * The judge area's 10-minute cache of the row goes too, which in real
     * life would have run out long before.
     */
    protected function expireSession(): void
    {
        $sid = (string)$this->sid();
        $this->forumDb()->prepare('DELETE FROM phpbb_sessions WHERE session_id = ?')->execute([$sid]);
        $this->forgetCached($sid);
    }

    /** Ages the session row by $seconds, as if nobody had used it since. */
    protected function ageSession(int $seconds): void
    {
        $sid = (string)$this->sid();
        $this->forumDb()->prepare('UPDATE phpbb_sessions SET session_time = UNIX_TIMESTAMP() - ? WHERE session_id = ?')
            ->execute([$seconds, $sid]);
        $this->forgetCached($sid);
    }

    /** @return array{session_time: int, session_page: string}|null */
    protected function sessionRow(string $sid): ?array
    {
        $statement = $this->forumDb()->prepare('SELECT session_time, session_page FROM phpbb_sessions WHERE session_id = ?');
        $statement->execute([$sid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['session_time' => (int)$row['session_time'], 'session_page' => (string)$row['session_page']];
    }

    /** Whether phpBB still has this "remember me" key (it stores md5 of it). */
    protected function keyExists(string $key): bool
    {
        $statement = $this->forumDb()->prepare('SELECT COUNT(*) FROM phpbb_sessions_keys WHERE key_id = ?');
        $statement->execute([md5($key)]);
        return (int)$statement->fetchColumn() > 0;
    }

    /** Deletes the key, as "log out everywhere" or its expiry would. */
    protected function revokeKey(string $key): void
    {
        $this->forumDb()->prepare('DELETE FROM phpbb_sessions_keys WHERE key_id = ?')->execute([md5($key)]);
    }

    protected function forumDb(): PDO
    {
        if ($this->forumDb === null) {
            $this->forumDb = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('PHPBB_DB_HOST'), getenv('PHPBB_DB_PORT'), getenv('PHPBB_DB_NAME')),
                (string)getenv('BAJA_TEST_DB_USER'),
                (string)getenv('BAJA_TEST_DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return $this->forumDb;
    }

    // ------------------------------------------------------------------
    // The browser's cookies
    // ------------------------------------------------------------------

    /** One of the forum's cookies as the judge area receives it; null if none. */
    protected function cookie(string $suffix): ?string
    {
        return $this->browser->cookie($this->prefix . $suffix, 'juiz.baja.local');
    }

    protected function sid(): ?string
    {
        return $this->cookie('_sid');
    }

    // ------------------------------------------------------------------
    // Reading the stops of a trip
    // ------------------------------------------------------------------

    /**
     * @param list<array{url: string, status: int, location: string, body: string, setCookies: array<string, string>}> $stops
     * @return string[] the address of every stop
     */
    protected function urls(array $stops): array
    {
        return array_column($stops, 'url');
    }

    /** @param list<array{url: string}> $stops */
    protected function forumStops(array $stops): int
    {
        return count(array_filter($stops, fn(array $stop): bool => str_starts_with($stop['url'], self::FORUM)));
    }

    /** @param list<array{url: string, status: int, body: string}> $stops */
    protected function assertEndsOnTheLoginForm(array $stops): void
    {
        $last = end($stops);
        $this->assertSame(self::JUIZ . '/login.php', $last['url'], 'ended at ' . $last['url'] . "\nstops: " . implode(' -> ', $this->urls($stops)));
        $this->assertSame(200, $last['status']);
        $this->assertStringContainsString(self::LOGIN_FORM_MARKER, $last['body']);
    }

    private function userId(): int
    {
        $statement = $this->forumDb()->prepare('SELECT user_id FROM phpbb_users WHERE username_clean = ?');
        $statement->execute([self::USERNAME]);
        return (int)$statement->fetchColumn();
    }

    /** As if SESSION_CACHE_TTL_SECONDS had passed since the judge area cached this session. */
    protected function forgetCachedSession(string $sid): void
    {
        $this->forgetCached($sid);
    }

    private function forgetCached(string $sid): void
    {
        if ($this->redis === null) {
            $this->redis = new \Redis();
            $this->redis->connect((string)getenv('REDIS_HOST'), (int)getenv('REDIS_PORT'));
        }
        $this->redis->del('baja:phpbb:session:' . $sid);
    }
}
