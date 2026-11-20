<?php
declare(strict_types=1);

namespace Baja\Tests\Auth\Http;

use Baja\Auth\PhpbbSessionShim;

/**
 * Activity in the judge area keeps the forum session alive (BAJAAPP-86).
 *
 * phpBB deletes a session an hour after its row's session_time, which only a
 * forum page updates. A judge who spends the whole day in the judge area
 * never opens one, so the forum logged them out mid-event (and, without
 * "remember me", the judge area with it). The judge area's MySQL user may
 * only read the forum's tables, so it cannot update session_time itself.
 *
 * Instead, a judge page whose session row is more than a few minutes old
 * (PhpbbSessionShim::KEEPALIVE_AFTER_SECONDS) carries a small script that
 * requests the forum's /app.php/baja/keepalive, and phpBB, answering it,
 * writes the new session_time. This test makes that request the way the
 * script would: with the browser's cookies, to the address in the page.
 */
final class KeepAliveTest extends ForumSessionTestCase
{
    private const KEEPALIVE = self::FORUM . '/app.php/baja/keepalive';

    public function testAJudgePageRefreshesAnAgingSession(): void
    {
        $this->logIn(rememberMe: false);
        $sid = (string)$this->sid();
        $this->ageSession(PhpbbSessionShim::KEEPALIVE_AFTER_SECONDS + 300);
        $aged = $this->sessionRow($sid);

        // The judge opens a page; it is served as usual, and asks for the
        // keep-alive.
        $page = $this->browser->request('GET', self::JUIZ . '/index.php');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString(self::LOGGED_IN_MARKER, $page['body']);
        $this->assertStringContainsString(json_encode(self::KEEPALIVE, JSON_UNESCAPED_SLASHES), $page['body'], 'the page asks for the keep-alive');

        // The browser's request, as the script makes it.
        $response = $this->browser->request('GET', self::KEEPALIVE);
        $this->assertSame(204, $response['status']);
        $this->assertSame([], $response['setCookies'], 'the same session: no new cookies');

        $refreshed = $this->sessionRow($sid);
        $this->assertNotNull($refreshed, 'the same session row');
        $this->assertGreaterThanOrEqual(time() - 5, $refreshed['session_time'], 'phpBB wrote the current time');
        $this->assertSame($aged['session_page'], $refreshed['session_page'], '"Who is online" still shows the last forum page');

        // The next page reads the new time, and does not ask again.
        $next = $this->browser->request('GET', self::JUIZ . '/index.php');
        $this->assertStringNotContainsString('/app.php/baja/keepalive', $next['body']);
    }

    /** A fresh session: no request to the forum on every page. */
    public function testAFreshSessionIsLeftAlone(): void
    {
        $this->logIn(rememberMe: false);

        $page = $this->browser->request('GET', self::JUIZ . '/index.php');
        $this->assertStringContainsString(self::LOGGED_IN_MARKER, $page['body']);
        $this->assertStringNotContainsString('/app.php/baja/keepalive', $page['body']);
    }

    /** Nobody logged in: nothing to keep alive. */
    public function testTheLoginFormDoesNotAskForIt(): void
    {
        $page = $this->browser->request('GET', self::JUIZ . '/login.php');
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('/app.php/baja/keepalive', $page['body']);
    }

    /**
     * Why phpBB has to do the writing: the judge area's MySQL user may read
     * the forum's tables and nothing more, and must stay that way. If this
     * fails, somebody granted it more; take it back rather than change the
     * test.
     */
    public function testTheAppsDatabaseUserMayOnlyReadTheForumsTables(): void
    {
        $db = new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('PHPBB_DB_HOST'), getenv('PHPBB_DB_PORT'), getenv('PHPBB_DB_NAME')),
            (string)getenv('PHPBB_DB_USER'),
            (string)getenv('PHPBB_DB_PASS')
        );
        $forumGrants = array_values(array_filter(
            $db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN),
            fn(string $grant): bool => str_contains($grant, '`phpbb_baja`') || str_contains($grant, ' ON *.* ')
        ));

        $this->assertNotEmpty($forumGrants);
        foreach ($forumGrants as $grant) {
            $this->assertMatchesRegularExpression('/^GRANT (SELECT|USAGE) ON /', $grant);
        }
    }
}
