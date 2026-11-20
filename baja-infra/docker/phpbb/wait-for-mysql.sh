#!/bin/sh
# Waits until a phpBB forum's database answers queries. Shared by both phpBB
# images (COPYed to /usr/local/bin/wait-for-mysql.sh), so the two entrypoints
# cannot drift apart again.
#
# Usage:
#   wait-for-mysql.sh LABEL DATABASE USER PASSWORD_VARIABLE
#   wait-for-mysql.sh phpbb-baja phpbb_baja phpbb_baja MYSQL_PHPBB_BAJA_PASSWORD
#
# PASSWORD_VARIABLE is the NAME of the environment variable that holds the
# password, so the password itself never appears in a command line or a log.
#
# Settings, from the environment:
#   MYSQL_WAIT_TIMEOUT_SECONDS  how long to wait in total before giving up
#                               (default 900, 15 minutes)
#   MYSQL_HOST                  default "mysql", the compose service
#   MYSQL_WAIT_PROBE_SECONDS    pause between probes (default 2)
#   MYSQL_WAIT_LOG_SECONDS      a progress line this often (default 30)
# The last two exist for the tests; leave them alone otherwise.
#
# WHY THE WAIT EXISTS
#
# mysql's depends_on:condition:service_healthy only verifies that the daemon
# is listening and the seed has loaded. On a cold start the init scripts
# (CREATE DATABASE, dumps, CREATE USER) run AFTER mysqld accepts
# connections, and there is a window where the forum's DB user does not
# exist yet. And depends_on is only honoured by `docker compose up`: after a
# host reboot dockerd starts every container by itself, in no particular
# order, so a forum can start long before MySQL is ready.
#
# The probe uses PHP's mysqli (already in the image; phpBB uses it), so the
# image needs no mariadb-client, whose alpine build lacks the
# caching_sha2_password plugin MySQL 8 uses. `SELECT 1 FROM phpbb_config` as
# the forum's own user succeeds only once the seed AND the user both exist.
#
# WHY BY TIME, AND WHY WE DO NOT GIVE UP EARLY (BAJAAPP-98, 2026-11-11)
#
# The entrypoints used to try 60 times, about a minute, and then `exit 1`.
# On 11/11 the production VM was hard-reset; MySQL recovered from the crash
# on a slow disk and took about 4 minutes to accept connections. phpbb-baja
# gave up twice, and each time the restart policy simply started it again,
# to wait all over again. The exits bought nothing, and cost a lot: each
# restart can give the container a NEW IP address, and at 12:58 both forums
# came back on each other's old IPs. nginx, which had looked the IPs up
# once, then served each forum's pages under the other one's domain for
# hours. A number of attempts is not a time budget either: how long an
# attempt takes depends on how MySQL fails (refused at once, or a connect
# that hangs).
#
# So: wait by the clock, for long enough to cover a slow crash recovery,
# keep the log readable, and exit only when MySQL is really broken. At that
# point a restart loop is the right signal.

set -u

if [ "$#" -ne 4 ]; then
    echo "usage: wait-for-mysql.sh LABEL DATABASE USER PASSWORD_VARIABLE" >&2
    exit 2
fi
label="$1"
WAIT_MYSQL_DB="$2"
WAIT_MYSQL_USER="$3"
WAIT_MYSQL_PASSWORD_VAR="$4"
WAIT_MYSQL_HOST="${MYSQL_HOST:-mysql}"
export WAIT_MYSQL_DB WAIT_MYSQL_USER WAIT_MYSQL_PASSWORD_VAR WAIT_MYSQL_HOST

timeout="${MYSQL_WAIT_TIMEOUT_SECONDS:-900}"
probe_every="${MYSQL_WAIT_PROBE_SECONDS:-2}"
log_every="${MYSQL_WAIT_LOG_SECONDS:-30}"
for value in "$timeout" "$probe_every" "$log_every"; do
    case "$value" in
        ''|*[!0-9]*)
            echo "${label}: MYSQL_WAIT_* settings must be whole numbers of seconds, got '${value}'" >&2
            exit 2 ;;
    esac
done

# One connection attempt, quiet and bounded:
#  - mysqli throws exceptions on PHP 8.1+ (the `@` the old probe used does
#    not silence those), so the probe catches everything and prints ONE
#    line, "probe-error: <message>", and nothing else;
#  - display_errors goes to stderr, which is thrown away, so even a PHP
#    error outside the try cannot print a stack trace into the log;
#  - a 3 s connect and read timeout, so a hanging connection cannot stall
#    the loop.
# The settings come in through the environment, not pasted into the PHP
# code, so no password or quote can break it.
# shellcheck disable=SC2016  # PHP code: its $ are PHP's, not the shell's
PROBE='
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
    $db->options(MYSQLI_OPT_READ_TIMEOUT, 3);
    $db->real_connect(getenv("WAIT_MYSQL_HOST"), getenv("WAIT_MYSQL_USER"),
        (string) getenv(getenv("WAIT_MYSQL_PASSWORD_VAR")), getenv("WAIT_MYSQL_DB"));
    $db->query("SELECT 1 FROM phpbb_config LIMIT 1");
    exit(0);
} catch (Throwable $e) {
    echo "probe-error: ", str_replace(array("\r", "\n"), " ", $e->getMessage()), "\n";
    exit(1);
}
'

now() { date +%s; }

started="$(now)"
last_log="$started"
last_error="none yet"
echo "${label}: waiting for mysql (${WAIT_MYSQL_DB} as ${WAIT_MYSQL_USER} on ${WAIT_MYSQL_HOST}), up to ${timeout}s..."

while :; do
    if output="$(php -d display_errors=stderr -d log_errors=0 -r "$PROBE" 2>/dev/null)"; then
        echo "${label}: mysql is query-ready after $(( $(now) - started ))s"
        exit 0
    fi
    # Only our own "probe-error:" line is kept, cut to 200 characters.
    # Anything else the probe printed is dropped.
    message="$(printf '%s\n' "$output" | sed -n 's/^probe-error: //p' | head -n 1 | cut -c 1-200)"
    last_error="${message:-the probe failed without a message}"

    elapsed=$(( $(now) - started ))
    if [ "$elapsed" -ge "$timeout" ]; then
        echo "${label}: mysql was not query-ready after ${elapsed}s (MYSQL_WAIT_TIMEOUT_SECONDS=${timeout}), giving up. Last error: ${last_error}"
        exit 1
    fi
    if [ $(( $(now) - last_log )) -ge "$log_every" ]; then
        echo "${label}: still waiting for mysql, ${elapsed}s so far. Last error: ${last_error}"
        last_log="$(now)"
    fi

    # Never sleep past the deadline: the last probe happens at the deadline.
    pause="$probe_every"
    left=$(( timeout - elapsed ))
    if [ "$pause" -gt "$left" ]; then
        pause="$left"
    fi
    sleep "$pause"
done
