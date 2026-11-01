<?php
declare(strict_types=1);

namespace Baja\Tests\Auth\Http;

/**
 * The forum's /baja/resume and /baja/keepalive must not become an open
 * redirect (BAJAAPP-86).
 *
 * /baja/resume?redirect=<address> sends the browser to <address>. Anybody can
 * build such a link, and it starts with the forum's real address, so if any
 * <address> were accepted it would be a perfect phishing link: "log in at
 * forum.bajasaebrasil.net" that lands on somebody else's site. The route uses
 * the same check as /baja/login and /baja/logout (main::validateRedirect):
 * only our own sites, otherwise the forum's default page.
 *
 * Needs no login, and changes nothing; it only needs the dev web server.
 */
final class ResumeRedirectTest extends ForumSessionTestCase
{
    /** Our own sites are where it may go. */
    public function testOurOwnPageIsAccepted(): void
    {
        foreach ([self::JUIZ . '/26BR/prova.php?p=END&x=1', 'http://fila.baja.local/index.php', 'http://baja.local/', '/index.php'] as $target) {
            $response = $this->browser->request('GET', self::FORUM . '/app.php/baja/resume?redirect=' . rawurlencode($target));
            $this->assertSame(302, $response['status'], $target);
            // Browser::request() makes a relative Location absolute, on the forum.
            $this->assertSame(str_starts_with($target, '/') ? self::FORUM . $target : $target, $response['location'], $target);
        }
    }

    /** Anything else is replaced by the forum's default page. */
    public function testAForeignAddressIsRefused(): void
    {
        foreach ([
            'https://evil.example/login',
            '//evil.example/login',                 // "same scheme, other site"
            '/\\evil.example/login',                // a browser reads \ as /
            'http://baja.local.evil.example/',      // our name as a prefix of theirs
            'http://evilbaja.local/',               // our name as a suffix of theirs, without the dot
            'http://juiz.baja.local@evil.example/', // the part before @ is a user name
            "/\tevil",                              // control characters, which browsers drop
            'javascript:alert(1)',
        ] as $target) {
            $response = $this->browser->request('GET', self::FORUM . '/app.php/baja/resume?redirect=' . rawurlencode($target));
            $this->assertSame(302, $response['status'], $target);
            $this->assertSame(self::DEFAULT_REDIRECT, $response['location'], $target);
        }
    }

    /** No cache in between may keep these: each answer carries cookies for one browser. */
    public function testNothingIsCached(): void
    {
        foreach (['/app.php/baja/resume?redirect=' . rawurlencode(self::JUIZ . '/index.php'), '/app.php/baja/keepalive'] as $path) {
            $headers = $this->headersOf(self::FORUM . $path);
            $this->assertMatchesRegularExpression('/^cache-control:.*no-store/mi', $headers, $path);
        }
    }

    /** The keep-alive answers with nothing at all, and sends the browser nowhere. */
    public function testTheKeepAliveRedirectsNowhere(): void
    {
        $response = $this->browser->request('GET', self::FORUM . '/app.php/baja/keepalive?redirect=' . rawurlencode('https://evil.example/'));
        $this->assertSame(204, $response['status']);
        $this->assertSame('', $response['location']);
        $this->assertSame('', $response['body']);
    }

    private function headersOf(string $url): string
    {
        $parts = parse_url($url);
        $curl = curl_init('http://nginx' . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : ''));
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Host: ' . $parts['host']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = (string)curl_exec($curl);
        $size = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        return substr($raw, 0, $size);
    }
}
