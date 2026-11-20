<?php
declare(strict_types=1);

namespace Baja\Auth;

use Baja\Url;

/**
 * Keeps the judge area logged in for as long as the forum is.
 *
 * Two things went wrong before this existed (BAJAAPP-86):
 *
 *   - A judge who had ticked "remember me" on the forum was shown the judge
 *     area's login form once the forum session row expired, while the forum
 *     itself still showed them logged in. The forum resumes a session from the
 *     {prefix}_k cookie on its next page; the judge area only reads the
 *     session row, and could not. Now a page that needs a login and finds
 *     none, but sees that cookie, sends the browser once through the forum's
 *     /app.php/baja/resume, where phpBB resumes the session and bounces back
 *     to the same page (bounceIfPossible).
 *
 *   - A judge who only used the judge area was logged out of the forum after
 *     session_length, mid-event: nothing here updated the row's session_time,
 *     and our MySQL user may not. Now a juiz page whose session row is a few
 *     minutes old asks the forum's /app.php/baja/keepalive for it, from the
 *     browser, and phpBB writes it (keepAliveTag).
 *
 * The loop guard is a cookie, MARKER, set on the way out to the forum and
 * cleared on the first page that has a session again. If the browser comes
 * back with it and still no session, the page shows the login form as it
 * always has, instead of bouncing again. A cookie rather than a query
 * parameter so that the URL the judge lands on, and may bookmark, is the one
 * they asked for.
 *
 * When does the bounce come back without a session? When the key was
 * revoked, expired or deleted on logout, phpBB answers with an anonymous
 * session and _u=1, so the next page would not bounce again anyway; the
 * marker is for the cases where phpBB cannot answer at all, or its cookies
 * do not stick: the forum down or behind a challenge the browser did not
 * pass, cookies blocked, a cookie_domain that does not cover this host.
 */
final class ForumResume
{
    /** The loop-guard cookie. Host-only: the forum never needs to see it. */
    public const MARKER = 'baja_resume';

    /**
     * Long enough for the round trip through the forum, including a
     * Cloudflare challenge on the forum host; short enough that a judge who
     * gives up on the login form gets the bounce again on their next visit.
     */
    private const MARKER_SECONDS = 120;

    /**
     * For a page that needs a login and found none: send the browser to the
     * forum to resume the session, when that can work and was not just tried.
     * Returns without doing anything otherwise, and the caller shows the
     * login form. Before any output.
     */
    public static function bounceIfPossible(): void
    {
        $shim = $GLOBALS['user'] ?? null;
        if (!$shim instanceof PhpbbSessionShim || headers_sent()) {
            return;
        }
        $decision = $shim->resumeDecision(isset($_COOKIE[self::MARKER]), self::isNavigation($_SERVER));
        if ($decision !== PhpbbSessionShim::RESUME) {
            return;
        }
        $here = self::currentUrl($_SERVER, Url::scheme(), Url::domain());
        if ($here === null) {
            return;
        }
        self::writeMarker('1', time() + self::MARKER_SECONDS);
        header('Location: ' . self::resumeUrl($here));
        exit();
    }

    /**
     * Clears the loop guard once there is a session again. Every page with a
     * session runs it (src/phpbb_login.php), so the next expiry gets its
     * bounce. Before any output.
     */
    public static function settle(PhpbbSessionShim $shim): void
    {
        if (isset($_COOKIE[self::MARKER]) && $shim->data['username'] !== '') {
            self::writeMarker('', 1);
        }
    }

    /**
     * The keep-alive for a juiz page's <head>: a request to the forum that
     * makes phpBB refresh the session row, or '' when it is fresh enough
     * (PhpbbSessionShim::KEEPALIVE_AFTER_SECONDS). Fire and forget: the
     * response cannot be read from here (another origin) and is not needed.
     */
    public static function keepAliveTag(): string
    {
        $shim = $GLOBALS['user'] ?? null;
        if (!$shim instanceof PhpbbSessionShim || !$shim->keepAliveNeeded(time())) {
            return '';
        }
        $shim->forgetCachedSession();
        $url = json_encode(Url::forum('/app.php/baja/keepalive'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        // credentials "include" sends the forum's cookies; mode "no-cors"
        // because the forum sends no CORS headers and nothing is read back.
        return '<script>if (window.fetch) { fetch(' . $url . ', {mode: "no-cors", credentials: "include", cache: "no-store", keepalive: true}).catch(function () {}); }</script>';
    }

    /** The forum address that resumes the session and comes back to $here. */
    public static function resumeUrl(string $here): string
    {
        return Url::forum('/app.php/baja/resume?redirect=' . rawurlencode($here));
    }

    /**
     * Whether the request is the browser opening a page. Pure.
     *
     * GET or HEAD, and not a script's request: jQuery marks its own with
     * X-Requested-With, and browsers mark every request with Sec-Fetch-Mode,
     * which is "navigate" only for opening a page. A browser too old to send
     * Sec-Fetch-Mode counts as navigating; a script's request through the
     * bounce fails no worse than the login page it would get otherwise.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function isNavigation(array $server): bool
    {
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }
        if (strtolower((string)($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return false;
        }
        $mode = strtolower((string)($server['HTTP_SEC_FETCH_MODE'] ?? ''));
        return $mode === '' || $mode === 'navigate';
    }

    /**
     * The address of this request, to come back to; null when the Host is not
     * one of ours. Pure. The forum's validator refuses foreign hosts too, but
     * it would answer with its default page rather than this one.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function currentUrl(array $server, string $scheme, string $domain): ?string
    {
        $host = strtolower((string)($server['HTTP_HOST'] ?? ''));
        $uri  = (string)($server['REQUEST_URI'] ?? '');
        $name = (string)preg_replace('/:\d+$/', '', $host);
        $ours = $domain !== '' && ($name === $domain || str_ends_with($name, '.' . $domain));
        if (!$ours || preg_match('/^[a-z0-9.:-]+$/', $host) !== 1 || !str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            return null;
        }
        return $scheme . '://' . $host . $uri;
    }

    private static function writeMarker(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::MARKER, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => Url::scheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
