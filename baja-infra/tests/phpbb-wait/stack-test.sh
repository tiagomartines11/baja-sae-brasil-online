#!/usr/bin/env bash
# The 11/11 scenario for real: a phpBB forum starting while MySQL is down.
#
# WHAT HAPPENED ON 2026-11-11, IN SHORT
#
# After a hard reset of the production VM, the forums started before MySQL
# was ready (MySQL needed about 4 minutes to recover). The old entrypoint
# gave up after about a minute and the container exited; Docker restarted
# it, each restart could give it a new IP, and the two forums ended up on
# each other's IPs. See run-tests.sh next to this file for the full story.
#
# WHAT THIS DOES
#
# With the REAL phpbb-baja image, built from this checkout, and the REAL
# MySQL of docker-compose.yml with the dev seed:
#
#   1. starts MySQL once, so it loads the seed, then stops it;
#   2. starts phpbb-baja while MySQL is stopped;
#   3. waits 90 s: the forum must still be running and waiting (the old one
#      had given up by then), with a short, quiet log;
#   4. starts MySQL again;
#   5. checks that the forum went on by itself: "ready after Ns" in the
#      log, php-fpm up, and RestartCount still 0. A container that never
#      restarted kept its IP.
#
# run-tests.sh (next to this file) tests the waiting logic quickly with a
# fake MySQL; this one proves the image and the entrypoint really use it.
# It takes about 5 minutes, most of it building the image and MySQL's first
# start.
#
# It uses its own compose project and a .env made from .env.example, and
# removes its containers and volumes at the end. The compose file gives the
# containers fixed names (baja-mysql, baja-phpbb-baja) and MySQL publishes
# port 3306, so it refuses to run while a dev stack is up on this machine.
#
# HOW TO RUN, from the repository root:
#
#     baja-infra/tests/phpbb-wait/stack-test.sh

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA="$(cd "${HERE}/../.." && pwd)"
# How long MySQL stays down while the forum waits. More than the old
# entrypoint's 60 tries, which is the point.
MYSQL_DOWN_SECONDS=90

say()  { printf '[phpbb-wait stack-test] %s\n' "$*"; }
fail() { printf '[phpbb-wait stack-test] FAILED: %s\n' "$*" >&2; exit 1; }

command -v docker > /dev/null 2>&1 || fail "docker is required"
if docker ps -a --format '{{.Names}}' | grep -qxE 'baja-mysql|baja-phpbb-baja'; then
    fail "a baja-mysql or baja-phpbb-baja container exists already (a dev stack?); stop it first"
fi

TMP="$(mktemp -d)"
PROJECT="baja-waittest-$$"
sed -e 's/^ENV=.*/ENV=dev/' "${INFRA}/.env.example" > "${TMP}/test.env"
COMPOSE=(docker compose --project-directory "$INFRA" -f "${INFRA}/docker-compose.yml"
         -p "$PROJECT" --env-file "${TMP}/test.env")

cleanup() {
    local status=$?
    if (( status != 0 )); then
        say "the forum's log, for the record:"
        "${COMPOSE[@]}" logs --no-color phpbb-baja 2>/dev/null | tail -40 || true
    fi
    "${COMPOSE[@]}" down -v --remove-orphans > /dev/null 2>&1 || true
    rm -rf -- "$TMP"
}
trap cleanup EXIT

forum_log() { "${COMPOSE[@]}" logs --no-color phpbb-baja 2>&1; }
forum_id()  { "${COMPOSE[@]}" ps -aq phpbb-baja; }
mysql_id()  { "${COMPOSE[@]}" ps -aq mysql; }

say "building the phpbb-baja image from this checkout..."
"${COMPOSE[@]}" build -q phpbb-baja

say "1. first start of MySQL, which loads the dev seed..."
"${COMPOSE[@]}" up -d mysql
for (( i = 0; i < 100; i++ )); do
    [[ "$(docker inspect -f '{{.State.Health.Status}}' "$(mysql_id)")" == healthy ]] && break
    sleep 3
done
[[ "$(docker inspect -f '{{.State.Health.Status}}' "$(mysql_id)")" == healthy ]] \
    || fail "MySQL did not become healthy in 5 minutes"
"${COMPOSE[@]}" stop mysql
say "   MySQL loaded the seed and is stopped now"

say "2. starting phpbb-baja while MySQL is down..."
# --no-deps: without it, compose would start MySQL first (depends_on).
# After a host reboot dockerd ignores depends_on, which is what we copy.
"${COMPOSE[@]}" up -d --no-deps phpbb-baja

say "3. MySQL stays down for ${MYSQL_DOWN_SECONDS} s..."
sleep "$MYSQL_DOWN_SECONDS"
[[ "$(docker inspect -f '{{.State.Running}}' "$(forum_id)")" == true ]] \
    || fail "the forum is not running after ${MYSQL_DOWN_SECONDS} s without MySQL"
same_restarts="$(docker inspect -f '{{.RestartCount}}' "$(forum_id)")"
[[ "$same_restarts" == 0 ]] || fail "the forum restarted ${same_restarts} time(s) while waiting"
log="$(forum_log)"
grep -q 'phpbb-baja: waiting for mysql (phpbb_baja as phpbb_baja on mysql), up to 900s' <<< "$log" \
    || fail "no 'waiting for mysql' line in the forum's log"
progress="$(grep -c 'phpbb-baja: still waiting for mysql' <<< "$log" || true)"
(( progress >= 2 && progress <= 3 )) \
    || fail "expected 2 or 3 progress lines after ${MYSQL_DOWN_SECONDS} s (one every 30 s), got ${progress}"
say "   still waiting, RestartCount 0, ${progress} progress lines"

say "4. starting MySQL again..."
"${COMPOSE[@]}" start mysql

say "5. the forum must carry on by itself..."
for (( i = 0; i < 100; i++ )); do
    forum_log | grep -q 'ready to handle connections' && break
    sleep 3
done
log="$(forum_log)"
grep -q 'ready to handle connections' <<< "$log" || fail "php-fpm did not start within 5 minutes of MySQL"
waited="$(sed -n 's/.*phpbb-baja: mysql is query-ready after \([0-9]*\)s.*/\1/p' <<< "$log" | tail -1)"
[[ -n "$waited" ]] || fail "no 'mysql is query-ready after Ns' line in the forum's log"
(( waited >= MYSQL_DOWN_SECONDS )) || fail "ready after ${waited} s, but MySQL was down for ${MYSQL_DOWN_SECONDS} s"
restarts="$(docker inspect -f '{{.RestartCount}}' "$(forum_id)")"
[[ "$restarts" == 0 ]] || fail "RestartCount is ${restarts}, expected 0"
[[ "$(docker inspect -f '{{.State.Running}}' "$(forum_id)")" == true ]] || fail "the forum is not running"
# The quiet probe: nothing of PHP's exception text in the whole log.
if grep -qE 'Fatal error|Stack trace|Uncaught' <<< "$log"; then
    fail "PHP error text in the forum's log"
fi
say "   ready after ${waited} s, php-fpm up, RestartCount ${restarts}, no PHP error text"
say "passed"
