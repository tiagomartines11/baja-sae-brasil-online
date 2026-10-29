#!/usr/bin/env bash
# archive-event.sh — freeze one finished event's published results in the
# backups (BAJAAPP-67).
#
# When an admin marks an event finalizado in juiz/evento.php, the page
# records a pending archive (table evento_arquivamento). The nightly
# backup-run.sh picks it up: it has the baja-app container run
#
#     php scripts/results-snapshot.php --update --dir <tmp>/snapshots --events <EVT>
#
# read-only, then calls THIS script, in the backup image, with that folder
# mounted read-only at /archive. This script tars it, encrypts it like
# every other artifact, and uploads it under its own folder:
#
#     events/<EVT>/<YYYYMMDDTHHMMSSZ>/
#         results.tar.gz.age   results.tar.gz.age.sha256
#         manifest.json        <- uploaded LAST; its presence means complete
#
# Old archives are never replaced: unmarking and re-marking finalizado
# makes a new request, and a new request makes a new folder. The pruner
# does not look at events/ at all.
#
# Usage:
#   archive-event.sh           # reads /archive/request.json and /archive/snapshots/
#   archive-event.sh -h | --help
#
# On success the last line on stdout is
#   ARCHIVED events/<EVT>/<timestamp>
# which backup-run.sh records against the request.
#
# Environment: the same as backup-db.sh for the destination and the keys
# (BACKUP_REMOTE, GDRIVE_SA_WRITER_JSON, GDRIVE_SHARED_DRIVE_ID,
# AGE_RECIPIENT_ESCROW, AGE_RECIPIENT_VERIFIER), plus
#   ARCHIVE_INPUT_DIR    (default /archive)
#   BACKUP_CODE_VERSION  `git describe --tags` of the deployed checkout, or
#                        "unknown"; recorded in the manifest
#   ARCHIVE_TIMESTAMP    test hook: forces the folder's timestamp

set -euo pipefail

SCRIPT_VERSION="1.0.0"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_PREFIX="archive-event"
# shellcheck source-path=SCRIPTDIR
# shellcheck source=./backup-lib.sh
source "$SCRIPT_DIR/backup-lib.sh"

ARCHIVE_INPUT_DIR="${ARCHIVE_INPUT_DIR:-/archive}"
BACKUP_REMOTE="${BACKUP_REMOTE:-gdrive:}"
BACKUP_WORK_DIR="${BACKUP_WORK_DIR:-/var/tmp/baja-backup}"
BACKUP_CODE_VERSION="${BACKUP_CODE_VERSION:-unknown}"

WORK=""
cleanup() {
    if [[ -n "$WORK" && -d "$WORK" ]]; then
        rm -rf -- "$WORK"
    fi
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

usage() {
    sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" \
        | sed -e '/^set -euo pipefail$/d' -e 's/^# \{0,1\}//'
}

main() {
    case "${1:-run}" in
        -h|--help|help) usage; exit 0 ;;
        run) : ;;
        *) err "unknown subcommand: $1"; usage; exit 2 ;;
    esac

    local tool
    for tool in age rclone jq tar gzip sha256sum; do
        command -v "$tool" >/dev/null 2>&1 || die "$tool not found in PATH (image built wrong?)"
    done
    [[ -n "${AGE_RECIPIENT_ESCROW:-}" && -n "${AGE_RECIPIENT_VERIFIER:-}" ]] \
        || die "AGE_RECIPIENT_ESCROW and AGE_RECIPIENT_VERIFIER must both be set"
    backup_check_recipients
    if [[ "$BACKUP_REMOTE" != "gdrive:" ]]; then
        warn "BACKUP_REMOTE=${BACKUP_REMOTE} — this is NOT the production Shared Drive; nothing written here is a real archive."
    fi

    # ----- What to archive -----
    local request="${ARCHIVE_INPUT_DIR}/request.json"
    local snapshots="${ARCHIVE_INPUT_DIR}/snapshots"
    [[ -f "$request" ]] || die "no ${request} — run through backup-run.sh, which writes it"
    [[ -d "$snapshots" ]] || die "no ${snapshots} folder — results-snapshot.php did not run"

    local event request_id requested
    event="$(jq -r '.evento_id // empty' "$request")"
    request_id="$(jq -r '.arquivamento_id // empty' "$request")"
    requested="$(jq -r '.pedido_em_utc // empty' "$request")"

    # The event code becomes a folder name on the Shared Drive. Same rule
    # as results-snapshot.php's --events.
    backup_is_event_code "$event" || die "not an event code: '${event}'"
    [[ "$request_id" =~ ^[0-9]+$ ]] || die "request id is not a number: '${request_id}'"
    [[ "$requested" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] \
        || die "request time is not an ISO UTC timestamp: '${requested}'"

    # Only the event asked for: a folder for another event in here would be
    # archived under the wrong name.
    local other
    other="$(find "$snapshots" -mindepth 1 -maxdepth 1 ! -name "$event" -print -quit)"
    [[ -z "$other" ]] || die "${snapshots} holds $(basename "$other"), not only ${event}"

    local snapshot_count
    snapshot_count="$(find "$snapshots" -type f -name '*.json' | wc -l | tr -d ' ')"
    (( snapshot_count > 0 )) \
        || die "${event}: results-snapshot.php wrote no snapshot — nothing was published, or it ran against the wrong database"
    log "${event}: ${snapshot_count} snapshot file(s) for request ${request_id}"

    # ----- Package -----
    WORK="$(mktemp -d "${BACKUP_WORK_DIR%/}.event.XXXXXX" 2>/dev/null || mktemp -d)"
    chmod 700 "$WORK"
    local tarball="${WORK}/results.tar.gz"

    tar --create --gzip --file "$tarball" --directory "$ARCHIVE_INPUT_DIR" snapshots \
        || die "tar of ${snapshots} failed"
    gzip -t "$tarball" || die "the archive failed its own gzip test"

    local count bytes digest
    IFS=$'\t' read -r count bytes digest < <(backup_tree_lines_from_tar "$tarball" | backup_tree_summary)

    local sha artifact_bytes
    sha="$(backup_encrypt "$tarball" "${tarball}.age")"
    rm -f -- "$tarball"
    artifact_bytes="$(stat -c %s "${tarball}.age")"

    local stamp="${ARCHIVE_TIMESTAMP:-$(date -u +%Y%m%dT%H%M%SZ)}"
    [[ "$stamp" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || die "folder timestamp is not YYYYMMDDTHHMMSSZ: '${stamp}'"
    local folder="events/${event}/${stamp}"

    # Counts and hashes only, like the nightly manifest: team names and
    # scores are in the encrypted artifact, not here.
    # shellcheck disable=SC2016
    jq -n \
        --arg event "$event" --argjson request_id "$request_id" \
        --arg requested "$requested" \
        --arg archived "${stamp:0:4}-${stamp:4:2}-${stamp:6:2}T${stamp:9:2}:${stamp:11:2}:${stamp:13:2}Z" \
        --arg code_version "$BACKUP_CODE_VERSION" --arg script_version "$SCRIPT_VERSION" \
        --argjson snapshot_count "$snapshot_count" \
        --arg escrow "$AGE_RECIPIENT_ESCROW" --arg verifier "$AGE_RECIPIENT_VERIFIER" \
        --arg sha "$sha" --argjson artifact_bytes "$artifact_bytes" \
        --argjson count "$count" --argjson bytes "$bytes" --arg digest "$digest" \
        '{
            kind: "event-archive",
            schema_version: 1,
            event: $event,
            request_id: $request_id,
            requested_utc: $requested,
            archived_utc: $archived,
            code_version: $code_version,
            script_version: $script_version,
            snapshot_count: $snapshot_count,
            age_recipients: [$escrow, $verifier],
            artifact: {
                file: "results.tar.gz.age",
                sha256: $sha,
                artifact_bytes: $artifact_bytes,
                file_count: $count,
                total_bytes: $bytes,
                tree_sha256: $digest
            }
        }' > "${WORK}/manifest.json"
    backup_manifest_check_clean "${WORK}/manifest.json"

    # ----- Upload, manifest last -----
    backup_configure_rclone_writer
    log "upload: -> ${BACKUP_REMOTE}${folder}/"
    rclone_put "${WORK}/results.tar.gz.age"        "${folder}/results.tar.gz.age"
    rclone_put "${WORK}/results.tar.gz.age.sha256" "${folder}/results.tar.gz.age.sha256"
    rclone_put "${WORK}/manifest.json"             "${folder}/manifest.json"

    log "${event}: archived, $(human_bytes "$artifact_bytes") encrypted"
    printf 'ARCHIVED %s\n' "$folder"
}

main "$@"
