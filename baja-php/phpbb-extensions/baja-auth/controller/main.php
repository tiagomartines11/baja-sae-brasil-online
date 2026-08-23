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
 * content, and rebuilding the image alone won't propagate changes. See
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
     * Cloudflare challenge warm-up. Deliberately does nothing but validate
     * and bounce.
     *
     * Its value is entirely in being a plain GET on the forum origin. The
     * forum is behind a Managed Challenge, and Cloudflare cannot replay a
     * POST body through one — but it replays a GET intact. Sending the user
     * here first means any captcha is solved BEFORE they type a password, and
     * they return to the login form holding cf_clearance, so the credential
     * -carrying POST is never challenged. See
     * baja-php/src/Baja/Auth/ChallengeWarmup.php.
     */
    public function warmup(): Response
    {
        return new RedirectResponse(
            $this->validateRedirect($this->request->variable('redirect', ''))
        );
    }

    public function login(): Response
    {
        // `redirect` travels in the QUERY STRING of the form action, not as a
        // POST field, precisely so it survives a Cloudflare challenge replay
        // (which discards the body but keeps the URL). Resolve the target
        // first so the no-credentials path below has somewhere to send the
        // user. request->variable() reads GET and POST alike.
        $redirect = $this->request->variable('redirect', '');
        $target   = $this->validateRedirect($redirect);

        // No POSTed username means this is not a real submission. In practice
        // it is Cloudflare replaying a challenged login POST as a bodyless
        // GET. This used to be an unstyled 405 — and before the route
        // accepted GET at all, it was phpBB's 404 page on the forum domain,
        // which is what stranded users mid-login.
        //
        // Bounce back to the form instead. cf_clearance exists by now, so the
        // retry goes straight through. Note this checks is_set_post, not the
        // merged value: a genuine POST with an empty username still has the
        // key present and correctly falls through to the 'missing' branch.
        if (!$this->request->is_set_post('username')) {
            return new RedirectResponse($this->appendError($target, 'challenge'));
        }

        // POST scope explicitly. request->variable() defaults to REQUEST, which
        // merges the query string — so without this a password could be sourced
        // from a URL (landing it in access logs and Referer headers), and
        // autologin could be switched on by a query parameter neither login
        // form offers. `redirect` above deliberately stays on REQUEST: it has
        // to survive a Cloudflare challenge replay, which keeps only the URL.
        $username  = $this->request->variable('username', '', true, request_interface::POST);
        $password  = $this->request->variable('password', '', true, request_interface::POST);
        $autologin = (bool) $this->request->variable('autologin', 0, false, request_interface::POST);

        if ($username === '' || $password === '') {
            return new RedirectResponse($this->appendError($target, 'missing'));
        }

        $result = $this->auth->login($username, $password, $autologin, true, false);
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

        // session_kill destroys the session row and rotates phpBB's cookies
        // (_u → 1 anonymous, _sid cleared). session_begin re-initialises an
        // anonymous session so any subsequent code on the response path has
        // a valid $user object to work with.
        $this->user->session_kill();
        $this->user->session_begin();

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

    private function appendError(string $target, string $code): string
    {
        $sep = str_contains($target, '?') ? '&' : '?';
        return $target . $sep . 'error=' . urlencode($code);
    }

    private function mapLoginError(int $status): string
    {
        return match ($status) {
            LOGIN_ERROR_USERNAME => 'unknown_user',
            LOGIN_ERROR_PASSWORD => 'bad_password',
            LOGIN_ERROR_ATTEMPTS => 'too_many_attempts',
            default              => 'unknown',
        };
    }
}
