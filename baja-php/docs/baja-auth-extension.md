# baja/auth phpBB Extension

**Status:** implemented (WP5). Lives at [phpbb-extensions/baja-auth/](../phpbb-extensions/baja-auth/),
baked into the `baja-phpbb-baja` image, enabled automatically on container
boot.

Provides three endpoints under `forum.baja.local/app.php/baja/...` so the
baja-app (juiz, fila, etc.) can drive cross-subdomain login and logout
through phpBB's own auth pipeline:

| Route | Method | Purpose |
|---|---|---|
| `/baja/login` | POST | Authenticate via `$auth->login()`. On success, phpBB sets session cookies on `.baja.local`; the baja-app shim picks them up on the next request. |
| `/baja/logout` | GET, POST | `$user->session_kill()` followed by `session_begin()` (anonymous re-init), then redirect. Requires the CSRF token. |
| `/baja/return` | GET | Validated no-op redirect. A return hop out of phpBB's own login form, which discards off-board redirect targets. |

Both accept an optional `redirect` parameter validated against a
configured allowed-domain suffix; off-domain targets are replaced with a
configured default URL.

## Why an extension lives in the forum container

The baja-app shim at `baja-php/src/Baja/Auth/` is read-only against `phpbb_sessions`. It can verify a user is logged
in by reading the session row that phpBB wrote, but it cannot create or
destroy a session itself — that requires writing to the table, setting
cookies on the right path, and updating user-tracking fields like
`user_lastvisit`. Reimplementing all of that on the baja side would
duplicate phpBB's auth pipeline (rate-limit tracking, autologin keys,
cookie domain handling, etc.). Routing the writes through phpBB's own
controllers keeps a single source of truth for session lifecycle.

## Files

| File | Role |
|---|---|
| [composer.json](../phpbb-extensions/baja-auth/composer.json) | Standard phpBB extension manifest. |
| [ext.php](../phpbb-extensions/baja-auth/ext.php) | Empty extension class — default lifecycle hooks are sufficient. |
| [config/routing.yml](../phpbb-extensions/baja-auth/config/routing.yml) | Symfony route definitions for `/baja/login`, `/baja/logout` and `/baja/return`. |
| [config/services.yml](../phpbb-extensions/baja-auth/config/services.yml) | DI wiring for the controller (`@auth`, `@user`, `@config`, `@request`). |
| [controller/main.php](../phpbb-extensions/baja-auth/controller/main.php) | All three endpoints + `validateRedirect()` + `mapLoginError()`. |
| [../src/Baja/Auth/LoginCsrf.php](../src/Baja/Auth/LoginCsrf.php) | baja-app side of the CSRF pair: plants the `baja_csrf` cookie before output and renders the matching hidden field. |
| [migrations/v100/install_baja_auth_config.php](../phpbb-extensions/baja-auth/migrations/v100/install_baja_auth_config.php) | Declares the two `phpbb_config` rows the controller reads (cleaned up on extension purge). Initial values come from env vars at install time. |
| [language/en/common.php](../phpbb-extensions/baja-auth/language/en/common.php) | Required-but-empty stub. The controller never renders translatable strings. |

## Configuration

The redirect validator reads two `phpbb_config` rows. They are seeded
into the table by the install migration on first `extension:enable`, and
refreshed from env vars on every container boot via `phpbbcli config:set`
([phpbb-baja/entrypoint.sh](../../baja-infra/phpbb-baja/entrypoint.sh)). Env vars are
the source of truth; `phpbb_config` is the cached form that survives
container restarts and is editable via the ACP if needed.

| Env var | `phpbb_config` row | Dev value | Prod value |
|---|---|---|---|
| `BAJA_AUTH_ALLOWED_DOMAIN_SUFFIX` | `baja_auth_allowed_domain_suffix` | `.baja.local` | `.bajasaebrasil.net` |
| `BAJA_AUTH_DEFAULT_REDIRECT` | `baja_auth_default_redirect` | `http://baja.local/` | `https://bajasaebrasil.net/` |

## Redirect validation

`controller/main.php::validateRedirect()` accepts a target URL only when:

1. It is empty/null (returns the configured default).
2. It is a site-relative path starting with `/` and not `//` (rejects
   protocol-relative `//evil.com/x`).
3. Its host equals the suffix without the leading dot (`baja.local`),
   or ends with the suffix (`juiz.baja.local`).

Anything else falls through to the configured default. This is the
guard against open-redirect phishing — naively trusting `?redirect=`
would let an attacker craft `forum.baja.local/app.php/baja/login?redirect=https://attacker.example/`
links that look legitimate.

## Login flow

```
juiz.baja.local/login.php
        |  LoginCsrf::start() plants the CSRF cookie, form embeds the token
        |  user submits form (POST)
        v
forum.baja.local/app.php/baja/login?redirect=…    <-- baja/auth controller
        |  Origin checked, CSRF token checked
        |  $auth->login(user, pass) → LOGIN_SUCCESS
        |  phpBB writes phpbb_sessions row,
        |  Set-Cookie phpbb3_baja_{sid,u,k} domain=.baja.local
        v
HTTP 302 to validateRedirect($_REQUEST['redirect'])
        |  browser follows
        v
juiz.baja.local/index.php
        |  shim reads cookies, queries phpbb_sessions, populates $user
        v
authenticated request
```

## Cloudflare, and a wrong turn worth recording

The forum sits behind a Cloudflare Managed Challenge in production. When
login broke with *"A página solicitada não foi encontrada"* after a captcha,
the challenge looked like the culprit: the theory was that Cloudflare
cannot replay a POST body, so it re-issued the login POST as a bodyless
GET, which matched no route and fell through to phpBB's 404.

**That was wrong**, and it is recorded here because the wrong answer was
plausible enough to survive a while. Production access logs showed the
request arriving as a `POST`, not a GET, and the 404 coming from phpBB's
*router* — a registered route would have answered a bodyless POST with a
405 or a redirect, never a 404.

The real cause was a stale compiled router: `cache/production/url_matcher.php`
had been built before the extension was enabled and never regenerated, so
**both** routes 404'd for months. `extension:show` reported the extension as
enabled because that reads the database, not the cache. Users quietly fell
back to logging in through phpBB's own form, which sets the same cookies, so
nobody reported it. See "Operating notes" for the entrypoint fix.

A `/baja/warmup` route and a pre-login redirect existed for a while to work
around the imagined POST-body loss. Both were removed once the logs
disproved the premise. `/baja/return` is what remains, kept for an unrelated
and real reason: phpBB's own `redirect()` discards off-board targets.

Cloudflare does still challenge the hostname, and that is fine — a solved
challenge replays the POST intact.

## CSRF protection

Both endpoints require proof the request came from our own login form.

`/baja/login` demands **two** independent things, and rejects with
`?error=csrf` if either is missing:

1. **An `Origin` on the allowed domain.** This POST is always cross-origin
   (`juiz.`/`fila.` → `forum.`), and browsers always send `Origin` on a
   cross-origin POST — which is what makes failing closed on a missing
   header safe here, where it would not be for a same-origin form. Checked
   against the same `baja_auth_allowed_domain_suffix` the redirect
   validator uses, deliberately, so the two cannot drift apart.
2. **A double-submit token.** `LoginCsrf` writes a random 32-byte
   value to the `baja_csrf` cookie on the parent domain, and the login form
   embeds the same value. The controller requires them to match
   (`hash_equals`). An attacker's page can neither read our cookie nor guess
   the value, so it cannot produce a matching pair.

`/baja/logout` answers to GET, so `<img src=".../baja/logout">` on any page
a judge visits would end their session mid-event. `Origin` is no help there
— a top-level GET navigation carries none — so the token is the whole
defence, and `Session::endSession()` puts it in the query string. A forged
logout is a no-op redirect rather than a loud refusal.

**The token must be minted before any output.** `LoginCsrf::start()` is
called at the top of each login page for exactly this reason: the form
embeds the value long after `printHeader()` has flushed, and `setcookie()`
is a silent no-op once headers are sent. Getting this wrong produces a form
carrying a token whose cookie was never sent — every genuine login refused
as a forgery, with nothing in the logs. It happened once and reached a fully
green suite, so `token()` now returns `''` rather than inventing a value it
cannot back with a cookie, and a smoke test drives the real page to catch
it; the tests that supply both halves themselves cannot.

## Lockout recovery

phpBB gates `$auth->login()` behind a CAPTCHA once `user_login_attempts`
reaches `max_login_attempts` (3), and that gate runs *before* the password
check — so the correct password is refused too. The counter has no
time-based expiry: it clears only on a successful login or a password
reset. This route cannot present a CAPTCHA, so a locked user could never
get back in through it.

On `LOGIN_ERROR_ATTEMPTS` the controller therefore redirects to the forum's
own login form, which *can* show the CAPTCHA (`includes/functions.php`,
`case LOGIN_ERROR_ATTEMPTS`) and resets the counter on success — setting the
same session cookies the shim reads.

The `redirect` handed to phpBB is **board-relative** on purpose
(`./app.php/baja/return?redirect=…`): phpBB's `redirect()` discards
off-board targets, so an absolute `juiz.` URL would be dropped and the user
stranded on the board index. Pointing it at our own return hop keeps it
board-relative, and that route revalidates the real target through
`validateRedirect()`.

Note this makes the lockout *recoverable*, not impossible. An attacker who
knows a username can still lock that account with three failed POSTs; the
user now has a way back in rather than a dead end. Closing that would need
`max_login_attempts = 0` or a CAPTCHA on the baja form.

## Operating notes

- **Updating the extension code** requires `docker compose down -v &&
  docker compose up -d` because the `phpbb_baja_html` volume retains its
  initial content; subsequent image rebuilds don't propagate into an
  existing volume. (This is a known docker volume gotcha, not specific
  to this extension.)
- **Changing a route needs phpBB's compiled router rebuilt.** phpBB caches
  it in `cache/production/url_matcher.php` and does not notice a changed
  `routing.yml`. `extension:enable` would purge it, but it is a no-op once
  the extension is already enabled — the steady state on every restart — so
  nothing regenerated it. Production 404'd `/baja/login` and `/baja/logout`
  for months this way, with `extension:show` reporting the extension enabled
  the whole time (it reads the database, not the cache). The entrypoint now
  runs `cache:purge` on every boot. Run it as `www-data`: php-fpm runs as
  `www-data`, and a cache purged as root leaves root-owned files it cannot
  rewrite, making the staleness permanent.
- **Disabling the extension** via `php bin/phpbbcli.php extension:disable
  baja/auth` immediately stops the routes from resolving. The
  `phpbb_config` rows persist until `extension:purge baja/auth`, which
  runs the migration's reverse path.
- **Re-enabling** is idempotent — the entrypoint runs `extension:enable`
  on every boot and tolerates the nonzero exit code that comes back when
  it's already enabled.
- **Smoke tests** #9-#16 in
  [baja-php/tests/smoke-test.sh](https://github.com/baja-sae-brasil/baja-sae-brasil-online/blob/main/baja-php/tests/smoke-test.sh)
  exercise the endpoints end-to-end (login, dashboard access with the
  resulting cookies, logout, open-redirect rejection).
