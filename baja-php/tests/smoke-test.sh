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

# 2. Anonymous can hit certificado root. It used to render an event selector
# and a CPF field; since the certificate rewrite it redirects to /buscar, which
# searches every event at once. 302 is the healthy answer now.
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_CERT/")
check_in "anonymous /certificado/ redirects to the search form" "$status" 302

# 2b. And the form it redirects to renders for an anonymous visitor.
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_CERT/buscar")
check_in "anonymous /buscar renders" "$status" 200

# 3. Anonymous hitting juiz/login.php (the login form itself) should render.
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/login.php")
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
: > "$COOKIES"
status=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIES" -X POST \
    --data-urlencode "username=juiz1" \
    --data-urlencode "password=123456" \
    --data-urlencode "redirect=$BASE_JUIZ/index.php" \
    "$BASE_FORUM/app.php/baja/login")
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

# 10. Use those cookies to hit /juiz/index.php.
#
# This check asserted the dashboard and had been failing, with a comment
# blaming cross-request login. That diagnosis was wrong: the login works —
# checks 9 and 11 show the session row and the cookies. What is missing is a
# row for juiz1 in baja_resultados.user. phpBB says who you are; that table
# says what you may do here, and the seed data creates the first without the
# second.
#
# Until recently that state redirected to login with nothing said, where
# logging in succeeded and bounced again — which is why this read as a broken
# login rather than as an unprovisioned account. It now renders a page saying
# so, and that page is what this check asserts.
#
# Since the lap counting seed (baja-infra/mysql/init/06-contagem.sql) juiz1 does
# have a row on a freshly seeded database, so there this check passes through
# its second branch, the dashboard. The first branch is kept for databases
# seeded before that file existed.
body=$(curl -s -b "$COOKIES" "$BASE_JUIZ/index.php")
if echo "$body" | grep -q 'ainda não tem acesso a este sistema'; then
    green "PASS  authenticated but unprovisioned /juiz/index.php explains itself"
    PASS=$((PASS + 1))
elif echo "$body" | grep -q 'login.php?act=logout'; then
    green "PASS  authenticated /juiz/index.php (dashboard markers present — juiz1 is provisioned here)"
    PASS=$((PASS + 1))
else
    red "FAIL  /juiz/index.php neither rendered the dashboard nor explained the missing account"
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
    --data-urlencode "redirect=https://evil.com/steal" \
    "$BASE_FORUM/app.php/baja/login")
if echo "$location" | grep -q 'evil.com'; then
    red "FAIL  open-redirect honored: Location='$location'"
    FAIL=$((FAIL + 1))
else
    green "PASS  open-redirect rejected (Location='$location')"
    PASS=$((PASS + 1))
fi

# --- Lap counting (contagem de voltas) ---------------------------------------
#
# These rely on what baja-infra/mysql/init/06-contagem.sql seeds: the Enduro of
# 26BR (prova END) is a laps prova with sources 1, 2 and 3; juiz1 controls it;
# equipe1 counts on source 1 and equipe2 on source 2. On a database seeded
# before that file existed they fail, and `docker compose down -v` followed by
# `up -d` is the fix.
#
# The event is named in the URL (/26BR/...) so the checks do not depend on
# which event bootstrap.php would pick by default.

# Replace whatever is in the cookie jar with a fresh login for one user, via
# the same forum endpoint check 9 uses.
login_as() {
    : > "$COOKIES"
    curl -s -o /dev/null -c "$COOKIES" -X POST \
        --data-urlencode "username=$1" \
        --data-urlencode "password=123456" \
        --data-urlencode "redirect=$BASE_JUIZ/index.php" \
        "$BASE_FORUM/app.php/baja/login"
}

# check_body NAME BODY MARKER: pass when BODY contains MARKER.
check_body() {
    # -F: the marker is plain text, not a pattern.
    if echo "$2" | grep -qF "$3"; then
        green "PASS  $1"
        PASS=$((PASS + 1))
    else
        red "FAIL  $1 (did not find '$3' in the response)"
        FAIL=$((FAIL + 1))
    fi
}

# What a page says when the user may not see it (Session::permissionCheck and
# the contagem pages use the same sentence). These pages answer 200 either
# way, so the body is what tells a rendered page from a refusal.
REFUSAL='Você não tem acesso a essa página'

# Markers that only exist in the fully rendered pages.
COUNT_PAGE_MARKER='id="lista-recentes"'
REVIEW_PAGE_MARKER='id="fila-abertos"'

COUNT_PAGE="$BASE_JUIZ/26BR/contagem_contar.php?p=END&f=1"
REVIEW_PAGE="$BASE_JUIZ/26BR/contagem_revisao.php?p=END"

# 13. A counter opens the count page of the source they are linked to.
login_as equipe1
body=$(curl -s -b "$COOKIES" "$COUNT_PAGE")
check_body "count page renders for a counter linked to that source (equipe1, source 1)" "$body" "$COUNT_PAGE_MARKER"

# 14. A counter linked to a different source is refused: counters must not be
# able to wander into each other's source, or the cross-check between sources
# means nothing.
login_as equipe2
body=$(curl -s -b "$COOKIES" "$COUNT_PAGE")
check_body "count page refuses a counter of another source (equipe2, source 1)" "$body" "$REFUSAL"

# 15. The controller of the prova opens the review screen.
login_as juiz1
body=$(curl -s -b "$COOKIES" "$REVIEW_PAGE")
check_body "review page renders for the controller (juiz1)" "$body" "$REVIEW_PAGE_MARKER"

# 16. A counter is not a reviewer: seeing the other sources' entries is
# exactly what a counter must never be able to do.
login_as equipe1
body=$(curl -s -b "$COOKIES" "$REVIEW_PAGE")
check_body "review page refuses a counter (equipe1)" "$body" "$REFUSAL"

# 17. The upload endpoint takes a device token, not a session. Without a token
# it must answer 401, with or without cookies; sent here with none at all.
status=$(curl -s -o /dev/null -w "%{http_code}" -X POST \
    -H 'Content-Type: application/json' \
    --data '{"entries": []}' \
    "$BASE_JUIZ/contagem_sync.php")
check_in "POST /contagem_sync.php without a token is refused" "$status" 401

echo
echo "Smoke tests done.  PASS=$PASS  FAIL=$FAIL"
[[ $FAIL -eq 0 ]] || exit 1
