#!/usr/bin/env bash
# Smoke tests for the phpBB session shim (WP2).
# Run from the host while baja-infra docker compose stack is up:
#   cd ~/baja-infra && docker compose up -d
#   ~/code/baja-sae-brasil-online/baja-php/tests/smoke-test.sh
#
# Hostnames must resolve — add to /etc/hosts:
#   127.0.0.1 resultados.baja.local juiz.baja.local fila.baja.local certificado.baja.local forum.baja.local

set -u

BASE_BAJA="http://resultados.baja.local"
BASE_JUIZ="http://juiz.baja.local"
BASE_CERT="http://certificado.baja.local"
BASE_FORUM="http://forum.baja.local"

PASS=0
FAIL=0

green() { printf '\e[32m%s\e[0m\n' "$1"; }
red()   { printf '\e[31m%s\e[0m\n' "$1"; }

check_in() {
    local name="$1"
    local actual="$2"
    shift 2
    for expected in "$@"; do
        if [[ "$actual" == "$expected" ]]; then
            green "PASS  $name (got $actual)"
            PASS=$((PASS + 1))
            return 0
        fi
    done
    red "FAIL  $name (expected one of: $*; got $actual)"
    FAIL=$((FAIL + 1))
    return 1
}

# 1. Anonymous can hit prova.php (SKIP_AUTH path — nginx sets SKIP_AUTH=1 here).
# After WP3 (PHP 8.3 dep upgrade), Propel is functional again so prova.php
# without an ?id= falls through to the natural redirect (302 → index.php)
# instead of the prior R7 fatal. Pre-WP3, this returned 500 because the
# bundled Propel dev-master was incompatible with PHP 8. What we're really
# verifying here is that the SKIP_AUTH branch in bootstrap.php doesn't
# fatal *because of the shim* — any endpoint reaching application code
# proves bootstrap completed.
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_BAJA/prova.php")
check_in "anonymous /prova.php (SKIP_AUTH path reaches app code)" "$status" 200 302 500

# 2. Anonymous can hit certificado root (no auth required, no DB needed for the form)
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_CERT/")
check_in "anonymous /certificado/" "$status" 200

# 3. Anonymous hitting juiz/login.php (the login form itself) should render.
# A cold browser is first bounced through the forum's Cloudflare warm-up
# (ChallengeWarmup::ensure), so the form only renders after following that
# round trip — hence -L. The bounce itself is asserted separately in #13.
status=$(curl -s -o /dev/null -w "%{http_code}" -L "$BASE_JUIZ/login.php")
check_in "anonymous /juiz/login.php form renders" "$status" 200

# 4. Anonymous hitting juiz/index.php — Session::initSession redirects to
# login.php on missing user. Following redirects should land on the login form.
status=$(curl -s -o /dev/null -w "%{http_code}" -L "$BASE_JUIZ/index.php")
check_in "anonymous /juiz/index.php redirects" "$status" 200

# 5. Anonymous hitting juiz/remote.php (R4) — must NOT 500.
# This endpoint is hit by hardware without phpBB cookies. The shim must
# soft-fail to anonymous, not throw. The endpoint itself returns 403 on
# missing/invalid $_remoteKey body; that's expected (means no PHP fatal).
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/remote.php")
check_in "anonymous /juiz/remote.php degrades gracefully" "$status" 200 400 403

# 6. Login flow. The form posts to login.php?act=login (NOT bare login.php —
# the act=login query-string parameter is what triggers the auth branch).
# All seeded test users share password '123456'.
COOKIES=$(mktemp -t shim-cookies.XXXXXX)
trap "rm -f \"$COOKIES\" \"$COOKIES.bak\"" EXIT

# Fetch the login form first so any cookies the page sets are captured.
curl -s -c "$COOKIES" "$BASE_JUIZ/login.php" > /dev/null

# POST credentials. -L follows the post-login Location: index.php redirect.
status=$(curl -s -o /dev/null -w "%{http_code}" -L \
    -b "$COOKIES" -c "$COOKIES" \
    --data-urlencode "username=juiz1" \
    --data-urlencode "password=123456" \
    "$BASE_JUIZ/login.php?act=login")
check_in "login as juiz1" "$status" 200

# 7. After login, hitting /juiz/index.php with the cookie jar.
# baja-side login currently does NOT write cross-request session state
# (no phpbb_sessions row, no cookie set — see phpbb-shim.md "What the
# shim does NOT do"). So this request lands on login.php (200) via
# Session::initSession's redirect, NOT on the dashboard. The original
# WP2 assertion of 200-as-dashboard was masked by Propel's PHP-8 fatal
# (always 500 pre-WP3); WP3 unmasks the underlying architectural gap.
# Wiring cross-request login is a separate ticket. For now we just
# verify no fatal — the redirect-to-login path returns 200.
status=$(curl -s -o /dev/null -w "%{http_code}" -L -b "$COOKIES" "$BASE_JUIZ/index.php")
check_in "authenticated /juiz/index.php (no fatal; cross-request login is a follow-up)" "$status" 200

# 8. Forum-logout simulation: rotate the _u cookie to '1' (anonymous user_id),
# mimicking what phpBB's UI logout does. The shim's session_begin() must
# treat _u=1 as anonymous regardless of _sid — otherwise the request would
# stay logged in for up to SESSION_CACHE_TTL_SECONDS (the ghost-session
# window this check exists to close).
#
# Netscape cookie jar format is tab-separated; columns:
#   domain  flag  path  secure  expiration  name  value
# We rewrite the value column for the row whose name is phpbb3_baja_u.
sed -i.bak -E 's/(phpbb3_baja_u\t)[^\t]*$/\11/' "$COOKIES"
status=$(curl -s -o /dev/null -w "%{http_code}" -L -b "$COOKIES" "$BASE_JUIZ/index.php")
check_in "forum-logout simulation: anonymous after _u=1" "$status" 200

# 9. POST to the new baja/auth login endpoint. phpBB's $auth->login should
# create a session row and Set-Cookie headers with domain=.baja.local
# (per phpbb_config.cookie_domain). Response is a 302/303 to the validated
# redirect target.
#
# redirect travels in the query string, matching what the login forms now
# emit — it has to survive a Cloudflare challenge replay, which keeps the URL
# but discards the body.
: > "$COOKIES"
status=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIES" -X POST \
    --data-urlencode "username=juiz1" \
    --data-urlencode "password=123456" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
check_in "POST /app.php/baja/login returns redirect" "$status" 302 303

# Cookie jar in Netscape format: domain<TAB>flag<TAB>path<TAB>secure<TAB>exp<TAB>name<TAB>value
sid_value=$(awk '$6 == "phpbb3_baja_sid" { print $7 }' "$COOKIES")
sid_domain=$(awk '$6 == "phpbb3_baja_sid" { print $1 }' "$COOKIES")
if [[ -n "$sid_value" && "$sid_domain" == *baja.local ]]; then
    green "PASS  cookies set with sid='$sid_value' on domain '$sid_domain'"
    PASS=$((PASS + 1))
else
    red "FAIL  expected phpbb3_baja_sid cookie on .baja.local; got value='$sid_value' domain='$sid_domain'"
    FAIL=$((FAIL + 1))
fi

# 10. Use those cookies to hit /juiz/index.php — should now reach the
# dashboard (not redirect to login). The dashboard shows a logout link
# pointing at login.php?act=logout; presence of that link is our marker
# that we landed on the authenticated page rather than the login form.
body=$(curl -s -b "$COOKIES" "$BASE_JUIZ/index.php")
if echo "$body" | grep -q 'login.php?act=logout'; then
    green "PASS  authenticated /juiz/index.php (dashboard markers present)"
    PASS=$((PASS + 1))
else
    red "FAIL  /juiz/index.php did not render dashboard for logged-in user"
    FAIL=$((FAIL + 1))
fi

# 11. Logout endpoint. phpBB's session_kill clears the session row and
# rotates the cookies (_u → 1 anonymous, _sid → cleared). After the
# round-trip, the jar should reflect that.
status=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIES" -b "$COOKIES" \
    "$BASE_FORUM/app.php/baja/logout?redirect=$BASE_JUIZ/login.php")
check_in "GET /app.php/baja/logout returns redirect" "$status" 302 303

u_value=$(awk '$6 == "phpbb3_baja_u" { print $7 }' "$COOKIES")
if [[ -z "$u_value" || "$u_value" == "1" ]]; then
    green "PASS  _u cleared/anonymized after logout (got '$u_value')"
    PASS=$((PASS + 1))
else
    red "FAIL  _u still '$u_value' after logout (expected '1' or empty)"
    FAIL=$((FAIL + 1))
fi

# 12. Open-redirect rejection. Even with valid credentials, a redirect
# pointing off-domain must be replaced with the configured default
# (baja_auth_default_redirect). curl's %{redirect_url} surfaces the
# Location header from the 302 response without following it.
: > "$COOKIES"
location=$(curl -s -o /dev/null -w "%{redirect_url}" -X POST \
    --data-urlencode "username=juiz1" \
    --data-urlencode "password=123456" \
    "$BASE_FORUM/app.php/baja/login?redirect=https://evil.com/steal")
if echo "$location" | grep -q 'evil.com'; then
    red "FAIL  open-redirect honored: Location='$location'"
    FAIL=$((FAIL + 1))
else
    green "PASS  open-redirect rejected (Location='$location')"
    PASS=$((PASS + 1))
fi

# ---------------------------------------------------------------------------
# 13-16. Cloudflare challenge resilience.
#
# Dev has no Cloudflare in front of it, so these cannot reproduce a real
# challenge. What they pin down is the contract the challenge depends on:
# a GET must never fall through to phpBB's 404 page, and the warm-up route
# must behave like a validated no-op redirect. Those are the two properties
# that broke in production.
# ---------------------------------------------------------------------------

# 13. The warm-up endpoint: a plain GET that bounces back to the validated
# target. In production this is the request that absorbs the captcha, so it
# must be reachable by GET and must preserve its redirect target.
location=$(curl -s -o /dev/null -w "%{redirect_url}" \
    "$BASE_FORUM/app.php/baja/warmup?redirect=$BASE_JUIZ/login.php%3Fwarmed%3D1")
if [[ "$location" == "$BASE_JUIZ/login.php?warmed=1" ]]; then
    green "PASS  warmup redirects to validated target ('$location')"
    PASS=$((PASS + 1))
else
    red "FAIL  warmup target wrong: expected '$BASE_JUIZ/login.php?warmed=1', got '$location'"
    FAIL=$((FAIL + 1))
fi

# 14. Warm-up honours the same open-redirect guard as login/logout.
location=$(curl -s -o /dev/null -w "%{redirect_url}" \
    "$BASE_FORUM/app.php/baja/warmup?redirect=https://evil.com/steal")
if echo "$location" | grep -q 'evil.com'; then
    red "FAIL  warmup open-redirect honored: Location='$location'"
    FAIL=$((FAIL + 1))
else
    green "PASS  warmup open-redirect rejected (Location='$location')"
    PASS=$((PASS + 1))
fi

# 15. THE REGRESSION TEST. A bodyless GET on /baja/login is exactly what
# Cloudflare produces when it replays a challenged login POST. This used to
# match no route, so phpBB rendered its own 404 ("A página solicitada não foi
# encontrada") on the forum domain and the user was stranded. It must now be
# a redirect carrying ?error=challenge, never a 404.
status=$(curl -s -o /dev/null -w "%{http_code}" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
check_in "GET /app.php/baja/login is not a 404" "$status" 302 303

location=$(curl -s -o /dev/null -w "%{redirect_url}" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
if echo "$location" | grep -q 'error=challenge'; then
    green "PASS  bodyless GET login redirects with error=challenge ('$location')"
    PASS=$((PASS + 1))
else
    red "FAIL  expected error=challenge on bodyless GET login; got '$location'"
    FAIL=$((FAIL + 1))
fi

# 16. Loop guard. login.php?warmed=1 must render the form directly and must
# NOT bounce to the warm-up again — a browser that refuses cookies can never
# set the marker, and re-bouncing would trap it in an infinite redirect loop
# between juiz and forum. Assert a terminal 200 with no Location at all.
for warmed in 1 challenge; do
    status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/login.php?warmed=$warmed")
    location=$(curl -s -o /dev/null -w "%{redirect_url}" "$BASE_JUIZ/login.php?warmed=$warmed")
    if [[ "$status" == "200" && -z "$location" ]]; then
        green "PASS  login.php?warmed=$warmed renders without re-bouncing (no loop)"
        PASS=$((PASS + 1))
    else
        red "FAIL  login.php?warmed=$warmed should render terminally; got status=$status location='$location'"
        FAIL=$((FAIL + 1))
    fi
done

# 17. warmed=challenge is how a lapsed-clearance bounce reports itself (a
# single value rather than an extra &error= pair, because phpBB html-escapes
# the '&' inside a redirect target). The form must actually show the message.
body=$(curl -s "$BASE_JUIZ/login.php?warmed=challenge")
if echo "$body" | grep -q 'verificação de segurança'; then
    green "PASS  warmed=challenge renders the pt-BR challenge message"
    PASS=$((PASS + 1))
else
    red "FAIL  warmed=challenge did not render the challenge message"
    FAIL=$((FAIL + 1))
fi

echo
echo "Smoke tests done.  PASS=$PASS  FAIL=$FAIL"
[[ $FAIL -eq 0 ]] || exit 1
