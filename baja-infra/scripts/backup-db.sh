#!/usr/bin/env bash
# backup-db.sh — nightly encrypted logical backup of the three production
# databases to the SAE BRASIL `baja-backups` Google Shared Drive.
#
# Produces THREE SEPARATE ARTIFACTS per run — one per database — plus one
# manifest covering all three. Separate because retention differs per
# database (baja_resultados is the certificate archive and is kept far
# longer than forum history), because the realistic incident is one
# mangled forum rather than total loss, because one corrupt artifact then
# costs one database instead of three, and because a forum dump can be
# handed to a student contributor without also handing over every
# competitor's CPF.
#
# The accepted trade-off: three invocations are three snapshots seconds
# apart, not one atomic snapshot across all three. baja_resultados.user
# rows correspond to phpBB user IDs, so a registration landing mid-run can
# end up on one side of the boundary, producing the known unprovisioned-user
# path (bounced to login; an admin adds the row). That is noise next to the
# hours of data already lost in any scenario where you are restoring at
# all. Do NOT "fix" it by dumping all three in one transaction and
# splitting the file on `-- Current Database:` markers — that puts
# dump-parsing on the critical path of recovery.
#
# Usage:
#   backup-db.sh run         # every phase; this is what cron invokes
#   backup-db.sh preflight   # checks only, writes nothing, uploads nothing
#   backup-db.sh -h | --help
#
# Invoked from the host as:
#   docker compose --profile tools run --rm backup
#
# Phases: preflight -> dump -> encrypt -> manifest -> upload -> report
#
#   The work package sketches this as `... -> manifest -> encrypt -> ...`.
#   It is implemented the other way around for one unavoidable reason: the
#   manifest records each artifact's sha256 and byte size, and an artifact
#   does not exist until it has been encrypted. The database-side facts the
#   manifest carries (row counts, routine/trigger/event counts, the charset
#   canary) are still captured in the `dump` phase — see the comment there
#   for why their timing is load-bearing.
#
# Exit behaviour is the contract with Uptime Kuma: ANY failure exits
# non-zero WITHOUT pinging, and the monitor alerts on the missing
# heartbeat. Nothing in this script reports its own failure upstream,
# because a script that must succeed at reporting failure can fail at that
# too.
#
# Required environment (baja-infra/.env — see .env.example):
#   MYSQL_BACKUP_USER       restricted MySQL user (SELECT/LOCK TABLES/
#                           SHOW VIEW/TRIGGER/EVENT; no write grants)
#   MYSQL_BACKUP_PASSWORD
#   GDRIVE_SA_WRITER_JSON   path to the baja-backup-writer service-account
#                           key INSIDE this container (bind-mounted ro)
#   GDRIVE_SHARED_DRIVE_ID  ID of the `baja-backups` Shared Drive
#   AGE_RECIPIENT_ESCROW    age public key, escrow half (offline private
#                           key held by Joao AND Tiago)
#   AGE_RECIPIENT_VERIFIER  age public key used by the weekly verify stack
#   KUMA_PUSH_URL_BACKUP    Uptime Kuma push URL for this job
#
# Optional:
#   MYSQL_HOST (default: mysql)      MYSQL_PORT (default: 3306)
#   BACKUP_WORK_DIR (default: /var/tmp/baja-backup)
#   BACKUP_DUMP_TIMEOUT  (default: 3600s) per-mysqldump ceiling
#   BACKUP_QUERY_TIMEOUT (default: 300s)  per-query ceiling
#   BACKUP_REMOTE   (default: gdrive:)  see the DEV-MODE note in preflight
#   BACKUP_RUN_ID   override the run id; the only way to exercise the
#                   first-of-month / first-of-year branches without waiting

set -euo pipefail

SCRIPT_VERSION="1.0.0"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_PREFIX="backup"
# shellcheck source-path=SCRIPTDIR
# shellcheck source=./backup-lib.sh
source "$SCRIPT_DIR/backup-lib.sh"

# ----- Configuration -----
MYSQL_HOST="${MYSQL_HOST:-mysql}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
BACKUP_WORK_DIR="${BACKUP_WORK_DIR:-/var/tmp/baja-backup}"
BACKUP_REMOTE="${BACKUP_REMOTE:-gdrive:}"
# Upper bound on a single mysqldump. Sized as "far longer than any real
# dump, far shorter than forever": at current sizes a dump takes seconds,
# so an hour means something is wrong, and hanging is the failure this
# prevents. Raise it if the databases ever grow into it.
BACKUP_DUMP_TIMEOUT="${BACKUP_DUMP_TIMEOUT:-3600s}"

# ----- Run state -----
RUN_ID=""
RUN_DIR=""
START_EPOCH=0
MYSQL_DEFAULTS_FILE=""
MYSQL_VERSION=""
declare -A DUMP_BYTES=()
declare -A ARTIFACT_BYTES=()
declare -A ARTIFACT_SHA=()

# ----- Cleanup -----
#
# The work directory holds PLAINTEXT dumps: every competitor CPF, every
# forum private message. It must not survive the process under any exit
# path, including a kill. Registered before the directory is created so
# there is no window where it exists untrapped.
cleanup() {
    local rc=$?
    if [[ -n "$RUN_DIR" && -d "$RUN_DIR" ]]; then
        rm -rf -- "$RUN_DIR"
    fi
    if (( rc != 0 )); then
        err "run failed (exit ${rc}) — NO Kuma heartbeat sent; the monitor will alert on silence"
    fi
    return 0
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

# ----- Phase: preflight -----
#
# Every human prerequisite this job depends on is something the script
# cannot create, so each one gets its own specific error. "Drive
# unreachable" and "service-account key missing" must never both surface
# as a generic rclone failure at 4am.
phase_preflight() {
    log "preflight: checking tools, configuration and connectivity"

    local tool
    for tool in mysql mysqldump age rclone curl jq gzip sha256sum; do
        command -v "$tool" >/dev/null 2>&1 || die "$tool not found in PATH (image built wrong?)"
    done

    local missing=() var
    for var in MYSQL_BACKUP_USER MYSQL_BACKUP_PASSWORD \
               AGE_RECIPIENT_ESCROW AGE_RECIPIENT_VERIFIER; do
        [[ -n "${!var:-}" ]] || missing+=("$var")
    done

    # DEV MODE. BACKUP_REMOTE exists so the upload, tiering and manifest
    # logic can be exercised against a local directory before the Shared
    # Drive and its service accounts exist, and so the acceptance tests in
    # docs/backup-restore.md can be run at all. Anything other than the
    # default is NOT the production destination and says so, loudly.
    if [[ "$BACKUP_REMOTE" == "gdrive:" ]]; then
        for var in GDRIVE_SA_WRITER_JSON GDRIVE_SHARED_DRIVE_ID; do
            [[ -n "${!var:-}" ]] || missing+=("$var")
        done
    else
        warn "BACKUP_REMOTE=${BACKUP_REMOTE} — this is NOT the production Shared Drive."
        warn "Nothing written by this run is a real backup. Unset BACKUP_REMOTE for production."
    fi

    (( ${#missing[@]} == 0 )) || die "required environment variables unset: ${missing[*]}"

    # age recipients: catch a private key pasted where a public one
    # belongs. A private key on the VPS defeats the entire escrow design,
    # and age itself would happily accept the string as a file path and
    # fail later with something unhelpful.
    local recipient
    for recipient in "$AGE_RECIPIENT_ESCROW" "$AGE_RECIPIENT_VERIFIER"; do
        case "$recipient" in
            AGE-SECRET-KEY-*) die "an age SECRET key is configured as a recipient — only public keys (age1...) belong on this host" ;;
            age1*) : ;;
            *) die "not an age public key: '${recipient:0:12}...' (expected it to start with age1)" ;;
        esac
    done
    [[ "$AGE_RECIPIENT_ESCROW" != "$AGE_RECIPIENT_VERIFIER" ]] \
        || die "escrow and verifier age recipients are the same key — the escrow key must never be the one the VPS-side verifier holds"

    # Work directory, 0700, on a filesystem we then measure.
    RUN_DIR="${BACKUP_WORK_DIR}/run.$$"
    ( umask 077; mkdir -p "$RUN_DIR" )
    chmod 700 "$RUN_DIR"

    MYSQL_DEFAULTS_FILE="${RUN_DIR}/client.cnf"
    write_defaults_file "$MYSQL_DEFAULTS_FILE" \
        "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_BACKUP_USER" "$MYSQL_BACKUP_PASSWORD"

    log "preflight: MySQL at ${MYSQL_HOST}:${MYSQL_PORT} as '${MYSQL_BACKUP_USER}'"
    db_query_nodb "SELECT 1" >/dev/null 2>&1 \
        || die "cannot connect to MySQL at ${MYSQL_HOST}:${MYSQL_PORT} as '${MYSQL_BACKUP_USER}'"

    # Recorded now, not at manifest time: this is the version the dump was
    # taken from, and by manifest time the server may be gone.
    MYSQL_VERSION="$(db_query_nodb "SELECT VERSION()")"
    log "preflight: server reports MySQL ${MYSQL_VERSION}"

    # Measured sizes, logged every run. These are what the retention tiers
    # were sized against; if they have grown by an order of magnitude the
    # tiers deserve a second look (see docs/backup-restore.md).
    local total_estimate=0 db estimate present
    for db in "${BACKUP_DATABASES[@]}"; do
        present="$(db_query_nodb "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${db}'")"
        [[ "$present" == "1" ]] \
            || die "database '${db}' not visible to '${MYSQL_BACKUP_USER}' — missing GRANT, or wrong server"
        estimate="$(backup_estimated_bytes "$db")"
        total_estimate=$(( total_estimate + estimate ))
        log "preflight:   ${db} — $(human_bytes "$estimate") of table+index data"
    done
    log "preflight: total across all three — $(human_bytes "$total_estimate")"

    # 3x headroom: the plaintext dump, plus its gzip, plus the age
    # artifact all coexist briefly. The dump is written to disk rather
    # than streamed precisely so a partial failure is visible, and that
    # costs space.
    local need=$(( total_estimate * 3 )) avail_kb avail
    avail_kb="$(df -Pk "$RUN_DIR" | awk 'NR==2 {print $4}')"
    avail=$(( avail_kb * 1024 ))
    log "preflight: $(human_bytes "$avail") free on the work filesystem, need $(human_bytes "$need")"
    (( avail >= need )) || die "insufficient free disk: have $(human_bytes "$avail"), need 3x the estimated dump size ($(human_bytes "$need"))"

    # Charset canary sanity: a canary over pure-ASCII rows cannot detect
    # the encoding fault it exists to detect. Soft, because an empty dev
    # database is a legitimate reason to see this.
    for db in "${BACKUP_DATABASES[@]}"; do
        local canary_sql
        canary_sql="$(backup_canary_sql "$db")"
        if ! db_query "$db" "$canary_sql" | LC_ALL=C grep -q '[^ -~]'; then
            warn "${db}: charset canary source contains no non-ASCII characters — the canary cannot prove accent fidelity for this database"
        fi
    done

    configure_rclone
    log "preflight: checking the Shared Drive is reachable and writable"
    rclone lsd "$BACKUP_REMOTE" >/dev/null \
        || die "cannot list ${BACKUP_REMOTE} — check GDRIVE_SA_WRITER_JSON, GDRIVE_SHARED_DRIVE_ID, and that baja-backup-writer is a Contributor on the Shared Drive"

    log "preflight: OK"
}

# rclone is configured entirely through the environment, so no rclone.conf
# is written and the service-account path is the only thing on disk.
# RCLONE_CONFIG=/dev/null makes "no config file" explicit rather than
# accidental — otherwise a stray ~/.config/rclone/rclone.conf would
# silently take part.
configure_rclone() {
    export RCLONE_CONFIG=/dev/null
    if [[ "$BACKUP_REMOTE" == "gdrive:" ]]; then
        [[ -r "$GDRIVE_SA_WRITER_JSON" ]] \
            || die "service-account key not readable at ${GDRIVE_SA_WRITER_JSON} (is it bind-mounted into the container?)"
        export RCLONE_CONFIG_GDRIVE_TYPE=drive
        export RCLONE_CONFIG_GDRIVE_SCOPE=drive
        export RCLONE_CONFIG_GDRIVE_SERVICE_ACCOUNT_FILE="$GDRIVE_SA_WRITER_JSON"
        export RCLONE_CONFIG_GDRIVE_TEAM_DRIVE="$GDRIVE_SHARED_DRIVE_ID"
    fi
}

# ----- Phase: dump -----
#
# Per database: capture the manifest's database-side facts, then dump.
#
# The ORDER MATTERS and is the reason this is not in the manifest phase.
# `--single-transaction` snapshots at the instant mysqldump starts, so
# counts taken immediately BEFORE that instant describe very nearly the
# same data as the dump. Counts taken after the dump would be off by the
# entire dump duration — minutes, during which results are being entered
# live at an event. The residual window is the sub-second between the last
# count query and mysqldump's snapshot; `counts_taken_utc` records it so a
# spurious verification failure can be recognised as one. See the
# "Known limits" section of docs/backup-restore.md.
phase_dump() {
    log "dump: run_id=${RUN_ID}"

    local db
    for db in "${BACKUP_DATABASES[@]}"; do
        dump_one "$db"
    done

    log "dump: OK — three artifacts staged"
}

dump_one() {
    local db="$1"
    local sql="${RUN_DIR}/${db}.sql"
    local meta="${RUN_DIR}/dbmeta.${db}.json"

    log "dump: ${db} — capturing row counts and charset canary"

    local counts_taken canary counts_tsv objects
    counts_taken="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    counts_tsv="$(backup_table_counts "$db")"
    canary="$(backup_canary_hash "$db")"
    objects="$(backup_object_counts "$db")"

    local procedures functions triggers events
    IFS=$'\t' read -r procedures functions triggers events <<<"$objects"

    local -a excluded=()
    mapfile -t excluded < <(backup_excluded_tables "$db")

    # jq builds the JSON so table names and counts can never produce
    # invalid output, and so the manifest cannot be corrupted by a table
    # named something unexpected.
    jq -n \
        --arg file "${db}.sql.gz.age" \
        --arg counts_taken "$counts_taken" \
        --arg canary "$canary" \
        --argjson procedures "$procedures" \
        --argjson functions "$functions" \
        --argjson triggers "$triggers" \
        --argjson events "$events" \
        --argjson excluded "$(printf '%s\n' "${excluded[@]}" | jq -Rn '[inputs | select(length > 0)]')" \
        --argjson table_counts "$(printf '%s\n' "$counts_tsv" | jq -Rn '[inputs | select(length > 0) | split("\t") | {(.[0]): (.[1] | tonumber)}] | add // {}')" \
        '{
            file: $file,
            counts_taken_utc: $counts_taken,
            table_counts: $table_counts,
            excluded_tables: $excluded,
            routine_counts: { procedures: $procedures, functions: $functions },
            trigger_count: $triggers,
            event_count: $events,
            charset_canary_sha256: $canary
        }' > "$meta"

    log "dump: ${db} — mysqldump -> ${db}.sql"

    # --single-transaction  consistent InnoDB snapshot without blocking
    #                       writers; results are entered live during events.
    # --no-tablespaces      lets the restricted user work without PROCESS.
    # --set-gtid-purged=OFF avoids restore friction on a non-replica target.
    # --routines/--triggers/--events  the schema is not just tables.
    # --databases <name>    keeps CREATE DATABASE IF NOT EXISTS and USE in
    #                       the artifact, so a restore is one command
    #                       rather than a command plus a remembered step.
    # --default-character-set=utf8mb4  matches the rest of the stack (see
    #                       migrate.sh); several tables are still latin1 and
    #                       the connection charset is what decides whether
    #                       that round-trips or gets mangled.
    #
    # Written to a temp FILE, never piped straight to gzip/age/rclone:
    # a streaming pipeline reports success for a dump that died halfway.
    local -a opts=(
        --defaults-file="$MYSQL_DEFAULTS_FILE"
        --single-transaction
        --routines --triggers --events
        --no-tablespaces
        --set-gtid-purged=OFF
        --default-character-set=utf8mb4
    )
    # `x && y` as a whole statement is a set -e landmine: when the test is
    # false the statement's status is 1 and the script dies. Written long.
    local t
    while read -r t; do
        if [[ -n "$t" ]]; then
            opts+=("--ignore-table=${db}.${t}")
        fi
    done < <(backup_excluded_tables "$db")

    # Wrapped in `timeout` because a MySQL client whose connection is
    # blackholed — a network partition, a container losing its network,
    # the host's conntrack table dropping the flow — blocks forever
    # rather than erroring. Without this, cron would pile up runs that
    # never end. --kill-after escalates if mysqldump ignores SIGTERM.
    timeout --kill-after=30s "$BACKUP_DUMP_TIMEOUT" \
        mysqldump "${opts[@]}" --databases "$db" > "$sql" \
        || die "mysqldump of ${db} failed or exceeded BACKUP_DUMP_TIMEOUT=${BACKUP_DUMP_TIMEOUT}"

    [[ -s "$sql" ]] || die "dump of ${db} produced an empty file"

    # mysqldump's trailer is the only cheap proof the dump ran to
    # completion. Its absence means truncation even when the file is
    # otherwise well-formed SQL that imports without error.
    tail -c 4096 "$sql" | grep -q '^-- Dump completed' \
        || die "dump of ${db} has no '-- Dump completed' trailer — truncated"

    DUMP_BYTES["$db"]="$(stat -c %s "$sql")"
    log "dump: ${db} — $(human_bytes "${DUMP_BYTES[$db]}") of SQL"
}

# ----- Phase: encrypt -----
#
# gzip -9, then age to BOTH recipients, always.
#
# The verifier key is what the weekly check uses. The escrow key is
# exercised only during the twice-yearly human drill and its private half
# never lands on a server — which is exactly why it must be a recipient on
# every artifact: the day it is needed, nobody will be able to add it
# retroactively.
phase_encrypt() {
    log "encrypt: gzip -9 then age (escrow + verifier)"

    local db
    for db in "${BACKUP_DATABASES[@]}"; do
        local sql="${RUN_DIR}/${db}.sql"
        local gz="${sql}.gz"
        local artifact="${gz}.age"

        gzip -9 "$sql"
        gzip -t "$gz" || die "gzip of ${db} failed its own integrity test"

        age -r "$AGE_RECIPIENT_ESCROW" -r "$AGE_RECIPIENT_VERIFIER" -o "$artifact" "$gz" \
            || die "age encryption of ${db} failed"
        rm -f -- "$gz"

        ARTIFACT_BYTES["$db"]="$(stat -c %s "$artifact")"
        ARTIFACT_SHA["$db"]="$(sha256sum "$artifact" | cut -d' ' -f1)"

        # Sidecar in `sha256sum -c` format, so a restore on a machine with
        # nothing but coreutils can check the download.
        printf '%s  %s\n' "${ARTIFACT_SHA[$db]}" "${db}.sql.gz.age" > "${artifact}.sha256"

        log "encrypt: ${db} — $(human_bytes "${ARTIFACT_BYTES[$db]}") encrypted"
    done

    log "encrypt: OK"
}

# ----- Phase: manifest -----
#
# ONE manifest per run, covering all three artifacts. It is both the
# contract the verifier checks against and the atomicity anchor: three
# separate artifacts must not become three independently-missing
# artifacts.
#
# Uploaded UNENCRYPTED so the verifier can check freshness and integrity
# without decrypting anything. It therefore carries counts and hashes
# ONLY — never a name, a CPF, or an email address. Anything added here in
# future must clear that bar.
#
# It describes the run AS CREATED. Pruning removes files from older tiers
# by design, so a manifest in monthly/ may reference artifacts that no
# longer exist; only the newest daily/ run is ever checked for manifest
# completeness.
phase_manifest() {
    log "manifest: assembling"

    local -a jq_args=()
    # The $names below are jq variables bound by --arg/--argjson, not
    # shell expansions; the single quotes are what keeps them that way.
    # shellcheck disable=SC2016
    local jq_expr='{
        schema_version: 2,
        run_id: $run_id,
        created_utc: $created,
        script_version: $script_version,
        mysql_version: $mysql_version,
        age_recipients: [$escrow, $verifier],
        artifacts: {}
    }'

    jq_args+=(--arg run_id "$RUN_ID")
    jq_args+=(--arg created "$(date -u +%Y-%m-%dT%H:%M:%SZ)")
    jq_args+=(--arg script_version "$SCRIPT_VERSION")
    jq_args+=(--arg mysql_version "$MYSQL_VERSION")
    jq_args+=(--arg escrow "$AGE_RECIPIENT_ESCROW")
    jq_args+=(--arg verifier "$AGE_RECIPIENT_VERIFIER")

    local db
    for db in "${BACKUP_DATABASES[@]}"; do
        jq_args+=(--slurpfile "meta_${db}" "${RUN_DIR}/dbmeta.${db}.json")
        jq_args+=(--arg "sha_${db}" "${ARTIFACT_SHA[$db]}")
        jq_args+=(--argjson "dump_bytes_${db}" "${DUMP_BYTES[$db]}")
        jq_args+=(--argjson "artifact_bytes_${db}" "${ARTIFACT_BYTES[$db]}")
        jq_expr+=" | .artifacts[\"${db}\"] = (\$meta_${db}[0]
            + { sha256: \$sha_${db},
                dump_bytes: \$dump_bytes_${db},
                artifact_bytes: \$artifact_bytes_${db} })"
    done

    jq -n "${jq_args[@]}" "$jq_expr" > "${RUN_DIR}/manifest.json"

    # Last line of defence on the no-personal-data rule: the manifest is
    # the one thing here that goes to Drive in the clear, so refuse to
    # upload one carrying anything that looks like a CPF.
    if grep -Eq '"[^"]*(cpf|email|nome|username)[^"]*"[[:space:]]*:[[:space:]]*"' "${RUN_DIR}/manifest.json"; then
        die "manifest appears to contain a personal-data field — refusing to upload it unencrypted"
    fi

    jq empty "${RUN_DIR}/manifest.json" || die "assembled manifest is not valid JSON"
    log "manifest: OK ($(human_bytes "$(stat -c %s "${RUN_DIR}/manifest.json")"))"
}

# ----- Phase: upload -----
#
# One folder per run, and THE MANIFEST GOES LAST. Its presence is the
# commit marker for the run: a partial upload then reads as an incomplete
# run and fails verification loudly, instead of looking like a healthy
# backup with one database quietly missing.
phase_upload() {
    configure_rclone

    # Both tiers are derived from RUN_ID rather than from `date` a second
    # time, so the folder a run lands in always agrees with its own id —
    # and so BACKUP_RUN_ID is enough to exercise both branches in a test.
    local day_of_month="${RUN_ID:6:2}"
    local day_of_year
    day_of_year="$(date -u -d "${RUN_ID:0:4}-${RUN_ID:4:2}-${RUN_ID:6:2}" +%j)"

    upload_tier "daily/${RUN_ID}" "${BACKUP_DATABASES[@]}"

    # First of the month: a full second copy on the monthly ladder.
    if [[ "$day_of_month" == "01" ]]; then
        log "upload: ${RUN_ID} is the first of the month — also writing monthly/"
        upload_tier "monthly/${RUN_ID}" "${BACKUP_DATABASES[@]}"
    fi

    # First of the year: baja_resultados ALONE. The certificate-archive
    # justification for indefinite retention does not extend to forum
    # history, and keeping forum copies forever would be hoarding personal
    # data past its purpose rather than prudence.
    if [[ "$day_of_year" == "001" ]]; then
        log "upload: ${RUN_ID} is the first of the year — also writing yearly/ (baja_resultados only)"
        upload_tier "yearly/${RUN_ID}" baja_resultados
    fi

    log "upload: OK"
}

upload_tier() {
    local dest="$1"; shift
    local -a dbs=("$@")
    local db

    log "upload: -> ${BACKUP_REMOTE}${dest}/"
    for db in "${dbs[@]}"; do
        rclone_put "${RUN_DIR}/${db}.sql.gz.age"        "${dest}/${db}.sql.gz.age"
        rclone_put "${RUN_DIR}/${db}.sql.gz.age.sha256" "${dest}/${db}.sql.gz.age.sha256"
    done

    # The manifest is uploaded to every tier, including yearly/ where it
    # describes artifacts that tier does not hold. It is the record of what
    # the run contained; a folder without it cannot be interpreted, and the
    # pruner is forbidden from removing one except with its whole folder.
    rclone_put "${RUN_DIR}/manifest.json" "${dest}/manifest.json"
}

rclone_put() {
    local src="$1" dest="$2"
    rclone copyto --retries 3 --low-level-retries 10 --checksum "$src" "${BACKUP_REMOTE}${dest}" \
        || die "upload of ${dest} failed — the run folder is incomplete and MUST NOT be treated as a backup"
}

# ----- Phase: report -----
#
# Reached only when every phase above succeeded.
phase_report() {
    local duration=$(( $(date +%s) - START_EPOCH ))
    # A clock step during the run (ntp, or a VM resuming) can make this
    # negative. Duration is reported to Kuma as a ping value, and a
    # negative one is worse than a slightly wrong one.
    if (( duration < 0 )); then duration=0; fi

    local total=0 db
    for db in "${BACKUP_DATABASES[@]}"; do
        total=$(( total + ${ARTIFACT_BYTES[$db]} ))
    done

    # Declared and assigned separately so a failure inside human_bytes is
    # not masked by `local`'s own exit status.
    local size_text msg
    size_text="$(human_bytes "$total")"
    msg="run=${RUN_ID} dbs=${#BACKUP_DATABASES[@]} size=${size_text} duration=${duration}s"
    log "report: ${msg}"

    if [[ "$BACKUP_REMOTE" != "gdrive:" ]]; then
        warn "report: BACKUP_REMOTE is not the production Drive — skipping the Kuma heartbeat so a dev run cannot mark the monitor healthy"
        return 0
    fi

    kuma_push "${KUMA_PUSH_URL_BACKUP:-}" "$msg" "$(( duration * 1000 ))"
    log "report: OK"
}

# ----- Dispatch -----
usage() {
    sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" \
        | sed -e '/^set -euo pipefail$/d' -e 's/^# \{0,1\}//'
}

main() {
    local cmd="${1:-run}"
    case "$cmd" in
        -h|--help|help)
            usage
            exit 0
            ;;
        preflight)
            START_EPOCH="$(date +%s)"
            RUN_ID="${BACKUP_RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
            phase_preflight
            ;;
        run)
            START_EPOCH="$(date +%s)"
            RUN_ID="${BACKUP_RUN_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
            phase_preflight
            phase_dump
            phase_encrypt
            phase_manifest
            phase_upload
            phase_report
            log "DONE."
            ;;
        *)
            err "unknown subcommand: $cmd"
            usage
            exit 2
            ;;
    esac
}

main "$@"
