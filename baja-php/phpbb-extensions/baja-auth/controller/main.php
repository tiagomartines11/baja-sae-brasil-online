<?php
/**
 * ============================================================================
 * THIS FILE IS BAKED INTO THE phpbb-baja CONTAINER AT BUILD TIME.
 * ============================================================================
 * Editing this file does NOT take effect in a running container.
 *
 * To apply changes:
 *     cd baja-infra && docker compose down -v && docker compose build phpbb-baja && docker compose up -d
 *
 * The down -v is required: the phpbb_baja_html volume retains its initial
 * content, and rebuilding the image alone won't propagate changes. Changing a
 * ROUTE additionally needs phpBB's compiled router rebuilt — see the note in
 * config/routing.yml. Both traps have bitten production. See
 * baja-php/docs/baja-auth-extension.md "Operating notes" for context.
 * ============================================================================
 */

namespace baja\auth\controller;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\request\request;
use phpbb\request\request_interface;
use phpbb\user;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class main
{
    /** Must match Baja\Auth\LoginCsrf::COOKIE on the baja-app side. */
    private const CSRF_COOKIE = 'baja_csrf';

    private auth $auth;
    private user $user;
    private config $config;
    private request $request;

    public function __construct(auth $auth, user $user, config $config, request $request)
    {
        $this->auth    = $auth;
        $this->user    = $user;
        $this->config  = $config;
        $this->request = $request;
    }

    /**
     * Validated no-op redirect. Exists only so forumLoginUrl() has a
     * board-relative target to hand phpBB; see the note in routing.yml.
     */
    public function returnTo(): Response
    {
        return new RedirectResponse(
            $this->validateRedirect($this->request->variable('redirect', ''))
        );
    }

    public function login(): Response
    {
        // `redirect` lives in the query string of the form action rather than
        // the POST body, so read it from the merged scope. It is not user
        // input — the login pages build it — and validateRedirect() gates it
        // regardless.
        $redirect = $this->request->variable('redirect', '');
        $target   = $this->validateRedirect($redirect);

        // Two independent CSRF checks. Without them an attacker's page can
        // auto-submit THEIR credentials and silently log a judge into the
        // attacker's account; results are then entered under attacker control.
        //
        // Origin first, because it is the cheap one and needs nothing from the
        // form. This POST is always cross-origin (juiz./fila. -> forum.), so
        // browsers always send Origin, which makes failing closed on a missing
        // header safe here in a way it would not be for a same-origin form.
        if (!$this->originAllowed()) {
            return new RedirectResponse($this->appendError($target, 'csrf'));
        }

        // Then the double-submit token, which does not depend on a header at
        // all. Planted as a cookie on the parent domain by Baja\Auth\LoginCsrf
        // and echoed in the form; an attacker can neither read the cookie nor
        // guess the value, so it cannot produce a matching pair. hash_equals
        // keeps the comparison constant-time.
        $cookieToken = $this->request->variable(self::CSRF_COOKIE, '', false, request_interface::COOKIE);
        $formToken   = $this->request->variable('csrf', '', false, request_interface::POST);
        if ($cookieToken === '' || !hash_equals($cookieToken, $formToken)) {
            return new RedirectResponse($this->appendError($target, 'csrf'));
        }

        // POST scope explicitly. request->variable() defaults to REQUEST, which
        // merges the query string — so without this a password could be sourced
        // from a URL (landing it in access logs and Referer headers), and
        // autologin could be switched on by a query parameter neither login
        // form offers. `redirect` above deliberately stays on REQUEST, since
        // the form action carries it in the query string.
        $username  = $this->request->variable('username', '', true, request_interface::POST);
        $password  = $this->request->variable('password', '', true, request_interface::POST);
        $autologin = (bool) $this->request->variable('autologin', 0, false, request_interface::POST);

        if ($username === '' || $password === '') {
            return new RedirectResponse($this->appendError($target, 'missing'));
        }

        $result = $this->auth->login($username, $password, $autologin, true, false);

        // LOGIN_ERROR_ATTEMPTS is a dead end on this route, not an error the
        // user can act on. phpBB gates $auth->login() behind a CAPTCHA once
        // user_login_attempts reaches max_login_attempts, and that gate runs
        // BEFORE the password check — so from here the correct password is
        // refused too. The counter has no time-based expiry: it clears only on
        // a successful login or a password reset. This route cannot present a
        // CAPTCHA, so telling the user to wait would be a lie and they would
        // never get back in.
        //
        // The forum's own login form CAN show it, and succeeding there resets
        // the counter and sets the very session cookies the shim reads. So send
        // them there instead: they solve it once and are logged in.
        if ((int) $result['status'] === LOGIN_ERROR_ATTEMPTS) {
            return new RedirectResponse($this->forumLoginUrl($target));
        }

        if ($result['status'] !== LOGIN_SUCCESS) {
            return new RedirectResponse($this->appendError($target, $this->mapLoginError((int) $result['status'])));
        }

        // phpBB's $auth->login → session_create has already set the session
        // cookies on .baja.local (per phpbb_config.cookie_domain) by this
        // point. Just bounce the user to the validated target.
        return new RedirectResponse($target);
    }

    public function logout(): Response
    {
        $redirect = $this->request->variable('redirect', '');
        $target   = $this->validateRedirect($redirect);

        // Logout is CSRF-relevant in the other direction: this route answers to
        // GET, so <img src=".../baja/logout"> on any page the judge visits logs
        // them out mid-event. The Origin check cannot help — a top-level GET
        // navigation carries no Origin — so the token is the whole defence
        // here, and it rides in the query string that Session::endSession builds.
        //
        // Failing closed means doing nothing rather than refusing loudly: a
        // forged logout becomes a no-op redirect, while a genuine one always
        // carries the token. Kill the session only once the token matches.
        $cookieToken = $this->request->variable(self::CSRF_COOKIE, '', false, request_interface::COOKIE);
        $urlToken    = $this->request->variable('csrf', '');
        if ($cookieToken !== '' && hash_equals($cookieToken, $urlToken)) {
            // session_kill destroys the session row and rotates phpBB's cookies
            // (_u → 1 anonymous, _sid cleared). session_begin re-initialises an
            // anonymous session so any subsequent code on the response path has
            // a valid $user object to work with.
            $this->user->session_kill();
            $this->user->session_begin();
        }

        return new RedirectResponse($target);
    }

    /**
     * Validates a redirect target against the configured allowed-domain
     * suffix. Falls back to the configured default URL if the input is
     * empty, malformed, or off-domain. Both config values come from
     * phpbb_config (admin-editable) and are seeded from env vars on
     * container boot — see phpbb-baja/entrypoint.sh.
     */
    private function validateRedirect(?string $url): string
    {
        $allowedSuffix = (string) $this->config['baja_auth_allowed_domain_suffix'];
        $default       = (string) $this->config['baja_auth_default_redirect'];

        // Defense in depth: if either config row is unset (migration did not
        // run, ACP wipe, etc.) `str_ends_with($host, '')` would return true
        // for every host and open the gate. Refuse to validate anything in
        // that state; return a hardcoded safe relative path.
        if ($allowedSuffix === '') {
            return $default !== '' ? $default : '/';
        }

        if ($url === null || $url === '') {
            return $default !== '' ? $default : '/';
        }
        // Backslashes and control characters are rejected before anything else
        // because browsers rewrite them and this validator does not.
        //
        // Per the WHATWG URL spec a backslash is treated as a forward slash in
        // special-scheme URLs, so "/\evil.com" satisfies the "starts with /
        // but not //" test below, is returned verbatim, and then navigates the
        // browser to http://evil.com/. Browsers also strip tab, newline and
        // other C0 controls before parsing, which can smuggle a second slash
        // past that same test.
        //
        // Neither ever appears in a target we generate, so reject outright
        // rather than trying to normalise the way each browser would.
        // str_contains for the backslash and a POSIX class for the controls,
        // rather than one regex with \\ and \x escapes: in a single-quoted PHP
        // string those compose into something PCRE rejects outright, and a
        // failed preg_match returns false, so the guard would silently fail
        // open. Both checks below are unambiguous at a glance.
        if (str_contains($url, '\\') || preg_match('/[[:cntrl:]]/', $url) === 1) {
            return $default !== '' ? $default : '/';
        }
        // Allow site-relative paths but reject protocol-relative ("//evil.com/x").
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $default !== '' ? $default : '/';
        }
        $host = strtolower($parts['host']);
        $suffixHost = ltrim($allowedSuffix, '.');
        if ($host === $suffixHost || str_ends_with($host, $allowedSuffix)) {
            return $url;
        }
        return $default !== '' ? $default : '/';
    }

    /**
     * True when the request's Origin is one we serve the login form from.
     *
     * Fails closed on a missing header. That is safe specifically because this
     * POST is always cross-origin — the form lives on juiz./fila. and posts to
     * forum. — and browsers always send Origin on a cross-origin POST. The same
     * choice on a same-origin form would break clients that omit it.
     *
     * Deliberately reuses baja_auth_allowed_domain_suffix rather than adding a
     * second list: the set of hosts allowed to submit the form and the set
     * allowed as a redirect target are the same set, and letting them drift
     * apart is how one of them ends up wrong.
     */
    private function originAllowed(): bool
    {
        $allowedSuffix = (string) $this->config['baja_auth_allowed_domain_suffix'];
        if ($allowedSuffix === '') {
            return false;
        }

        $origin = trim($this->request->header('Origin'));
        if ($origin === '') {
            return false;
        }

        $parts = parse_url($origin);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }

        $host       = strtolower($parts['host']);
        $suffixHost = ltrim($allowedSuffix, '.');

        return $host === $suffixHost || str_ends_with($host, $allowedSuffix);
    }

    /**
     * The forum's own login form, carrying the user back to $target afterwards.
     *
     * Relative on purpose: this controller runs on the forum host, and phpBB's
     * redirect() rejects off-board targets, so an absolute juiz./fila. URL in
     * its `redirect` parameter would be discarded and the user dumped on the
     * board index — which is what a judge clearing a lockout would hit.
     *
     * Pointing it at our own /baja/return keeps the target board-relative,
     * which phpBB accepts, and that route revalidates $target through
     * validateRedirect() and bounces there.
     */
    private function forumLoginUrl(string $target): string
    {
        $back = './app.php/baja/return?redirect=' . urlencode($target);

        return '/ucp.php?mode=login&redirect=' . urlencode($back);
    }

    private function appendError(string $target, string $code): string
    {
        $sep = str_contains($target, '?') ? '&' : '?';
        return $target . $sep . 'error=' . urlencode($code);
    }

    private function mapLoginError(int $status): string
    {
        // "No such user" and "wrong password" deliberately collapse to one
        // code. Distinguishing them is a username oracle: an attacker learns
        // which names are real by reading the redirect URL, without ever
        // guessing a password. That composes badly with the lockout, which is
        // still reachable — enumerate first, then lock those accounts out with
        // three requests each.
        //
        // Collapsed HERE rather than in the login pages' message map, so the
        // distinction is absent from the ?error= code in the URL too. Mapping
        // two codes to one string would still leak it to anyone reading the
        // address bar.
        //
        // Note this only closes the oracle on OUR form; phpBB's own login page
        // still distinguishes the two, so an attacker willing to use the forum
        // directly can still enumerate. Closing that means changing phpBB.
        return match ($status) {
            LOGIN_ERROR_USERNAME,
            LOGIN_ERROR_PASSWORD => 'bad_credentials',
            LOGIN_ERROR_ATTEMPTS => 'too_many_attempts',
            default              => 'unknown',
        };
    }
}
