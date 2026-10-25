<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Site;

use Baja\Model\Evento;
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
 * The header depends on one flag of the event: finalizado ("finished").
 * Not finished: a short cache, because results can still change. Finished:
 * a long one. Each test below reads as: "for such an event, the header is
 * this".
 *
 * The event's other flag, em_andamento, is NOT "the event is running": it
 * marks the site's default event (the one opened when the URL names no
 * event), and only one event has it at a time. So it must not change the
 * cache, and the tests at the end check that it does not.
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

    /**
     * Not finished: during the event, between its days, or waiting for the
     * final results. Results can still change: short cache.
     */
    public function testAnEventThatIsNotFinishedIsCachedBriefly(): void
    {
        $this->assertSame(self::LIVE, CachePolicy::publicHeader(finished: false));
    }

    /** A finished event only changes when someone corrects it: long cache. */
    public function testAFinishedEventIsCachedLonger(): void
    {
        $this->assertSame(self::FINISHED, CachePolicy::publicHeader(finished: true));
    }

    // --- Race data: overlaydata.php and enduro_dump.php ------------------------
    //
    // The broadcast overlays read lap counts every second or two, so until
    // the event is finished these get seconds, not the results pages'
    // 10 + 15. Once it is finished they are as static as any result: the
    // long cache.

    private const RACE_DATA = 'public, max-age=' . CachePolicy::RACE_DATA_BROWSER_SECONDS
        . ', s-maxage=' . CachePolicy::RACE_DATA_EDGE_SECONDS;

    public function testRaceDataIsCachedForSecondsUntilTheEventIsFinished(): void
    {
        $this->assertSame(self::RACE_DATA, CachePolicy::raceDataHeader(finished: false));
        $this->assertSame(self::RACE_DATA, CachePolicy::raceDataHeaderFor(null));
    }

    public function testRaceDataOfAFinishedEventGetsTheFinishedCache(): void
    {
        $this->assertSame(self::FINISHED, CachePolicy::raceDataHeader(finished: true));
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
        $this->assertSame('public, max-age=10, s-maxage=15', CachePolicy::publicHeader(false));
        $this->assertSame('public, max-age=300, s-maxage=3600', CachePolicy::publicHeader(true));
        $this->assertSame('public, max-age=1, s-maxage=2', CachePolicy::raceDataHeader(false));
    }

    // --- em_andamento does not count -----------------------------------------
    //
    // These go through the event itself, as the pages do. The Evento objects
    // are never saved: no database involved.

    /** The default event, finished (the dev seed's 26BR is like this): long cache. */
    public function testTheDefaultEventIsCachedLongerOnceFinished(): void
    {
        $evento = self::evento(emAndamento: true, finalizado: true);
        $this->assertSame(self::FINISHED, CachePolicy::publicHeaderFor($evento));
        $this->assertSame(self::FINISHED, CachePolicy::raceDataHeaderFor($evento));
    }

    /** Not the default event, and not finished: still the short cache. */
    public function testAnEventThatIsNotTheDefaultIsCachedBrieflyUntilFinished(): void
    {
        $evento = self::evento(emAndamento: false, finalizado: false);
        $this->assertSame(self::LIVE, CachePolicy::publicHeaderFor($evento));
        $this->assertSame(self::RACE_DATA, CachePolicy::raceDataHeaderFor($evento));
    }

    public function testTheDefaultEventIsCachedBrieflyUntilFinished(): void
    {
        $this->assertSame(self::LIVE, CachePolicy::publicHeaderFor(self::evento(emAndamento: true, finalizado: false)));
    }

    public function testAFinishedEventThatIsNotTheDefaultIsCachedLonger(): void
    {
        $this->assertSame(self::FINISHED, CachePolicy::publicHeaderFor(self::evento(emAndamento: false, finalizado: true)));
    }

    private static function evento(bool $emAndamento, bool $finalizado): Evento
    {
        return (new Evento())->setEmAndamento($emAndamento)->setFinalizado($finalizado);
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
