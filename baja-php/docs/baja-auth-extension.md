# baja/auth phpBB Extension

**Status:** implemented (WP5). Lives at [phpbb-extensions/baja-auth/](../phpbb-extensions/baja-auth/),
baked into the `baja-phpbb-baja` image, enabled automatically on container
boot.

Provides three endpoints under `forum.baja.local/app.php/baja/...` so the
baja-app (juiz, fila, etc.) can drive cross-subdomain login and logout
through phpBB's own auth pipeline:

| Route | Method | Purpose |
|---|---|---|
| `/baja/login` | GET, POST | Authenticate via `$auth->login()`. On success, phpBB sets session cookies on `.baja.local`; the baja-app shim picks them up on the next request. GET is accepted only to catch Cloudflare challenge replays — see below. |
| `/baja/logout` | GET, POST | `$user->session_kill()` followed by `session_begin()` (anonymous re-init), then redirect. |
| `/baja/warmup` | GET | No-op validated redirect. Exists purely to absorb a Cloudflare challenge on a GET, before the user types a password. |

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
| [config/routing.yml](../phpbb-extensions/baja-auth/config/routing.yml) | Symfony route definitions for `/baja/login`, `/baja/logout` and `/baja/warmup`. |
| [config/services.yml](../phpbb-extensions/baja-auth/config/services.yml) | DI wiring for the controller (`@auth`, `@user`, `@config`, `@request`). |
| [controller/main.php](../phpbb-extensions/baja-auth/controller/main.php) | All three endpoints + `validateRedirect()` + `mapLoginError()`. |
| [../src/Baja/Auth/ChallengeWarmup.php](../src/Baja/Auth/ChallengeWarmup.php) | baja-app side of the warm-up: decides when to bounce a login page through `/baja/warmup`, and holds the loop guard. |
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
        |  ChallengeWarmup::ensure() — no warm marker? bounce (GET)
        v
forum.baja.local/app.php/baja/warmup?redirect=…/login.php%3Fwarmed%3D1
        |  Cloudflare presents any captcha HERE (prod only)
        |  solved → cf_clearance issued → CF replays the GET intact
        v
juiz.baja.local/login.php?warmed=1
        |  sets baja_cf_warm marker, renders the form
        |  user submits form (POST); cf_clearance rides along
        v
forum.baja.local/app.php/baja/login?redirect=…    <-- baja/auth controller
        |  not challenged — clearance already held
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

In dev there is no Cloudflare, so the warm-up is just two extra redirects.

## Cloudflare interaction

In production the forum is served through Cloudflare with a Managed
Challenge. That interacts badly with a cross-origin login POST, and the
failure is silent and confusing, so it is worth stating plainly:

**Cloudflare cannot replay a POST body through a challenge.** It serves
the interstitial, and once the captcha is solved it re-issues the
*original* request as a bodyless **GET**. Username and password are
discarded in transit.

While `/baja/login` was POST-only, that replayed GET matched no route, so
phpBB's router fell through to its own 404 page — *"A página solicitada
não foi encontrada"* — rendered on the forum domain. From the user's
side: captcha, then dumped into the forum, never logged in, nothing in
the app logs, because phpBB rejected the request before any baja code
ran.

Three changes make the flow survive this:

1. **`/baja/warmup`** — the user passes any challenge on a plain GET,
   *before* typing a password. Cloudflare replays a GET intact, so
   nothing is lost. They come back holding `cf_clearance`.
2. **`redirect` moved into the query string** of the form action rather
   than a hidden POST field, so the target survives a replay. Browsers
   preserve an action's query string on POST, so the normal path is
   unchanged.
3. **`/baja/login` accepts GET** and treats a credential-less request as
   a challenge replay, redirecting to the form with `?error=challenge`
   instead of 404ing. This still matters with warm-up in place, because
   `cf_clearance` can lapse while a login form sits open.

The clearance carries across subdomains because `juiz.`, `fila.` and
`forum.` share a registrable domain: the login POST is cross-origin but
**same-site**, so `cf_clearance` is sent even under `SameSite=Lax`. The
`baja_cf_warm` marker is therefore set on the parent domain — one
warm-up covers every baja subdomain — with a 20-minute TTL, deliberately
under Cloudflare's 30-minute `cf_clearance` default.

Optionally, a Cloudflare WAF **Skip** rule on
`forum.<domain>/app.php/baja/*` removes the captcha for these endpoints
entirely. It is not required — the flow above works with the challenge
fully enabled — but it removes the extra round trip. The code must keep
working without it, since WAF state is dashboard-managed and invisible
to this repo.

On failure the controller redirects back to the validated target with
`?error=<code>`. Codes: `missing`, `unknown_user`, `bad_password`,
`too_many_attempts`, `unknown`. The login pages
([baja-php/juiz/login.php](https://github.com/baja-sae-brasil/baja-sae-brasil-online/blob/main/baja-php/juiz/login.php),
[baja-php/fila/login.php](https://github.com/baja-sae-brasil/baja-sae-brasil-online/blob/main/baja-php/fila/login.php))
map these to Portuguese error messages.

## Logout flow

```
juiz.baja.local/index.php — "Logout" link
        v
juiz.baja.local/login.php?act=logout → Baja\Session::endSession()
        v
forum.baja.local/app.php/baja/logout?redirect=...
        |  $user->session_kill()
        |  Set-Cookie phpbb3_baja_{sid,u,k} cleared domain=.baja.local
        |  $user->session_begin() — anonymous re-init
        v
HTTP 302 to validateRedirect(...) → juiz.baja.local/login.php
        |  browser follows
        v
anonymous request lands on login form
```

The shim's `session_kill()` is no longer called from
`Baja\Session::endSession()` — the round-trip through the forum is what
actually clears the cookies in the browser. Cookies set with
`domain=.baja.local` from `forum.baja.local` cannot be cleared by code
running on `juiz.baja.local` due to cookie-path semantics; the cleanest
fix is to do the cookie work on the same origin that set them.

## Operating notes

- **Updating the extension code** requires `docker compose down -v &&
  docker compose up -d` because the `phpbb_baja_html` volume retains its
  initial content; subsequent image rebuilds don't propagate into an
  existing volume. (This is a known docker volume gotcha, not specific
  to this extension.)
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
