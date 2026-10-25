#!/usr/bin/env bash
# HTTP tests for the results cache and the judges' staff cookie
# (docs/results-caching.md, BAJAAPP-23).
#
# What they check, in plain words: a visitor without the staff cookie always
# gets the same public page, marked cacheable, and never a cookie; only the
# right cookie AND a judge's session in the resultado's authorized_users see
# past the spoilers; and any response built with a session is marked
# "private, no-store", so no cache ever keeps it.
#
# These need nginx and PHP both in the path (nginx picks the public or the
# session path from the cookie), so they run against a running dev stack,
# from the host:
#
#   baja-php/tests/results-caching/http-test.sh [results-url] [juiz-url]
#
# The defaults are http://resultados.baja.local and http://juiz.baja.local.
# The URLs must use the vhosts' own hostnames, not 127.0.0.1: nginx picks the
# server block by Host. If they are not in your hosts file, point curl at the
# stack with CURL_EXTRA instead:
#
#   CURL_EXTRA="--resolve resultados.baja.local:80:127.0.0.1 --resolve juiz.baja.local:80:127.0.0.1" \
#       baja-php/tests/results-caching/http-test.sh
#
# Like tests/certificado/insercao-http-test.sh, it sets up what it needs
# through `docker exec` into the app container (APP_CONTAINER, default
# baja-app): a made-up event 29NE with spoilers on (fixture.sql next to this
# file), four ZZStaff* users, and their forum sessions, seeded straight into
# the Redis session cache instead of logging in through the forum. It reads
# the right cookie value from that container's STAFF_COOKIE_VALUE. All of it
# is removed at the end, including on failure. It refuses to run unless the
# container says ENV=dev.
#
# Cloudflare is not in the path here, so this checks what the server sends;
# docs/results-caching.md has the curl checklist for the real thing.

set -u

RESULTS="${1:-http://resultados.baja.local}"
JUIZ="${2:-http://juiz.baja.local}"
# shellcheck disable=SC2206
CURL_ARGS=(${CURL_EXTRA:-})
APP="${APP_CONTAINER:-baja-app}"
DIR="$(cd "$(dirname "$0")" && pwd)"

PASS=0
FAIL=0
SKIP=0

green() { printf '\e[32m%s\e[0m\n' "$1"; }
red()   { printf '\e[31m%s\e[0m\n' "$1"; }
grey()  { printf '\e[90m%s\e[0m\n' "$1"; }

ok()   { green "PASS  $1"; PASS=$((PASS + 1)); }
bad()  { red   "FAIL  $1"; FAIL=$((FAIL + 1)); }
skip() { grey  "SKIP  $1"; SKIP=$((SKIP + 1)); }

same() { # name expected actual
    if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1 (expected \"$2\", got \"$3\")"; fi
}

contains() { # name needle haystack
    if grep -qiF -- "$2" <<< "$3"; then ok "$1"; else bad "$1 (did not find \"$2\")"; fi
}

lacks() { # name needle haystack
    if grep -qiF -- "$2" <<< "$3"; then bad "$1 (found \"$2\")"; else ok "$1"; fi
}

finish() {
    echo
    echo "$PASS passed, $FAIL failed, $SKIP skipped"
    [[ $FAIL -eq 0 ]]
    exit $?
}

if ! docker exec "$APP" true 2>/dev/null; then
    skip "results cache tests (no $APP container; set APP_CONTAINER)"
    finish
fi
if [[ "$(docker exec "$APP" printenv ENV)" != "dev" ]]; then
    red "$APP is not a dev container (ENV is not dev); this script writes a test event, so it stops here."
    exit 1
fi

# The right cookie value, as nginx and baja-app know it.
STAFF="$(docker exec "$APP" printenv STAFF_COOKIE_VALUE)"
if [[ -z "$STAFF" ]]; then
    red "$APP has no STAFF_COOKIE_VALUE; see baja-infra/.env.example"
    exit 1
fi

# --- Fixtures ----------------------------------------------------------------
#
# Four users and their forum session ids (32 characters, like phpBB's):
#
#   SID_AUT  ZZStaffAutorizado  judge, in 29NE_SPL's authorized_users
#   SID_JUI  ZZStaffJuiz        judge, NOT in authorized_users
#   SID_EQU  ZZStaffEquipe      team member: a queue (fila) permission only
#   SID_SEM  ZZStaffSemConta    logged in to the forum, no account here
#   SID_GUE  a forum guest: phpBB gives every visitor a session id, with
#            _u=1 (the anonymous user). Not seeded; it is not looked up.
SID_AUT=zzstaffaut00000000000000000000000
SID_JUI=zzstaffjui00000000000000000000000
SID_EQU=zzstaffequ00000000000000000000000
SID_SEM=zzstaffsem00000000000000000000000
SID_GUE=zzstaffgue00000000000000000000000

php_in_app() { # code, run with the app's autoloader and database
    docker exec -i "$APP" php -r 'require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";'"$1"
}

cleanup() {
    php_in_app '
        $r = new Redis();
        $r->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
        foreach (["'"$SID_AUT"'", "'"$SID_JUI"'", "'"$SID_EQU"'", "'"$SID_SEM"'"] as $sid) {
            $r->del("baja:phpbb:session:" . $sid);
        }
        \Baja\Model\UserQuery::create()
            ->filterByUsername(["ZZStaffAutorizado", "ZZStaffJuiz", "ZZStaffEquipe"])
            ->delete();
        \Propel\Runtime\Propel::getConnection()->exec("DELETE FROM evento WHERE evento_id = \"29NE\"");
    ' >/dev/null 2>&1
}
trap cleanup EXIT
cleanup  # left over from a run that was interrupted

# fixture.sql, one statement at a time, so that an error in any of them stops.
php_in_app '
    $db = \Propel\Runtime\Propel::getConnection();
    foreach (preg_split("/;\s*$/m", stream_get_contents(STDIN)) as $statement) {
        if (trim(preg_replace("/^--.*$/m", "", $statement)) !== "") {
            $db->exec($statement);
        }
    }
    foreach ([
        ["ZZStaffAutorizado", ["index", "29NE_SPL"]],
        ["ZZStaffJuiz", ["index", "29NE_SPL"]],
        ["ZZStaffEquipe", ["29NE_FILA_1_1"]],
    ] as [$nome, $perms]) {
        $u = \Baja\Model\UserQuery::create()->findOneByUsername($nome) ?? new \Baja\Model\User();
        $u->setUsername($nome);
        $u->setPermissions($perms);
        $u->save();
    }
    $r = new Redis();
    $r->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
    foreach ([
        "'"$SID_AUT"'" => "ZZStaffAutorizado",
        "'"$SID_JUI"'" => "ZZStaffJuiz",
        "'"$SID_EQU"'" => "ZZStaffEquipe",
        "'"$SID_SEM"'" => "ZZStaffSemConta",
    ] as $sid => $nome) {
        $r->setex("baja:phpbb:session:" . $sid, 900, json_encode(["user_id" => 2, "username" => $nome]));
    }
' < "$DIR/fixture.sql" >/dev/null || { red "could not load the fixtures"; exit 1; }

# --- Requests ------------------------------------------------------------------
#
# Each takes the cookies to send ("" for none) and a URL, and prints the
# response headers then the body. session_cookies <sid> gives the forum's
# cookies for a session; staff_cookie <value> the staff cookie.

session_cookies() { echo "phpbb3_baja_sid=$1; phpbb3_baja_u=2"; }
guest_cookies()   { echo "phpbb3_baja_sid=$SID_GUE; phpbb3_baja_u=1"; }

fetch() { # cookies url  -> headers, blank line, body
    local jar=()
    [[ -n "$1" ]] && jar=(-b "$1")
    curl -s "${CURL_ARGS[@]}" "${jar[@]}" -D - "$2"
}
headers_of() { sed '/^\r\?$/q' <<< "$1"; }
body_of()    { sed '1,/^\r\?$/d' <<< "$1"; }
status_of()  { head -1 <<< "$1" | awk '{print $2}'; }
header_value() { # name response
    headers_of "$2" | grep -i "^$1:" | head -1 | cut -d: -f2- | sed 's/^ *//; s/\r$//'
}

PAGE="$RESULTS/29NE/prova.php?id=29NE_SPL"
LIVE="public, max-age=10, s-maxage=15"
FINISHED="public, max-age=300, s-maxage=3600"
PRIVATE="private, no-store"

# The two things a body can show for car 1's Pontos.
SPOILER_TEXT="ZZOCULTO"
REAL_VALUE="87.5"

echo
echo "--- no staff cookie: the public page, the same for everybody ---"
anon=$(fetch "" "$PAGE")
same "200 OK" 200 "$(status_of "$anon")"
same "cacheable while the event is not finished" "$LIVE" "$(header_value Cache-Control "$anon")"
lacks "no cookie is set" "Set-Cookie" "$(headers_of "$anon")"
contains "the spoiler is shown" "$SPOILER_TEXT" "$(body_of "$anon")"
lacks "the real value is not" "$REAL_VALUE" "$(body_of "$anon")"

guest=$(fetch "$(guest_cookies)" "$PAGE")
same "a forum guest's cookies change nothing in the body" "$(body_of "$anon")" "$(body_of "$guest")"
same "nor in the header" "$LIVE" "$(header_value Cache-Control "$guest")"
lacks "and set no cookie" "Set-Cookie" "$(headers_of "$guest")"

# The session is not even read without the staff cookie: an authorized judge
# who lost the cookie gets the public page too.
judge_no_staff=$(fetch "$(session_cookies "$SID_AUT")" "$PAGE")
same "an authorized judge's session without the staff cookie: same body" "$(body_of "$anon")" "$(body_of "$judge_no_staff")"
same "and public header" "$LIVE" "$(header_value Cache-Control "$judge_no_staff")"

echo
echo "--- a wrong staff cookie: exactly as no cookie ---"
for wrong in "wrong" "${STAFF}x" "${STAFF:0:20}"; do
    resp=$(fetch "baja_staff=$wrong; $(session_cookies "$SID_AUT")" "$PAGE")
    same "baja_staff=${wrong:0:12}...: same body as no cookie" "$(body_of "$anon")" "$(body_of "$resp")"
    same "baja_staff=${wrong:0:12}...: public header" "$LIVE" "$(header_value Cache-Control "$resp")"
    lacks "baja_staff=${wrong:0:12}...: no cookie set" "Set-Cookie" "$(headers_of "$resp")"
done

echo
echo "--- the right staff cookie, no session: public view, never cached ---"
resp=$(fetch "baja_staff=$STAFF" "$PAGE")
same "no-store" "$PRIVATE" "$(header_value Cache-Control "$resp")"
contains "the spoiler is shown" "$SPOILER_TEXT" "$(body_of "$resp")"
lacks "the real value is not" "$REAL_VALUE" "$(body_of "$resp")"
resp=$(fetch "baja_staff=$STAFF; $(guest_cookies)" "$PAGE")
same "same with a forum guest's cookies" "$PRIVATE" "$(header_value Cache-Control "$resp")"
contains "and the spoiler" "$SPOILER_TEXT" "$(body_of "$resp")"

echo
echo "--- the right staff cookie and a session in authorized_users: past the spoilers ---"
resp=$(fetch "baja_staff=$STAFF; $(session_cookies "$SID_AUT")" "$PAGE")
same "no-store" "$PRIVATE" "$(header_value Cache-Control "$resp")"
contains "the real value is shown" "$REAL_VALUE" "$(body_of "$resp")"
lacks "the spoiler is not" "$SPOILER_TEXT" "$(body_of "$resp")"

echo
echo "--- the right staff cookie and a session NOT in authorized_users: public view ---"
for sid in "$SID_JUI" "$SID_EQU" "$SID_SEM"; do
    resp=$(fetch "baja_staff=$STAFF; $(session_cookies "$sid")" "$PAGE")
    same "${sid:0:10}: no-store" "$PRIVATE" "$(header_value Cache-Control "$resp")"
    contains "${sid:0:10}: the spoiler is shown" "$SPOILER_TEXT" "$(body_of "$resp")"
    lacks "${sid:0:10}: the real value is not" "$REAL_VALUE" "$(body_of "$resp")"
done

echo
echo "--- every public page: public without the cookie, no-store with it ---"
# The pages listed in docs/results-caching.md, with the parameters each reads.
PUBLIC_PAGES=(
    "/"
    "/29NE/"
    "/index.php"
    "/29NE/prova.php?id=29NE_SPL"
    "/24SU/torneio.php?id=24SU_SPR"
    "/mapa.php"
    "/clock.php"
    "/notificacoes.php"
    "/overlay.php?evt=29NE&ovl=1"
    "/overlaydata.php?evt=29NE&mode=json"
    "/enduro_dump.php"
)
for page in "${PUBLIC_PAGES[@]}"; do
    resp=$(fetch "" "$RESULTS$page")
    cc=$(header_value Cache-Control "$resp")
    if [[ "$cc" == public,* ]]; then ok "$page: public ($cc)"; else bad "$page: expected a public header, got \"$cc\""; fi
    lacks "$page: no cookie set" "Set-Cookie" "$(headers_of "$resp")"

    for sid in "$SID_AUT" "$SID_EQU" ""; do
        cookies="baja_staff=$STAFF"
        [[ -n "$sid" ]] && cookies="$cookies; $(session_cookies "$sid")"
        resp=$(fetch "$cookies" "$RESULTS$page")
        same "$page with the staff cookie${sid:+ and session ${sid:0:10}}: no-store" \
            "$PRIVATE" "$(header_value Cache-Control "$resp")"
    done
done

echo
echo "--- how long: finished events are cached longer ---"
resp=$(fetch "" "$RESULTS/22BR/prova.php?id=22BR_GER")
same "22BR is finished: the long cache" "$FINISHED" "$(header_value Cache-Control "$resp")"
resp=$(fetch "" "$RESULTS/24SU/torneio.php?id=24SU_SPR")
same "torneio.php goes by its resultado's event (24SU, finished)" "$FINISHED" "$(header_value Cache-Control "$resp")"

echo
echo "--- clean URLs: tracking tags and junk are redirected away ---"
resp=$(fetch "" "$RESULTS/29NE/prova.php?fbclid=IwAR0abc&id=29NE_SPL&utm_source=x")
same "301" 301 "$(status_of "$resp")"
same "to the same page with only id" "/29NE/prova.php?id=29NE_SPL" "$(header_value Location "$resp")"
same "and the redirect is cacheable" "$FINISHED" "$(header_value Cache-Control "$resp")"
lacks "and sets no cookie" "Set-Cookie" "$(headers_of "$resp")"
resp=$(fetch "" "$RESULTS/torneio.php?overlay=1&id=24SU_SPR")
same "parameters in another order: 301" 301 "$(status_of "$resp")"
same "to the page's order" "/torneio.php?id=24SU_SPR&overlay=1" "$(header_value Location "$resp")"
resp=$(fetch "" "$RESULTS/?utm_campaign=baja")
same "the index too" "/" "$(header_value Location "$resp")"
resp=$(fetch "" "$RESULTS/29NE/prova.php?id=29NE_SPL")
same "the clean URL itself is not redirected" 200 "$(status_of "$resp")"
resp=$(fetch "baja_staff=$STAFF; $(session_cookies "$SID_AUT")" "$RESULTS/29NE/prova.php?id=29NE_SPL&fbclid=x")
same "with the staff cookie the redirect still happens" 301 "$(status_of "$resp")"
same "but is not cached" "$PRIVATE" "$(header_value Cache-Control "$resp")"

echo
echo "--- the judges' former URLs ---"
resp=$(fetch "" "$RESULTS/29NE/prova_juiz.php?id=29NE_SPL&x=1")
same "prova_juiz.php: 301" 301 "$(status_of "$resp")"
same "to prova.php, same id and event" "/29NE/prova.php?id=29NE_SPL" "$(header_value Location "$resp")"
same "not cached" "$PRIVATE" "$(header_value Cache-Control "$resp")"

resp=$(fetch "$(session_cookies "$SID_JUI")" "$RESULTS/22BR/index_juiz.php")
same "index_juiz.php: not cached" "$PRIVATE" "$(header_value Cache-Control "$resp")"
contains "its menu links to prova.php" 'href="prova.php?id=' "$(body_of "$resp")"
lacks "and never to prova_juiz.php" "prova_juiz.php" "$(body_of "$resp")"
lacks "nor does the public menu" "prova_juiz.php" "$(body_of "$(fetch "" "$RESULTS/22BR/")")"

echo
echo "--- the staff cookie: set for judges only (juiz area, where login lands) ---"
# After a login the forum sends the browser to the judge area's index.php;
# that page load is where the cookie is set.
resp=$(fetch "$(session_cookies "$SID_AUT")" "$JUIZ/index.php")
setc=$(headers_of "$resp" | grep -i "^Set-Cookie: baja_staff=")
contains "a judge gets the cookie" "baja_staff=$STAFF" "$setc"
contains "on the parent domain" "domain=." "$setc"
contains "HttpOnly" "HttpOnly" "$setc"
contains "SameSite=Lax" "SameSite=Lax" "$setc"
contains "Path=/" "path=/" "$setc"

resp=$(fetch "$(session_cookies "$SID_JUI")" "$JUIZ/index.php")
contains "a judge not in any authorized_users gets it too (it is not about spoilers)" "baja_staff=$STAFF" "$(headers_of "$resp")"

resp=$(fetch "baja_staff=$STAFF; $(session_cookies "$SID_AUT")" "$JUIZ/index.php")
lacks "a judge who has it is not sent it again" "baja_staff=" "$(headers_of "$resp")"

resp=$(fetch "baja_staff=an-old-value; $(session_cookies "$SID_AUT")" "$JUIZ/index.php")
contains "a judge with an old value gets the new one" "baja_staff=$STAFF" "$(headers_of "$resp")"

resp=$(fetch "$(session_cookies "$SID_EQU")" "$JUIZ/index.php")
lacks "a team member does not get it" "baja_staff=" "$(headers_of "$resp")"
resp=$(fetch "$(session_cookies "$SID_SEM")" "$JUIZ/index.php")
lacks "a forum user with no account here does not get it" "baja_staff=" "$(headers_of "$resp")"
resp=$(fetch "" "$JUIZ/login.php")
lacks "nor does a visitor who is not logged in" "baja_staff=" "$(headers_of "$resp")"

resp=$(fetch "baja_staff=$STAFF; $(session_cookies "$SID_EQU")" "$JUIZ/index.php")
contains "a team member who has it (copied) loses it" "baja_staff=deleted" "$(headers_of "$resp")"

echo
echo "--- logout clears it ---"
resp=$(fetch "baja_staff=$STAFF; $(session_cookies "$SID_AUT")" "$JUIZ/login.php?act=logout")
same "logout redirects to the forum" 302 "$(status_of "$resp")"
contains "and deletes the staff cookie" "baja_staff=deleted" "$(headers_of "$resp")"

finish
