<?php
declare(strict_types=1);

namespace Baja\Auth;

use Baja\Url;

/**
 * CSRF protection for the login and logout forms.
 *
 * Why not Baja\Certificado\Insercao\Csrf
 * --------------------------------------
 * That helper derives its token from the phpBB session id, and deliberately
 * returns '' when there is no session. Login is pre-session by definition —
 * the whole point of the request is to create one — so there is no secret to
 * key an HMAC on yet. Hence a different mechanism here rather than a shared
 * one: this is double-submit, not derive-from-session.
 *
 * How it works
 * ------------
 * A random value goes into a cookie on the parent domain, so the forum (where
 * the baja/auth controller runs) receives it, and the same value is rendered
 * into the form. The controller requires the two to match.
 *
 * A cross-site attacker can neither read our cookie nor guess 32 random bytes,
 * so their forged form cannot produce a matching pair. The cookie is httponly:
 * nothing in the browser needs to read it, because the value is rendered
 * server-side.
 *
 * Ordering
 * --------
 * start() MUST be called before any output. setcookie() is a silent no-op once
 * headers are sent, and a form carrying a token whose cookie was never sent
 * means every genuine login is refused as a forgery — with nothing in the logs
 * to say why. That exact bug reached a fully green test suite once already, so
 * token() refuses to invent a value it cannot back with a cookie rather than
 * repeating it quietly.
 */
final class LoginCsrf
{
    public const FIELD = 'csrf';

    /** Must match main::CSRF_COOKIE in the baja/auth extension. */
    private const COOKIE = 'baja_csrf';

    private static ?string $token = null;

    /**
     * Establish the token. Call at the top of a login page, before output.
     *
     * Reuses whatever is already in the jar so every tab of the same browser
     * agrees; regenerating per page load would invalidate a form left open in
     * a second tab.
     */
    public static function start(): void
    {
        if (self::$token !== null) {
            return;
        }

        $existing = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($existing) && preg_match('/^[a-f0-9]{64}$/', $existing) === 1) {
            self::$token = $existing;
            return;
        }

        $token = bin2hex(random_bytes(32));

        if (headers_sent()) {
            // Cannot back this with a cookie, so do not hand out a token that
            // is guaranteed to fail validation. Leaving it unset makes the
            // mistake visible at the call site instead of turning into a
            // mysterious rejected login.
            return;
        }

        setcookie(self::COOKIE, $token, [
            'expires'  => 0,
            'path'     => '/',
            'domain'   => '.' . Url::domain(),
            'secure'   => Url::scheme() === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        self::$token = $token;
    }

    /**
     * The token for the form or the logout URL.
     *
     * Calls start() itself so a caller that only needs the value cannot forget
     * it — but that only helps while headers are still open, which is why
     * login pages call start() explicitly at the top.
     */
    public static function token(): string
    {
        if (self::$token === null) {
            self::start();
        }

        return self::$token ?? '';
    }

    /** The hidden input, ready to drop into a form. */
    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
        );
    }
}
