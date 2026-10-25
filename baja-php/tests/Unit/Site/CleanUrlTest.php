<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Site;

use Baja\Site\CleanUrl;
use PHPUnit\Framework\TestCase;

/**
 * The redirect to a page's clean URL (docs/results-caching.md).
 *
 * Cloudflare stores one copy of a page per distinct URL, query string
 * included. So prova.php?id=26BR_GER and prova.php?id=26BR_GER&fbclid=abc are
 * two copies, and a new fbclid on every Facebook click would mean every click
 * misses the cache and reaches the server.
 *
 * Each page therefore lists the parameters it really reads. A request with
 * anything else, or with them in a different order, is redirected (HTTP 301)
 * to the one clean URL. After that redirect, every visitor asks for the same
 * URL, and one cached copy serves them all.
 *
 * CleanUrl::target($path, $queryString, $allowed) answers: "where should this
 * request be redirected?" It returns null for "nowhere, it is already clean".
 * Each test gives it a request and checks the answer.
 */
final class CleanUrlTest extends TestCase
{
    // --- Already clean: no redirect -----------------------------------------

    public function testACleanUrlIsNotRedirected(): void
    {
        $this->assertNull(CleanUrl::target('/prova.php', 'id=26BR_GER', ['id']));
    }

    public function testAPageWithNoParametersAndNoneGivenIsNotRedirected(): void
    {
        $this->assertNull(CleanUrl::target('/', '', []));
    }

    /** torneio.php reads two parameters; both are kept, in the page's order. */
    public function testEveryDocumentedParameterIsKept(): void
    {
        $this->assertNull(CleanUrl::target('/torneio.php', 'id=26BR_CAB&overlay=1', ['id', 'overlay']));
    }

    // --- Tracking tags and junk: removed ------------------------------------

    public function testFacebookClickIdIsRemoved(): void
    {
        $this->assertSame(
            '/prova.php?id=26BR_GER',
            CleanUrl::target('/prova.php', 'id=26BR_GER&fbclid=IwAR0abc123', ['id'])
        );
    }

    public function testUtmTagsAreRemoved(): void
    {
        $this->assertSame(
            '/prova.php?id=26BR_GER',
            CleanUrl::target('/prova.php', 'utm_source=instagram&id=26BR_GER&utm_medium=social&utm_campaign=baja', ['id'])
        );
    }

    /** Anything the page does not read goes, whatever its name. */
    public function testJunkParametersAreRemoved(): void
    {
        $this->assertSame(
            '/prova.php?id=26BR_GER',
            CleanUrl::target('/prova.php', 'id=26BR_GER&x=1&refresh=please&_=1712345678', ['id'])
        );
    }

    /** A page that reads nothing loses the whole query string. */
    public function testAPageWithNoParametersLosesThemAll(): void
    {
        $this->assertSame('/clock.php', CleanUrl::target('/clock.php', 'fbclid=abc', []));
    }

    /** The index is reached as "/" (or "/26BR/"); the redirect keeps that. */
    public function testTheIndexKeepsItsPath(): void
    {
        $this->assertSame('/26BR/', CleanUrl::target('/26BR/', 'utm_source=whatsapp', []));
    }

    // --- Order and spelling: one URL ----------------------------------------

    /** The same parameters in another order would be another cache copy. */
    public function testParametersAreReorderedToThePagesOrder(): void
    {
        $this->assertSame(
            '/torneio.php?id=26BR_CAB&overlay=1',
            CleanUrl::target('/torneio.php', 'overlay=1&id=26BR_CAB', ['id', 'overlay'])
        );
    }

    /** id=a&id=b: PHP reads the last one, and the clean URL says so. */
    public function testARepeatedParameterKeepsTheValueThePageReads(): void
    {
        $this->assertSame('/prova.php?id=B', CleanUrl::target('/prova.php', 'id=A&id=B', ['id']));
    }

    /** id[]=x is a list, which no page reads: dropped. */
    public function testAParameterGivenAsAListIsDropped(): void
    {
        $this->assertSame('/prova.php', CleanUrl::target('/prova.php', 'id[]=26BR_GER', ['id']));
    }

    /**
     * A value is written one way only: "%5F" and "_" are the same letter, so
     * they redirect to one spelling. The clean URL then stays clean (no loop).
     */
    public function testAValueIsSpelledOneWay(): void
    {
        $target = CleanUrl::target('/prova.php', 'id=26BR%5FGER', ['id']);
        $this->assertSame('/prova.php?id=26BR_GER', $target);
        $this->assertNull(CleanUrl::target('/prova.php', 'id=26BR_GER', ['id']));
    }

    /** A value with a space ends up as %20, whichever way it came. */
    public function testASpaceIsSpelledOneWay(): void
    {
        $this->assertSame('/prova.php?id=a%20b', CleanUrl::target('/prova.php', 'id=a+b', ['id']));
        $this->assertNull(CleanUrl::target('/prova.php', 'id=a%20b', ['id']));
    }

    /** Characters that mean something in HTML or JavaScript come out encoded. */
    public function testDangerousCharactersAreEncoded(): void
    {
        $this->assertSame(
            "/prova.php?id=%27%3E%3Cscript%3E",
            CleanUrl::target('/prova.php', "id='><script>", ['id'])
        );
    }

    // --- The event prefix, and staying on this site -------------------------

    /** /26BR/prova.php is how other events' pages are reached; kept. */
    public function testTheEventPrefixIsKept(): void
    {
        $this->assertSame(
            '/24SE/prova.php?id=24SE_GER',
            CleanUrl::target('/24SE/prova.php', 'fbclid=abc&id=24SE_GER', ['id'])
        );
    }

    /** "//evil.example/..." in a Location header would leave the site. */
    public function testTheRedirectCannotLeaveTheSite(): void
    {
        $this->assertSame(
            '/evil.example/prova.php?id=X',
            CleanUrl::target('//evil.example/prova.php', 'id=X&a=1', ['id'])
        );
        $this->assertSame('/', CleanUrl::safePath('/\\evil.example'));
        $this->assertSame('/', CleanUrl::safePath("/prova.php\r\nSet-Cookie: x=1"));
    }
}
