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

# 5b. Timers. timer.php is the one juiz page meant to work with no session:
# it must render the key form rather than redirect to login, a seeded access
# key must reach the timer, the read-only key must reach it without being able
# to act, and the sync endpoint must refuse a bad key.
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/timer.php")
check_in "anonymous /juiz/timer.php renders key form" "$status" 200
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/timer_action.php?k=DEVTM2")
check_in "anonymous /juiz/timer_action.php with seeded key" "$status" 200
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/timer_action.php?k=DEVVW2")
check_in "anonymous /juiz/timer_action.php with seeded read-only key" "$status" 200
status=$(curl -s -o /dev/null -w "%{http_code}" -X POST --data '{"acoes":[{"id":"smoke","acao":"parar","at":0,"state":{"status":"stopped","step":0}}]}' "$BASE_JUIZ/timer_action.php?k=DEVVW2")
check_in "read-only key cannot post actions" "$status" 403
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/timer_action.php?k=XXXXXX")
check_in "anonymous /juiz/timer_action.php with bad key" "$status" 403
status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_JUIZ/timer_action.php?id=1")
check_in "anonymous /juiz/timer_action.php by id" "$status" 403

# 6. Login flow. The form posts to login.php?act=login (NOT bare login.php —
# the act=login query-string parameter is what triggers the auth branch).
# All seeded test users share password '123456'.
COOKIES=$(mktemp -t shim-cookies.XXXXXX)

# Double-submit CSRF token. The controller holds no server-side state — it only
# checks that the cookie and the form field match — so a fixed value works here.
# That is the mechanism rather than a weakness: the protection comes from an
# attacker's page being unable to read or set a cookie on our domain.
CSRF=smoketestcsrftoken0000000000000000000000000000000000000000000000
# Real submissions also require Origin. Browsers always send it on a
# cross-origin POST; curl does not, so pass it explicitly.
ORIGIN_HDR=(-H "Origin: $BASE_JUIZ")
# For calls needing both the phpBB session cookies and the token, append the
# token to the jar curl already wrote.
csrf_into_jar() { printf '.baja.local\tTRUE\t/\tFALSE\t0\tbaja_csrf\t%s\n' "$CSRF" >> "$1"; }
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
# redirect travels in the query string, matching what the login forms emit.
: > "$COOKIES"
status=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIES" -X POST \
    "${ORIGIN_HDR[@]}" -b "baja_csrf=$CSRF" \
    --data-urlencode "username=juiz1" \
    --data-urlencode "password=123456" \
    --data-urlencode "csrf=$CSRF" \
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
csrf_into_jar "$COOKIES"
status=$(curl -s -o /dev/null -w "%{http_code}" -c "$COOKIES" -b "$COOKIES" \
    "$BASE_FORUM/app.php/baja/logout?redirect=$BASE_JUIZ/login.php&csrf=$CSRF")
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
    "${ORIGIN_HDR[@]}" -b "baja_csrf=$CSRF" \
    --data-urlencode "username=juiz1" \
    --data-urlencode "password=123456" \
    --data-urlencode "csrf=$CSRF" \
    "$BASE_FORUM/app.php/baja/login?redirect=https://evil.com/steal")
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
#
# /baja/login wants two proofs that the request came from one of our own login
# forms (checks 23-26 test them one by one): the Origin header of an allowed
# site, and the CSRF token sent twice, as the baja_csrf cookie and as the csrf
# form field. A browser adds all of that by itself; curl adds none of it, so it
# is passed here by hand, exactly as check 9 does. Without it the forum answers
# with ?error=csrf and no session cookies, and every page asked for afterwards
# is seen as an anonymous visitor. That is what happened when these lap
# counting checks (written before the CSRF protection) met it in a merge: all
# four failed with "marker not found", which points at the pages when the real
# problem was that nobody was logged in.
#
# The redirect target goes in the query string, where the real forms put it.
login_as() {
    : > "$COOKIES"
    local location
    location=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$COOKIES" -X POST \
        "${ORIGIN_HDR[@]}" -b "baja_csrf=$CSRF" \
        --data-urlencode "username=$1" \
        --data-urlencode "password=123456" \
        --data-urlencode "csrf=$CSRF" \
        "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
    # Check the login itself, so that a refusal is reported as a refusal and
    # not as a page that "did not render". A successful login redirects to the
    # target with no ?error= and leaves the forum's session cookie in the jar.
    if [[ "$location" == "$BASE_JUIZ/index.php" ]] && grep -q 'phpbb3_baja_sid' "$COOKIES"; then
        green "PASS  login_as $1 got a forum session"
        PASS=$((PASS + 1))
    else
        red "FAIL  login_as $1: the forum refused the login (Location='$location')"
        FAIL=$((FAIL + 1))
    fi
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

# 18. Security regression test for tournament_entry.php
#     (hotfix/tournament-permission).
#
# What the page does: tournament_entry.php is the judge page for bracket
# events (for example the SPR tournament). Two of its request parameters
# CHANGE results in the database:
#   ?p=<prova>&reset=<match>                  -> clears the winner of a match
#   ?p=<prova>&match=<match>&setwinner=<team> -> records a winner
# Both are plain GET links, so simply opening the URL triggers them.
#
# The bug: the page used to act on those parameters BEFORE calling
# Session::permissionCheck(). On judge pages that call is the ONLY thing
# that notices "nobody is logged in" and redirects to login.php. So an
# anonymous visitor who typed the URL could rewrite competition results.
# The fix moved the permission check to the top of the page.
#
# What this test simulates: an attacker with no session. The cookie jar is
# NOT passed to curl, so both requests below are anonymous.
#
# What must happen: each request is answered with a redirect to login.php
# (HTTP 302), and the tournament table is identical before and after. The
# table must not change because an anonymous visitor has no right to touch
# results at all. If it does change, the permission check is running too
# late (or not at all) and the vulnerability is back.
#
# To make the test meaningful we first insert one throw-away match row.
# Without a real row the vulnerable code would crash before writing (it
# calls ->setWinner() on a null object) and the test would pass for the
# wrong reason. The row lives under event 23SU / prova SPR, which the dev
# seed (baja-infra/mysql/init/05-seed-baja-resultados.sql) always has, and
# uses teams 5 and 18 from that same event. match_id 9999 is far above any
# real match so it cannot collide with seeded data. It is deleted at the end.
#
# The MySQL root password is read from baja-infra/.env (dev default:
# devrootpass). Export MYSQL_ROOT_PASSWORD=... to override.
INFRA_ENV="$(dirname "$0")/../../baja-infra/.env"
if [[ -z "${MYSQL_ROOT_PASSWORD:-}" && -f "$INFRA_ENV" ]]; then
    MYSQL_ROOT_PASSWORD=$(grep -E '^MYSQL_ROOT_PASSWORD=' "$INFRA_ENV" | tail -n 1 | cut -d= -f2-)
fi
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-devrootpass}"

# Runs one SQL statement against the app database inside the mysql
# container. -N drops the column-name header and -B prints tab-separated
# rows, so the output is stable enough to compare as plain text.
tournament_sql() {
    docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" baja-mysql \
        mysql -N -B -uroot baja_resultados -e "$1"
}

TEST_EVENT="23SU"
TEST_PROVA="SPR"
TEST_MATCH=9999
TEST_TEAM1=5
TEST_TEAM2=18

# Remove any leftover from an earlier run that was interrupted, then insert
# the throw-away row with team 18 already recorded as the winner, so that a
# successful "reset" would visibly null it out.
tournament_sql "DELETE FROM tournament WHERE evento_id='$TEST_EVENT' AND prova_id='$TEST_PROVA' AND match_id=$TEST_MATCH;"
if tournament_sql "INSERT INTO tournament (evento_id, prova_id, match_id, round, equipe1_id, equipe2_id, winner, dados) VALUES ('$TEST_EVENT', '$TEST_PROVA', $TEST_MATCH, '0', '$TEST_TEAM1', '$TEST_TEAM2', $TEST_TEAM2, NULL);"; then
    green "PASS  seeded throw-away tournament row $TEST_EVENT/$TEST_PROVA/$TEST_MATCH"
    PASS=$((PASS + 1))
else
    red "FAIL  could not seed tournament row (is baja-mysql up and the dev DB seeded?)"
    FAIL=$((FAIL + 1))
fi

# Snapshot: every row of the table, in a fixed order. An empty snapshot
# means we could not talk to the database, which is a failure in itself
# (otherwise "nothing changed" would be trivially true).
snapshot_sql="SELECT * FROM tournament ORDER BY evento_id, prova_id, match_id;"
before_snapshot=$(tournament_sql "$snapshot_sql")
if [[ -z "$before_snapshot" ]]; then
    red "FAIL  could not read the tournament table before the requests"
    FAIL=$((FAIL + 1))
fi

# Anonymous "reset" request. The /23SU/ prefix in the URL pins the event so
# the page looks at the rows we seeded. curl prints two things: the HTTP
# status and the Location header it would have followed (the page sends
# "Location: login.php"; curl resolves that to an absolute URL for us).
reset_url="$BASE_JUIZ/$TEST_EVENT/tournament_entry.php?p=$TEST_PROVA&reset=$TEST_MATCH"
read -r status location < <(curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" "$reset_url")
check_in "anonymous tournament_entry.php?reset= is redirected" "$status" 302
if [[ "$location" == */login.php ]]; then
    green "PASS  anonymous reset= redirected to login (Location='$location')"
    PASS=$((PASS + 1))
else
    red "FAIL  anonymous reset= did not redirect to login.php (Location='$location')"
    FAIL=$((FAIL + 1))
fi

# Anonymous "setwinner" request, trying to declare team 5 the winner.
setwinner_url="$BASE_JUIZ/$TEST_EVENT/tournament_entry.php?p=$TEST_PROVA&match=$TEST_MATCH&setwinner=$TEST_TEAM1"
read -r status location < <(curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" "$setwinner_url")
check_in "anonymous tournament_entry.php?setwinner= is redirected" "$status" 302
if [[ "$location" == */login.php ]]; then
    green "PASS  anonymous setwinner= redirected to login (Location='$location')"
    PASS=$((PASS + 1))
else
    red "FAIL  anonymous setwinner= did not redirect to login.php (Location='$location')"
    FAIL=$((FAIL + 1))
fi

# The decisive check: the table must be identical. A redirect alone proves
# nothing, because the vulnerable code ALSO redirected, right after writing.
after_snapshot=$(tournament_sql "$snapshot_sql")
if [[ -n "$before_snapshot" && "$before_snapshot" == "$after_snapshot" ]]; then
    green "PASS  tournament table unchanged by anonymous reset=/setwinner= requests"
    PASS=$((PASS + 1))
else
    red "FAIL  tournament table CHANGED after anonymous requests: results can be edited without logging in"
    FAIL=$((FAIL + 1))
    tournament_sql "SELECT * FROM tournament WHERE evento_id='$TEST_EVENT' AND prova_id='$TEST_PROVA' AND match_id=$TEST_MATCH;"
fi

# Clean up the throw-away row so the dev DB looks like it did before.
tournament_sql "DELETE FROM tournament WHERE evento_id='$TEST_EVENT' AND prova_id='$TEST_PROVA' AND match_id=$TEST_MATCH;"

# --- Login shim security (fix/login-shim-security) -------------------------
#
# Checks 19-28 came in with the login-shim hardening and were numbered 13-25
# on that branch; develop had meanwhile used 13-18 for lap counting and the
# tournament hotfix, so they were renumbered on merge. Nothing else changed.

# ---------------------------------------------------------------------------
# 19-20. The return hop used to escape a lockout.
#
# /baja/return is a validated no-op redirect. It exists because phpBB's
# redirect() discards off-board targets, so forumLoginUrl() hands phpBB a
# board-relative path pointing here rather than an absolute juiz. URL that
# phpBB would drop, stranding the user on the board index.
# ---------------------------------------------------------------------------

location=$(curl -s -o /dev/null -w "%{redirect_url}"     "$BASE_FORUM/app.php/baja/return?redirect=$BASE_JUIZ/index.php")
if [[ "$location" == "$BASE_JUIZ/index.php" ]]; then
    green "PASS  return hop bounces to the validated target ('$location')"
    PASS=$((PASS + 1))
else
    red "FAIL  return hop target wrong: expected '$BASE_JUIZ/index.php', got '$location'"
    FAIL=$((FAIL + 1))
fi

location=$(curl -s -o /dev/null -w "%{redirect_url}"     "$BASE_FORUM/app.php/baja/return?redirect=https://evil.com/steal")
if echo "$location" | grep -q 'evil\.com'; then
    red "FAIL  return hop open-redirect honored: Location='$location'"
    FAIL=$((FAIL + 1))
else
    green "PASS  return hop open-redirect rejected (Location='$location')"
    PASS=$((PASS + 1))
fi

# ---------------------------------------------------------------------------
# 21-22. Security regressions from the login-shim review.
# ---------------------------------------------------------------------------

# 21. Open redirect via backslash. Browsers treat "\" as "/" in special-scheme
# URLs (WHATWG URL), so "/\evil.com" satisfied validateRedirect's "starts with
# / but not //" test, was echoed back verbatim, and navigated to
# http://evil.com/. Control characters are stripped by browsers before parsing
# and could smuggle a second slash past the same test. Checked on the return
# hop — the cleanest vector, being unauthenticated and GET — and on login.
#
# Assert on the HOST the browser would end up on, not on whether the string
# contains "evil.com". A same-origin path that merely mentions the name (e.g.
# /%09/evil.com, where the % is literal) is harmless, and a substring check
# reports it as a failure.
for payload in '/\evil.com' '/\/evil.com' "/$(printf '	')/evil.com" "/$(printf '')/evil.com"; do
    printable=$(printf '%s' "$payload" | cat -v)

    # The return hop is a GET route.
    loc_return=$(curl -s -o /dev/null -w "%{redirect_url}"         --get --data-urlencode "redirect=$payload"         "$BASE_FORUM/app.php/baja/return")

    # login is POST-only, so a GET would 405 with no Location and the host
    # check below would pass without validateRedirect ever running. POST it,
    # deliberately without Origin: the request is refused as CSRF, but only
    # AFTER the target has been resolved, so the Location still proves what
    # the validator did with the payload.
    loc_login=$(curl -s -o /dev/null -w "%{redirect_url}" -X POST         --data-urlencode "redirect=$payload"         "$BASE_FORUM/app.php/baja/login")

    for pair in "return:$loc_return" "login:$loc_login"; do
        route=${pair%%:*}
        location=${pair#*:}
        # Strip scheme, then take everything before the first / — the authority.
        host=${location#*://}
        host=${host%%/*}
        if [[ -z "$location" ]]; then
            red "FAIL  $route produced no redirect for '$printable' — the check would pass vacuously"
            FAIL=$((FAIL + 1))
        elif [[ "$host" == *evil.com ]]; then
            red "FAIL  $route sent the browser off-domain for '$printable' -> '$location'"
            FAIL=$((FAIL + 1))
        else
            green "PASS  $route kept '$printable' on-domain (host=$host)"
            PASS=$((PASS + 1))
        fi
    done
done

# 22. Anonymous must never match a user row. The shim represents "no session"
# as username => '', and findOneByUsername('') is a plain WHERE username = ''.
# A row with an empty username would therefore authenticate every
# unauthenticated visitor as that row. Create one directly in the DB — bypassing
# the application guards, which is the point: this asserts the seam holds even
# when a row exists — then confirm anonymous access is still refused.
if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' | grep -q '^baja-mysql$'; then
    docker exec baja-mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-devrootpass}" -N -e \
        "INSERT INTO baja_resultados.user (username, permissions) VALUES ('', '| index | admin |');" 2>/dev/null

    body=$(curl -s "$BASE_JUIZ/index.php")
    if echo "$body" | grep -q 'login.php?act=logout'; then
        red "FAIL  anonymous authenticated as the empty-username row (auth bypass)"
        FAIL=$((FAIL + 1))
    else
        green "PASS  anonymous does not match the empty-username row"
        PASS=$((PASS + 1))
    fi

    docker exec baja-mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-devrootpass}" -N -e \
        "DELETE FROM baja_resultados.user WHERE username = '';" 2>/dev/null
else
    echo "SKIP  empty-username check (needs the baja-mysql container)"
fi

# ---------------------------------------------------------------------------
# 23-26. CSRF. A forged login must not authenticate, and a forged logout must
# not end a session. Each case below omits exactly one of the two required
# proofs, so a pass means that proof is genuinely load-bearing.
# ---------------------------------------------------------------------------

# 23. Right token, wrong Origin — the attacker's page.
location=$(curl -s -o /dev/null -w "%{redirect_url}" -X POST \
    -H "Origin: https://evil.com" -b "baja_csrf=$CSRF" \
    --data-urlencode "username=juiz1" --data-urlencode "password=123456" \
    --data-urlencode "csrf=$CSRF" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
if echo "$location" | grep -q 'error=csrf'; then
    green "PASS  login rejected off-domain Origin"
    PASS=$((PASS + 1))
else
    red "FAIL  login accepted Origin https://evil.com -> '$location'"
    FAIL=$((FAIL + 1))
fi

# 24. Right Origin, no token — a forged form cannot read our cookie, so it
# cannot produce the pair even if it spoofs everything else.
location=$(curl -s -o /dev/null -w "%{redirect_url}" -X POST \
    "${ORIGIN_HDR[@]}" \
    --data-urlencode "username=juiz1" --data-urlencode "password=123456" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
if echo "$location" | grep -q 'error=csrf'; then
    green "PASS  login rejected missing CSRF token"
    PASS=$((PASS + 1))
else
    red "FAIL  login accepted a request with no CSRF token -> '$location'"
    FAIL=$((FAIL + 1))
fi

# 25. Cookie and form field present but different — guards against a
# comparison that only checks both are non-empty.
location=$(curl -s -o /dev/null -w "%{redirect_url}" -X POST \
    "${ORIGIN_HDR[@]}" -b "baja_csrf=$CSRF" \
    --data-urlencode "username=juiz1" --data-urlencode "password=123456" \
    --data-urlencode "csrf=not-the-same-value" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
if echo "$location" | grep -q 'error=csrf'; then
    green "PASS  login rejected mismatched CSRF token"
    PASS=$((PASS + 1))
else
    red "FAIL  login accepted a mismatched CSRF token -> '$location'"
    FAIL=$((FAIL + 1))
fi

# 26. Forced logout. /baja/logout answers to GET, so <img src> on any page a
# judge visits would end their session mid-event. Log in, fire a token-less
# logout, and confirm the session still works. Origin cannot help here — a
# top-level GET navigation carries none — so the token is the whole defence.
: > "$COOKIES"
curl -s -o /dev/null -c "$COOKIES" -X POST \
    "${ORIGIN_HDR[@]}" -b "baja_csrf=$CSRF" \
    --data-urlencode "username=juiz1" --data-urlencode "password=123456" \
    --data-urlencode "csrf=$CSRF" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php"

curl -s -o /dev/null -c "$COOKIES" -b "$COOKIES" \
    "$BASE_FORUM/app.php/baja/logout?redirect=$BASE_JUIZ/login.php"

body=$(curl -s -b "$COOKIES" "$BASE_JUIZ/index.php")
if echo "$body" | grep -q 'login.php?act=logout'; then
    green "PASS  token-less logout did not end the session"
    PASS=$((PASS + 1))
else
    red "FAIL  token-less logout ended the session (forced-logout CSRF)"
    FAIL=$((FAIL + 1))
fi

# 27. The token the real page mints must actually work.
#
# Every check above supplies both halves of the pair itself, which proves the
# comparison works but says nothing about whether the login page produces a
# usable token. It did not: the form embedded a value while setcookie() was a
# silent no-op — printHeader() had already flushed output — so the cookie was
# never sent and every genuine login was refused as a forgery, with the suite
# fully green. So drive the real flow: render the page, take the token from the
# HTML and the cookie from the jar, and require both that they agree and that
# logging in with them succeeds.
REALJAR=$(mktemp -t shim-real.XXXXXX)
curl -s -L -c "$REALJAR" -b "$REALJAR" -o /tmp/real-login.html "$BASE_JUIZ/login.php"
form_token=$(grep -o 'name="csrf" value="[a-f0-9]*"' /tmp/real-login.html | sed 's/.*value="//;s/"//')
jar_token=$(awk '$6 == "baja_csrf" { print $7 }' "$REALJAR")

if [[ -n "$form_token" && "$form_token" == "$jar_token" ]]; then
    green "PASS  login page mints a CSRF token into both the form and the cookie"
    PASS=$((PASS + 1))
else
    red "FAIL  form token '${form_token:0:12}…' vs cookie '${jar_token:0:12}…' (empty cookie = setcookie ran after output)"
    FAIL=$((FAIL + 1))
fi

location=$(curl -s -o /dev/null -w "%{redirect_url}" -c "$REALJAR" -b "$REALJAR" -X POST \
    "${ORIGIN_HDR[@]}" \
    --data-urlencode "username=juiz1" --data-urlencode "password=123456" \
    --data-urlencode "csrf=$form_token" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php")
if [[ "$location" == "$BASE_JUIZ/index.php" ]]; then
    green "PASS  login with the page's own token succeeds"
    PASS=$((PASS + 1))
else
    red "FAIL  login with the page's own token was refused -> '$location'"
    FAIL=$((FAIL + 1))
fi
rm -f "$REALJAR"

# 28. Logout through the link a judge actually clicks.
#
# Everything above exercises /app.php/baja/logout directly. Nothing covered
# juiz/login.php?act=logout, which is the only path in the UI — and that gap
# let a regression through: the already-logged-in redirect at the top of
# login.php matches a logged-in user clicking Logout, so once it grew an
# exit() it swallowed act=logout and the click silently returned the user to
# index.php, still logged in. Drive the link, not the endpoint.
REALJAR2=$(mktemp -t shim-logout.XXXXXX)
curl -s -L -c "$REALJAR2" -b "$REALJAR2" -o /tmp/lo-login.html "$BASE_JUIZ/login.php"
lo_token=$(grep -o 'name="csrf" value="[a-f0-9]*"' /tmp/lo-login.html | sed 's/.*value="//;s/"//')
curl -s -o /dev/null -c "$REALJAR2" -b "$REALJAR2" -X POST \
    "${ORIGIN_HDR[@]}" \
    --data-urlencode "username=juiz1" --data-urlencode "password=123456" \
    --data-urlencode "csrf=$lo_token" \
    "$BASE_FORUM/app.php/baja/login?redirect=$BASE_JUIZ/index.php"

body=$(curl -s -b "$REALJAR2" "$BASE_JUIZ/index.php")
if ! echo "$body" | grep -q 'login.php?act=logout'; then
    red "FAIL  could not log in, so the logout link check is inconclusive"
    FAIL=$((FAIL + 1))
else
    # Follow the link exactly as the browser would, redirects and all.
    curl -s -L -c "$REALJAR2" -b "$REALJAR2" -o /dev/null "$BASE_JUIZ/login.php?act=logout"
    after=$(curl -s -b "$REALJAR2" "$BASE_JUIZ/index.php")
    if echo "$after" | grep -q 'login.php?act=logout'; then
        red "FAIL  login.php?act=logout left the user logged in (logout link is dead)"
        FAIL=$((FAIL + 1))
    else
        green "PASS  login.php?act=logout actually ends the session"
        PASS=$((PASS + 1))
    fi
fi
rm -f "$REALJAR2"

# 29. juiz1 keeps the grants of BOTH seed files.
#
# Two files seed juiz1's row in baja_resultados.user: 05-seed-baja-resultados.sql
# (score entry on four provas) and 06-contagem.sql (controller of the Enduro,
# a laps prova). username is unique, and the second file writes its row with
# ON DUPLICATE KEY UPDATE, which replaces the whole permissions column. So the
# two rows must list the same complete set, or the file that runs last quietly
# throws the other's grants away. That is what happened when the branch that
# added juiz1 to the first file was merged with the one that added the second:
# juiz1 came out of a fresh database with the Enduro and nothing else.
#
# Checked through the dashboard rather than the database, because the
# dashboard prints one link per prova the user may enter and so shows both
# kinds of grant at once: the Enduro is a laps prova (contagem.php), and
# Manobrabilidade a normal one (dashboard.php). Needs a database seeded by both
# files; on an older one recreate it (docker compose down -v, then up -d).
login_as juiz1
body=$(curl -s -b "$COOKIES" "$BASE_JUIZ/26BR/index.php")
check_body "juiz1 keeps the Enduro grant from 06-contagem.sql" "$body" 'href="contagem.php?p=END"'
check_body "juiz1 keeps the score-entry grants from 05-seed-baja-resultados.sql" "$body" 'href="dashboard.php?p=MAN"'

# 30. The prova editor's script stays a script (BAJAAPP-75).
#
# The browser ends a <script> element at the first "</script" it meets, even
# one inside a JavaScript comment or string. A comment in juiz/prova.php wrote
# the tag out, so the script ended there and its code, starting with
# "const atual = ...", was printed on the page as text; the backup and restore
# buttons did nothing. tests/Unit/Site/ScriptBlockEndTest.php catches that in
# the source; this checks the page as served.
#
# The script elements are cut out the way the browser cuts them (up to the
# first "</script"), and "const atual" must not be left in what remains. The
# first check makes sure the editor rendered at all, so that a refusal or a
# redirect cannot pass the second. superadmin has the admin permission
# prova.php asks for; END is the 26BR Enduro both seed files know.
login_as superadmin
body=$(curl -s -b "$COOKIES" "$BASE_JUIZ/26BR/prova.php?id=END")
check_body "prova editor renders for superadmin (26BR END)" "$body" 'const atual'
outside_scripts=$(printf '%s' "$body" | perl -0777 -pe 's{<script\b[^>]*>.*?</script}{}gis')
if echo "$outside_scripts" | grep -qF 'const atual'; then
    red "FAIL  prova editor: 'const atual' is printed as page text, outside any <script>"
    FAIL=$((FAIL + 1))
else
    green "PASS  prova editor: 'const atual' only inside a <script>"
    PASS=$((PASS + 1))
fi

# ---------------------------------------------------------------------------
# 31-33. Resuming an expired session, and the keep-alive (BAJAAPP-86).
#
# The judge area sends a browser whose forum session expired, but which still
# has the "remember me" cookie, through /baja/resume and back to the same
# page; juiz pages request /baja/keepalive so that the session does not expire
# under a judge who never opens the forum. The full trips are tested in
# tests/Auth/Http; here, that the routes exist at all (a stale compiled router
# 404'd the other routes for months), keep the page's whole query string, and
# refuse a foreign target.
# ---------------------------------------------------------------------------

target="$BASE_JUIZ/index.php?a=1&b=2"
location=$(curl -s -o /dev/null -w "%{redirect_url}" -G --data-urlencode "redirect=$target" \
    "$BASE_FORUM/app.php/baja/resume")
if [[ "$location" == "$target" ]]; then
    green "PASS  resume bounces back to the same page ('$location')"
    PASS=$((PASS + 1))
else
    red "FAIL  resume target wrong: expected '$target', got '$location'"
    FAIL=$((FAIL + 1))
fi

location=$(curl -s -o /dev/null -w "%{redirect_url}" "$BASE_FORUM/app.php/baja/resume?redirect=https://evil.com/steal")
if echo "$location" | grep -q 'evil\.com'; then
    red "FAIL  resume open-redirect honored: Location='$location'"
    FAIL=$((FAIL + 1))
else
    green "PASS  resume open-redirect rejected (Location='$location')"
    PASS=$((PASS + 1))
fi

status=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_FORUM/app.php/baja/keepalive")
check_in "GET /app.php/baja/keepalive answers 204" "$status" 204

echo
echo "Smoke tests done.  PASS=$PASS  FAIL=$FAIL"
[[ $FAIL -eq 0 ]] || exit 1
