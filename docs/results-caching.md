# Results caching

How the public results pages are cached at Cloudflare, and how judges still
get live pages, past the spoilers, on the same URLs (BAJAAPP-23).

During an event most of the server's load is visitors refreshing the
results. Each public page is now the same for everybody and says how long
it may be cached. A Cloudflare cache rule then serves it from Cloudflare,
so the server builds each page once per cache period, not once per
visitor. Judges carry a cookie that skips the cache.

## What is cached, and for how long

Every public page sends a `Cache-Control` header from one class,
`baja-php/src/Baja/Site/CachePolicy.php`. `max-age` is for the browser and
`s-maxage` for Cloudflare:

| The page's event | `Cache-Control` | A change shows after at most |
|---|---|---|
| in progress (`em_andamento`), or not finished (`finalizado` = 0) | `public, max-age=10, s-maxage=15` | about 25 s |
| finished, and not in progress | `public, max-age=300, s-maxage=3600` | about 1 h 5 min, unless purged |
| any page, built with a forum session (judges, below) | `private, no-store` | never cached |

The race-data pages, `overlaydata.php` and `enduro_dump.php`, feed the
broadcast overlays, which poll every second or two. They are a named case,
`CachePolicy::sendRaceData()`, like `contagem_feed.php`:

| The page's event | `Cache-Control` | A change shows after at most |
|---|---|---|
| in progress, or not finished | `public, max-age=1, s-maxage=2` | about 3 s |
| finished, and not in progress | `public, max-age=300, s-maxage=3600` (as above) | about 1 h 5 min, unless purged |

Both flags on at once (the dev seed's 26BR is like that) counts as in
progress. The numbers are constants at the top of `CachePolicy`, to be
tuned by watching an event. If you change them, change this table and
`CachePolicyTest::testTheStartingNumbers`.

### The pages

All in `baja-php/resultados/`. Each one lists the query parameters it
reads; any other parameter gets a redirect, see [Clean URLs](#clean-urls).

| Page | Parameters kept | Event that sets the cache time |
|---|---|---|
| `/` and `/<EVENT>/` (`index.php`) | none | the URL's event |
| `prova.php` | `id` | the URL's event |
| `torneio.php` | `id`, `overlay` | the resultado's own event |
| `mapa.php` | none | the URL's event |
| `clock.php` | none | the URL's event |
| `notificacoes.php` | none | the URL's event |
| `overlay.php` | `evt`, `ovl` | `evt` |
| `overlaydata.php` | `evt`, `mode`, `order`, `time` | `evt` (race data) |
| `enduro_dump.php` | none | 19SU, the page is fixed to it (race data) |

`clock.php` is safe to cache: the clock syncs against the static
`js/ServerDate.js`, not against the page. `overlay.js` POSTs to
`overlaydata.php`, and nothing caches a POST; the GET form is cached.

### Not cached

| Page | Why |
|---|---|
| `contagem_feed.php` | Has its own Cloudflare rule; left alone. |
| `t.php` | Prints which server answered. Caching would defeat it. |
| `index_juiz.php` | The judge area's results menu. Runs with the forum session and sends `private, no-store`. Its links now go to `prova.php`. |
| `prova_juiz.php` | Now only a 301 to `prova.php` with the same `id` (and event prefix), sent with `private, no-store`. |

No public page needs the user, so none was left out for that reason. The
API (`api.` vhost) is out of scope.

## How a request is served

```
visitor ──► Cloudflare ──► nginx (resultados vhost) ──► baja-app (PHP)

1. Cloudflare: does the request's Cookie header contain
   "baja_staff=<value>"?
     no  → it may answer from its cache (rule below)
     yes → always passed to the server

2. nginx: is the baja_staff cookie EXACTLY <value>?
     no  → SKIP_AUTH=1: the public path
     yes → SKIP_AUTH=0: the session path

3. PHP, public path:  never reads or starts the forum session, sets no
                      cookie, same page for everybody, public header.
   PHP, session path: reads the forum session. A user in the resultado's
                      authorized_users (or a developer) sees past
                      spoilers; anybody else gets the public view.
                      Always "private, no-store".
```

The map is at the top of
`baja-infra/nginx/templates/baja-app-resultados.conf.template`, and the
public pages share one `location` there. A page added to that location
must call `CleanUrl::enforce()` and `CachePolicy::send()` before printing
anything, and be added to the Cloudflare rule below.

`CachePolicy` is the only place that sends a public header, and it sends
`private, no-store` when either check says "session": SKIP_AUTH, read the
way `src/bootstrap.php` reads it, or `$user` being set (the session shim
was loaded). If those two ever disagree, nothing personal reaches a public
cache.

## The staff cookie

`baja_staff=<value>`, where `<value>` is `STAFF_COOKIE_VALUE` in
`baja-infra/.env`. The code is `baja-php/src/Baja/Auth/StaffCookie.php`.

- **Who gets it:** users with judge permissions, meaning a user row in the
  results database holding `index` (the judge area) or `admin`. Team
  members also log in to the forum, and some have a row for the queue
  (fila), but never `index`; they do not get it.
- **When:** on every page that loads the forum session (`src/bootstrap.php`
  calls `StaffCookie::sync()`), except on the API vhost. A login on the
  forum lands the judge on `juiz/index.php` (or the fila), so that page
  load sets it. Because every such page checks it, a new value in `.env`
  reaches judges on their next page in the judge area, without logging in
  again. The phpBB extension did not change; no forum rebuild is needed.
- **Cleared:** on logout (`Baja\Session::endSession`), and on any page with
  a session whose user is not a judge, or with no user at all.
- **Attributes:** domain `.<BAJA_DOMAIN>` (so the resultados host sees it),
  `Path=/`, `HttpOnly`, `SameSite=Lax`, `Secure` when the site runs on
  https (`LISTEN_MODE=listen-tls`; the dev stack is plain http, where a
  Secure cookie would be dropped). It lasts 30 days, so that phpBB's
  "remember me" judges keep it across browser restarts.
- **The value:** letters and digits, at least 32. Generate it with
  `openssl rand -hex 24`. Anything else counts as unset, and then nobody
  gets the cookie.

### Why a faked cookie is harmless

The cookie is not a credential. Its only effect is skipping the cache.
Who sees past spoilers is decided from the real forum session, every
time.

| Request | What happens |
|---|---|
| No cookie, or a wrong value | Cloudflare may serve the cached public page. nginx gives the public path. Same page as everybody. |
| The right value, no judge session | Cloudflare skips its cache, nginx loads the session, PHP finds no authorized user: the public view, with `private, no-store`. Only costs the server one uncached request. |
| The right value and a session in that resultado's `authorized_users` | The live page, past the spoilers, `private, no-store`. This needs the judge's own forum login. |

The one thing to protect is the value itself: anyone who has it can skip
the cache at will, which only costs load. Keep it out of chats and
screenshots, and rotate it if it leaks.

## Clean URLs

Cloudflare keeps one copy per distinct URL, query string included. A link
shared on Facebook comes back as `prova.php?id=26BR_GER&fbclid=...` with a
new `fbclid` on every click, and each would be a cache miss.

So `Baja\Site\CleanUrl::enforce()` answers a GET or HEAD whose query string
is not exactly the page's clean one with a **301** to it:

- unknown parameters (`fbclid`, `utm_*`, anything else) are removed;
- the kept ones are put in the page's order (`overlay=1&id=X` becomes
  `id=X&overlay=1`), and spelled one way (`%5F` becomes `_`, `+` becomes
  `%20`);
- the path, with its event prefix, is kept (`/26BR/prova.php`);
- the redirect carries the long public header (where a messy URL leads
  never changes), or `private, no-store` on the session path.

## The Cloudflare cache rule

Create it once, after the deploy below. Dashboard → the zone → **Caching →
Cache Rules → Create rule**.

- **Rule name:** `Public results pages`
- **When incoming requests match:** Custom filter expression → **Edit
  expression**, and paste this, with the real value in place of
  `<STAFF_COOKIE_VALUE>` (production is `bajasaebrasil.net`):

  ```
  (http.host eq "resultados.bajasaebrasil.net"
   and (ends_with(http.request.uri.path, "/")
     or ends_with(http.request.uri.path, "/index.php")
     or ends_with(http.request.uri.path, "/prova.php")
     or ends_with(http.request.uri.path, "/torneio.php")
     or ends_with(http.request.uri.path, "/mapa.php")
     or ends_with(http.request.uri.path, "/clock.php")
     or ends_with(http.request.uri.path, "/notificacoes.php")
     or ends_with(http.request.uri.path, "/overlay.php")
     or ends_with(http.request.uri.path, "/overlaydata.php")
     or ends_with(http.request.uri.path, "/enduro_dump.php"))
   and not http.cookie contains "baja_staff=<STAFF_COOKIE_VALUE>")
  ```

  `ends_with` covers both `/prova.php` and `/26BR/prova.php`. The free plan
  supports the Cookie field with *contains* / *does not contain*.
- **Then:**
  - **Cache eligibility:** Eligible for cache
  - **Edge TTL:** Use cache-control header if present, bypass cache if not
  - **Browser TTL:** Respect origin TTL
  - Leave the cache key as it is. Do not turn on "ignore query string":
    the query string is what tells `prova.php?id=A` and `?id=B` apart.
- **Deploy.** It does not overlap the existing `contagem_feed.php` rule.
  If some other rule ever matches the same requests, note that Cloudflare
  applies matching cache rules in list order and a later one overrides an
  earlier one's settings.

To publish a correction before the cache time runs out: **Caching →
Configuration → Purge Cache → Custom Purge**, by URL (each page's clean
URL, e.g. `https://resultados.bajasaebrasil.net/26BR/prova.php?id=26BR_GER`).

## Deploy (first time)

1. Generate a value: `openssl rand -hex 24`.
2. Add it to the production `baja-infra/.env` as `STAFF_COOKIE_VALUE=...`.
   Without it `docker compose` refuses to run at all, with a message
   naming the variable.
3. Deploy the code as usual. What changed outside PHP: the nginx template
   `baja-app-resultados.conf.template` (the map and the public pages'
   location) and `docker-compose.yml` (`STAFF_COOKIE_VALUE` passed to nginx
   and baja-app, and added to nginx's envsubst filter).
4. Recreate both containers, baja-app first, then restart nginx so that it
   resolves baja-app's new address (see dev-workflow.md, the 502 FAQ):

   ```
   cd baja-infra
   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d baja-app nginx
   docker compose restart nginx
   ```

5. Create the Cloudflare rule above, with the value from step 1. Until it
   exists nothing is cached at Cloudflare; the pages are already correct.
6. Run the checklist below.
7. Judges get the cookie on their next page in the judge area. Tell them to
   open it once; until then they see the public pages.

## Rotating the value

Do it outside event hours: between steps 2 and 3, judges get public pages.

1. New value: `openssl rand -hex 24`, into `baja-infra/.env` as
   `STAFF_COOKIE_VALUE`.
2. Restart with it:

   ```
   cd baja-infra
   docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d baja-app nginx
   docker compose restart nginx
   ```

3. Cloudflare: edit the rule, replace the value after `baja_staff=`, save.
4. Judges get the new value on their next page in the judge area.

## Verification checklist

Against production, from any machine. `U` is a page of the event in
progress; `V` is the value from `.env`.

```
U="https://resultados.bajasaebrasil.net/26BR/prova.php?id=26BR_GER"
V="<STAFF_COOKIE_VALUE>"
show() { grep -iE '^(HTTP|cf-cache-status|cache-control|age|set-cookie|location):'; }
```

1. **Public, first request: a miss.**
   `curl -s -o /dev/null -D - "$U" | show` →
   `cf-cache-status: MISS` (or `EXPIRED`), `cache-control: public, max-age=…`,
   no `set-cookie`.
2. **Public, again within s-maxage: a hit.** Same command →
   `cf-cache-status: HIT` and an `age:` header (seconds in the cache).
3. **With the staff cookie: never cached.**
   `curl -s -o /dev/null -D - -b "baja_staff=$V" "$U" | show` →
   `cf-cache-status: DYNAMIC`, `cache-control: private, no-store`.
4. **A wrong cookie is just public.**
   `curl -s -o /dev/null -D - -b "baja_staff=wrong" "$U" | show` →
   `HIT` or `MISS`, `cache-control: public, …`.
5. **Tracking tags are redirected.**
   `curl -s -o /dev/null -D - "$U&fbclid=abc" | show` → `HTTP/2 301`,
   `location: /26BR/prova.php?id=26BR_GER`.
6. **A judge.** Log in to the judge area, then in the browser's developer
   tools check that `baja_staff` is set for `.bajasaebrasil.net`, and that
   a results page shows `cf-cache-status: DYNAMIC`.

## Tests

Read the tests to see every rule above as an example; each has a comment
saying what it checks and why.

- **Unit, no database** (part of `--testsuite unit`, run in CI):
  `baja-php/tests/Unit/Site/CachePolicyTest.php` (the headers),
  `baja-php/tests/Unit/Site/CleanUrlTest.php` (the redirect: `fbclid`,
  `utm_*`, junk, order, spelling, kept parameters),
  `baja-php/tests/Unit/Auth/StaffCookieTest.php` (who gets the cookie).

  ```
  docker compose exec --user "$(id -u):$(id -g)" baja-app vendor/bin/phpunit --testsuite unit
  ```

- **Against the dev stack:** `baja-php/tests/results-caching/http-test.sh`.
  It needs nginx and PHP both in the path, so it is not in CI. It loads a
  made-up event, 29NE, with spoilers on (`fixture.sql` next to it), four
  test users and their forum sessions, through `docker exec` into the app
  container, and removes them at the end. It checks: no cookie, a wrong
  cookie, the right cookie with no session, with an authorized session and
  with other sessions; that every public page is public without the cookie
  and `no-store` with it; the clean-URL redirects; `prova_juiz.php` and the
  judge menu; and that the cookie is set for judges only and cleared on
  logout. Run it from the host:

  ```
  baja-php/tests/results-caching/http-test.sh http://resultados.baja.local http://juiz.baja.local
  ```

  Without hosts-file entries, add
  `CURL_EXTRA="--resolve resultados.baja.local:80:127.0.0.1 --resolve juiz.baja.local:80:127.0.0.1"`.
  Set `APP_CONTAINER` if your app container is not called `baja-app`. It
  refuses to run unless that container has `ENV=dev`.

- **Results snapshots** still run the public path, unchanged
  ([`results-snapshots.md`](results-snapshots.md)).

## Known limits

- A browser keeps its own copy for `max-age`. A judge who loaded a page
  just before getting the cookie may see that copy for up to 10 s (5 min
  for a finished event); reloading fixes it.
- Any path ending in `/` matches the rule, and nginx answers unknown ones
  with the index, so a junk path can make a cache entry. Each entry holds
  the correct page; it only costs one request.
- Logging out from the forum's own pages (not the judge area's Logout)
  leaves the cookie until the judge's next page with a session. Harmless:
  without the session it only skips the cache.
- The legacy Apache `.htaccess` in `resultados/` still marks only
  `prova.php`; nginx is what runs everywhere.
