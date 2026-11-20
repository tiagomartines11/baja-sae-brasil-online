#!/usr/bin/env bash
# backup-run.sh — what host cron calls every night.
#
# Two jobs, one after the other, each reported on its own:
#
#   1. The nightly backup: `docker compose --profile tools run --rm backup`
#      (scripts/backup-db.sh), which pings KUMA_PUSH_URL_BACKUP itself on
#      success.
#   2. The event archives (BAJAAPP-67): every request juiz/evento.php
#      recorded when an admin marked an event finalizado. For each one:
#        a. the results-archive service (baja-app image) runs
#           results-snapshot.php --update for that event, as the read-only
#           MySQL account, into a fresh folder under BACKUP_HOST_WORK_DIR;
#        b. the backup image's archive-event.sh tars, encrypts and uploads
#           it to events/<EVT>/<timestamp>/, manifest last;
#        c. results-archive marks the request done with that folder, or
#           failed with the reason, in which case it is tried again the
#           next night.
#      KUMA_PUSH_URL_ARCHIVE is pinged when the whole queue went through
#      without a failure, including on the usual night when it is empty.
#
# The folder in (a) is handed from one container to the other through
# the host because neither container may reach the other's tools, and the
# backup container must never get the docker socket.
#
# Usage:
#   ./backup-run.sh            # both jobs; this is what cron runs
#   ./backup-run.sh archives   # only the event archives
#   ./backup-run.sh -h | --help
#
# Cron (04:00 America/Sao_Paulo):
#   0 4 * * * /srv/baja/baja-sae-brasil-online/baja-infra/scripts/backup-run.sh >> /var/log/baja-backup.log 2>&1
#
# Exits non-zero if either job failed.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA_DIR="$(dirname "$SCRIPT_DIR")"
LOG_PREFIX="backup-run"
# shellcheck source-path=SCRIPTDIR
# shellcheck source=./backup-lib.sh
source "$SCRIPT_DIR/backup-lib.sh"
# shellcheck source-path=SCRIPTDIR source=./compose-files.sh
source "$SCRIPT_DIR/compose-files.sh"

COMPOSE=()
CODE_VERSION="unknown"
LOCK_FD=9

load_config() {
    # BAJA_ENV_FILE lets a test (tests/backup/stack-test.sh) hand over a
    # copy of .env with its own destination and keys. Everything this
    # loads is exported, and docker compose prefers exported variables
    # to its .env, so the copy wins everywhere.
    local env_file="${BAJA_ENV_FILE:-${INFRA_DIR}/.env}"
    [[ -f "$env_file" ]] || die "stack env not found: ${env_file}"
    load_env_file "$env_file"

    # The .env's COMPOSE_FILE when it sets one (staging: see
    # compose-files.sh). Otherwise production and staging run with the prod
    # overlay; it is what mounts the forum uploads from /srv/baja into the
    # backup container.
    local defaults=(docker-compose.yml)
    if [[ "${ENV:-dev}" != "dev" ]]; then
        defaults+=(docker-compose.prod.yml)
    fi
    compose_file_args "$INFRA_DIR" "${defaults[@]}"
    COMPOSE=(docker compose --project-directory "$INFRA_DIR" "${COMPOSE_FILE_ARGS[@]}" --profile tools)

    # What the deployed checkout is, for the manifests. A tag on a release,
    # "v4-12-gabc1234" between releases, "unknown" without git or tags.
    CODE_VERSION="$(git -C "$INFRA_DIR" describe --tags 2>/dev/null || echo unknown)"

    BACKUP_HOST_WORK_DIR="${BACKUP_HOST_WORK_DIR:-/var/tmp/baja-backup-host}"
    ( umask 077; mkdir -p "$BACKUP_HOST_WORK_DIR" )
}

# Two nights' runs must never overlap: a slow upload and the next cron
# would archive the same request twice. flock is released when the
# process exits, however it exits.
take_lock() {
    eval "exec ${LOCK_FD}>\"${BACKUP_HOST_WORK_DIR}/backup-run.lock\""
    flock -n "$LOCK_FD" || die "another backup-run.sh is still running (lock ${BACKUP_HOST_WORK_DIR}/backup-run.lock)"
}

run_nightly() {
    log "nightly backup: starting (code ${CODE_VERSION})"
    if "${COMPOSE[@]}" run --rm -T -e BACKUP_CODE_VERSION="$CODE_VERSION" backup run; then
        log "nightly backup: OK"
        return 0
    fi
    err "nightly backup: FAILED — see the [backup] lines above"
    return 1
}

# One request. Prints nothing on stdout; returns non-zero on failure,
# after recording the reason against the request.
archive_one() {
    local id="$1" event="$2" dir out folder reason=""
    dir="$(mktemp -d "${BACKUP_HOST_WORK_DIR}/event.XXXXXX")"
    chmod 700 "$dir"

    log "archive ${id} (${event}): taking the results snapshot"
    # As the invoking user, so the files land in $dir owned by whoever can
    # delete them afterwards, root or not.
    if ! "${COMPOSE[@]}" run --rm -T --no-deps --user "$(id -u):$(id -g)" \
            -v "${dir}:/archive" results-archive snapshot "$id" /archive >&2; then
        reason="results-snapshot.php failed"
    fi

    if [[ -z "$reason" ]]; then
        log "archive ${id} (${event}): encrypting and uploading"
        if out="$("${COMPOSE[@]}" run --rm -T --no-deps -v "${dir}:/archive:ro" \
                -e BACKUP_CODE_VERSION="$CODE_VERSION" \
                --entrypoint /usr/local/bin/archive-event.sh backup)"; then
            folder="$(printf '%s\n' "$out" | sed -n 's/^ARCHIVED //p' | tail -1)"
            printf '%s\n' "$out" | grep -v '^ARCHIVED ' >&2 || true
            [[ -n "$folder" ]] || reason="archive-event.sh did not say where it uploaded"
        else
            printf '%s\n' "$out" >&2
            reason="archive-event.sh failed: $(printf '%s\n' "$out" | grep 'ERROR' | head -1)"
        fi
    fi

    rm -rf -- "$dir"

    if [[ -z "$reason" ]]; then
        if "${COMPOSE[@]}" run --rm -T --no-deps results-archive "done" "$id" "$folder" >&2; then
            log "archive ${id} (${event}): OK -> ${folder}"
            return 0
        fi
        # The archive is complete on the Drive, but the request is still
        # pending, so the next night makes a second, identical archive in
        # a new folder. Harmless; say so.
        err "archive ${id} (${event}): uploaded to ${folder} but could not mark the request done; it will be archived again"
        return 1
    fi

    err "archive ${id} (${event}): ${reason}"
    "${COMPOSE[@]}" run --rm -T --no-deps results-archive failed "$id" "$reason" >&2 \
        || err "archive ${id} (${event}): could not even record the failure"
    return 1
}

run_archives() {
    local pending
    if ! pending="$("${COMPOSE[@]}" run --rm -T --no-deps results-archive pending)"; then
        err "event archives: could not read the queue"
        return 1
    fi

    # Into an array first, not a `while read` over the text: docker
    # compose run reads stdin, and would swallow the rest of the queue.
    local -a requests=()
    mapfile -t requests <<< "$pending"

    local line id event archived=0 failed=0
    for line in "${requests[@]}"; do
        [[ -n "$line" ]] || continue
        IFS=$'\t' read -r id event <<< "$line"
        if archive_one "$id" "$event" < /dev/null; then
            archived=$(( archived + 1 ))
        else
            failed=$(( failed + 1 ))
        fi
    done

    if (( failed > 0 )); then
        err "event archives: ${failed} failed, ${archived} archived — no Kuma heartbeat"
        return 1
    fi
    log "event archives: ${archived} archived"
    if [[ -n "${BACKUP_REMOTE:-}" ]]; then
        warn "event archives: BACKUP_REMOTE is set — not the production Drive, so no Kuma heartbeat"
        return 0
    fi
    kuma_push "${KUMA_PUSH_URL_ARCHIVE:-}" "archived=${archived}" 0
}

main() {
    local cmd="${1:-run}"
    case "$cmd" in
        -h|--help|help)
            sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" \
                | sed -e '/^set -euo pipefail$/d' -e 's/^# \{0,1\}//'
            exit 0
            ;;
        run|archives) : ;;
        *) die "unknown subcommand: ${cmd}" ;;
    esac

    load_config
    take_lock

    local rc=0
    if [[ "$cmd" == "run" ]]; then
        run_nightly || rc=1
    fi
    run_archives || rc=1
    exit "$rc"
}

main "$@"
