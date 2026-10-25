#!/usr/bin/env bash
# HTTP tests for the judge and admin forms' CSRF tokens (BAJAAPP-41).
#
# What CSRF is, in one paragraph: the browser sends the judge's session
# cookie with every request to the judge area, even one started by a page on
# another site. So that other page could submit, say, "delete this prova"
# with the judge's rights. Every form that changes something now carries a
# token the other site cannot know, and the page refuses a request without it
# (src/Baja/Auth/Csrf.php explains the token).
#
# What this checks, for every form listed in cases/*.sh:
#
#   1. the page that shows the form puts a token in it;
#   2. the same request WITHOUT the token is refused (403) and changes nothing;
#   3. with the token of ANOTHER logged-in session it is refused, unchanged;
#   4. as a GET (a link, or an <img src> on another site) it changes nothing;
#   5. with the right token it works: the change is made.
#
# "Changes nothing" is checked in the database, not in the response: each
# case gives a bit of PHP that prints the state the form would change, and
# the state is compared before and after.
#
# Run from the host against a running stack:
#   baja-php/tests/csrf/http-test.sh [juiz-base-url]
#
# The base URL must be the juiz vhost's own hostname (nginx picks the server
# by Host). Without a hosts-file entry:
#   CURL_EXTRA="--resolve juiz.baja.local:80:127.0.0.1" \
#       baja-php/tests/csrf/http-test.sh http://juiz.baja.local
#
# It loads a made-up event, 28SU (fixture.sql next to this file), test users
# and their forum sessions through `docker exec` into the app container (set
# APP_CONTAINER if yours is not called baja-app), and removes them at the
# end, also on failure. It refuses to run unless that container has ENV=dev.
#
# Adding a form: write a case in the cases/ file of its group, see "Cases"
# below. Each cases/*.sh file is read in alphabetical order.

set -u

BASE="${1:-http://juiz.baja.local}"
# shellcheck disable=SC2206
CURL_ARGS=(${CURL_EXTRA:-})
APP="${APP_CONTAINER:-baja-app}"
DIR="$(cd "$(dirname "$0")" && pwd)"
EVT=28SU

PASS=0
FAIL=0
SKIP=0

green() { printf '\e[32m%s\e[0m\n' "$1"; }
red()   { printf '\e[31m%s\e[0m\n' "$1"; }
grey()  { printf '\e[90m%s\e[0m\n' "$1"; }

ok()   { green "PASS  $1"; PASS=$((PASS + 1)); }
bad()  { red   "FAIL  $1"; FAIL=$((FAIL + 1)); }
skip() { grey  "SKIP  $1"; SKIP=$((SKIP + 1)); }

finish() {
    echo
    echo "$PASS passed, $FAIL failed, $SKIP skipped"
    [[ $FAIL -eq 0 ]]
    exit $?
}

if ! docker exec "$APP" true 2>/dev/null; then
    skip "CSRF form tests (no $APP container; set APP_CONTAINER)"
    finish
fi
if [[ "$(docker exec "$APP" printenv ENV)" != "dev" ]]; then
    red "$APP is not a dev container (ENV is not dev); this script writes a test event, so it stops here."
    exit 1
fi

# --- Fixtures ----------------------------------------------------------------
#
# Three users and their forum session ids (32 characters, like phpBB's):
#
#   SID_ADM  ZZCsrfAdmin  admin: may use every form
#   SID_OUT  ZZCsrfOutro  another admin. Only its token is used: a valid
#                         token, but of another session, must be refused
#   SID_JUI  ZZCsrfJuiz   judge of prova ZZA only, for the judge's forms
SID_ADM=zzcsrfadm000000000000000000000000
SID_OUT=zzcsrfout000000000000000000000000
SID_JUI=zzcsrfjui000000000000000000000000

php_in_app() { # code, run with the app's autoloader and database; prints what it echoes
    docker exec -i "$APP" php -r 'require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php"; use Baja\Model\EventoQuery; use Baja\Model\ProvaQuery; use Baja\Model\ResultadoQuery; use Baja\Model\EquipeQuery; use Baja\Model\InputQuery; use Baja\Model\UserQuery;'"$1"
}

# Cases add PHP here to remove what they created outside the 28SU event.
EXTRA_CLEANUP=""

cleanup() {
    php_in_app '
        $r = new Redis();
        $r->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
        foreach (["'"$SID_ADM"'", "'"$SID_OUT"'", "'"$SID_JUI"'"] as $sid) {
            $r->del("baja:phpbb:session:" . $sid);
        }
        UserQuery::create()->filterByUsername("ZZCsrf%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)->delete();
        \Propel\Runtime\Propel::getConnection()->exec("DELETE FROM evento WHERE evento_id LIKE \"28SU%\"");
        '"$EXTRA_CLEANUP"'
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
        ["ZZCsrfAdmin", ["index", "admin"]],
        ["ZZCsrfOutro", ["index", "admin"]],
        ["ZZCsrfJuiz", ["index", "28SU_ZZA"]],
    ] as [$nome, $perms]) {
        $u = UserQuery::create()->findOneByUsername($nome) ?? new \Baja\Model\User();
        $u->setUsername($nome);
        $u->setPermissions($perms);
        $u->save();
    }
    $r = new Redis();
    $r->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
    foreach ([
        "'"$SID_ADM"'" => "ZZCsrfAdmin",
        "'"$SID_OUT"'" => "ZZCsrfOutro",
        "'"$SID_JUI"'" => "ZZCsrfJuiz",
    ] as $sid => $nome) {
        $r->setex("baja:phpbb:session:" . $sid, 1800, json_encode(["user_id" => 2, "username" => $nome]));
    }
' < "$DIR/fixture.sql" >/dev/null || { red "could not load the fixtures"; exit 1; }

# --- Requests ------------------------------------------------------------------

cookies() { echo "phpbb3_baja_sid=$1; phpbb3_baja_u=2"; }

# The token a page shows to session $1: the hidden form field, or the
# <meta name="csrf-token"> that scripts read. Empty when the page has none.
token_on_page() { # sid path
    curl -s "${CURL_ARGS[@]}" -b "$(cookies "$1")" "$BASE$2" \
        | grep -oE '(name="_csrf" value="|name="csrf-token" content=")[0-9a-f]{64}' \
        | head -1 | grep -oE '[0-9a-f]{64}$'
}

# POST a form. $3 is the token to send ("" for none); the rest are fields,
# each "name=value", sent url-encoded. Prints the HTTP status.
post_form() { # sid path token field...
    local sid=$1 path=$2 token=$3; shift 3
    local data=()
    for f in "$@"; do data+=(--data-urlencode "$f"); done
    [[ -n "$token" ]] && data+=(--data-urlencode "_csrf=$token")
    curl -s "${CURL_ARGS[@]}" -b "$(cookies "$sid")" -o /dev/null -w '%{http_code}' "${data[@]}" "$BASE$path"
}

# The same fields as a GET query string. Prints the HTTP status.
get_form() { # sid path field...
    local sid=$1 path=$2; shift 2
    local data=()
    for f in "$@"; do data+=(--data-urlencode "$f"); done
    curl -s "${CURL_ARGS[@]}" -b "$(cookies "$sid")" -o /dev/null -w '%{http_code}' -G "${data[@]}" "$BASE$path"
}

# POST a JSON body, with the token in the X-CSRF-Token header ("" for none).
post_json() { # sid path token body
    local hdr=()
    [[ -n "$3" ]] && hdr=(-H "X-CSRF-Token: $3")
    curl -s "${CURL_ARGS[@]}" -b "$(cookies "$1")" -o /dev/null -w '%{http_code}' \
        -H 'Content-Type: application/json' "${hdr[@]}" --data-binary "$4" "$BASE$2"
}

# --- Cases ---------------------------------------------------------------------
#
# A form case:
#
#   form_case "prova.php: Deletar Prova" \
#       "$SID_ADM"                      who submits it
#       "/$EVT/prova.php?id=ZZA"        the page showing the form (token from here)
#       "/$EVT/prova.php?id=ZZA"        where the form posts
#       'echo ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZA") ? "existe" : "apagada";'
#       "act=Deletar Prova"             the fields, as the browser sends them
#
# The PHP prints the state the form changes ("existe" / "apagada" above). The
# case passes when steps 2 to 4 leave that output as it was and step 5
# changes it. A case whose form makes no change on success when the state is
# already what it sets must set the fixture up so that it does.
#
# json_case is the same for a script's endpoint: the page whose <meta> holds
# the token, the endpoint, the state PHP, and the JSON body.

form_case() { # name sid form-page action state-php field...
    local name=$1 sid=$2 page=$3 action=$4 state=$5; shift 5
    local token before now code wrong

    token=$(token_on_page "$sid" "$page")
    if [[ -z "$token" ]]; then bad "$name: the form shows no token"; return; fi
    ok "$name: the form carries a token"
    wrong=$(token_on_page "$SID_OUT" "$page")

    before=$(php_in_app "$state")

    code=$(post_form "$sid" "$action" "" "$@")
    now=$(php_in_app "$state")
    if [[ "$code" == 403 && "$now" == "$before" ]]; then ok "$name: no token: 403, nothing changed"
    else bad "$name: no token (expected 403 and \"$before\", got $code and \"$now\")"; fi

    code=$(post_form "$sid" "$action" "$wrong" "$@")
    now=$(php_in_app "$state")
    if [[ -n "$wrong" && "$wrong" != "$token" && "$code" == 403 && "$now" == "$before" ]]; then ok "$name: another session's token: 403, nothing changed"
    else bad "$name: another session's token (expected 403 and \"$before\", got $code and \"$now\")"; fi

    code=$(get_form "$sid" "$action" "$@")
    now=$(php_in_app "$state")
    if [[ "$now" == "$before" ]]; then ok "$name: as a GET: nothing changed (got $code)"
    else bad "$name: as a GET it changed \"$before\" to \"$now\""; fi

    code=$(post_form "$sid" "$action" "$token" "$@")
    now=$(php_in_app "$state")
    if [[ "$code" != 403 && "$now" != "$before" ]]; then ok "$name: with the token: done (\"$before\" -> \"$now\")"
    else bad "$name: with the token (expected a change from \"$before\", got $code and \"$now\")"; fi
}

json_case() { # name sid token-page endpoint state-php body
    local name=$1 sid=$2 page=$3 endpoint=$4 state=$5 body=$6
    local token before now code wrong

    token=$(token_on_page "$sid" "$page")
    if [[ -z "$token" ]]; then bad "$name: the page shows no token"; return; fi
    wrong=$(token_on_page "$SID_OUT" "$page")
    before=$(php_in_app "$state")

    code=$(post_json "$sid" "$endpoint" "" "$body")
    now=$(php_in_app "$state")
    if [[ "$code" == 403 && "$now" == "$before" ]]; then ok "$name: no token: 403, nothing changed"
    else bad "$name: no token (expected 403 and \"$before\", got $code and \"$now\")"; fi

    code=$(post_json "$sid" "$endpoint" "$wrong" "$body")
    now=$(php_in_app "$state")
    if [[ -n "$wrong" && "$code" == 403 && "$now" == "$before" ]]; then ok "$name: another session's token: 403, nothing changed"
    else bad "$name: another session's token (expected 403 and \"$before\", got $code and \"$now\")"; fi

    code=$(post_json "$sid" "$endpoint" "$token" "$body")
    now=$(php_in_app "$state")
    if [[ "$code" != 403 && "$now" != "$before" ]]; then ok "$name: with the token: done (\"$before\" -> \"$now\")"
    else bad "$name: with the token (expected a change from \"$before\", got $code and \"$now\")"; fi
}

for cases in "$DIR"/cases/*.sh; do
    echo
    echo "--- $(basename "$cases" .sh) ---"
    # shellcheck source=/dev/null
    source "$cases"
done

finish
