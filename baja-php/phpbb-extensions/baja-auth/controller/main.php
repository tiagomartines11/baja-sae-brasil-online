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

        $username = $this->request->variable('username', '', true);
        $password = $this->request->variable('password', '', true);
        $autologin = (bool) $this->request->variable('autologin', 0);

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
