<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Site;

use Baja\Site\CachePolicy;
use PHPUnit\Framework\TestCase;

/**
 * How long the public results pages may be cached (docs/results-caching.md).
 *
 * Every public results page sends a "Cache-Control" header, which tells the
 * browser and Cloudflare how long they may reuse a copy of the page before
 * asking the server again:
 *
 *   max-age=N    the visitor's browser may reuse it for N seconds;
 *   s-maxage=N   Cloudflare may serve it to every visitor for N seconds.
 *
 * The header depends on the event's two flags in the database:
 * em_andamento ("in progress") and finalizado ("finished"). Each test below
 * reads as: "for an event with these flags, the header is this".
 *
 * The exact numbers are constants in CachePolicy, so the tests use the
 * constants and will not need changing when someone tunes them. One test
 * (testTheStartingNumbers) pins today's values, so a change to them is a
 * deliberate one.
 */
final class CachePolicyTest extends TestCase
{
    private const LIVE = 'public, max-age=' . CachePolicy::LIVE_BROWSER_SECONDS
        . ', s-maxage=' . CachePolicy::LIVE_EDGE_SECONDS;
    private const FINISHED = 'public, max-age=' . CachePolicy::FINISHED_BROWSER_SECONDS
        . ', s-maxage=' . CachePolicy::FINISHED_EDGE_SECONDS;

    /** During the event results change every few minutes: short cache. */
    public function testAnEventInProgressIsCachedBriefly(): void
    {
        $this->assertSame(self::LIVE, CachePolicy::publicHeader(inProgress: true, finished: false));
    }

    /**
     * Not in progress but not finished either: before the event, or between
     * its days, or waiting for the final results. Results can still change,
     * so still the short cache.
     */
    public function testAnEventThatIsNotFinishedIsCachedBriefly(): void
    {
        $this->assertSame(self::LIVE, CachePolicy::publicHeader(inProgress: false, finished: false));
    }

    /** A finished event only changes when someone corrects it: long cache. */
    public function testAFinishedEventIsCachedLonger(): void
    {
        $this->assertSame(self::FINISHED, CachePolicy::publicHeader(inProgress: false, finished: true));
    }

    /**
     * Both flags on is a mistake in the event's settings. When unsure, the
     * short cache: a result shown late is worse than a few more requests.
     */
    public function testInProgressWinsOverFinished(): void
    {
        $this->assertSame(self::LIVE, CachePolicy::publicHeader(inProgress: true, finished: true));
    }

    /** No event found (an unknown event code in the URL): short cache. */
    public function testNoEventIsCachedBriefly(): void
    {
        $this->assertSame(self::LIVE, CachePolicy::publicHeaderFor(null));
    }

    /** The redirect to a clean URL never changes, so it gets the long cache. */
    public function testARedirectToTheCleanUrlIsCachedLonger(): void
    {
        $this->assertSame(self::FINISHED, CachePolicy::redirectHeader());
    }

    /**
     * Today's numbers, from BAJAAPP-23. If you tune them, change them here
     * too, and in the table in docs/results-caching.md.
     */
    public function testTheStartingNumbers(): void
    {
        $this->assertSame('public, max-age=10, s-maxage=15', CachePolicy::publicHeader(true, false));
        $this->assertSame('public, max-age=300, s-maxage=3600', CachePolicy::publicHeader(false, true));
    }

    /**
     * A page produced with somebody's forum session must never be stored by
     * any cache, or the next visitor could be served it.
     */
    public function testTheSessionHeaderForbidsAnyCache(): void
    {
        $this->assertSame('private, no-store', CachePolicy::PRIVATE);
    }

    // --- Was this page produced with a session? -----------------------------
    //
    // nginx passes SKIP_AUTH=1 for the public path and SKIP_AUTH=0 when the
    // staff cookie is right. src/bootstrap.php loads the forum session when
    // SKIP_AUTH is "0" or missing, and then sets the global $user. The public
    // header is only allowed when neither says "session".

    protected function tearDown(): void
    {
        putenv('SKIP_AUTH');
        unset($GLOBALS['user']);
    }

    public function testThePublicPathHasNoSession(): void
    {
        putenv('SKIP_AUTH=1');
        $this->assertFalse(CachePolicy::hasSession());
    }

    /** What nginx sends for a browser with the right staff cookie. */
    public function testSkipAuthZeroMeansSession(): void
    {
        putenv('SKIP_AUTH=0');
        $this->assertTrue(CachePolicy::hasSession());
    }

    /** A page nginx did not mark at all gets the session, as in bootstrap.php. */
    public function testNoSkipAuthMeansSession(): void
    {
        putenv('SKIP_AUTH');
        $this->assertTrue(CachePolicy::hasSession());
    }

    /**
     * Belt and braces: if the session was loaded anyway ($user exists), the
     * answer is "session" even with SKIP_AUTH=1.
     */
    public function testALoadedSessionWinsOverSkipAuth(): void
    {
        putenv('SKIP_AUTH=1');
        $GLOBALS['user'] = new \stdClass();
        $this->assertTrue(CachePolicy::hasSession());
    }
}
