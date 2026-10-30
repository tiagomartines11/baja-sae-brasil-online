<?php
declare(strict_types=1);

namespace Baja\Tests\Unit;

use Baja\Url;
use PHPUnit\Framework\TestCase;

/**
 * The scheme of every link the app builds, and of every cookie's Secure flag.
 *
 * Both come from LISTEN_MODE, the same variable that picks nginx's listen
 * block (baja-infra/nginx/templates/snippets/):
 *
 *   listen-http     dev, plain HTTP                  -> http
 *   listen-tls      production, nginx holds the TLS  -> https
 *   listen-proxied  staging, Caddy holds the TLS and
 *                   talks plain HTTP to nginx        -> https
 *
 * listen-proxied is the case worth a test: nginx itself is on http there, and
 * a link or cookie that followed nginx instead of the visitor would be wrong.
 */
final class UrlTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv('LISTEN_MODE');
    }

    protected function tearDown(): void
    {
        putenv($this->saved === false ? 'LISTEN_MODE' : 'LISTEN_MODE=' . $this->saved);
    }

    /** @return array<string, array{?string, string}> */
    public static function modes(): array
    {
        return [
            'dev'                  => ['listen-http', 'http'],
            'production'           => ['listen-tls', 'https'],
            'staging behind Caddy' => ['listen-proxied', 'https'],
            'unset'                => [null, 'http'],
            'unknown value'        => ['listen-something', 'http'],
        ];
    }

    /** @dataProvider modes */
    public function testTheSchemeFollowsTheListenMode(?string $mode, string $scheme): void
    {
        putenv($mode === null ? 'LISTEN_MODE' : 'LISTEN_MODE=' . $mode);
        $this->assertSame($scheme, Url::scheme());
    }

    public function testLinksBehindCaddyAreHttps(): void
    {
        putenv('LISTEN_MODE=listen-proxied');
        $this->assertStringStartsWith('https://certificado.', Url::subdomain('certificado', '/buscar'));
        $this->assertStringStartsWith('https://', Url::apex('/'));
        $this->assertStringStartsWith('https://forum.', Url::forum('/'));
    }

    /**
     * Every cookie the app sets takes its Secure flag from Url::scheme(), so
     * the test above covers all of them. A setcookie() that decided it some
     * other way (from $_SERVER['HTTPS'], say, which is empty behind Caddy)
     * would send the staff or login cookie without Secure on staging.
     */
    public function testEveryCookieTakesSecureFromTheScheme(): void
    {
        $root = dirname(__DIR__, 2);
        $found = 0;
        foreach (['src', 'juiz', 'resultados', 'fila', 'certificado', 'api'] as $dir) {
            if (!is_dir("$root/$dir")) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir"));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (!str_ends_with($path, '.php') || str_contains($path, '/Model/Base/') || str_contains($path, '/Model/Map/')) {
                    continue;
                }
                $code = (string)file_get_contents($path);
                // A call, not the word in a comment: "setcookie(" followed by
                // its arguments up to the closing "]);" of the options array.
                if (!preg_match_all('/\bsetcookie\(\s*[^)\s][^;]*;/s', $code, $calls)) {
                    continue;
                }
                foreach ($calls[0] as $call) {
                    $found++;
                    $this->assertStringContainsString(
                        "'secure' => Url::scheme() === 'https'",
                        (string)preg_replace('/\s+/', ' ', $call),
                        "$path sets a cookie whose Secure flag does not come from Url::scheme()"
                    );
                }
            }
        }
        $this->assertGreaterThanOrEqual(2, $found, 'expected at least the staff and login-csrf cookies');
    }
}
