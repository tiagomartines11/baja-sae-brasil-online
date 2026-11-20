#!/usr/bin/env bash
# Tests for baja-infra/docker/phpbb/wait-for-mysql.sh (BAJAAPP-98).
#
# SOME BACKGROUND FIRST
#
# Each forum (phpbb-baja, phpbb-formula) runs in its own container. When the
# container starts, its entrypoint script must wait until the forum's MySQL
# database answers, because phpBB cannot do anything without it. That wait
# is wait-for-mysql.sh. It tries one connection (a "probe") every 2 seconds,
# using PHP, until one works.
#
# WHAT WENT WRONG ON 2026-11-11
#
# The production VM was hard-reset. When it came back, MySQL needed about 4
# minutes to recover before it accepted connections. The old wait gave up
# after 60 tries (about one minute) and the container exited. Docker's
# restart policy started it again at once, and it waited again: giving up
# achieved nothing. Worse, a restarted container can get a NEW IP address.
# Both forums came back on each other's old IPs, and nginx (which had noted
# the old IPs) showed each forum under the other forum's domain for hours.
# On top of that, every failed try printed a long PHP "Fatal error ...
# Stack trace" block, burying the useful lines of the log.
#
# WHAT THE NEW SCRIPT MUST DO, AND WHAT EACH TEST CHECKS
#
#   - MySQL ready at once: carry on immediately, no pause at all.
#   - MySQL slow (like the 4 minutes on 11/11): keep waiting, then carry on,
#     and say how long it waited.
#   - MySQL never ready: give up only after the whole time budget
#     (MYSQL_WAIT_TIMEOUT_SECONDS, 900 s by default), not before, with a
#     clear message.
#   - Count TIME, not tries: a try that hangs for 3 s must not stretch the
#     budget.
#   - One progress line every 30 s, not one line per try.
#   - No PHP error or stack trace text ever reaches the log.
#   - It asks the forum's own database, as the forum's own user, with the
#     same query as before, and the password never appears in the log.
#   - Both forums' images and entrypoints use this one script.
#   - The real PHP probe (not a fake) against a real MySQL prints one clean
#     line when it fails, and succeeds when the database is ready.
#
# HOW THE TESTS FAKE MYSQL AND TIME
#
# The script runs `php`, `date` and `sleep` from PATH. The tests put the
# stand-ins in fake-bin/ first on PATH:
#
#   fake-bin/php    plays MySQL: it "becomes ready" at a pretend time the
#                   test chooses, and fails before that.
#   fake-bin/date   reads a pretend clock (a number in a file).
#   fake-bin/sleep  moves the pretend clock forward instead of sleeping.
#
# So "MySQL comes up after 4 minutes" or "give up after 15 minutes" runs in
# a fraction of a second, and every time in the log is exact.
#
# The real-PHP tests at the end need PHP with mysqli and a throwaway MySQL
# on port 3306 (TEST_MYSQL_HOST, TEST_MYSQL_ROOT_PASSWORD), as the backup
# tests do. Without them they are listed as "skip"; CI sets
# TEST_MYSQL_REQUIRED=1, which turns a skip into a failure.
#
# HOW TO RUN, from the repository root:
#
#     baja-infra/tests/phpbb-wait/run-tests.sh

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA="$(cd "${HERE}/../.." && pwd)"
SCRIPT="${INFRA}/docker/phpbb/wait-for-mysql.sh"
FAKE_BIN="${HERE}/fake-bin"

TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT

# ----- A tiny test runner (as in tests/backup/run-tests.sh) -----
PASSED=0
FAILED=0
SKIPPED=0
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
contains() { grep -qF -- "$2" <<< "$1" || fail "expected to find '$2' in:
$1"; }
lacks() { if grep -qF -- "$2" <<< "$1"; then fail "did not expect '$2' in:
$1"; fi; }

# wait_for SETTING=value...: runs wait-for-mysql.sh the way phpbb-baja's
# entrypoint does, with a pretend clock starting at 0 and the given extra
# settings (FAKE_READY_AT=240, MYSQL_WAIT_TIMEOUT_SECONDS=120, ...). Leaves:
#   $TMP/out     everything the script printed, i.e. what `docker logs`
#                would show
#   $TMP/status  its exit code (0 = MySQL ready, carry on)
#   $TMP/clock   the pretend time when it finished
#   $TMP/calls   one line per probe ("probe <time> ok|fail") and per pause
#                ("sleep <seconds>"), in order
wait_for() {
    echo 0 > "${TMP}/clock"
    : > "${TMP}/calls"
    local status=0
    env -u MYSQL_WAIT_TIMEOUT_SECONDS -u MYSQL_WAIT_PROBE_SECONDS -u MYSQL_WAIT_LOG_SECONDS -u MYSQL_HOST \
        PATH="${FAKE_BIN}:${PATH}" FAKE_CLOCK="${TMP}/clock" FAKE_LOG="${TMP}/calls" \
        MYSQL_PHPBB_BAJA_PASSWORD='s3cret-Pa55' "$@" \
        sh "$SCRIPT" phpbb-baja phpbb_baja phpbb_baja MYSQL_PHPBB_BAJA_PASSWORD \
        > "${TMP}/out" 2>&1 || status=$?
    echo "$status" > "${TMP}/status"
}
out()    { cat "${TMP}/out"; }
status() { cat "${TMP}/status"; }
clock()  { cat "${TMP}/clock"; }
# progress_times: the "Ns so far" of every progress line, space-separated.
progress_times() { sed -n 's/.*still waiting for mysql, \([0-9]*\)s so far.*/\1/p' "${TMP}/out" | tr '\n' ' ' | sed 's/ $//'; }

# ======================================================================
# With a fake MySQL and a pretend clock
# ======================================================================

test_ready_at_once() {
    # MySQL is up before the forum starts (a normal `docker compose up`):
    # one probe, no pause, and the entrypoint carries on.
    wait_for FAKE_READY_AT=0
    same "$(status)" 0 "exit code"
    same "$(grep -c '^probe' "${TMP}/calls")" 1 "number of probes"
    lacks "$(cat "${TMP}/calls")" "sleep"
    contains "$(out)" "phpbb-baja: waiting for mysql (phpbb_baja as phpbb_baja on mysql), up to 900s..."
    contains "$(out)" "phpbb-baja: mysql is query-ready after 0s"
    same "$(out | wc -l | tr -d ' ')" 2 "number of log lines"
}

test_slow_mysql_like_11_11() {
    # The 11/11 case: MySQL only answers after 240 s (4 minutes), with the
    # default settings. The old loop gave up at about 60 s; this one must
    # keep waiting, then carry on, and the log must stay short.
    wait_for FAKE_READY_AT=240
    same "$(status)" 0 "exit code"
    contains "$(out)" "phpbb-baja: mysql is query-ready after 240s"
    # A probe every 2 s: 0, 2, 4, ... 240, so 121 probes...
    same "$(grep -c '^probe' "${TMP}/calls")" 121 "number of probes"
    same "$(sort -u <<< "$(grep '^sleep' "${TMP}/calls")")" "sleep 2" "every pause"
    # ...but only one progress line every 30 s, not one per probe.
    same "$(progress_times)" "30 60 90 120 150 180 210" "progress lines at (seconds)"
    same "$(out | wc -l | tr -d ' ')" 9 "number of log lines (start, 7 progress, ready)"
    # Each progress line says what MySQL last answered, in one line.
    contains "$(out)" "phpbb-baja: still waiting for mysql, 30s so far. Last error: Connection refused"
}

test_never_ready_gives_up_after_budget_not_before() {
    # MySQL is really broken. The script must try for the WHOLE budget
    # (900 s by default) and only then exit with an error. Docker then
    # restarts the container, which at that point is the right alarm.
    wait_for FAKE_READY_AT=never
    same "$(status)" 1 "exit code"
    # It gave up at 900 s exactly, and its last probe was at 900 s: it did
    # not stop early.
    same "$(clock)" 900 "pretend time when it gave up"
    same "$(grep '^probe' "${TMP}/calls" | tail -1)" "probe 900 fail" "last probe"
    contains "$(out)" "phpbb-baja: mysql was not query-ready after 900s (MYSQL_WAIT_TIMEOUT_SECONDS=900), giving up. Last error: Connection refused"
    lacks "$(out)" "query-ready after 900s;"
}

test_budget_setting_is_used() {
    # MYSQL_WAIT_TIMEOUT_SECONDS changes the budget: 60 s here.
    wait_for FAKE_READY_AT=never MYSQL_WAIT_TIMEOUT_SECONDS=60
    same "$(status)" 1 "exit code"
    same "$(clock)" 60 "pretend time when it gave up"
    contains "$(out)" "up to 60s..."
    contains "$(out)" "not query-ready after 60s (MYSQL_WAIT_TIMEOUT_SECONDS=60)"
}

test_counts_time_not_tries() {
    # A connection that hangs: every probe takes 3 s (the probe's connect
    # timeout) before failing. Counting tries, 60 of them would take 300 s;
    # counting time, a 120 s budget must end at 120 s, give or take the one
    # probe that was running when time ran out.
    wait_for FAKE_READY_AT=never FAKE_PROBE_SECONDS=3 MYSQL_WAIT_TIMEOUT_SECONDS=120
    same "$(status)" 1 "exit code"
    local t
    t="$(clock)"
    (( t >= 120 && t <= 123 )) || fail "gave up at ${t}s, expected between 120 and 123"
    # Progress lines are still about 30 s apart (here 33, 63, 93: the slow
    # probes make them land a little late, never early or more often).
    local prev=0 p
    for p in $(progress_times); do
        (( p - prev >= 30 )) || fail "progress lines ${prev}s and ${p}s are less than 30 s apart"
        prev="$p"
    done
    same "$(progress_times | wc -w | tr -d ' ')" 3 "number of progress lines"
}

test_progress_interval_setting() {
    # The progress interval (MYSQL_WAIT_LOG_SECONDS, for tests) and the
    # pause (MYSQL_WAIT_PROBE_SECONDS) are honoured too: here a probe every
    # second and a progress line every 5 s.
    wait_for FAKE_READY_AT=17 MYSQL_WAIT_PROBE_SECONDS=1 MYSQL_WAIT_LOG_SECONDS=5
    same "$(status)" 0 "exit code"
    same "$(progress_times)" "5 10 15" "progress lines at (seconds)"
    same "$(grep -c '^probe' "${TMP}/calls")" 18 "number of probes (0 to 17)"
}

test_no_stack_trace_reaches_the_log() {
    # On PHP 8.1+ a failed MySQL connection throws an exception, and an
    # uncaught one prints "PHP Fatal error: Uncaught ..." and a stack trace.
    # On 11/11 the log had one of those per try. Here the fake prints a full
    # stack trace on both outputs, around the probe's own message: only the
    # message may reach the log.
    wait_for FAKE_READY_AT=never FAKE_FAILURE=noisy MYSQL_WAIT_TIMEOUT_SECONDS=40
    same "$(status)" 1 "exit code"
    local word
    for word in "Fatal" "Uncaught" "Stack trace" "#0" "#1" "thrown" "mysqli_sql_exception" "Command line code"; do
        lacks "$(out)" "$word"
    done
    contains "$(out)" "Last error: Connection refused"
    same "$(out | wc -l | tr -d ' ')" 3 "number of log lines (start, 1 progress, give up)"
}

test_failure_without_a_message() {
    # If the probe fails without printing its one-line message (PHP broken
    # in some unexpected way), the log says so, still without the noise.
    wait_for FAKE_READY_AT=never FAKE_FAILURE=junk MYSQL_WAIT_TIMEOUT_SECONDS=4
    same "$(status)" 1 "exit code"
    contains "$(out)" "Last error: the probe failed without a message"
    lacks "$(out)" "Stack trace"
    lacks "$(out)" "Fatal"
}

test_asks_the_forums_own_database() {
    # The probe must prove the FORUM's database is ready: its own database,
    # as its own user, with the same query the old loop used. The password
    # is passed by the NAME of its variable, so it never shows in a command
    # line, and it must never show in the log.
    wait_for FAKE_READY_AT=4
    same "$(grep '^asked ' "${TMP}/calls")" \
        "asked db=phpbb_baja user=phpbb_baja password_var=MYSQL_PHPBB_BAJA_PASSWORD host=mysql query=yes" \
        "what the probe was asked"
    lacks "$(out)" "s3cret-Pa55"
    lacks "$(cat "${TMP}/calls")" "s3cret-Pa55"
}

test_bad_usage_and_settings() {
    # A typo in the entrypoint or the .env must fail loudly at once, not
    # wait 15 minutes for nothing.
    local status=0
    sh "$SCRIPT" phpbb-baja phpbb_baja > "${TMP}/out" 2>&1 || status=$?
    same "$status" 2 "exit code with 2 arguments"
    wait_for FAKE_READY_AT=0 MYSQL_WAIT_TIMEOUT_SECONDS=15m
    same "$(status)" 2 "exit code with MYSQL_WAIT_TIMEOUT_SECONDS=15m"
    contains "$(out)" "whole numbers of seconds, got '15m'"
    same "$(grep -c '^probe' "${TMP}/calls")" 0 "number of probes"
}

test_both_forums_use_the_shared_script() {
    # The two entrypoints used to carry copy-pasted loops; this keeps them
    # from drifting apart again. Each image must COPY the shared script, and
    # each entrypoint must call it with its own database, user and password
    # variable, and contain no wait loop of its own.
    local forum db var
    for forum in phpbb-baja phpbb-formula; do
        db="${forum//-/_}"
        var="MYSQL_$(tr 'a-z-' 'A-Z_' <<< "$forum")_PASSWORD"
        grep -qxF "COPY baja-infra/docker/phpbb/wait-for-mysql.sh /usr/local/bin/wait-for-mysql.sh" \
            "${INFRA}/${forum}/Dockerfile" || fail "${forum}/Dockerfile does not COPY the shared wait script"
        grep -qxF "/usr/local/bin/wait-for-mysql.sh ${forum} ${db} ${db} ${var}" \
            "${INFRA}/${forum}/entrypoint.sh" || fail "${forum}/entrypoint.sh does not call the shared wait script as expected"
        if grep -qE 'new mysqli|RETRIES|until php' "${INFRA}/${forum}/entrypoint.sh"; then
            fail "${forum}/entrypoint.sh still has a wait loop of its own"
        fi
        grep -qE "^ +MYSQL_WAIT_TIMEOUT_SECONDS: \\\$\{MYSQL_WAIT_TIMEOUT_SECONDS:-900\}$" "${INFRA}/docker-compose.yml" \
            || fail "docker-compose.yml does not pass MYSQL_WAIT_TIMEOUT_SECONDS"
    done
    same "$(grep -c '^ \+MYSQL_WAIT_TIMEOUT_SECONDS:' "${INFRA}/docker-compose.yml")" 2 \
        "services given MYSQL_WAIT_TIMEOUT_SECONDS in docker-compose.yml"
}

# ======================================================================
# With the real PHP and a real MySQL
# ======================================================================
#
# The fakes above prove the waiting logic. These prove the PHP probe itself:
# that it really catches mysqli's exception and prints one clean line, and
# that it really succeeds against a ready database. They use the real
# `date` and `sleep` too, with a budget of 0 s: exactly one probe.

# needs_mysql NAME FUNCTION: runs FUNCTION as a test when PHP with mysqli
# and TEST_MYSQL_HOST are there; otherwise "skip", or a failure when
# TEST_MYSQL_REQUIRED=1 (as in CI).
needs_mysql() {
    local why=""
    if ! command -v php > /dev/null 2>&1 || ! php -m 2>/dev/null | grep -qx mysqli; then
        why="no php with mysqli"
    elif [[ -z "${TEST_MYSQL_HOST:-}" ]]; then
        why="no TEST_MYSQL_HOST"
    fi
    if [[ -z "$why" ]]; then
        run_test "$1" "$2"
    elif [[ "${TEST_MYSQL_REQUIRED:-0}" == 1 ]]; then
        echo "  FAIL $1"
        echo "         TEST_MYSQL_REQUIRED=1 but ${why}"
        FAILED=$(( FAILED + 1 ))
    else
        echo "  skip $1 (${why})"
        SKIPPED=$(( SKIPPED + 1 ))
    fi
}

TEST_DB="wait_test_$$"

# root_sql SQL: runs SQL as root on the throwaway MySQL, through PHP so that
# nothing beyond PHP is needed.
root_sql() {
    # shellcheck disable=SC2016  # PHP code: its $ are PHP's, not the shell's
    MYSQL_PWD="${TEST_MYSQL_ROOT_PASSWORD:-}" php -r '
        $db = new mysqli(getenv("TEST_MYSQL_HOST"), "root", getenv("MYSQL_PWD"));
        $db->query($argv[1]);' "$1"
}

# real_wait PASSWORD_VARIABLE: the script with the real php, date and sleep,
# connecting as root to TEST_DB, with a budget of 0 s (one probe).
real_wait() {
    local status=0
    env MYSQL_HOST="$TEST_MYSQL_HOST" MYSQL_WAIT_TIMEOUT_SECONDS=0 \
        TEST_ROOT_PW="${TEST_MYSQL_ROOT_PASSWORD:-}" WRONG_PW="not-the-password" \
        sh "$SCRIPT" test "$TEST_DB" root "$1" > "${TMP}/out" 2>&1 || status=$?
    echo "$status" > "${TMP}/status"
}

test_real_probe_fails_in_one_clean_line() {
    # Wrong password: MySQL refuses, mysqli throws, and the log must show
    # MySQL's own message in one line, with no PHP error text around it.
    real_wait WRONG_PW
    same "$(status)" 1 "exit code"
    contains "$(out)" "Last error: Access denied for user 'root'"
    lacks "$(out)" "Fatal"
    lacks "$(out)" "Stack trace"
    same "$(out | wc -l | tr -d ' ')" 2 "number of log lines (start, give up)"
}

test_real_probe_succeeds_when_ready() {
    # The database exists and has the phpbb_config table: one probe, ready.
    root_sql "CREATE DATABASE ${TEST_DB}"
    root_sql "CREATE TABLE ${TEST_DB}.phpbb_config (config_name VARCHAR(255))"
    real_wait TEST_ROOT_PW
    root_sql "DROP DATABASE ${TEST_DB}"
    same "$(status)" 0 "exit code"
    contains "$(out)" "test: mysql is query-ready after 0s"
}

test_real_probe_wants_the_phpbb_table() {
    # The database exists but is empty, as during MySQL's first-boot import:
    # not ready yet, because the query needs phpbb_config.
    root_sql "CREATE DATABASE ${TEST_DB}"
    real_wait TEST_ROOT_PW
    root_sql "DROP DATABASE ${TEST_DB}"
    same "$(status)" 1 "exit code"
    contains "$(out)" "Last error: Table '${TEST_DB}.phpbb_config' doesn't exist"
}

echo
echo "phpBB wait for MySQL (docker/phpbb/wait-for-mysql.sh, BAJAAPP-98)"
echo
run_test "MySQL ready at once: carries on with no pause"                          test_ready_at_once
run_test "MySQL ready after 4 minutes (11/11): keeps waiting, then carries on"    test_slow_mysql_like_11_11
run_test "MySQL never ready: gives up after the 900 s budget, not before"         test_never_ready_gives_up_after_budget_not_before
run_test "MYSQL_WAIT_TIMEOUT_SECONDS sets the budget"                             test_budget_setting_is_used
run_test "slow probes: the budget is time, not a number of tries"                 test_counts_time_not_tries
run_test "probe and progress intervals are honoured"                              test_progress_interval_setting
run_test "no PHP error or stack trace text reaches the log"                       test_no_stack_trace_reaches_the_log
run_test "a probe that fails without a message is logged plainly"                 test_failure_without_a_message
run_test "asks the forum's own database, same query, password never logged"      test_asks_the_forums_own_database
run_test "bad arguments or settings fail at once"                                 test_bad_usage_and_settings
run_test "both forums' images and entrypoints use the shared script"              test_both_forums_use_the_shared_script
needs_mysql "real PHP: a refused connection is one clean log line"                test_real_probe_fails_in_one_clean_line
needs_mysql "real PHP: a ready database passes the probe"                         test_real_probe_succeeds_when_ready
needs_mysql "real PHP: an empty database (no phpbb_config) is not ready"          test_real_probe_wants_the_phpbb_table
echo
echo "${PASSED} passed, ${FAILED} failed, ${SKIPPED} skipped"
(( FAILED == 0 ))
