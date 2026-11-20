<?php
declare(strict_types=1);

namespace Baja\Tests\Auth\Http;

/**
 * Logging out still logs out, now that the judge area can bring a session
 * back from the "remember me" key (BAJAAPP-86).
 *
 * The danger: the judge logs out, but a "remember me" key survives
 * somewhere, and the next page quietly logs them back in. It cannot happen,
 * because logging out on either side goes through phpBB's session_kill(),
 * which deletes the key from phpbb_sessions_keys and tells the browser to
 * delete the _k cookie; and phpBB then sets _u=1 (anonymous), which the judge
 * area never tries to resume. These tests check both, and also the case of a
 * browser that kept a copy of the old cookies (another tab, a restored
 * session): the forum no longer knows that key, so the trip ends on the
 * login form.
 */
final class LogoutTest extends ForumSessionTestCase
{
    /** Logging out with the judge area's "Logout" link. */
    public function testLoggingOutOfTheJudgeAreaDoesNotComeBack(): void
    {
        $this->logIn(rememberMe: true);
        $this->assertStringContainsString(self::LOGGED_IN_MARKER, $this->browser->request('GET', self::JUIZ . '/index.php')['body']);
        $before = $this->browser->allCookies();
        $key = (string)$this->cookie('_k');
        $this->assertTrue($this->keyExists($key));

        // juiz login.php?act=logout -> forum /baja/logout (with the CSRF
        // token) -> juiz login.php
        $stops = $this->browser->open(self::JUIZ . '/login.php?act=logout');
        $this->assertStringStartsWith(self::FORUM . '/app.php/baja/logout?', $stops[1]['url']);
        $this->assertEndsOnTheLoginForm($stops);

        // The judge area dropped its cached copy of the old session when the
        // judge clicked Logout (Session::endSession), so the old cookies are
        // refused at once.
        $this->assertNoResurrection($key, $before);
    }

    /** Logging out on the forum, with its own "Logout" link. */
    public function testLoggingOutOfTheForumDoesNotComeBack(): void
    {
        $this->logIn(rememberMe: true);
        $this->assertStringContainsString(self::LOGGED_IN_MARKER, $this->browser->request('GET', self::JUIZ . '/index.php')['body']);
        $before = $this->browser->allCookies();
        $oldSid = (string)$this->sid();
        $key = (string)$this->cookie('_k');

        // phpBB's logout link carries the session id, which is its own check
        // that the click came from its page.
        $response = $this->browser->request('GET', self::FORUM . '/ucp.php?mode=logout&sid=' . $this->sid());
        $this->assertSame(302, $response['status'], 'phpBB redirects after logging out');

        // The forum cannot reach the judge area's cache of the old session,
        // so a browser that still sends the OLD cookies is let in for up to
        // SESSION_CACHE_TTL_SECONDS (an old, known window; the same browser
        // is anonymous at once, because phpBB set _u=1). What matters here
        // is what happens after it: no bounce brings the session back.
        $this->forgetCachedSession($oldSid);
        $this->assertNoResurrection($key, $before);
    }

    /**
     * @param array<string, array{name: string, value: string, domain: string, hostOnly: bool}> $before
     *        the browser's cookies while it was still logged in
     */
    private function assertNoResurrection(string $key, array $before): void
    {
        // phpBB deleted the key, and told the browser to forget it.
        $this->assertFalse($this->keyExists($key), 'the key is gone from phpbb_sessions_keys');
        $this->assertNotSame($key, $this->cookie('_k'), 'the browser no longer has the key');
        $this->assertSame('1', $this->cookie('_u'), 'the browser is anonymous');

        // The next judge page: the login form, with no trip to the forum.
        $stops = $this->browser->open(self::JUIZ . '/index.php');
        $this->assertSame(0, $this->forumStops($stops), 'stops: ' . implode(' -> ', $this->urls($stops)));
        $this->assertEndsOnTheLoginForm($stops);

        // A browser that kept the old cookies (another tab, a restored
        // session) makes the trip, and the forum, which no longer knows the
        // key, answers "anonymous".
        $this->browser->replaceCookies($before);
        $stops = $this->browser->open(self::JUIZ . '/index.php');
        $this->assertSame(1, $this->forumStops($stops), 'the old key is tried once, at the forum; stops: ' . implode(' -> ', $this->urls($stops)));
        $this->assertEndsOnTheLoginForm($stops);
        $this->assertSame('1', $this->cookie('_u'), 'and the forum answered "anonymous"');
    }
}
