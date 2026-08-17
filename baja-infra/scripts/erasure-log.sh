#!/usr/bin/env bash
# erasure-log.sh — append-only record of LGPD subject erasure requests,
# and the tool that replays them after a restore.
#
# WHY THIS EXISTS
#
# Backups make deletion less final than it looks. When a competitor
# exercises Art. 18 and their row is deleted from production, every
# artifact already in Drive still contains it, and will keep containing it
# until the last tier expires. Standard practice is that you do NOT rewrite
# historical backups — rewriting them destroys their integrity and their
# evidentiary value, and you would have to re-encrypt and re-upload
# everything on every request.
#
# What you must NOT do is resurrect erased data. A restore from a backup
# taken before the request would silently bring the subject back. This log
# is the mitigation: it records what was erased, and the restore procedure
# in docs/backup-restore.md is not complete until `replay` has run against
# the restored database.
#
# Usage:
#   erasure-log.sh record --request <id> --database <db> --table <t> \
#                         --column <c> --value <v> [--method sql|manual] \
#                         [--note "..."]
#   erasure-log.sh list
#   erasure-log.sh verify                       # check the hash chain
#   erasure-log.sh replay --database <db> [--confirm]
#   erasure-log.sh -h | --help
#
# Run it through the backup image, which already has mysql and jq:
#   docker compose --profile tools run --rm \
#       --entrypoint erasure-log.sh backup list
#
# THE LOG CONTAINS PERSONAL DATA. It has to: an erasure you cannot
# identify is an erasure you cannot replay. Consequences:
#   - It lives OUTSIDE this repository. The repo is public.
#   - ERASURE_LOG_PATH must be on the VPS (default /srv/baja/erasure/),
#     mode 0600, and is worth making genuinely append-only with
#     `chattr +a`. See the runbook.
#   - Each line carries the SHA-256 of the previous line, so a deletion or
#     an edit in the middle of the file is detectable by `verify`. That is
#     tamper EVIDENCE, not tamper prevention.
#
# Environment:
#   ERASURE_LOG_PATH  default /srv/baja/erasure/erasure-log.jsonl
#   MYSQL_HOST / MYSQL_PORT / MYSQL_ADMIN_USER / MYSQL_ADMIN_PASSWORD
#                     only needed by `replay`, and they must be credentials
#                     that can WRITE to the restored database. Deliberately
#                     not the backup user, which cannot write anything.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_PREFIX="erasure"
# shellcheck source=./backup-lib.sh
source "$SCRIPT_DIR/backup-lib.sh"

ERASURE_LOG_PATH="${ERASURE_LOG_PATH:-/srv/baja/erasure/erasure-log.jsonl}"
MYSQL_HOST="${MYSQL_HOST:-mysql}"
MYSQL_PORT="${MYSQL_PORT:-3306}"

# ----- record -----
#
# `method` decides how a replay re-applies the erasure:
#
#   sql     a single DELETE against one table. Correct for
#           baja_resultados, whose schema is flat — a participantes row
#           stands alone.
#
#   manual  the erasure has to be redone through the application. This is
#           the right answer for phpBB and the default for it: deleting a
#           user touches upwards of twenty tables, decrements per-forum and
#           per-topic counters, and rewrites post ownership. Hand-written
#           SQL that "deletes a user" produces a forum with wrong post
#           counts and orphaned rows — a slow corruption discovered weeks
#           later. Replay prints the ACP steps instead of pretending.
cmd_record() {
    local request="" database="" table="" column="" value="" method="" note=""
    while (( $# > 0 )); do
        case "$1" in
            --request)  request="$2";  shift 2 ;;
            --database) database="$2"; shift 2 ;;
            --table)    table="$2";    shift 2 ;;
            --column)   column="$2";   shift 2 ;;
            --value)    value="$2";    shift 2 ;;
            --method)   method="$2";   shift 2 ;;
            --note)     note="$2";     shift 2 ;;
            *) die "record: unknown option '$1'" ;;
        esac
    done

    [[ -n "$request"  ]] || die "record: --request is required (your reference for the subject's request)"
    [[ -n "$database" ]] || die "record: --database is required"
    [[ -n "$table"    ]] || die "record: --table is required"
    [[ -n "$column"   ]] || die "record: --column is required"
    [[ -n "$value"    ]] || die "record: --value is required"

    if [[ -z "$method" ]]; then
        case "$database" in
            phpbb_*) method=manual ;;
            *)       method=sql ;;
        esac
    fi
    case "$method" in
        sql|manual) : ;;
        *) die "record: --method must be 'sql' or 'manual', got '${method}'" ;;
    esac

    ensure_log_exists

    local prev
    prev="$(chain_head)"

    local entry
    entry="$(jq -cn \
        --arg recorded_utc "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
        --arg request "$request" \
        --arg database "$database" \
        --arg table "$table" \
        --arg column "$column" \
        --arg value "$value" \
        --arg method "$method" \
        --arg note "$note" \
        --arg prev "$prev" \
        '{recorded_utc: $recorded_utc, request_id: $request, database: $database,
          table: $table, column: $column, value: $value, method: $method,
          note: $note, prev_sha256: $prev}')"

    printf '%s\n' "$entry" >> "$ERASURE_LOG_PATH"
    log "recorded ${method} erasure for request ${request}: ${database}.${table}.${column}"
    log "the live database is NOT touched by this command — erase there first, or separately"
}

ensure_log_exists() {
    local dir
    dir="$(dirname "$ERASURE_LOG_PATH")"
    [[ -d "$dir" ]] || die "erasure log directory does not exist: ${dir} (create it 0700 on the VPS; see the runbook)"
    if [[ ! -f "$ERASURE_LOG_PATH" ]]; then
        ( umask 077; : > "$ERASURE_LOG_PATH" )
        log "created empty erasure log at ${ERASURE_LOG_PATH}"
    fi
}

# SHA-256 of the last line, or the empty-chain sentinel.
chain_head() {
    if [[ ! -s "$ERASURE_LOG_PATH" ]]; then
        printf 'genesis'
        return 0
    fi
    tail -n 1 "$ERASURE_LOG_PATH" | sha256sum | cut -d' ' -f1
}

# ----- verify -----
cmd_verify() {
    [[ -f "$ERASURE_LOG_PATH" ]] || die "no erasure log at ${ERASURE_LOG_PATH}"

    local expected="genesis" lineno=0 line actual claimed
    while IFS= read -r line; do
        lineno=$(( lineno + 1 ))
        claimed="$(printf '%s' "$line" | jq -r '.prev_sha256')"
        if [[ "$claimed" != "$expected" ]]; then
            die "erasure log broken at line ${lineno}: entry claims prev=${claimed}, chain says ${expected}. A line has been edited or removed."
        fi
        actual="$(printf '%s\n' "$line" | sha256sum | cut -d' ' -f1)"
        expected="$actual"
    done < "$ERASURE_LOG_PATH"

    log "hash chain intact across ${lineno} entries"
}

# ----- list -----
cmd_list() {
    [[ -f "$ERASURE_LOG_PATH" ]] || die "no erasure log at ${ERASURE_LOG_PATH}"
    # Values are personal data, so the summary shows the request id and the
    # target rather than the identifier itself. Use `jq` on the file
    # directly if you genuinely need the value.
    jq -r '"\(.recorded_utc)  \(.request_id)  \(.method)  \(.database).\(.table).\(.column)  \(.note)"' \
        "$ERASURE_LOG_PATH"
}

# ----- replay -----
#
# Run this against a database you have just restored, BEFORE putting it
# back into service. Dry-run unless --confirm: the whole point of this
# script is to avoid surprises, and it issues DELETEs.
cmd_replay() {
    local database="" confirm=no
    while (( $# > 0 )); do
        case "$1" in
            --database) database="$2"; shift 2 ;;
            --confirm)  confirm=yes;   shift ;;
            *) die "replay: unknown option '$1'" ;;
        esac
    done
    [[ -n "$database" ]] || die "replay: --database is required"
    [[ -f "$ERASURE_LOG_PATH" ]] || die "no erasure log at ${ERASURE_LOG_PATH}"

    cmd_verify

    local admin_user="${MYSQL_ADMIN_USER:-}" admin_pass="${MYSQL_ADMIN_PASSWORD:-}"
    local work=""
    if [[ "$confirm" == "yes" ]]; then
        [[ -n "$admin_user" && -n "$admin_pass" ]] \
            || die "replay --confirm needs MYSQL_ADMIN_USER and MYSQL_ADMIN_PASSWORD (write credentials for the restored database)"
        work="$(mktemp -d)"
        # shellcheck disable=SC2064
        trap "rm -rf -- '$work'" EXIT
        MYSQL_DEFAULTS_FILE="${work}/client.cnf"
        write_defaults_file "$MYSQL_DEFAULTS_FILE" \
            "$MYSQL_HOST" "$MYSQL_PORT" "$admin_user" "$admin_pass"
    else
        log "DRY RUN — nothing will be changed. Re-run with --confirm to apply."
    fi

    local sql_applied=0 manual_pending=0 line
    while IFS= read -r line; do
        local entry_db entry_table entry_col entry_val entry_method entry_req
        entry_db="$(printf '%s' "$line" | jq -r '.database')"
        [[ "$entry_db" == "$database" ]] || continue

        entry_table="$(printf '%s' "$line" | jq -r '.table')"
        entry_col="$(printf '%s' "$line" | jq -r '.column')"
        entry_val="$(printf '%s' "$line" | jq -r '.value')"
        entry_method="$(printf '%s' "$line" | jq -r '.method')"
        entry_req="$(printf '%s' "$line" | jq -r '.request_id')"

        if [[ "$entry_method" == "manual" ]]; then
            manual_pending=$(( manual_pending + 1 ))
            warn "MANUAL: request ${entry_req} — re-apply through the phpBB ACP (User -> Manage users -> Delete), matching ${entry_table}.${entry_col}. SQL cannot do this safely; see the header of this script."
            continue
        fi

        if [[ "$confirm" != "yes" ]]; then
            log "would DELETE FROM \`${entry_table}\` WHERE \`${entry_col}\` = <value from request ${entry_req}>"
            continue
        fi

        # Value is passed through a user variable so it is never
        # interpolated into the statement text, and the affected-row count
        # is checked: a replay that deletes a hundred rows is a bug, not a
        # thorough erasure.
        local affected
        affected="$(db_query "$database" "
            SET @v = $(sql_quote "$entry_val");
            DELETE FROM \`${entry_table}\` WHERE \`${entry_col}\` = @v;
            SELECT ROW_COUNT();")"
        if (( affected > 20 )); then
            die "replay of request ${entry_req} matched ${affected} rows — refusing to continue; investigate before re-running"
        fi
        log "request ${entry_req}: deleted ${affected} row(s) from ${database}.${entry_table}"
        sql_applied=$(( sql_applied + 1 ))
    done < "$ERASURE_LOG_PATH"

    log "replay finished: ${sql_applied} SQL erasure(s) applied, ${manual_pending} manual item(s) listed above"
    if (( manual_pending > 0 )); then
        warn "The restore is NOT complete until the manual items have been re-applied through the application."
    fi
}

# Single-quote a value for SQL, escaping backslashes and quotes.
sql_quote() {
    local v="${1//\\/\\\\}"
    v="${v//\'/\\\'}"
    printf "'%s'" "$v"
}

usage() {
    sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" \
        | sed -e '/^set -euo pipefail$/d' -e 's/^# \{0,1\}//'
}

main() {
    local cmd="${1:-}"
    if (( $# > 0 )); then shift; fi
    case "$cmd" in
        -h|--help|help|"") usage; [[ -z "$cmd" ]] && exit 1 || exit 0 ;;
        record) cmd_record "$@" ;;
        list)   cmd_list ;;
        verify) cmd_verify ;;
        replay) cmd_replay "$@" ;;
        *) err "unknown subcommand: $cmd"; usage; exit 2 ;;
    esac
}

main "$@"
