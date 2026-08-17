#!/usr/bin/env bash
# verify-db.sh — weekly proof that the newest backup can actually be
# restored, and that what comes back is what went in.
#
# Runs INSIDE the verifier container of the baja-verify stack. Lifecycle —
# bringing the scratch server up, and destroying it and its volume
# afterwards — belongs to scripts/verify-run.sh on the host, because
# asserting that a docker volume is really gone cannot be done from inside
# a container. Cron calls verify-run.sh, not this.
#
# Usage:
#   verify-db.sh            # the whole check
#   verify-db.sh -h | --help
#
# WHAT IT CHECKS, cheapest first
#
#   1. Freshness      the newest daily/ run has a manifest younger than
#                     26h. Highest-value single check in the whole file:
#                     it catches a dead cron, which is likelier than a
#                     corrupt dump.
#   2. Completeness   every artifact the manifest names is present.
#                     Catches a partial upload, or a database silently
#                     dropping out of a run.
#   3. Integrity      each artifact's sha256 matches BOTH its sidecar and
#                     the manifest. Catches truncated uploads and
#                     disk-full-mid-dump.
#   4. Import         decrypts, gunzip -t, checks mysqldump's trailer,
#                     imports.
#   5. Fidelity       per-table COUNT(*), routine/trigger/event counts and
#                     the charset canary, each against the manifest.
#   6. Floors         absolute minimums that do not come from the
#                     manifest — see the comment on check_floors.
#   7. Functional     the real certificate-lookup query returns a row with
#                     every field a PDF needs.
#
# Everything from step 4 on runs PER DATABASE and reports per database. A
# corrupt phpbb_formula must not mask a healthy baja_resultados, and you
# should be able to see which one failed without reading logs.
#
# HARD failures (nothing arrived, checksum mismatch, import failed, count
# diff, canary mangled) suppress the Kuma heartbeat and the monitor
# alerts. SOFT findings (schema drift, duration trend, large count
# movement) are logged for review and do NOT alert. Noisy checks train
# people to ignore the alert that matters.
#
# Environment (see docker-compose.verify.yml):
#   MYSQL_HOST / MYSQL_PORT        the scratch server
#   MYSQL_VERIFY_ROOT_PASSWORD     its root password
#   MYSQL_ROOT_PASSWORD            production's, ONLY to assert they differ
#   GDRIVE_SA_READER_JSON          baja-backup-reader key (Viewer)
#   GDRIVE_SHARED_DRIVE_ID
#   AGE_IDENTITY_VERIFIER          path to the verifier PRIVATE key
#   KUMA_PUSH_URL_VERIFY
#   VERIFY_MIN_ROWS_USER           floor for baja_resultados.user
#   VERIFY_REMOTE                  test hook, mirrors BACKUP_REMOTE
#   VERIFY_STATE_DIR               previous run's hashes (default
#                                  /var/lib/baja-verify)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_PREFIX="verify"
# shellcheck source=./backup-lib.sh
source "$SCRIPT_DIR/backup-lib.sh"

MYSQL_HOST="${MYSQL_HOST:-verify-mysql}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
VERIFY_REMOTE="${VERIFY_REMOTE:-gdrive:}"
VERIFY_STATE_DIR="${VERIFY_STATE_DIR:-/var/lib/baja-verify}"
VERIFY_MIN_ROWS_USER="${VERIFY_MIN_ROWS_USER:-50}"
VERIFY_MAX_MANIFEST_AGE_HOURS="${VERIFY_MAX_MANIFEST_AGE_HOURS:-26}"
EXPECTED_SCRATCH_HOSTNAME="${EXPECTED_SCRATCH_HOSTNAME:-baja-verify-mysql}"

WORK_DIR=""
RUN_ID=""
MYSQL_DEFAULTS_FILE=""
# Whether the manifest is present and parseable. Everything from
# verify_one onwards compares against it, so without one there is nothing
# to verify AGAINST — the per-database pass is skipped rather than run
# blind. See phase_precheck.
MANIFEST_OK=no
HARD_FAILURES=()
SOFT_NOTES=()
declare -A DB_RESULT=()
declare -A DB_RESTORE_SECONDS=()

fail_hard() { HARD_FAILURES+=("$1"); err "$1"; }
note_soft() { SOFT_NOTES+=("$1"); warn "SOFT: $1"; }

# The work directory holds decrypted plaintext. Same rule as the backup
# job: it does not survive the process on any exit path.
cleanup() {
    if [[ -n "$WORK_DIR" && -d "$WORK_DIR" ]]; then
        rm -rf -- "$WORK_DIR"
    fi
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

# ----- Phase: preflight -----
#
# The guardrails come first and are absolute. Everything below this
# function assumes it is talking to a scratch server; if that assumption
# is wrong, the run imports production data over production.
phase_preflight() {
    log "preflight: guardrails"

    local tool
    for tool in mysql mysqldump age rclone curl jq gzip sha256sum; do
        command -v "$tool" >/dev/null 2>&1 || die "$tool not found in PATH"
    done

    local missing=() var
    for var in MYSQL_VERIFY_ROOT_PASSWORD AGE_IDENTITY_VERIFIER; do
        [[ -n "${!var:-}" ]] || missing+=("$var")
    done
    if [[ "$VERIFY_REMOTE" == "gdrive:" ]]; then
        for var in GDRIVE_SA_READER_JSON GDRIVE_SHARED_DRIVE_ID; do
            [[ -n "${!var:-}" ]] || missing+=("$var")
        done
    else
        warn "VERIFY_REMOTE=${VERIFY_REMOTE} — not the production Shared Drive; this is a test run"
    fi
    (( ${#missing[@]} == 0 )) || die "required environment variables unset: ${missing[*]}"

    # Guardrail 3. Same password on both servers would mean one typo in a
    # host or a defaults file is enough to point this job at production.
    if [[ -n "${MYSQL_ROOT_PASSWORD:-}" && "$MYSQL_ROOT_PASSWORD" == "$MYSQL_VERIFY_ROOT_PASSWORD" ]]; then
        die "MYSQL_VERIFY_ROOT_PASSWORD is identical to production's MYSQL_ROOT_PASSWORD — that removes the last thing standing between a misconfigured import and production"
    fi

    [[ -r "$AGE_IDENTITY_VERIFIER" ]] \
        || die "verifier age private key not readable at ${AGE_IDENTITY_VERIFIER}"

    WORK_DIR="$(mktemp -d /var/tmp/baja-verify.XXXXXX)"
    chmod 700 "$WORK_DIR"

    MYSQL_DEFAULTS_FILE="${WORK_DIR}/client.cnf"
    write_defaults_file "$MYSQL_DEFAULTS_FILE" \
        "$MYSQL_HOST" "$MYSQL_PORT" root "$MYSQL_VERIFY_ROOT_PASSWORD"

    db_query_nodb "SELECT 1" >/dev/null 2>&1 \
        || die "cannot reach the scratch MySQL at ${MYSQL_HOST}:${MYSQL_PORT}"

    # Guardrail: assert the target BEFORE importing anything.
    local scratch_hostname
    scratch_hostname="$(db_query_nodb "SELECT @@hostname")"
    [[ "$scratch_hostname" == "$EXPECTED_SCRATCH_HOSTNAME" ]] \
        || die "connected to a server calling itself '${scratch_hostname}', expected '${EXPECTED_SCRATCH_HOSTNAME}' — refusing to import"

    local existing
    existing="$(db_query_nodb "SELECT COUNT(*) FROM information_schema.SCHEMATA
        WHERE SCHEMA_NAME NOT IN ('mysql','information_schema','performance_schema','sys')")"
    (( existing == 0 )) \
        || die "the scratch server already holds ${existing} user database(s) — a previous run did not tear down; refusing to import into it"

    log "preflight: scratch server '${scratch_hostname}' is empty and reachable"

    configure_rclone
    rclone lsd "$VERIFY_REMOTE" >/dev/null \
        || die "cannot list ${VERIFY_REMOTE} — check GDRIVE_SA_READER_JSON and that baja-backup-reader is a Viewer on the Shared Drive"

    log "preflight: OK"
}

# Read-only by construction on both sides: the Viewer role on the Shared
# Drive, and rclone's drive.readonly scope. The verifier has no reason to
# be able to write anything.
configure_rclone() {
    export RCLONE_CONFIG=/dev/null
    if [[ "$VERIFY_REMOTE" == "gdrive:" ]]; then
        [[ -r "$GDRIVE_SA_READER_JSON" ]] \
            || die "reader service-account key not readable at ${GDRIVE_SA_READER_JSON}"
        export RCLONE_CONFIG_GDRIVE_TYPE=drive
        export RCLONE_CONFIG_GDRIVE_SCOPE=drive.readonly
        export RCLONE_CONFIG_GDRIVE_SERVICE_ACCOUNT_FILE="$GDRIVE_SA_READER_JSON"
        export RCLONE_CONFIG_GDRIVE_TEAM_DRIVE="$GDRIVE_SHARED_DRIVE_ID"
    fi
}

# ----- Phase: fetch -----
#
# Run ids are UTC timestamps, so lexicographic order is chronological and
# `sort | tail -1` is the newest run.
phase_fetch() {
    log "fetch: locating the newest run in daily/"

    local runs=""
    if ! runs="$(rclone lsf --dirs-only "${VERIFY_REMOTE}daily/" 2>/dev/null | sed 's:/*$::' | sort)"; then
        fail_hard "cannot list ${VERIFY_REMOTE}daily/ — the folder does not exist, or the reader account cannot see it"
        return 0
    fi
    if [[ -z "$runs" ]]; then
        fail_hard "daily/ is empty — no backup has ever arrived"
        return 0
    fi

    RUN_ID="$(printf '%s\n' "$runs" | tail -1)"
    log "fetch: newest run is ${RUN_ID}"

    if ! rclone copy --retries 3 "${VERIFY_REMOTE}daily/${RUN_ID}" "$WORK_DIR"; then
        fail_hard "could not download daily/${RUN_ID}"
        return 0
    fi
}

# ----- Phase: precheck -----
#
# Everything here happens WITHOUT decrypting anything, which is why the
# manifest is uploaded in the clear. These are the checks most likely to
# fire, so they run before the expensive ones.
phase_precheck() {
    local manifest="${WORK_DIR}/manifest.json"

    # The manifest is the commit marker for a run. Its absence means the
    # upload did not finish — which is exactly the state that must read as
    # an incomplete run rather than as a healthy backup with a database
    # quietly missing.
    if [[ ! -f "$manifest" ]]; then
        fail_hard "daily/${RUN_ID} has no manifest.json — INCOMPLETE RUN, not a backup"
        return 0
    fi
    if ! jq empty "$manifest" 2>/dev/null; then
        fail_hard "manifest.json in daily/${RUN_ID} is not valid JSON"
        return 0
    fi
    MANIFEST_OK=yes

    # 1. Freshness. Catches a dead cron, which is likelier than a corrupt
    #    dump and is invisible to every other check here — a week-old
    #    backup passes integrity and fidelity perfectly.
    local created age_seconds now
    created="$(jq -r '.created_utc' "$manifest")"
    now="$(date -u +%s)"
    age_seconds=$(( now - $(date -u -d "$created" +%s) ))
    local age_hours=$(( age_seconds / 3600 ))
    if (( age_seconds > VERIFY_MAX_MANIFEST_AGE_HOURS * 3600 )); then
        fail_hard "newest backup is ${age_hours}h old (limit ${VERIFY_MAX_MANIFEST_AGE_HOURS}h) — the nightly job has stopped running"
    else
        log "precheck: newest run is ${age_hours}h old — fresh"
    fi

    # 2. Completeness, and 3. integrity, per artifact.
    local db file expected_sha actual_sha sidecar_sha
    for db in "${BACKUP_DATABASES[@]}"; do
        file="$(jq -r --arg d "$db" '.artifacts[$d].file // empty' "$manifest")"
        if [[ -z "$file" ]]; then
            fail_hard "${db}: manifest does not describe this database at all"
            DB_RESULT["$db"]="MISSING FROM MANIFEST"
            continue
        fi
        if [[ ! -f "${WORK_DIR}/${file}" ]]; then
            fail_hard "${db}: manifest names ${file} but it is not in the run folder — partial upload"
            DB_RESULT["$db"]="ARTIFACT MISSING"
            continue
        fi

        expected_sha="$(jq -r --arg d "$db" '.artifacts[$d].sha256' "$manifest")"
        actual_sha="$(sha256sum "${WORK_DIR}/${file}" | cut -d' ' -f1)"
        if [[ "$actual_sha" != "$expected_sha" ]]; then
            fail_hard "${db}: sha256 does not match the manifest (artifact is corrupt or truncated)"
            DB_RESULT["$db"]="CHECKSUM MISMATCH"
            continue
        fi

        # The sidecar is checked separately rather than assumed to agree:
        # it is the copy a human restoring from Drive alone will use.
        if [[ ! -f "${WORK_DIR}/${file}.sha256" ]]; then
            fail_hard "${db}: ${file}.sha256 sidecar is missing"
            DB_RESULT["$db"]="SIDECAR MISSING"
            continue
        fi
        sidecar_sha="$(cut -d' ' -f1 < "${WORK_DIR}/${file}.sha256")"
        if [[ "$sidecar_sha" != "$actual_sha" ]]; then
            fail_hard "${db}: sidecar sha256 disagrees with the artifact"
            DB_RESULT["$db"]="SIDECAR MISMATCH"
            continue
        fi

        log "precheck: ${db} — present, sha256 matches manifest and sidecar"
    done
}

# ----- Per-database verification -----
#
# Never `die` in here. A corrupt phpbb_formula must not stop
# baja_resultados from being verified and reported on.
verify_one() {
    local db="$1"
    local manifest="${WORK_DIR}/manifest.json"

    # Skipped if precheck already failed it.
    [[ -z "${DB_RESULT[$db]:-}" ]] || { log "${db}: skipped (${DB_RESULT[$db]})"; return 0; }

    local artifact="${WORK_DIR}/${db}.sql.gz.age"
    local gz="${WORK_DIR}/${db}.sql.gz"
    local sql="${WORK_DIR}/${db}.sql"

    log "${db}: decrypting"
    if ! age -d -i "$AGE_IDENTITY_VERIFIER" -o "$gz" "$artifact" 2>"${WORK_DIR}/${db}.age.err"; then
        fail_hard "${db}: age decryption failed ($(tail -1 "${WORK_DIR}/${db}.age.err")) — wrong verifier key, or the artifact is damaged"
        DB_RESULT["$db"]="DECRYPT FAILED"
        return 0
    fi
    if ! gzip -t "$gz" 2>/dev/null; then
        fail_hard "${db}: gzip integrity test failed"
        DB_RESULT["$db"]="GUNZIP FAILED"
        return 0
    fi
    gzip -dc "$gz" > "$sql"
    rm -f -- "$gz"

    # The trailer is the check that catches truncation. It is more
    # reliable than the import's exit code, which is forgiving of a dump
    # that stops halfway through a table: the SQL that did arrive is
    # valid, so mysql exits 0 having restored part of the data.
    if ! tail -c 4096 "$sql" | grep -q '^-- Dump completed'; then
        fail_hard "${db}: dump has no '-- Dump completed' trailer — TRUNCATED"
        DB_RESULT["$db"]="TRUNCATED"
        return 0
    fi

    log "${db}: importing $(human_bytes "$(stat -c %s "$sql")") into the scratch server"
    local start_epoch end_epoch
    start_epoch="$(date +%s)"
    if ! mysql --defaults-file="$MYSQL_DEFAULTS_FILE" < "$sql" 2>"${WORK_DIR}/${db}.import.err"; then
        fail_hard "${db}: import failed — $(tail -1 "${WORK_DIR}/${db}.import.err")"
        DB_RESULT["$db"]="IMPORT FAILED"
        return 0
    fi
    end_epoch="$(date +%s)"
    # Clamped: this box's clock has been observed stepping backwards
    # mid-run, and a negative RTO in the report is worse than a slightly
    # wrong one.
    local elapsed=$(( end_epoch - start_epoch ))
    if (( elapsed < 0 )); then elapsed=0; fi
    DB_RESTORE_SECONDS["$db"]=$elapsed
    rm -f -- "$sql"

    local failures_before=${#HARD_FAILURES[@]}
    check_fidelity "$db" "$manifest"
    check_floors "$db"
    if [[ "$db" == "baja_resultados" ]]; then
        check_certificate_lookup "$db"
    fi
    record_soft_observations "$db"

    if (( ${#HARD_FAILURES[@]} > failures_before )); then
        DB_RESULT["$db"]="FAILED"
    else
        DB_RESULT["$db"]="OK (restored in ${DB_RESTORE_SECONDS[$db]}s)"
    fi
}

# Diff the restored database against the manifest. This is a check of
# TRANSIT fidelity — that what arrived is what was dumped.
check_fidelity() {
    local db="$1" manifest="$2"

    # Row counts, recomputed the same way the backup computed them,
    # because both sides call the same function in backup-lib.sh.
    local restored expected diff
    restored="$(backup_table_counts "$db" \
        | jq -Rn '[inputs | select(length > 0) | split("\t") | {(.[0]): (.[1] | tonumber)}] | add // {}')"
    expected="$(jq -c --arg d "$db" '.artifacts[$d].table_counts' "$manifest")"

    diff="$(jq -n --argjson a "$expected" --argjson b "$restored" '
        [ ((($a | keys) + ($b | keys)) | unique)[] as $k
          | { table: $k, manifest: ($a[$k] // null), restored: ($b[$k] // null) }
          | select(.manifest != .restored) ]')"
    if [[ "$(jq 'length' <<<"$diff")" != "0" ]]; then
        fail_hard "${db}: row counts differ from the manifest: $(jq -c '.' <<<"$diff")"
    fi

    # Routine/trigger/event counts. Catches someone quietly dropping
    # --routines in a refactor, which would otherwise produce a restore
    # that is missing its stored logic and passes every row count.
    local objects procedures functions triggers events
    objects="$(backup_object_counts "$db")"
    IFS=$'\t' read -r procedures functions triggers events <<<"$objects"
    local exp_proc exp_func exp_trig exp_evt
    exp_proc="$(jq -r --arg d "$db" '.artifacts[$d].routine_counts.procedures' "$manifest")"
    exp_func="$(jq -r --arg d "$db" '.artifacts[$d].routine_counts.functions' "$manifest")"
    exp_trig="$(jq -r --arg d "$db" '.artifacts[$d].trigger_count' "$manifest")"
    exp_evt="$(jq -r --arg d "$db" '.artifacts[$d].event_count' "$manifest")"
    if [[ "$procedures" != "$exp_proc" || "$functions" != "$exp_func" \
       || "$triggers" != "$exp_trig" || "$events" != "$exp_evt" ]]; then
        fail_hard "${db}: schema object counts differ — manifest says ${exp_proc}p/${exp_func}f/${exp_trig}t/${exp_evt}e, restored has ${procedures}p/${functions}f/${triggers}t/${events}e"
    fi

    # The charset canary. Accent mangling imports without a single error;
    # without this you would discover it when a name renders as "JosÃ©"
    # on a certificate someone is about to submit to an employer.
    local canary expected_canary
    canary="$(backup_canary_hash "$db")"
    expected_canary="$(jq -r --arg d "$db" '.artifacts[$d].charset_canary_sha256' "$manifest")"
    if [[ "$canary" != "$expected_canary" ]]; then
        fail_hard "${db}: CHARSET CANARY MISMATCH — accented characters did not survive the round trip"
    else
        log "${db}: fidelity OK (counts, objects, charset canary)"
    fi
}

# Absolute floors.
#
# The manifest diff above verifies transit fidelity, NOT source
# correctness. If the backup user silently loses SELECT on a table, the
# manifest records the wrong count too and the diff passes happily. These
# floors are the only checks here that do not trust the manifest.
check_floors() {
    local db="$1"

    local tables min_tables
    tables="$(db_query_nodb "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA='${db}' AND TABLE_TYPE='BASE TABLE'")"
    min_tables="$(backup_min_tables "$db")"
    if (( tables < min_tables )); then
        fail_hard "${db}: only ${tables} tables restored, expected at least ${min_tables} — tables are missing from the backup itself, not from transit"
    fi

    if [[ "$db" == "baja_resultados" ]]; then
        local users
        users="$(db_query "$db" "SELECT COUNT(*) FROM \`user\`")"
        if (( users < VERIFY_MIN_ROWS_USER )); then
            fail_hard "baja_resultados.user has ${users} rows, floor is ${VERIFY_MIN_ROWS_USER} — the dump is not of the database we think it is"
        fi
    fi
}

# The functional check — the one that matters.
#
# Certificates are generated on demand, so "the backup restored" means
# nothing until a certificate can actually be produced from it. This runs
# the application's real lookup (see baja-php/certificado/certificado.php)
# and asserts every field the PDF needs comes back non-empty.
#
# The subject is chosen by a deterministic rule — the oldest participant
# row belonging to an event that issues certificates — and NEVER by a
# hardcoded CPF. This repository is public.
check_certificate_lookup() {
    local db="$1"

    # The emptiness test is done in SQL and returns the NAMES of the bad
    # fields, not the values. Two reasons: nothing personal is ever pulled
    # into the shell or a log line, and splitting a tab-separated row in
    # bash is unsafe — tab is IFS whitespace, so consecutive tabs collapse
    # and an empty middle column silently shifts every later field.
    #
    # CONCAT_WS drops NULL arguments, so `missing` is empty when every
    # field is present and a comma-separated list of failures otherwise.
    # `local` needs backticks: it is a MySQL keyword.
    local row missing found
    row="$(db_query "$db" "
        SELECT
          CONCAT_WS(',',
            IF(p.nome               IS NULL OR p.nome = '',               'participante.nome',        NULL),
            IF(p.funcao             IS NULL OR p.funcao = '',             'participante.funcao',      NULL),
            IF(e.nome               IS NULL OR e.nome = '',               'evento.nome',              NULL),
            IF(e.\`local\`          IS NULL OR e.\`local\` = '',          'evento.local',             NULL),
            IF(e.presidente         IS NULL OR e.presidente = '',         'evento.presidente',        NULL),
            IF(e.data               IS NULL OR e.data = '',               'evento.data',              NULL),
            IF(e.mandato_presidente IS NULL OR e.mandato_presidente = '', 'evento.mandato_presidente', NULL)
          ) AS missing,
          'found' AS marker
        FROM participantes p
        JOIN evento e ON e.evento_id = p.evento
        WHERE e.tem_certificado = 1
        ORDER BY p.idparticipantes ASC
        LIMIT 1")"

    if [[ -z "$row" ]]; then
        fail_hard "${db}: the certificate lookup returned no rows — no participant is attached to any certificate-issuing event"
        return 0
    fi

    missing="${row%%$'\t'*}"
    found="${row##*$'\t'}"
    if [[ "$found" != "found" ]]; then
        fail_hard "${db}: certificate lookup returned an unreadable row"
        return 0
    fi
    if [[ -n "$missing" ]]; then
        fail_hard "${db}: certificate lookup is missing ${missing} — a PDF generated from this restore would be malformed"
        return 0
    fi
    log "${db}: certificate lookup OK — all seven PDF fields present"
}

# Record, do not assert.
#
# Schema drift is REPORTED rather than failed: schema changes have been
# made directly in MySQL before, so this is a free drift detector, and
# failing on it would mean every legitimate migration pages someone.
record_soft_observations() {
    local db="$1"
    local state_file="${VERIFY_STATE_DIR}/previous.json"

    local schema_hash total_rows
    schema_hash="$(mysqldump --defaults-file="$MYSQL_DEFAULTS_FILE" \
        --no-data --skip-dump-date --no-tablespaces --routines --triggers --events \
        --databases "$db" 2>/dev/null | sha256sum | cut -d' ' -f1)"
    total_rows="$(backup_table_counts "$db" | awk -F'\t' '{s += $2} END {print s + 0}')"

    if [[ -f "$state_file" ]]; then
        local prev_hash prev_rows
        prev_hash="$(jq -r --arg d "$db" '.[$d].schema_sha256 // empty' "$state_file")"
        prev_rows="$(jq -r --arg d "$db" '.[$d].total_rows // empty' "$state_file")"

        if [[ -n "$prev_hash" && "$prev_hash" != "$schema_hash" ]]; then
            note_soft "${db}: schema changed since the previous verification run"
        fi
        # A large swing in either direction is worth a human glance: a
        # collapse suggests data loss, a spike suggests something is
        # writing that should not be.
        if [[ -n "$prev_rows" && "$prev_rows" -gt 0 ]]; then
            local delta_pct=$(( (total_rows - prev_rows) * 100 / prev_rows ))
            if (( delta_pct > 20 || delta_pct < -20 )); then
                note_soft "${db}: total row count moved ${delta_pct}% since the previous run (${prev_rows} -> ${total_rows})"
            fi
        fi
    fi

    # This is the RTO, measured weekly rather than discovered during an
    # outage.
    log "${db}: restore took ${DB_RESTORE_SECONDS[$db]}s (this is the measured RTO for this database)"

    jq -n --arg d "$db" --arg h "$schema_hash" --argjson r "$total_rows" \
        '{($d): {schema_sha256: $h, total_rows: $r}}' \
        > "${WORK_DIR}/state.${db}.json"
}

persist_state() {
    [[ -d "$VERIFY_STATE_DIR" ]] || { note_soft "state directory ${VERIFY_STATE_DIR} is not mounted — schema drift cannot be detected next run"; return 0; }
    local -a files=()
    local db
    for db in "${BACKUP_DATABASES[@]}"; do
        if [[ -f "${WORK_DIR}/state.${db}.json" ]]; then
            files+=("${WORK_DIR}/state.${db}.json")
        fi
    done
    if (( ${#files[@]} > 0 )); then
        jq -s 'add' "${files[@]}" > "${VERIFY_STATE_DIR}/previous.json"
    fi
}

# ----- Phase: report -----
phase_report() {
    local db
    log "----- results -----"
    for db in "${BACKUP_DATABASES[@]}"; do
        log "  ${db}: ${DB_RESULT[$db]:-NOT REACHED}"
    done

    if (( ${#SOFT_NOTES[@]} > 0 )); then
        log "----- for review (does not alert) -----"
        local note
        for note in "${SOFT_NOTES[@]}"; do
            log "  ${note}"
        done
    fi

    if (( ${#HARD_FAILURES[@]} > 0 )); then
        err "----- ${#HARD_FAILURES[@]} hard failure(s) -----"
        local failure
        for failure in "${HARD_FAILURES[@]}"; do
            err "  ${failure}"
        done
        err "NO Kuma heartbeat sent — the monitor will alert on silence"
        return 1
    fi

    # No Kuma ping here, deliberately. Teardown is a compliance step and it
    # happens AFTER this process exits, so pinging from inside would report
    # success while a scratch database of decrypted competitor data was
    # still sitting on the production host. verify-run.sh pings only once
    # verification AND teardown have both succeeded.
    log "run=${RUN_ID} — all three databases restored and verified"
    return 0
}

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

    phase_preflight
    phase_fetch
    if (( ${#HARD_FAILURES[@]} == 0 )); then
        phase_precheck
        if [[ "$MANIFEST_OK" == "yes" ]]; then
            local db
            for db in "${BACKUP_DATABASES[@]}"; do
                verify_one "$db"
            done
            persist_state
        else
            err "skipping per-database verification: without a manifest there is nothing to verify against"
        fi
    fi
    phase_report
}

main "$@"
