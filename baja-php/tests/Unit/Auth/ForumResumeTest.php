<?php
declare(strict_types=1);

namespace Baja\Tests\Unit\Auth;

use Baja\Auth\ForumResume;
use PHPUnit\Framework\TestCase;

/**
 * The two facts about a request that the bounce to the forum depends on
 * (BAJAAPP-86; PhpbbSessionShimTest explains the bounce itself):
 *
 *   - is the browser opening a page? Only then can it be sent to the forum
 *     and back; a form's POST or a script's request would break on the way;
 *   - which address to come back to? The page the judge asked for, and only
 *     on one of our own hosts.
 */
final class ForumResumeTest extends TestCase
{
    // --- isNavigation ---------------------------------------------------------

    public function testOpeningAPageIsANavigation(): void
    {
        $this->assertTrue(ForumResume::isNavigation(['REQUEST_METHOD' => 'GET', 'HTTP_SEC_FETCH_MODE' => 'navigate']));
    }

    /** A browser too old to send Sec-Fetch-Mode is assumed to be opening a page. */
    public function testAGetWithoutFetchMetadataIsANavigation(): void
    {
        $this->assertTrue(ForumResume::isNavigation(['REQUEST_METHOD' => 'GET']));
    }

    public function testAPostIsNot(): void
    {
        $this->assertFalse(ForumResume::isNavigation(['REQUEST_METHOD' => 'POST', 'HTTP_SEC_FETCH_MODE' => 'navigate']));
    }

    public function testAScriptsRequestIsNot(): void
    {
        $this->assertFalse(ForumResume::isNavigation(['REQUEST_METHOD' => 'GET', 'HTTP_SEC_FETCH_MODE' => 'cors']));
        $this->assertFalse(ForumResume::isNavigation(['REQUEST_METHOD' => 'GET', 'HTTP_SEC_FETCH_MODE' => 'same-origin']));
        $this->assertFalse(ForumResume::isNavigation(['REQUEST_METHOD' => 'GET', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
    }

    // --- currentUrl -------------------------------------------------------------

    public function testThePageAskedForIsWhereTheBrowserComesBack(): void
    {
        $this->assertSame(
            'https://juiz.bajasaebrasil.net/26BR/prova.php?p=END&x=1',
            ForumResume::currentUrl(
                ['HTTP_HOST' => 'juiz.bajasaebrasil.net', 'REQUEST_URI' => '/26BR/prova.php?p=END&x=1'],
                'https',
                'bajasaebrasil.net'
            )
        );
    }

    /** A port, as on a dev machine running two stacks side by side, is kept. */
    public function testAPortIsKept(): void
    {
        $this->assertSame(
            'http://juiz.baja.local:8086/index.php',
            ForumResume::currentUrl(['HTTP_HOST' => 'juiz.baja.local:8086', 'REQUEST_URI' => '/index.php'], 'http', 'baja.local')
        );
    }

    /** A Host header naming somebody else's site: no bounce at all. */
    public function testAForeignHostIsRefused(): void
    {
        foreach (['evil.example', 'baja.local.evil.example', 'evilbaja.local', 'juiz.baja.local@evil.example', ''] as $host) {
            $this->assertNull(
                ForumResume::currentUrl(['HTTP_HOST' => $host, 'REQUEST_URI' => '/index.php'], 'http', 'baja.local'),
                $host
            );
        }
    }

    public function testAPathThatIsNotOneIsRefused(): void
    {
        foreach (['', 'index.php', '//evil.example/'] as $uri) {
            $this->assertNull(
                ForumResume::currentUrl(['HTTP_HOST' => 'juiz.baja.local', 'REQUEST_URI' => $uri], 'http', 'baja.local'),
                $uri
            );
        }
    }

    /** The address back travels as one parameter, whatever it contains. */
    public function testTheResumeAddressCarriesTheWholePage(): void
    {
        $url = ForumResume::resumeUrl('http://juiz.baja.local/prova.php?p=END&x=1');
        $this->assertStringEndsWith('/app.php/baja/resume?redirect=http%3A%2F%2Fjuiz.baja.local%2Fprova.php%3Fp%3DEND%26x%3D1', $url);
        $this->assertStringStartsWith('http://forum.', $url);
    }
}
