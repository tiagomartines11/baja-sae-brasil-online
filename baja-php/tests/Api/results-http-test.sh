#!/usr/bin/env bash
# HTTP-level tests for the public results API (api/resultados/,
# docs/results-api.md).
#
# The PHPUnit suites cover what the endpoints answer (tests/Unit/Api and the
# results-api-db suite); these cover what only exists once nginx and PHP are
# both in the path: the vhost routing, SKIP_AUTH (no session, no cookie), the
# response headers Cloudflare caches by, CORS, and the method check.
#
# Run from the host against a stack serving the API vhost, on the dev seed:
#   baja-php/tests/Api/results-http-test.sh [base-url]
#
# The base URL must be the vhost's own hostname: nginx picks the server
# block by Host. If that hostname is not in /etc/hosts, point it at the
# stack with CURL_EXTRA instead of editing hosts:
#
#   CURL_EXTRA="--resolve api.baja.local:80:127.0.0.1" \
#       baja-php/tests/Api/results-http-test.sh http://api.baja.local
#
# It only reads. It sends about forty requests, well inside the rate limit.

set -u

BASE="${1:-http://api.baja.local}"
# Extra curl arguments, e.g. --resolve. Word-split on purpose.
# shellcheck disable=SC2206
CURL_ARGS=(${CURL_EXTRA:-})

PASS=0
FAIL=0

green() { printf '\e[32m%s\e[0m\n' "$1"; }
red()   { printf '\e[31m%s\e[0m\n' "$1"; }

ok() {
    local name="$1" condition="$2" detail="${3:-}"
    if [[ "$condition" == "1" ]]; then
        green "PASS  $name"
        PASS=$((PASS + 1))
    else
        red "FAIL  $name${detail:+ ($detail)}"
        FAIL=$((FAIL + 1))
    fi
}
is() { [[ "$1" == "$2" ]] && echo 1 || echo 0; }
has() { grep -qi -- "$2" <<<"$1" && echo 1 || echo 0; }
lacks() { grep -qi -- "$2" <<<"$1" && echo 0 || echo 1; }

status_of() { curl -s "${CURL_ARGS[@]}" -o /dev/null -w "%{http_code}" "$@"; }
headers_of() { curl -s "${CURL_ARGS[@]}" -D - -o /dev/null "$@" | tr -d '\r'; }
body_of() { curl -s "${CURL_ARGS[@]}" "$@"; }

EVENTO="$BASE/resultados/evento.php?evt=26BR"
EQUIPES="$BASE/resultados/equipes.php?evt=26BR"
RESULTADO="$BASE/resultados/resultado.php?evt=26BR&id=26BR_GER"
CORRIDA="$BASE/resultados/corrida.php?evt=26BR&p=END"
TORNEIO="$BASE/resultados/torneio.php?evt=26BR&id=26BR_SPR"

# --- every endpoint answers, publicly cacheable -------------------------------
#
# Cloudflare only stores an answer that says s-maxage. 26BR is finished, so
# 300 s everywhere (docs/results-api.md, "Caching").

for url in "$EVENTO" "$EQUIPES" "$RESULTADO" "$RESULTADO&display=1" "$CORRIDA" "$TORNEIO"; do
    name="${url#"$BASE"}"
    h=$(headers_of "$url")
    ok "$name answers 200" "$(has "$h" '^HTTP/[0-9.]* 200')"
    ok "$name is JSON" "$(has "$h" '^Content-Type: application/json')"
    ok "$name is cacheable by Cloudflare" "$(has "$h" '^Cache-Control: public, max-age=300, s-maxage=300$')"
    ok "$name allows any origin" "$(has "$h" '^Access-Control-Allow-Origin: \*$')"
done

# --- the answer depends only on the URL ---------------------------------------
#
# A cached answer is served to everybody, so nothing in it may depend on
# who asked: no session cookie (SKIP_AUTH), no Vary on the Origin.

h=$(headers_of -H "Origin: https://overlay.example" -H "Cookie: phpbb3_baja_sid=whatever" "$RESULTADO")
ok "no session cookie is set" "$(lacks "$h" '^Set-Cookie:')"
ok "the answer does not vary by origin" "$(lacks "$h" '^Vary:.*Origin')"
ok "an unknown origin still gets *" "$(has "$h" '^Access-Control-Allow-Origin: \*$')"

a=$(body_of "$RESULTADO")
b=$(body_of -H "Origin: https://other.example" -H "Cookie: phpbb3_baja_u=2" "$RESULTADO")
ok "the same body whoever asks" "$(is "$a" "$b")"

# --- what is refused, and never cached ----------------------------------------
#
# A refused request must not be cached: Cloudflare's cache key leaves out
# unknown parameters, so a cached 400 for ?...&junk=1 would be served for the
# plain URL too.

for case in "400|$RESULTADO&junk=1" "400|$RESULTADO&display=0" "400|$RESULTADO&evt=26BR" \
            "400|$BASE/resultados/resultado.php?evt=26BR" "404|$BASE/resultados/resultado.php?evt=26BR&id=26BR_ZZZ" \
            "404|$BASE/resultados/evento.php?evt=26br" "404|$BASE/resultados/corrida.php?evt=26BR&p=SGE" \
            "404|$BASE/resultados/evento.php/x?evt=26BR"; do
    want="${case%%|*}"
    url="${case#*|}"
    name="${url#"$BASE"}"
    h=$(headers_of "$url")
    ok "$name answers $want" "$(has "$h" "^HTTP/[0-9.]* $want")"
    ok "$name is not cached" "$(has "$h" '^Cache-Control: no-store$')"
done

err=$(body_of "$RESULTADO&junk=1")
ok "an error body says why, in the foundation's shape" "$(has "$err" '"success":false,"error":".*","code":"unknown_parameter"')"

# --- methods -----------------------------------------------------------------

ok "POST is refused with 405" "$(is "$(status_of -X POST "$EVENTO")" 405)"
h=$(headers_of -X OPTIONS -H "Origin: https://overlay.example" -H "Access-Control-Request-Method: GET" -H "Access-Control-Request-Headers: X-Request-ID" "$EVENTO")
ok "a CORS preflight is answered" "$(has "$h" '^HTTP/[0-9.]* 200')"
ok "the preflight allows X-Request-ID" "$(has "$h" '^Access-Control-Allow-Headers:.*X-Request-ID')"
ok "HEAD works (curl -I, the check in the deploy notes)" "$(is "$(status_of -I "$EVENTO")" 200)"

# --- rate limit ---------------------------------------------------------------

h=$(headers_of "$TORNEIO")
ok "the results endpoints have their own limit (120/min)" "$(has "$h" '^X-RateLimit-Limit: 120$')"

echo
echo "$PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]]
