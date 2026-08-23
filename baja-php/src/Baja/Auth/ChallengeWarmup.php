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

        // Carry the error code through the bounce so the user still gets told
        // why their attempt was dropped, rather than silently facing a blank
        // form with their credentials gone.
        $back = Url::subdomain($subdomain, $loginPath . '?warmed=1' . ($lapsed ? '&error=challenge' : ''));

        header('Location: ' . Url::forum('/app.php/baja/warmup?redirect=' . urlencode($back)));
        exit();
    }

    private static function mark(): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE, '1', [
            'expires'  => time() + self::TTL,
            'path'     => '/',
            'domain'   => '.' . Url::domain(),
            'secure'   => Url::scheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
