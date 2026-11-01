#!/usr/bin/env bash
# Tests for the three nginx listen modes and the compose file sets.
#
# WHAT THIS CHECKS, IN PLAIN WORDS
#
# There are three ways this stack is run, picked by two settings in .env:
#
#   dev         docker-compose.yml                    LISTEN_MODE=listen-http
#   production  + docker-compose.prod.yml             LISTEN_MODE=listen-tls
#   staging     + docker-compose.prod.yml
#               + docker-compose.staging.yml          LISTEN_MODE=listen-proxied
#
# Staging sits behind the VPS's shared Caddy, which owns host ports 80 and
# 443 and forwards to nginx over the external network baja-edge. These tests
# check that:
#
#   - on staging, nginx publishes NO host port, is on baja-edge as well as
#     baja, and has exactly base's mounts (the certificates are not mounted);
#   - the merged compose config for dev and production is byte for byte what
#     it was at BASELINE_REF (develop before staging moved behind Caddy), so
#     adding the staging file changed nothing anywhere else;
#   - nginx accepts the rendered configuration in every mode (`nginx -t`);
#   - in dev and production, the rendered nginx configuration has exactly the
#     directives it had at BASELINE_REF, the real-IP ones in particular;
#   - in listen-proxied, the ONLY trusted proxy is 10.201.0.0/24, the header
#     is still CF-Connecting-IP, and there is no TLS and no redirect to https.
#
# The nginx part starts throwaway nginx:1.27-alpine containers (the image the
# stack uses) with no network and no published port. It needs Docker and
# openssl, makes a self-signed certificate for listen-tls, and removes
# everything it made when it ends.
#
# HOW TO RUN, from the repository root:
#
#     baja-infra/tests/listen-modes/run-tests.sh
#
# BASELINE_REF is a commit to compare against; it must be available locally
# (CI fetches it). When you change the dev or production compose or nginx
# output ON PURPOSE, this test fails: move BASELINE_REF to a commit that has
# your change, and say in the PR what changed and why.

set -euo pipefail

BASELINE_REF="${BASELINE_REF:-9b2832a5e17d7d5d768f12721b27b8c2b1127bc3}"
NGINX_IMAGE="nginx:1.27-alpine"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA="$(cd "${HERE}/../.." && pwd)"
REPO="$(dirname "$INFRA")"

for tool in docker git jq openssl; do
    command -v "$tool" >/dev/null 2>&1 || { echo "missing tool: ${tool}" >&2; exit 2; }
done
git -C "$REPO" cat-file -e "${BASELINE_REF}^{commit}" 2>/dev/null \
    || { echo "BASELINE_REF ${BASELINE_REF} is not in this clone (git fetch origin ${BASELINE_REF})" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT

# The baseline's baja-infra/, unpacked at the same depth below a folder of
# the same name, so that compose gives it the same project name and the
# same relative paths as the real one.
mkdir -p "${TMP}/old"
git -C "$REPO" archive "$BASELINE_REF" baja-infra | tar -x -C "${TMP}/old"
OLD_INFRA="${TMP}/old/baja-infra"

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
        printf '%s\n' "$out" | tail -25 | sed 's/^/         /'
        FAILED=$(( FAILED + 1 ))
    fi
}
fail() { echo "ASSERTION FAILED: $*" >&2; exit 1; }
same() { [[ "$1" == "$2" ]] || fail "${3:-value}: got '$1', expected '$2'"; }

# ----- The three environments' .env files -----
#
# All made from this branch's .env.example and used for the baseline too, so
# that the only difference between "before" and "after" is the compose and
# template files themselves.
sed -e 's/^ENV=.*/ENV=dev/' "${INFRA}/.env.example" > "${TMP}/dev.env"
sed -e 's/^ENV=.*/ENV=prod/' -e 's/^LISTEN_MODE=.*/LISTEN_MODE=listen-tls/' \
    "${INFRA}/.env.example" > "${TMP}/prod.env"
# TAILSCALE_IP is in no .env.example; the prod overlay needs one.
echo 'TAILSCALE_IP=100.64.0.1' >> "${TMP}/prod.env"
sed -e 's/^ENV=prod/ENV=staging/' -e 's/^LISTEN_MODE=.*/LISTEN_MODE=listen-proxied/' \
    "${TMP}/prod.env" > "${TMP}/staging.env"
echo 'COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml:docker-compose.staging.yml' >> "${TMP}/staging.env"

# compose_config DIR ENV_FILE FORMAT [ARGS...]: the merged config, as
# compose prints it in FORMAT (yaml or json). COMPOSE_FILE comes from the
# env file when it has one, as on the server; otherwise the -f flags in ARGS
# decide.
compose_config() {
    local dir="$1" env_file="$2" format="$3"
    shift 3
    ( cd "$dir" && env -u COMPOSE_FILE docker compose --env-file "$env_file" "$@" config --format "$format" )
}

# ======================================================================
# Compose
# ======================================================================

test_staging_nginx_has_no_host_port() {
    # No -f flags: the files come from COMPOSE_FILE in the staging .env, as
    # they do for a plain `docker compose` on the server.
    local json
    json="$(compose_config "$INFRA" "${TMP}/staging.env" json)"
    # No "ports" at all: not 80 from the base file, not 443 from the prod
    # overlay.
    same "$(jq -c '.services.nginx.ports // []' <<< "$json")" "[]" "nginx ports"
    # Nothing else may have taken 80 or 443 either.
    same "$(jq -r '[.services[] | (.ports // [])[] | select(.published == "80" or .published == "443")] | length' <<< "$json")" \
        0 "services publishing 80 or 443"
    same "$(jq -r '.services.nginx.networks | keys | sort | join(" ")' <<< "$json")" "baja baja-edge" "nginx networks"
    same "$(jq -r '.networks["baja-edge"].external' <<< "$json")" "true" "baja-edge external"
    same "$(jq -r '.networks["baja-edge"].name' <<< "$json")" "baja-edge" "baja-edge name"
    same "$(jq -r '.services.nginx.container_name' <<< "$json")" "baja-nginx" "container_name"
    same "$(jq -r '.services.nginx.environment.LISTEN_MODE' <<< "$json")" "listen-proxied" "LISTEN_MODE"
    # The certificates are not mounted, and nginx has exactly base's mounts:
    # docker-compose.staging.yml restates them, and must not drift.
    jq -e '[.services.nginx.volumes[].source] | any(test("letsencrypt")) | not' <<< "$json" > /dev/null \
        || fail "staging nginx still mounts /etc/letsencrypt"
    local base_mounts staging_mounts
    base_mounts="$(compose_config "$INFRA" "${TMP}/dev.env" json -f docker-compose.yml \
                   | jq -c '.services.nginx.volumes')"
    staging_mounts="$(jq -c '.services.nginx.volumes' <<< "$json")"
    same "$staging_mounts" "$base_mounts" "staging nginx volumes vs docker-compose.yml's"
    # The rest of staging is production's: the prod overlay's MySQL binding
    # and /srv/baja mounts are still there.
    jq -e '.services.mysql.volumes | any(.source == "/srv/baja/mysql")' <<< "$json" > /dev/null \
        || fail "staging lost the prod overlay's /srv/baja/mysql mount"
}

# same_config_as_baseline LABEL ENV_FILE ARGS...: the merged config from this
# branch and from BASELINE_REF must be identical. The baseline's folder name
# is swapped for the real one first, since compose prints absolute paths.
same_config_as_baseline() {
    local label="$1" env_file="$2"
    shift 2
    compose_config "$INFRA" "$env_file" yaml "$@" > "${TMP}/${label}.new.yml"
    compose_config "$OLD_INFRA" "$env_file" yaml "$@" | sed "s#${TMP}/old#${REPO}#g" > "${TMP}/${label}.old.yml"
    diff -u "${TMP}/${label}.old.yml" "${TMP}/${label}.new.yml" || fail "${label}: compose config differs from ${BASELINE_REF}"
}

test_dev_and_prod_compose_unchanged() {
    same_config_as_baseline dev "${TMP}/dev.env"
    same_config_as_baseline dev-tools "${TMP}/dev.env" --profile tools
    same_config_as_baseline prod "${TMP}/prod.env" -f docker-compose.yml -f docker-compose.prod.yml
    same_config_as_baseline prod-tools "${TMP}/prod.env" -f docker-compose.yml -f docker-compose.prod.yml --profile tools
}

# ======================================================================
# nginx
# ======================================================================

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

# render TEMPLATES MODE: prints `nginx -T` — the whole configuration nginx
# loaded, every file in it — for the templates in TEMPLATES rendered with
# LISTEN_MODE=MODE, exactly as the container renders them at start. Fails
# when nginx refuses the configuration.
#
# --network none: nothing can reach it, and it reaches nothing. The
# upstreams (baja-app, phpbb-*, baja-js) are named in --add-host only
# because nginx looks them up once when it loads the configuration.
render() {
    local templates="$1" mode="$2"
    docker run --rm --network none \
        --add-host baja-app:127.0.0.1 --add-host baja-js:127.0.0.1 \
        --add-host phpbb-baja:127.0.0.1 --add-host phpbb-formula:127.0.0.1 \
        -e BAJA_DOMAIN=baja.local -e FORMULA_DOMAIN=forum.formula.local \
        -e LISTEN_MODE="$mode" -e STAFF_COOKIE_VALUE=0123456789abcdef0123456789abcdef \
        -e NGINX_ENVSUBST_FILTER='^(BAJA_DOMAIN|FORMULA_DOMAIN|LISTEN_MODE|STAFF_COOKIE_VALUE)$' \
        -e NGINX_ENTRYPOINT_QUIET_LOGS=1 \
        -v "${templates}:/etc/nginx/templates:ro" \
        -v "${TMP}/letsencrypt:/etc/letsencrypt:ro" \
        "$NGINX_IMAGE" nginx -T 2>&1
}

# directives: the directives of a `nginx -T` output, one per line, sorted.
# Comments, blank lines, the "# configuration file" headers and the include
# lines are dropped: those differ when a directive moves from one file to
# another, which is all this change does in dev and production.
directives() {
    sed -e 's/#.*$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/[[:space:]][[:space:]]*/ /g' \
        | grep -v -e '^$' -e '^include ' -e '^nginx: ' | LC_ALL=C sort
}

# real_ip: just the real-IP module's directives, sorted. Sorted because the
# order means nothing to nginx (set_real_ip_from builds a set of trusted
# addresses) while `nginx -T` prints files in the order it opens them, which
# moving the list into a snippet changed.
real_ip() {
    grep -E '^[[:space:]]*(set_real_ip_from|real_ip_header|real_ip_recursive)[[:space:]]' \
        | sed -e 's/^[[:space:]]*//' | LC_ALL=C sort
}

test_nginx_accepts_every_mode() {
    local mode out
    for mode in listen-http listen-tls listen-proxied; do
        out="$(render "${INFRA}/nginx/templates" "$mode")" || { printf '%s\n' "$out"; fail "nginx -T refused ${mode}"; }
        grep -q 'syntax is ok' <<< "$out" || fail "${mode}: no 'syntax is ok'"
        printf '%s\n' "$out" > "${TMP}/${mode}.new.conf"
    done
}

test_dev_and_prod_nginx_unchanged() {
    local mode
    for mode in listen-http listen-tls; do
        [[ -s "${TMP}/${mode}.new.conf" ]] || fail "${mode}: not rendered (see the test above)"
        render "${OLD_INFRA}/nginx/templates" "$mode" > "${TMP}/${mode}.old.conf" \
            || fail "${mode}: the baseline does not render"
        diff -u <(real_ip < "${TMP}/${mode}.old.conf") <(real_ip < "${TMP}/${mode}.new.conf") \
            || fail "${mode}: real-IP directives differ from ${BASELINE_REF}"
        diff -u <(directives < "${TMP}/${mode}.old.conf") <(directives < "${TMP}/${mode}.new.conf") \
            || fail "${mode}: nginx directives differ from ${BASELINE_REF}"
    done
}

test_proxied_trusts_only_baja_edge() {
    local conf="${TMP}/listen-proxied.new.conf"
    [[ -s "$conf" ]] || fail "listen-proxied: not rendered (see the test above)"
    same "$(real_ip < "$conf")" "real_ip_header CF-Connecting-IP;
set_real_ip_from 10.201.0.0/24;" "listen-proxied real-IP directives"
    # Plain HTTP on 80 only: no TLS, no 443, no redirect to https. (The
    # image's own conf.d/default.conf, a "localhost" server on 80 that every
    # mode has, is the one that adds "listen [::]:80".)
    local d
    d="$(directives < "$conf")"
    local others
    others="$(grep -E '^listen ' <<< "$d" | grep -vE '^listen (80|\[::\]:80)( default_server)?;$' || true)"
    [[ -z "$others" ]] || fail "listen-proxied listens on something other than 80: ${others}"
    same "$(grep -c '^listen 80 default_server;$' <<< "$d")" 1 "default_server on 80"
    if grep -qE '^ssl_|^http2 ' <<< "$d"; then fail "listen-proxied has TLS directives"; fi
    if grep -q 'return 301 https' <<< "$d"; then fail "listen-proxied redirects to https (Caddy does that)"; fi
    grep -q '^absolute_redirect off;$' <<< "$d" || fail "listen-proxied: no absolute_redirect off"
    if grep -q '^absolute_redirect on;$' <<< "$d"; then fail "listen-proxied: a server turns absolute_redirect back on"; fi
}

test_proxied_redirects_stay_on_https() {
    # nginx's own redirect, for real: /26BR on the judge host is answered by
    # nginx itself with a 301 to /26BR/. Behind Caddy the visitor is on
    # https and nginx on http, so the Location must be the bare path (the
    # browser keeps https), never http://juiz.baja.local/26BR/. Busybox
    # wget, from inside the throwaway container: no port is published.
    local out
    out="$(docker run --rm --network none \
        --add-host baja-app:127.0.0.1 --add-host baja-js:127.0.0.1 \
        --add-host phpbb-baja:127.0.0.1 --add-host phpbb-formula:127.0.0.1 \
        -e BAJA_DOMAIN=baja.local -e FORMULA_DOMAIN=forum.formula.local \
        -e LISTEN_MODE=listen-proxied -e STAFF_COOKIE_VALUE=0123456789abcdef0123456789abcdef \
        -e NGINX_ENVSUBST_FILTER='^(BAJA_DOMAIN|FORMULA_DOMAIN|LISTEN_MODE|STAFF_COOKIE_VALUE)$' \
        -e NGINX_ENTRYPOINT_QUIET_LOGS=1 \
        -v "${INFRA}/nginx/templates:/etc/nginx/templates:ro" \
        "$NGINX_IMAGE" sh -c '/docker-entrypoint.sh nginx >/dev/null 2>&1; sleep 1;
            wget -S -O /dev/null --header "Host: juiz.baja.local" http://127.0.0.1/26BR 2>&1 | grep -i "^ *location:"' || true)"
    same "$(sed -e 's/^[[:space:]]*//' -e 's/\r$//' <<< "$out" | head -1)" "Location: /26BR/" "Location header"
}

echo
echo "listen modes and compose file sets (baseline ${BASELINE_REF:0:12})"
echo
run_test "staging: nginx publishes no host port, joins baja-edge, mounts no certificates" test_staging_nginx_has_no_host_port
run_test "dev and production: compose config is unchanged"                           test_dev_and_prod_compose_unchanged
make_certs
# Pulled here, once, so that `docker run` never pulls it inside render(): the
# pull progress it would print lands in the rendered output and makes the
# first render differ from the second.
docker pull -q "$NGINX_IMAGE" > /dev/null
run_test "nginx accepts the configuration in listen-http, listen-tls and listen-proxied" test_nginx_accepts_every_mode
run_test "dev and production: nginx directives, real-IP ones included, are unchanged"  test_dev_and_prod_nginx_unchanged
run_test "listen-proxied: trusts only baja-edge, plain HTTP on 80, no redirect"        test_proxied_trusts_only_baja_edge
run_test "listen-proxied: nginx's own redirects keep the visitor on https"             test_proxied_redirects_stay_on_https
echo
echo "${PASSED} passed, ${FAILED} failed"
(( FAILED == 0 ))
