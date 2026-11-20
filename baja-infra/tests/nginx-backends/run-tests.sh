#!/usr/bin/env bash
# shellcheck disable=SC2016
#   (SC2016: the patterns below hold a literal $ on purpose: they look for
#   nginx variables such as $backend_phpbb_baja, not for shell variables.)
#
# Tests for how nginx finds the containers behind it (BAJAAPP-98).
#
# SOME BACKGROUND FIRST
#
# nginx sits in front of everything. It answers static files (images, CSS)
# itself, and hands everything else to another container: the two phpBB
# forums, the PHP site (baja-app) and the Next.js site (baja-js). The
# directives that do the handing over are `fastcgi_pass` (for PHP) and
# `proxy_pass` (for Next.js), and they name the container, for example
# `fastcgi_pass phpbb-baja:9000`.
#
# Containers do not have fixed IP addresses. Docker gives each one an IP on
# the "baja" network when it starts, and the next start may give it a
# different one. Docker runs a small DNS server (at 127.0.0.11, inside every
# container) that turns the name "phpbb-baja" into whatever the IP is NOW.
#
# THE BUG
#
# With a plain name in `fastcgi_pass`, nginx asks the DNS server once, when
# it starts, and then uses that IP forever. If the container gets a new IP,
# nginx keeps knocking on the old one.
#
# THE INCIDENT, 2026-11-11
#
# The production server rebooted. nginx started and noted the two forums'
# IPs. Four minutes later both forum containers restarted, and each came
# back on the IP the OTHER one had before. nginx, still using its old notes,
# sent forum.bajasaebrasil.net to the Fórmula forum and fsaebrasil.net to the
# Baja forum. The logo and CSS still looked right (nginx serves those itself,
# from each forum's own files), so the pages looked like the right site with
# the wrong forum inside. It lasted hours, until someone restarted nginx.
#
# THE FIX
#
# When the address in `fastcgi_pass` or `proxy_pass` contains an nginx
# variable, nginx asks the DNS server while handling the request, and only
# trusts the answer for a short time. So every one of them now names a
# variable such as $backend_phpbb_baja, defined in
# nginx/templates/00-backends.conf.template together with the DNS server
# (`resolver 127.0.0.11 valid=10s`). After an IP change, nginx follows within
# 10 seconds, without a restart.
#
# WHAT THIS CHECKS, IN PLAIN WORDS
#
#   1. Static checks, on the configuration nginx actually loads, in every
#      listen mode (dev, production...):
#        - nginx accepts it (`nginx -t`), even with no backend reachable;
#        - no fastcgi_pass or proxy_pass names a container directly;
#        - every $backend_* variable they use is defined;
#        - the resolver is Docker's DNS, with a short valid= time.
#   2. The incident itself, for real, in throwaway containers:
#        - two fake forums named phpbb-baja and phpbb-formula, each answering
#          with its own name, and nginx with the real forum configuration;
#        - both forum hostnames reach their own forum;
#        - the two fakes are recreated on each other's IPs, nginx untouched;
#        - after a little more than valid=, both hostnames STILL reach their
#          own forum.
#   3. The same incident against the configuration from before the fix
#      (OLD_REF), which must get it wrong, exactly as production did. This
#      proves that test 2 really can tell a broken nginx from a fixed one.
#
# It needs Docker, git and openssl. Everything it starts carries a label
# with this run's id and is removed when the script ends, passed or failed.
#
# HOW TO RUN, from the repository root:
#
#     baja-infra/tests/nginx-backends/run-tests.sh
#
# OLD_REF must be available locally (CI fetches it).

set -euo pipefail

# The commit just before the fix: master as it was on 2026-11-11, which is
# what production ran during the incident. Only its forum templates are
# used, in test 3.
OLD_REF="${OLD_REF:-a79769c31c74ca2a2433793873e2452721a82874}"
# The images the stack itself runs (docker-compose.yml, and the FROM line of
# baja-infra/phpbb-baja/Dockerfile).
NGINX_IMAGE="nginx:1.27-alpine"
PHP_IMAGE="php:8.3.36-fpm-alpine"
# The throwaway network's addresses. Any private range nothing else on the
# machine uses will do; it only has to be fixed so that we can choose which
# fake forum gets which IP.
SUBNET="10.198.98.0/24"
IP_A="10.198.98.10"
IP_B="10.198.98.11"
# How long to wait after the swap. It must be longer than valid= in
# 00-backends.conf.template (10s), or even a correct nginx could still be
# using its previous answer.
WAIT_AFTER_SWAP=13

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA="$(cd "${HERE}/../.." && pwd)"
REPO="$(dirname "$INFRA")"
TEMPLATES="${INFRA}/nginx/templates"

for tool in docker git openssl; do
    command -v "$tool" >/dev/null 2>&1 || { echo "missing tool: ${tool}" >&2; exit 2; }
done
git -C "$REPO" cat-file -e "${OLD_REF}^{commit}" 2>/dev/null \
    || { echo "OLD_REF ${OLD_REF} is not in this clone (git fetch origin ${OLD_REF})" >&2; exit 2; }

# ----- Cleaning up, whatever happens -----
#
# RUN_ID makes this run's names and label unique, so two runs on one machine
# (or a stack that happens to be running) never touch each other's things.
# The trap runs when the script exits for any reason, a failure included.
RUN_ID="nginx-backends-$$-${RANDOM}"
LABEL="baja-test=${RUN_ID}"
NET="${RUN_ID}"
TMP="$(mktemp -d)"
# teardown: removes every container and the network this run made.
teardown() {
    docker ps -aq --filter "label=${LABEL}" | xargs -r docker rm -f > /dev/null 2>&1 || true
    docker network rm "$NET" > /dev/null 2>&1 || true
}
cleanup() {
    teardown
    rm -rf -- "$TMP"
}
trap cleanup EXIT

# ----- A tiny test runner (as in tests/backup/run-tests.sh) -----
PASSED=0
FAILED=0
run_test() {
    local name="$1" fn="$2" out
    if out="$( ( "$fn" ) 2>&1 )"; then
        echo "  ok   ${name}"
        PASSED=$(( PASSED + 1 ))
    else
        echo "  FAIL ${name}"
        printf '%s\n' "$out" | tail -30 | sed 's/^/         /'
        FAILED=$(( FAILED + 1 ))
    fi
}
fail() { echo "ASSERTION FAILED: $*" >&2; exit 1; }
same() { [[ "$1" == "$2" ]] || fail "${3:-value}: got '$1', expected '$2'"; }

# The settings the stack gives nginx (docker-compose.yml), with made-up
# domains: the Baja forum is forum.baja.local, the Fórmula one
# forum.formula.local.
NGINX_ENV=(
    -e BAJA_DOMAIN=baja.local -e FORMULA_DOMAIN=forum.formula.local
    -e STAFF_COOKIE_VALUE=0123456789abcdef0123456789abcdef
    -e NGINX_ENVSUBST_FILTER='^(BAJA_DOMAIN|FORMULA_DOMAIN|LISTEN_MODE|STAFF_COOKIE_VALUE)$'
    -e NGINX_ENTRYPOINT_QUIET_LOGS=1
)

# ======================================================================
# 1. Static checks
# ======================================================================

# The listen modes are whatever the templates offer: every mode has a
# snippets/<mode>-redirect.conf.template. Read from the folder rather than
# written here, so a mode added later is checked without editing this file.
MODES=()
for f in "${TEMPLATES}"/snippets/listen-*-redirect.conf.template; do
    m="$(basename "$f")"
    MODES+=("${m%-redirect.conf.template}")
done

# A self-signed certificate where listen-tls expects one, for each domain.
# nginx -t opens the files, so they must exist; their content is irrelevant.
make_certs() {
    local domain
    for domain in baja.local forum.formula.local; do
        mkdir -p "${TMP}/letsencrypt/live/${domain}"
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=${domain}" \
            -keyout "${TMP}/letsencrypt/live/${domain}/privkey.pem" \
            -out "${TMP}/letsencrypt/live/${domain}/fullchain.pem" 2>/dev/null
    done
    chmod -R a+rX "${TMP}/letsencrypt"
}

# render MODE: prints `nginx -T`, the whole configuration nginx loaded with
# LISTEN_MODE=MODE, after the container's own template step (the same as at
# every start of the real container). Fails when nginx refuses it.
#
# --network none: no network and no DNS at all. Before the fix nginx had to
# look every backend up while loading, so this needed fake --add-host
# entries; now it must load without being able to look anything up.
render() {
    local mode="$1"
    docker run --rm --network none "${NGINX_ENV[@]}" -e LISTEN_MODE="$mode" \
        -v "${TEMPLATES}:/etc/nginx/templates:ro" \
        -v "${TMP}/letsencrypt:/etc/letsencrypt:ro" \
        "$NGINX_IMAGE" nginx -T 2>&1
}

# directives: the configuration without comments and blank lines, one
# directive per line, spaces tidied. Comments go first so that an example in
# a comment (like the one at the top of 00-backends.conf) is never mistaken
# for a real directive.
directives() {
    sed -e 's/#.*$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/[[:space:]][[:space:]]*/ /g' \
        | grep -v -e '^$' -e '^nginx: ' || true
}

test_nginx_accepts_every_mode() {
    local mode out
    [[ ${#MODES[@]} -ge 2 ]] || fail "found only these listen modes: ${MODES[*]}"
    for mode in "${MODES[@]}"; do
        out="$(render "$mode")" || { printf '%s\n' "$out"; fail "nginx refused the configuration in ${mode}"; }
        grep -q 'syntax is ok' <<< "$out" || fail "${mode}: no 'syntax is ok'"
        printf '%s\n' "$out" | directives > "${TMP}/${mode}.conf"
    done
}

# pass_lines MODE: every fastcgi_pass and proxy_pass nginx loaded in MODE.
pass_lines() {
    [[ -s "${TMP}/$1.conf" ]] || fail "$1: not rendered (see the first test)"
    grep -E '^(fastcgi|proxy)_pass ' "${TMP}/$1.conf" || true
}

test_no_pass_names_a_container() {
    # Each line must be `fastcgi_pass $backend_...;` or
    # `proxy_pass http://$backend_...;` and nothing else. A line such as
    # `fastcgi_pass phpbb-baja:9000;` is exactly the bug: nginx would look
    # that name up once and never again.
    local mode lines bad
    for mode in "${MODES[@]}"; do
        lines="$(pass_lines "$mode")"
        # The stack has 12 of them today. Fewer than that would mean this
        # check quietly stopped seeing them, and passes for the wrong reason.
        (( $(grep -c . <<< "$lines") >= 12 )) || { printf '%s\n' "$lines"; fail "${mode}: expected at least 12 *_pass lines"; }
        bad="$(grep -vE '^(fastcgi_pass |proxy_pass http://)\$backend_[a-z_]+;$' <<< "$lines" || true)"
        [[ -z "$bad" ]] || fail "${mode}: these name a container directly (use a \$backend_* variable from 00-backends.conf):
${bad}"
    done
}

test_every_backend_variable_is_defined() {
    # A variable nginx has never heard of stops it from starting, so the
    # first test would already fail. A variable that exists but is EMPTY
    # does not: nginx would start and answer every request with an error.
    # So: every $backend_* used in a *_pass is defined by a `map`, and that
    # map gives it a "host:port" default. If the container's template step
    # had swallowed the variable names, they would be missing here.
    local mode used name
    for mode in "${MODES[@]}"; do
        used="$(pass_lines "$mode" | grep -oE '\$backend_[a-z_]+' | sort -u)"
        [[ -n "$used" ]] || fail "${mode}: no \$backend_* variable in use"
        for name in $used; do
            grep -qE "^map [^ ]+ \\${name} \\{$" "${TMP}/${mode}.conf" \
                || fail "${mode}: ${name} is used but no map defines it"
            # The line after "map ... {" is the default: "default name:port;".
            grep -A1 -E "^map [^ ]+ \\${name} \\{$" "${TMP}/${mode}.conf" | tail -1 \
                | grep -qE '^default [a-z0-9-]+:[0-9]+;$' \
                || fail "${mode}: ${name} has no 'default host:port;'"
        done
    done
}

test_resolver_is_dockers_dns_with_short_ttl() {
    # Without a resolver nginx has nobody to ask, and refuses a variable
    # address. It must be Docker's DNS (127.0.0.11), and valid= must be set:
    # otherwise nginx trusts each answer for as long as Docker's TTL says,
    # which is far longer than we can afford after an IP change. Every
    # resolver line must say so, so no vhost can quietly override it.
    local mode resolvers bad
    for mode in "${MODES[@]}"; do
        resolvers="$(grep -E '^resolver ' "${TMP}/${mode}.conf" || true)"
        [[ -n "$resolvers" ]] || fail "${mode}: no resolver"
        bad="$(grep -vE '^resolver 127\.0\.0\.11( [^;]*)? valid=[0-9]+s( [^;]*)?;$' <<< "$resolvers" || true)"
        [[ -z "$bad" ]] || fail "${mode}: resolver is not 127.0.0.11 with valid=: ${bad}"
    done
}

# ======================================================================
# 2 and 3. The incident, for real
# ======================================================================

# The two fake forums. Each is a stock PHP-FPM container (the same image
# the real forums are built FROM) serving one index.php that prints its
# own name, so a request shows plainly which forum answered.
for name in phpbb-baja phpbb-formula; do
    mkdir -p "${TMP}/stubs/${name}"
    printf '<?php echo "%s\\n";\n' "$name" > "${TMP}/stubs/${name}/index.php"
done
# PHP-FPM runs as www-data, which must be able to read them.
chmod -R a+rX "${TMP}/stubs"

# nginx's configuration for these tests: the REAL forum vhosts (and, for the
# fixed one, the real 00-backends.conf), with dev's listen snippets. The
# other vhosts are left out, as their backends are not part of the story.
copy_forum_templates() {
    local from="$1" to="$2"
    mkdir -p "${to}/snippets"
    cp "${from}/phpbb-baja.conf.template" "${from}/phpbb-formula.conf.template" "${to}/"
    cp "${from}/snippets/listen-http.conf.template" "${from}/snippets/listen-http-formula.conf.template" "${to}/snippets/"
}
copy_forum_templates "$TEMPLATES" "${TMP}/fixed"
cp "${TEMPLATES}/00-backends.conf.template" "${TMP}/fixed/"
mkdir -p "${TMP}/old-tree"
git -C "$REPO" archive "$OLD_REF" baja-infra/nginx/templates | tar -x -C "${TMP}/old-tree"
copy_forum_templates "${TMP}/old-tree/baja-infra/nginx/templates" "${TMP}/old"

# start_forum NAME IP: starts the fake forum NAME at address IP.
#
# The container's own name has the run id in it, so it can never clash with
# a real stack on the same machine. What nginx asks the DNS for is the
# --network-alias, which is exactly the name the stack uses: phpbb-baja or
# phpbb-formula.
start_forum() {
    local name="$1" ip="$2"
    docker run -d --name "${RUN_ID}-${name}" --label "$LABEL" \
        --network "$NET" --ip "$ip" --network-alias "$name" \
        -v "${TMP}/stubs/${name}:/var/www/html:ro" \
        "$PHP_IMAGE" > /dev/null
}

stop_forum() {
    docker rm -f "${RUN_ID}-$1" > /dev/null
}

# ask HOST: asks nginx for /index.php on HOST and prints the answer: the
# name of the forum that served it, or wget's error. The request is made
# from inside the nginx container (busybox wget), so no port is published.
ask() {
    docker exec "${RUN_ID}-nginx" wget -q -O - -T 5 --header "Host: $1" http://127.0.0.1/index.php 2>&1 || true
}

# incident TEMPLATE_DIR: plays the 11/11 incident against nginx configured
# from TEMPLATE_DIR, and prints which forum answered each hostname, before
# and after the swap, one line each:
#
#   before forum.baja.local phpbb-baja
#   before forum.formula.local phpbb-formula
#   after forum.baja.local ...
#   after forum.formula.local ...
incident() {
    local templates="$1" host i
    # Whatever an earlier run of this function left behind, if it stopped
    # half way, goes first.
    teardown
    docker network create --subnet "$SUBNET" --label "$LABEL" "$NET" > /dev/null

    # The forums first, then nginx, as on 11/11. (The old configuration
    # also needs this order: it looks the forums up when it starts, and
    # refuses to start if it cannot find them.)
    start_forum phpbb-baja "$IP_A"
    start_forum phpbb-formula "$IP_B"
    docker run -d --name "${RUN_ID}-nginx" --label "$LABEL" --network "$NET" \
        "${NGINX_ENV[@]}" -e LISTEN_MODE=listen-http \
        -v "${templates}:/etc/nginx/templates:ro" \
        "$NGINX_IMAGE" > /dev/null

    # Give everything up to 30 seconds to start: until both hostnames get an
    # answer from some forum.
    for (( i = 0; i < 30; i++ )); do
        if [[ "$(ask forum.baja.local)" == phpbb-* && "$(ask forum.formula.local)" == phpbb-* ]]; then
            break
        fi
        sleep 1
    done
    for host in forum.baja.local forum.formula.local; do
        echo "before ${host} $(ask "$host")"
    done

    # The swap: both forums removed and started again, each on the IP the
    # other one had. nginx is not touched.
    stop_forum phpbb-baja
    stop_forum phpbb-formula
    start_forum phpbb-baja "$IP_B"
    start_forum phpbb-formula "$IP_A"

    # Longer than valid=, so a correct nginx has had to ask the DNS again.
    sleep "$WAIT_AFTER_SWAP"
    for host in forum.baja.local forum.formula.local; do
        echo "after ${host} $(ask "$host")"
    done

    teardown
}

test_incident_fixed_config() {
    # With the fix, each hostname reaches its own forum before the swap AND
    # after it. Before the fix, the two "after" lines were the other way
    # round: that is the bug.
    local got
    got="$(incident "${TMP}/fixed")"
    same "$got" "before forum.baja.local phpbb-baja
before forum.formula.local phpbb-formula
after forum.baja.local phpbb-baja
after forum.formula.local phpbb-formula" "which forum answered"
}

test_incident_old_config_gets_it_wrong() {
    # The same steps with the configuration that ran on 11/11. It must be
    # right before the swap and crossed after it: Baja's hostname served by
    # the Fórmula forum and the other way round, as in production. If this
    # ever passes with the forums uncrossed, the test above can no longer
    # tell a broken nginx from a fixed one, and proves nothing.
    local got
    got="$(incident "${TMP}/old")"
    same "$got" "before forum.baja.local phpbb-baja
before forum.formula.local phpbb-formula
after forum.baja.local phpbb-formula
after forum.formula.local phpbb-baja" "which forum answered"
}

echo
echo "nginx backends: looked up per request, not once (BAJAAPP-98)"
echo "listen modes: ${MODES[*]}; configuration before the fix: ${OLD_REF:0:12}"
echo
make_certs
# Pulled once, here, so the pull's progress output never lands in a render.
docker pull -q "$NGINX_IMAGE" > /dev/null
docker pull -q "$PHP_IMAGE" > /dev/null
run_test "nginx accepts the configuration in every listen mode, with no DNS at all" test_nginx_accepts_every_mode
run_test "no fastcgi_pass or proxy_pass names a container directly"                 test_no_pass_names_a_container
run_test "every \$backend_* variable in use is defined, with a host:port"            test_every_backend_variable_is_defined
run_test "the resolver is Docker's DNS (127.0.0.11) with a valid= time"            test_resolver_is_dockers_dns_with_short_ttl
run_test "11/11 replayed: forums swap IPs, each hostname still reaches its own forum" test_incident_fixed_config
run_test "11/11 replayed on the old configuration: the forums come out crossed"    test_incident_old_config_gets_it_wrong
echo
echo "${PASSED} passed, ${FAILED} failed"
(( FAILED == 0 ))
