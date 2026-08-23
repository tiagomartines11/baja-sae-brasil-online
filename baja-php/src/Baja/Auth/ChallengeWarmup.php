<?php
declare(strict_types=1);

namespace Baja\Auth;

use Baja\Url;

/**
 * Cloudflare challenge warm-up for the cross-origin login POST.
 *
 * The problem
 * -----------
 * The login forms live on juiz./fila. but POST their credentials to the
 * forum origin, which sits behind a Cloudflare Managed Challenge. Cloudflare
 * cannot replay a POST body through a challenge: it serves the interstitial,
 * and once the captcha is solved it re-issues the ORIGINAL request as a
 * bodyless GET. The username and password are destroyed in transit, and the
 * user lands on a forum URL with no session — historically phpBB's own 404
 * page, because /baja/login only accepted POST.
 *
 * The fix
 * -------
 * A GET, unlike a POST, survives a challenge replay intact. So we spend one
 * harmless GET navigation on the forum origin BEFORE the user types anything.
 * Any captcha is presented there; solving it issues cf_clearance for the
 * forum host, and the warm-up endpoint bounces straight back to the login
 * form. The real login POST then sails through unchallenged.
 *
 * Why the clearance carries across subdomains: juiz., fila. and forum. all
 * share the registrable domain, so the login POST is same-site (cross-origin,
 * but same-site). cf_clearance is therefore sent even under SameSite=Lax,
 * which only withholds cookies on cross-SITE requests.
 *
 * The marker cookie is set on the parent domain because a single cf_clearance
 * on the forum host covers every baja subdomain — warming up from juiz also
 * warms up fila, so there is no reason to pay for the round trip twice.
 */
final class ChallengeWarmup
{
    private const COOKIE = 'baja_cf_warm';

    /** Double-submit CSRF token; see csrfToken(). Session cookie, no TTL. */
    private const CSRF_COOKIE = 'baja_csrf';

    private static ?string $csrfToken = null;

    /**
     * Deliberately shorter than Cloudflare's 30-minute cf_clearance default.
     * Re-warming slightly early costs one redirect that Cloudflare answers
     * without a captcha; re-warming late costs the user a discarded login.
     */
    private const TTL = 1200;

    /**
     * Call at the top of a login page, before any output.
     *
     * @param string $subdomain Subdomain the login form lives on ('juiz', 'fila').
     * @param string $loginPath Path of that login page.
     */
    public static function ensure(string $subdomain, string $loginPath = '/login.php'): void
    {
        // Mint the CSRF token here, at the top, while headers are still open.
        //
        // The login form embeds it much further down — after printHeader() has
        // already flushed output — and setcookie() is a silent no-op once
        // headers are sent. Minting it there produced a form carrying a token
        // whose cookie was never sent, so every genuine login was rejected as
        // a forgery. The static cache then hands the form the same value we
        // set here.
        self::csrfToken();

        // Returning from the forum. Record the marker and let the caller
        // render the form.
        //
        // This branch MUST NOT redirect under any circumstance. If the browser
        // refuses cookies the marker can never stick, and bouncing a second
        // time would put the user in an infinite redirect loop between the two
        // origins. Arriving back here is proof the warm-up ran, which is all we
        // require to proceed.
        if (isset($_GET['warmed'])) {
            self::mark();

            // Surface why we bounced, through the channel the login pages
            // already read. This rides in the `warmed` value itself rather
            // than as a second &error= pair because phpBB runs string request
            // variables through htmlspecialchars: an '&' inside the redirect
            // target comes back as '&amp;' and the browser would parse it as
            // a junk 'amp;error' parameter, silently dropping the message.
            if (($_GET['warmed'] ?? '') === 'challenge') {
                $_GET['error'] = 'challenge';
            }

            return;
        }

        // A 'challenge' code means the login POST reached /baja/login with no
        // body — clearance lapsed while the form sat open. The marker cookie
        // may well still be valid, so it cannot be trusted here; force a
        // re-warm regardless of what it says.
        $lapsed = (($_GET['error'] ?? '') === 'challenge');

        if (isset($_COOKIE[self::COOKIE]) && !$lapsed) {
            return;
        }

        // Carry the reason through the bounce so the user still gets told why
        // their attempt was dropped, rather than silently facing a blank form
        // with their credentials gone. Single parameter, no '&' — see the
        // note in the warmed branch above.
        $back = Url::subdomain($subdomain, $loginPath . '?warmed=' . ($lapsed ? 'challenge' : '1'));

        header('Location: ' . Url::forum('/app.php/baja/warmup?redirect=' . urlencode($back)));
        exit();
    }

    /**
     * The browser half of the login/logout CSRF defence (double-submit).
     *
     * The token is written to a cookie on the parent domain — so the forum,
     * where the baja/auth controller runs, receives it — and the same value is
     * embedded in the login form and the logout URL. The controller then
     * requires the two to match.
     *
     * That defeats a cross-site forgery because the attacker's page can
     * neither read our cookie (different origin) nor guess 32 random bytes, so
     * it cannot produce a matching pair. The cookie stays httponly: nothing in
     * the browser needs to read it, since the value is rendered server-side.
     *
     * Reuses the token already in the jar when there is one, so every tab of
     * the same browser agrees. Regenerating per page load would break a form
     * left open in a second tab.
     *
     * The static cache matters on the request that first mints the token:
     * setcookie() only affects the NEXT request, so $_COOKIE is still empty
     * here and re-reading it would embed a different value than the one the
     * browser was just handed.
     */
    public static function csrfToken(): string
    {
        if (self::$csrfToken !== null) {
            return self::$csrfToken;
        }

        $existing = $_COOKIE[self::CSRF_COOKIE] ?? '';
        if (is_string($existing) && preg_match('/^[a-f0-9]{64}$/', $existing) === 1) {
            return self::$csrfToken = $existing;
        }

        $token = bin2hex(random_bytes(32));
        self::putCookie(self::CSRF_COOKIE, $token, 0);

        return self::$csrfToken = $token;
    }

    private static function mark(): void
    {
        self::putCookie(self::COOKIE, '1', time() + self::TTL);
    }

    /** @param int $expires 0 for a session cookie. */
    private static function putCookie(string $name, string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => '.' . Url::domain(),
            'secure'   => Url::scheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
