<?php
declare(strict_types=1);

namespace Baja\Tests\Auth\Http;

use Baja\Auth\ForumResume;

/**
 * A judge whose forum session expired comes back to the judge area
 * (BAJAAPP-86). See ForumSessionTestCase for what _sid, _u and _k are.
 *
 * THE TRIP, WHEN IT WORKS
 *
 *   1. juiz page        no session row for _sid, but _u and _k are there:
 *                       302 to the forum's /app.php/baja/resume?redirect=<page>,
 *                       and the loop-guard cookie (baja_resume) is set
 *   2. forum resume     phpBB, starting up, finds the key and creates a new
 *                       session (new _sid, new _k): 302 back to <page>
 *   3. juiz page        has a session again: the page itself, and the
 *                       loop-guard cookie is cleared
 *
 * THE TRIP, WHEN IT CANNOT WORK
 *
 * With no key, the judge area goes straight to its login form as before.
 * With a key phpBB does not know (revoked, expired, made up), phpBB answers
 * step 2 with an anonymous session (_u=1), and step 3 sends the browser to
 * the login form. And if the browser comes back from the forum with no
 * session for any other reason, the loop-guard cookie makes step 3 show the
 * login form instead of starting again.
 */
final class ResumeTest extends ForumSessionTestCase
{
    /** A page with a query string, to check the judge lands on exactly it. */
    private const PAGE = self::JUIZ . '/index.php?aba=provas&teste=bajaapp86';

    /**
     * SITUATION: a judge ticked "remember me" on the forum, then left the
     * judge area open for longer than the forum's session lasts.
     *
     * BEFORE: the judge area showed its login form, while the forum, in the
     * next tab, showed them logged in.
     *
     * EXPECTED: one trip through the forum, invisible to the judge, and the
     * same page, logged in. phpBB gave out a new session and a new key, and
     * threw the old key away.
     */
    public function testAnExpiredSessionWithAValidKeyIsResumedOnTheSamePage(): void
    {
        $this->logIn(rememberMe: true);
        $oldSid = (string)$this->sid();
        $oldKey = (string)$this->cookie('_k');
        $this->expireSession();

        $stops = $this->browser->open(self::PAGE);

        $this->assertSame(
            [self::PAGE, ForumResume::resumeUrl(self::PAGE), self::PAGE],
            $this->urls($stops),
            'juiz page -> forum resume -> the same juiz page'
        );
        $this->assertSame('1', $stops[0]['setCookies'][ForumResume::MARKER] ?? null, 'the loop guard is set on the way out');
        $last = end($stops);
        $this->assertSame(200, $last['status']);
        $this->assertStringContainsString(self::LOGGED_IN_MARKER, $last['body'], 'logged in again');
        $this->assertSame('', $last['setCookies'][ForumResume::MARKER] ?? null, 'the loop guard is cleared once back');
        $this->assertNull($this->browser->cookie(ForumResume::MARKER, 'juiz.baja.local'));

        $this->assertNotSame($oldSid, $this->sid(), 'a new session');
        $this->assertNotNull($this->sessionRow((string)$this->sid()), 'with a row the judge area can read');
        $this->assertNotSame($oldKey, $this->cookie('_k'), 'phpBB hands out a new key on every resume');
        $this->assertFalse($this->keyExists($oldKey), 'and forgets the old one');
        $this->assertTrue($this->keyExists((string)$this->cookie('_k')));
    }

    /**
     * SITUATION: the same judge opens the login page itself (a bookmark, or
     * the address bar's suggestion).
     *
     * EXPECTED: the same trip, then the login page does what it does for a
     * logged-in judge: sends them to the start page.
     */
    public function testTheLoginPageResumesToo(): void
    {
        $this->logIn(rememberMe: true);
        $this->expireSession();

        $stops = $this->browser->open(self::JUIZ . '/login.php');

        $this->assertSame(
            [self::JUIZ . '/login.php', ForumResume::resumeUrl(self::JUIZ . '/login.php'), self::JUIZ . '/login.php', self::JUIZ . '/index.php'],
            $this->urls($stops)
        );
        $this->assertStringContainsString(self::LOGGED_IN_MARKER, end($stops)['body']);
    }

    /**
     * SITUATION: a judge who did NOT tick "remember me" (the judge area's own
     * form has no such box), after the session expired.
     *
     * EXPECTED: the login form, as before, with no trip to the forum: the
     * forum has nothing to resume either.
     */
    public function testAnExpiredSessionWithoutAKeyGetsTheLoginForm(): void
    {
        $this->logIn(rememberMe: false);
        $this->assertSame('', (string)$this->cookie('_k'), 'logged in without "remember me": empty key');
        $this->expireSession();

        $stops = $this->browser->open(self::PAGE);

        $this->assertSame(0, $this->forumStops($stops), 'no trip to the forum');
        $this->assertEndsOnTheLoginForm($stops);
    }

    /**
     * SITUATION: the key in the browser no longer exists in the forum: it
     * expired (max_autologin_time) or was revoked ("log out everywhere").
     *
     * EXPECTED: one trip to the forum, which answers "anonymous", then the
     * login form. One trip, not a loop.
     */
    public function testARevokedKeyGetsTheLoginFormWithoutALoop(): void
    {
        $this->logIn(rememberMe: true);
        $this->revokeKey((string)$this->cookie('_k'));
        $this->expireSession();

        $stops = $this->browser->open(self::PAGE);

        $this->assertSame(1, $this->forumStops($stops), 'exactly one trip to the forum; stops: ' . implode(' -> ', $this->urls($stops)));
        $this->assertEndsOnTheLoginForm($stops);
        $this->assertSame('1', $this->cookie('_u'), 'phpBB answered with the anonymous user');
    }

    /** Same, with a key that never existed. */
    public function testAMadeUpKeyGetsTheLoginFormWithoutALoop(): void
    {
        $this->logIn(rememberMe: true);
        $this->browser->setCookie($this->prefix . '_k', 'f00f00f00f00f00f', 'baja.local');
        $this->expireSession();

        $stops = $this->browser->open(self::PAGE);

        $this->assertSame(1, $this->forumStops($stops), 'stops: ' . implode(' -> ', $this->urls($stops)));
        $this->assertEndsOnTheLoginForm($stops);
    }

    /**
     * THE LOOP GUARD ON ITS OWN. The browser comes back from the forum still
     * without a session, but phpBB did not say "anonymous" (the forum was
     * down, a challenge was not passed, its cookies did not stick). Here the
     * forum is simply not visited: the browser arrives with the guard cookie
     * the first trip set, and the cookies it had before.
     *
     * EXPECTED: the login form, not a second trip.
     */
    public function testComingBackWithTheGuardCookieGetsTheLoginForm(): void
    {
        $this->logIn(rememberMe: true);
        $this->expireSession();
        $this->browser->setCookie(ForumResume::MARKER, '1', 'juiz.baja.local', hostOnly: true);

        $stops = $this->browser->open(self::PAGE);

        $this->assertSame(0, $this->forumStops($stops), 'stops: ' . implode(' -> ', $this->urls($stops)));
        $this->assertEndsOnTheLoginForm($stops);
    }

    /**
     * A form's POST, or a script's request, with an expired session: no trip.
     * A POST would arrive back as a GET without its fields, and a script
     * cannot follow a redirect to the forum (another origin). Both get what
     * they got before.
     */
    public function testOnlyPageNavigationsAreSentToTheForum(): void
    {
        $this->logIn(rememberMe: true);
        $this->expireSession();

        $post = $this->browser->request('POST', self::PAGE, 'x=1', ['Content-Type: application/x-www-form-urlencoded']);
        $fetch = $this->browser->request('GET', self::PAGE, null, ['Sec-Fetch-Mode: cors']);
        $jquery = $this->browser->request('GET', self::PAGE, null, ['X-Requested-With: XMLHttpRequest']);

        foreach (['POST' => $post, 'fetch' => $fetch, 'jQuery' => $jquery] as $what => $response) {
            $this->assertSame(302, $response['status'], $what);
            $this->assertSame(self::JUIZ . '/login.php', $response['location'], $what);
        }

        // The same page, opened by the browser, is resumed: the key was not
        // used up by the requests above.
        $stops = $this->browser->open(self::PAGE);
        $this->assertSame(1, $this->forumStops($stops));
        $this->assertStringContainsString(self::LOGGED_IN_MARKER, end($stops)['body']);
    }
}
