<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Auth;

use Baja\Auth\PhpbbSessionShim;
use Baja\Auth\SessionStore;
use PHPUnit\Framework\TestCase;

/**
 * When the judge area sends a browser to the forum to get its session back,
 * and when it asks the forum to keep the session alive (BAJAAPP-86).
 *
 * WHY THE FORUM AND THE JUDGE AREA DISAGREED
 *
 * The forum (phpBB) keeps a login in cookies:
 *
 *   phpbb3_baja_sid  the session. Points at a row in phpbb_sessions, which
 *                    phpBB deletes after an hour (session_length) with no
 *                    activity.
 *   phpbb3_baja_u    the user's id. 1 means "anonymous", which is what
 *                    logging out leaves behind.
 *   phpbb3_baja_k    the "remember me" key. Only there when the user ticked
 *                    the box; empty otherwise.
 *
 * When the session row is gone, the forum's next page notices the _k cookie
 * and quietly creates a new session: the user stays logged in. The judge
 * area cannot do that, because its MySQL user may only read the forum's
 * tables, and it only ever looked at _sid. So the forum said "logged in"
 * and the judge area showed its login form.
 *
 * Now, for a page that needs a login and finds none, decideResume() says
 * whether to send the browser through the forum (where phpBB does the
 * resuming) and straight back, or to show the login form as before. These
 * tests check that decision, and keepAliveDue(), which says when a judge
 * page should poke the forum so that judge-area activity counts as activity.
 * They need nothing but PHP; the round trips themselves are tested over HTTP
 * in tests/Auth/Http.
 */
final class PhpbbSessionShimTest extends TestCase
{
    private const PREFIX = 'phpbb3_baja';
    private const KEY = '0123456789abcdef';

    private array $cookiesBefore = [];

    protected function setUp(): void
    {
        $this->cookiesBefore = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookiesBefore;
    }

    // --- decideResume: when the bounce happens -----------------------------

    /** The case this was built for: no session, but a "remember me" key. */
    public function testAnExpiredSessionWithAKeyIsResumed(): void
    {
        $this->assertSame(PhpbbSessionShim::RESUME, PhpbbSessionShim::decideResume(false, '2', self::KEY, false, true));
    }

    /** Logged in already: nothing to resume. (Callers only ask without a session; this is a guard.) */
    public function testALoggedInUserIsNeverSentToTheForum(): void
    {
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, PhpbbSessionShim::decideResume(true, '2', self::KEY, false, true));
    }

    /** No "remember me": the forum could not resume either, so the login form, as before. */
    public function testWithoutAKeyTheLoginFormIsShown(): void
    {
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, PhpbbSessionShim::decideResume(false, '2', '', false, true));
    }

    /**
     * _u=1 is the anonymous user: what logging out (on either side) leaves.
     * Even with a key left over in the browser, that is a logout to respect.
     */
    public function testAfterALogoutTheLoginFormIsShown(): void
    {
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, PhpbbSessionShim::decideResume(false, '1', self::KEY, false, true));
    }

    /** phpBB reads the key only together with _u; without it the bounce could not work. */
    public function testAKeyWithoutAUserIdIsNotEnough(): void
    {
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, PhpbbSessionShim::decideResume(false, '', self::KEY, false, true));
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, PhpbbSessionShim::decideResume(false, 'abc', self::KEY, false, true));
    }

    /**
     * THE LOOP GUARD. The browser went to the forum a moment ago and came back
     * still without a session: show the login form, do not go round again.
     */
    public function testComingBackWithoutASessionShowsTheLoginForm(): void
    {
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, PhpbbSessionShim::decideResume(false, '2', self::KEY, true, true));
    }

    /** A form post or a script's request is not bounced: it would not survive the trip. */
    public function testOnlyPageNavigationsAreBounced(): void
    {
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, PhpbbSessionShim::decideResume(false, '2', self::KEY, false, false));
    }

    // --- The same decision, from the request's cookies ------------------------

    /**
     * End to end within the shim: the session row is gone (the store finds
     * nothing), the browser still sends _u and _k, and the shim says RESUME.
     */
    public function testTheShimReadsTheCookiesItDecidesOn(): void
    {
        $_COOKIE = [
            self::PREFIX . '_sid' => 'expired-session-id',
            self::PREFIX . '_u'   => '2',
            self::PREFIX . '_k'   => self::KEY,
        ];
        $shim = new PhpbbSessionShim($this->storeReturning(null), self::PREFIX);
        $shim->session_begin();

        $this->assertSame('', $shim->data['username']);
        $this->assertSame(PhpbbSessionShim::RESUME, $shim->resumeDecision(false, true));
        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, $shim->resumeDecision(true, true));
    }

    /** name[]=x arrives as an array; it is no key, and must not raise a warning. */
    public function testACookieSentAsAnArrayCountsAsAbsent(): void
    {
        $_COOKIE = [
            self::PREFIX . '_u' => '2',
            self::PREFIX . '_k' => ['x'],
        ];
        $shim = new PhpbbSessionShim($this->storeReturning(null), self::PREFIX);
        $shim->session_begin();

        $this->assertSame(PhpbbSessionShim::LOGIN_FORM, $shim->resumeDecision(false, true));
    }

    // --- keepAliveDue: when a judge page pokes the forum ----------------------

    public function testAFreshSessionNeedsNoKeepAlive(): void
    {
        $now = 1_800_000_000;
        $this->assertFalse(PhpbbSessionShim::keepAliveDue($now - 10, $now));
        $this->assertFalse(PhpbbSessionShim::keepAliveDue($now - PhpbbSessionShim::KEEPALIVE_AFTER_SECONDS + 1, $now));
    }

    public function testASessionAFewMinutesOldIsKeptAlive(): void
    {
        $now = 1_800_000_000;
        $this->assertTrue(PhpbbSessionShim::keepAliveDue($now - PhpbbSessionShim::KEEPALIVE_AFTER_SECONDS, $now));
        $this->assertTrue(PhpbbSessionShim::keepAliveDue($now - 3000, $now));
    }

    /** No session, or a cached one without its time: nothing to go on, so no request. */
    public function testAnUnknownSessionTimeNeverTriggersIt(): void
    {
        $this->assertFalse(PhpbbSessionShim::keepAliveDue(null, 1_800_000_000));
    }

    /**
     * phpBB writes session_time at most once a minute; asking more often than
     * that would only cost requests. And it must come well before the forum's
     * one hour, or the session could still expire under an active judge.
     */
    public function testTheIntervalIsBetweenPhpbbsMinuteAndItsHour(): void
    {
        $this->assertGreaterThan(60, PhpbbSessionShim::KEEPALIVE_AFTER_SECONDS);
        $this->assertLessThanOrEqual(600, PhpbbSessionShim::KEEPALIVE_AFTER_SECONDS);
    }

    /** The shim takes the time from the session row the store found. */
    public function testTheShimUsesTheSessionRowsTime(): void
    {
        $now = time();
        $_COOKIE = [self::PREFIX . '_sid' => 'sid', self::PREFIX . '_u' => '2'];

        $old = new PhpbbSessionShim($this->storeReturning(['user_id' => 2, 'username' => 'juiz1', 'session_time' => $now - 900]), self::PREFIX);
        $old->session_begin();
        $this->assertSame('juiz1', $old->data['username']);
        $this->assertTrue($old->keepAliveNeeded($now));

        $fresh = new PhpbbSessionShim($this->storeReturning(['user_id' => 2, 'username' => 'juiz1', 'session_time' => $now - 30]), self::PREFIX);
        $fresh->session_begin();
        $this->assertFalse($fresh->keepAliveNeeded($now));
    }

    /**
     * After asking the forum to refresh the row, the cached copy is dropped,
     * so the next page reads the new time instead of asking again.
     */
    public function testKeepingAliveForgetsTheCachedSession(): void
    {
        $_COOKIE = [self::PREFIX . '_sid' => 'the-sid', self::PREFIX . '_u' => '2'];
        $store = $this->createMock(SessionStore::class);
        $store->method('lookupBySessionId')->willReturn(['user_id' => 2, 'username' => 'juiz1', 'session_time' => 1]);
        $store->expects($this->once())->method('invalidateSessionId')->with('the-sid');

        $shim = new PhpbbSessionShim($store, self::PREFIX);
        $shim->session_begin();
        $shim->forgetCachedSession();
    }

    private function storeReturning(?array $row): SessionStore
    {
        $store = $this->createStub(SessionStore::class);
        $store->method('lookupBySessionId')->willReturn($row);
        return $store;
    }
}
