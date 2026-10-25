#!/usr/bin/env bash
# shellcheck disable=SC2034,SC2016
# shellcheck source-path=SCRIPTDIR/../../scripts
#   (SC2034: the tests set variables — RUN_DIR, WORK_DIR, LOG_PREFIX... —
#   read by the scripts they source, which shellcheck cannot see. SC2016:
#   the fixtures hold literal $ signs on purpose.)
#
# Tests for the backup scripts in baja-infra/scripts/.
#
# WHAT THIS CHECKS, IN PLAIN WORDS
#
# Every night the backup job copies the forum attachments, the forum
# avatars and the generated timer sounds into encrypted archives (the
# "file sets"), copies the erasure log, writes a "manifest" (a small JSON
# file listing everything the night produced), and uploads it all to a
# Google Shared Drive with the manifest LAST. Separately, when an event is
# finished, its published results are frozen in an "event archive".
#
# These tests run those exact functions and scripts on made-up files, with
# a fake Google Drive (a folder on this machine) and freshly made
# encryption keys, and check that:
#
#   - a file set restores to exactly the files that went in, and both
#     keys (escrow and verifier) can open it;
#   - a missing forum folder stops the backup instead of producing an
#     empty archive that looks fine;
#   - the weekly verifier notices when restored files differ;
#   - the manifest lists every artifact with the right checksum, and
#     refuses to contain anything that looks like personal data;
#   - the manifest is uploaded after everything else, in every tier;
#   - the erasure log survives a backup and restore with its chain intact;
#   - an event archive records the event, the time, the code version and
#     the number of snapshots, restores, and passes the verifier.
#
# Nothing here needs Docker, MySQL or a Google account. It needs bash,
# GNU tar, jq and age (`sudo apt install age jq` on Ubuntu/Debian).
#
# HOW TO RUN, from the repository root:
#
#     baja-infra/tests/backup/run-tests.sh
#
# It prints "ok" or "FAIL" for each test and exits non-zero if any failed.
# Everything it creates is in a temporary folder that is removed at the end.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS="$(cd "${HERE}/../../scripts" && pwd)"

for tool in age age-keygen jq tar gzip sha256sum; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "missing tool: ${tool} (on Ubuntu/Debian: sudo apt install age jq)" >&2
        exit 2
    fi
done

# ----- The playground -----
#
# One temporary folder for the whole run. Inside it:
#   keys/     two age key pairs, standing in for the escrow and verifier keys
#   drive/    the fake Shared Drive (see fake-bin/rclone)
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT

mkdir -p "${TMP}/keys"
age-keygen -o "${TMP}/keys/escrow.key" 2>/dev/null
age-keygen -o "${TMP}/keys/verifier.key" 2>/dev/null
AGE_RECIPIENT_ESCROW="$(age-keygen -y "${TMP}/keys/escrow.key")"
AGE_RECIPIENT_VERIFIER="$(age-keygen -y "${TMP}/keys/verifier.key")"
export AGE_RECIPIENT_ESCROW AGE_RECIPIENT_VERIFIER

# The fake rclone goes first on PATH, so the scripts use it without knowing.
export PATH="${HERE}/fake-bin:${PATH}"

# ----- A tiny test runner -----
#
# Each test is a function run in its own subshell, so whatever one test
# sets or breaks cannot leak into the next. Inside a test, `fail "why"`
# ends it as failed; reaching the end means it passed.
PASSED=0
FAILED=0
run_test() {
    local name="$1" fn="$2" out
    if out="$( ( "$fn" ) 2>&1 )"; then
        echo "  ok   ${name}"
        PASSED=$(( PASSED + 1 ))
    else
        echo "  FAIL ${name}"
        printf '%s\n' "$out" | tail -15 | sed 's/^/         /'
        FAILED=$(( FAILED + 1 ))
    fi
}
fail() { echo "ASSERTION FAILED: $*" >&2; exit 1; }
same() { [[ "$1" == "$2" ]] || fail "${3:-value}: got '$1', expected '$2'"; }

# Made-up forum uploads for one forum, like the real layout:
#   <root>/files/                  attachments (phpBB names them by hash)
#   <root>/images/avatars/upload/  avatars
# with the awkward cases on purpose: a sub-folder, a name with spaces and
# accents, an empty file, and binary content.
make_forum_files() {
    local root="$1"
    mkdir -p "${root}/files/thumbs" "${root}/images/avatars/upload"
    printf 'attachment one' > "${root}/files/3f2a9c0d1e"
    printf 'a thumbnail'    > "${root}/files/thumbs/t_3f2a9c0d1e"
    printf 'Relatório de inspeção técnica' > "${root}/files/nome com espaço e ção.txt"
    : > "${root}/files/empty"
    head -c 4096 /dev/urandom > "${root}/images/avatars/upload/a1b2c3_42.png"
    # Something in the volume that must NOT be backed up: phpBB's
    # config.php holds the forum's database password.
    printf '%s' '<?php $dbpasswd = "secret";' > "${root}/config.php"
}

# ======================================================================
# File sets (the forum uploads and the timer sounds)
# ======================================================================

test_file_set_round_trip() {
    # Arrange: a fake /state with one forum's uploads.
    export BACKUP_STATE_DIR="${TMP}/rt/state"
    make_forum_files "${BACKUP_STATE_DIR}/phpbb-baja"
    local out="${TMP}/rt/out" restored
    mkdir -p "$out"
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"

    # Act: what the nightly backup does for this set — archive, then
    # encrypt to both keys.
    backup_file_set_archive phpbb_baja-files "$out"
    backup_encrypt "${out}/phpbb_baja-files.tar.gz" "${out}/phpbb_baja-files.tar.gz.age" >/dev/null
    rm "${out}/phpbb_baja-files.tar.gz"

    # Assert: the manifest facts count the five uploads, not config.php.
    same "$(jq -r .file_count "${out}/fileset.phpbb_baja-files.json")" 5 "file count"
    same "$(jq -c .members "${out}/fileset.phpbb_baja-files.json")" '["files","images/avatars/upload"]' "members"

    # The sidecar is what a person restoring by hand checks with sha256sum -c.
    ( cd "$out" && sha256sum -c --quiet phpbb_baja-files.tar.gz.age.sha256 ) || fail "sidecar does not match"

    # Restore exactly as docs/backup-restore.md says, once with each key.
    local key
    for key in escrow verifier; do
        restored="${TMP}/rt/restored-${key}"
        mkdir -p "$restored"
        age -d -i "${TMP}/keys/${key}.key" "${out}/phpbb_baja-files.tar.gz.age" \
            | tar -xzf - -C "$restored" --numeric-owner
        # The restored tree must equal the original uploads, byte for byte...
        diff -r "${BACKUP_STATE_DIR}/phpbb-baja/files" "${restored}/files" || fail "files differ after restore with the ${key} key"
        diff -r "${BACKUP_STATE_DIR}/phpbb-baja/images/avatars/upload" "${restored}/images/avatars/upload" \
            || fail "avatars differ after restore with the ${key} key"
        # ...and hold nothing else.
        [[ ! -e "${restored}/config.php" ]] || fail "config.php was backed up"
        # The digest the verifier computes from the restored files equals
        # the one the backup computed from the archive.
        same "$(backup_tree_lines_from_dir "$restored" | backup_tree_summary | cut -f3)" \
             "$(jq -r .tree_sha256 "${out}/fileset.phpbb_baja-files.json")" "tree digest (${key})"
    done
}

test_required_folder_missing_stops_the_backup() {
    # A forum folder that is not mounted must fail the run: an empty
    # archive would look like a forum with no attachments.
    export BACKUP_STATE_DIR="${TMP}/missing/state"
    mkdir -p "${BACKUP_STATE_DIR}/phpbb-formula/files"     # avatars folder absent
    mkdir -p "${TMP}/missing/out"
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    if ( backup_file_set_archive phpbb_formula-files "${TMP}/missing/out" ) 2>/dev/null; then
        fail "archived a forum whose avatars folder is missing"
    fi
}

test_optional_file_set_may_be_empty() {
    # The generated timer sounds folder only exists once somebody has
    # generated a sound. Then the set is an empty archive, not an error.
    export BACKUP_STATE_DIR="${TMP}/empty/state"
    mkdir -p "${BACKUP_STATE_DIR}/baja-app-sons" "${TMP}/empty/out"
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    backup_file_set_archive baja-app-sons "${TMP}/empty/out"
    same "$(jq -r .file_count "${TMP}/empty/out/fileset.baja-app-sons.json")" 0 "file count"
    same "$(jq -c .members "${TMP}/empty/out/fileset.baja-app-sons.json")" '[]' "members"
}

# Builds a complete fake run folder, as backup-db.sh leaves it just before
# upload: three dump artifacts (random bytes standing in for encrypted
# SQL), the three file sets, the erasure log, all sidecars, the manifest.
# Prints the folder.
make_run_dir() {
    local base="$1" run="${1}/run"
    export BACKUP_STATE_DIR="${base}/state"
    make_forum_files "${BACKUP_STATE_DIR}/phpbb-baja"
    make_forum_files "${BACKUP_STATE_DIR}/phpbb-formula"
    mkdir -p "${BACKUP_STATE_DIR}/baja-app-sons/gerados" "$run"
    printf 'RIFF....WAVE' > "${BACKUP_STATE_DIR}/baja-app-sons/gerados/troca-de-carros.wav"

    local db set
    for db in "${BACKUP_DATABASES[@]}"; do
        head -c 512 /dev/urandom > "${run}/${db}.sql.gz.age"
        printf '%s  %s\n' "$(sha256sum "${run}/${db}.sql.gz.age" | cut -d' ' -f1)" "${db}.sql.gz.age" \
            > "${run}/${db}.sql.gz.age.sha256"
        # What dump_one records about a database (counts only, no rows).
        jq -n --arg f "${db}.sql.gz.age" '{file: $f, counts_taken_utc: "2026-01-01T04:00:00Z",
            table_counts: {evento: 17}, excluded_tables: [], routine_counts: {procedures: 0, functions: 0},
            trigger_count: 0, event_count: 0, charset_canary_sha256: "x"}' > "${run}/dbmeta.${db}.json"
        jq -n --arg s "$(cut -d' ' -f1 < "${run}/${db}.sql.gz.age.sha256")" \
            '{sha256: $s, dump_bytes: 1000, artifact_bytes: 512}' > "${run}/artifact.${db}.json"
    done
    for set in "${BACKUP_FILE_SETS[@]}"; do
        backup_file_set_archive "$set" "$run"
        jq -n --arg s "$(backup_encrypt "${run}/${set}.tar.gz" "${run}/${set}.tar.gz.age")" \
            --argjson b "$(stat -c %s "${run}/${set}.tar.gz")" '{sha256: $s, artifact_bytes: $b}' \
            > "${run}/artifact.${set}.json"
        rm "${run}/${set}.tar.gz"
    done
    : > "${base}/erasure-log.jsonl"
    jq -n '{file: "erasure-log.jsonl.age", entries: 0, chain_ok: true}' > "${run}/erasure.json"
    jq -n --arg s "$(backup_encrypt "${base}/erasure-log.jsonl" "${run}/erasure-log.jsonl.age")" \
        '{sha256: $s, artifact_bytes: 1}' > "${run}/artifact.erasure-log.json"

    backup_assemble_manifest "$run" 20260101T040000Z 2026-01-01T04:00:00Z test 8.4.0 v4-2-gabc1234 \
        > "${run}/manifest.json"
    printf '%s' "$run"
}

test_verifier_notices_restored_files_that_differ() {
    # The weekly verifier restores each file set and compares what came
    # out with the manifest. Here the manifest is doctored to describe
    # different files: the verifier must say so, and pass the honest one.
    local base="${TMP}/differ" run
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    run="$(make_run_dir "$base")"

    export AGE_IDENTITY_VERIFIER="${TMP}/keys/verifier.key"
    # shellcheck source=verify-db.sh
    source "${SCRIPTS}/verify-db.sh"
    WORK_DIR="$run"
    MANIFEST_SCHEMA=3

    verify_file_set phpbb_baja-files
    [[ "${OTHER_RESULT[phpbb_baja-files]}" == OK* ]] || fail "honest set: ${OTHER_RESULT[phpbb_baja-files]}"

    jq '.file_sets["phpbb_formula-files"].tree_sha256 = "0000"' "${run}/manifest.json" > "${run}/m" \
        && mv "${run}/m" "${run}/manifest.json"
    verify_file_set phpbb_formula-files 2>/dev/null
    same "${OTHER_RESULT[phpbb_formula-files]}" "CONTENT DIFFERS" "doctored set"
    (( ${#HARD_FAILURES[@]} == 1 )) || fail "expected exactly one hard failure, got ${#HARD_FAILURES[@]}"
}

# ======================================================================
# The manifest
# ======================================================================

test_manifest_lists_every_artifact() {
    local base="${TMP}/manifest" run m
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    run="$(make_run_dir "$base")"
    m="${run}/manifest.json"

    same "$(jq -r .schema_version "$m")" 3 "schema_version"
    same "$(jq -r .code_version "$m")" v4-2-gabc1234 "code_version"
    same "$(jq -c '.age_recipients | length' "$m")" 2 "number of recipients"
    same "$(jq -r '.age_recipients[0]' "$m")" "$AGE_RECIPIENT_ESCROW" "escrow recipient"

    # Every database, every file set and the erasure log is described, and
    # the checksum the manifest gives is the checksum of the file on disk.
    local db set
    for db in "${BACKUP_DATABASES[@]}"; do
        same "$(jq -r --arg d "$db" '.artifacts[$d].sha256' "$m")" \
             "$(sha256sum "${run}/${db}.sql.gz.age" | cut -d' ' -f1)" "sha256 of ${db}"
    done
    for set in "${BACKUP_FILE_SETS[@]}"; do
        same "$(jq -r --arg s "$set" '.file_sets[$s].sha256' "$m")" \
             "$(sha256sum "${run}/${set}.tar.gz.age" | cut -d' ' -f1)" "sha256 of ${set}"
    done
    same "$(jq -r '.erasure_log.sha256' "$m")" \
         "$(sha256sum "${run}/erasure-log.jsonl.age" | cut -d' ' -f1)" "sha256 of the erasure log"

    # The manifest travels unencrypted, so it must carry no file name from
    # inside a file set (attachment and avatar names point at people).
    if grep -q 'a1b2c3_42\|3f2a9c0d1e\|troca-de-carros' "$m"; then
        fail "a file name from inside a file set is in the manifest"
    fi
    backup_manifest_check_clean "$m"
}

test_manifest_refuses_personal_data() {
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    printf '{"artifacts": {"x": {"nome": "Fulano"}}}' > "${TMP}/pii1.json"
    if ( backup_manifest_check_clean "${TMP}/pii1.json" ) 2>/dev/null; then
        fail "accepted a manifest with a 'nome' field"
    fi
    printf '{"note": "fulano@example.com"}' > "${TMP}/pii2.json"
    if ( backup_manifest_check_clean "${TMP}/pii2.json" ) 2>/dev/null; then
        fail "accepted a manifest with an email address"
    fi
}

test_manifest_is_uploaded_last_in_every_tier() {
    # On 1 January a run is written to daily/, monthly/ and yearly/. In
    # each folder the manifest must be the LAST upload: a folder with a
    # manifest is, by definition, complete.
    local base="${TMP}/upload" run
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    run="$(make_run_dir "$base")"
    export FAKE_DRIVE="${base}/drive" FAKE_RCLONE_LOG="${base}/rclone.log" BACKUP_REMOTE="fake:"
    mkdir -p "$FAKE_DRIVE"

    # shellcheck source=backup-db.sh
    source "${SCRIPTS}/backup-db.sh"
    RUN_DIR="$run"
    RUN_ID=20260101T040000Z
    phase_upload >/dev/null

    local tier last
    for tier in daily monthly yearly; do
        last="$(grep "^copyto .* fake:${tier}/" "$FAKE_RCLONE_LOG" | tail -1 | awk '{print $3}')"
        same "$last" "fake:${tier}/${RUN_ID}/manifest.json" "last upload to ${tier}/"
    done

    # daily/ holds everything: 7 artifacts, 7 sidecars, 1 manifest.
    same "$(find "${FAKE_DRIVE}/daily/${RUN_ID}" -type f | wc -l)" 15 "files in daily/"
    # yearly/ holds baja_resultados alone (and the manifest).
    same "$(find "${FAKE_DRIVE}/yearly/${RUN_ID}" -type f -printf '%f\n' | sort | tr '\n' ' ')" \
         "baja_resultados.sql.gz.age baja_resultados.sql.gz.age.sha256 manifest.json " "files in yearly/"
}

# ======================================================================
# The erasure log
# ======================================================================

test_erasure_log_round_trip() {
    # Record two (synthetic) erasures, back the log up the way the nightly
    # job does, and have the verifier restore it: same entries, chain intact.
    local base="${TMP}/erasure"
    mkdir -p "$base"
    export ERASURE_LOG_PATH="${base}/erasure-log.jsonl"
    "${SCRIPTS}/erasure-log.sh" record --request REQ-T-1 --database baja_resultados \
        --table participantes --column cpf --value 00000000191 --note test >/dev/null
    "${SCRIPTS}/erasure-log.sh" record --request REQ-T-2 --database phpbb_baja \
        --table phpbb_users --column user_id --value 2 --note test >/dev/null

    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    local run="${base}/run"
    mkdir -p "$run"
    backup_encrypt "$ERASURE_LOG_PATH" "${run}/erasure-log.jsonl.age" >/dev/null
    jq -n '{erasure_log: {file: "erasure-log.jsonl.age", entries: 2, chain_ok: true}}' > "${run}/manifest.json"

    export AGE_IDENTITY_VERIFIER="${TMP}/keys/verifier.key"
    # shellcheck source=verify-db.sh
    source "${SCRIPTS}/verify-db.sh"
    WORK_DIR="$run"
    verify_erasure_log
    same "${OTHER_RESULT[erasure-log]}" "OK (2 entries, chain intact)" "verifier result"
    [[ ! -e "${run}/erasure-log.jsonl" ]] || fail "the decrypted erasure log was left behind"
}

# ======================================================================
# Event archives (BAJAAPP-67)
# ======================================================================

# What backup-run.sh hands archive-event.sh: the request, and the folder
# results-snapshot.php --update wrote (three made-up snapshot files).
make_archive_input() {
    local dir="$1" event="${2:-22BR}"
    mkdir -p "${dir}/snapshots/${event}"
    jq -n --arg e "$event" '{arquivamento_id: 7, evento_id: $e,
        pedido_em_utc: "2026-10-20T21:15:00Z", pedido_por: "admin"}' > "${dir}/request.json"
    printf '{"tables": [{"rows": [{"#": "9", "Total": "598.85"}]}]}\n' > "${dir}/snapshots/${event}/${event}_GER.json"
    printf '{"tables": []}\n' > "${dir}/snapshots/${event}/${event}_END.json"
    printf '{"premiacao": []}\n' > "${dir}/snapshots/${event}/premiacao.${event}01.json"
}

test_event_archive_manifest_and_restore() {
    local base="${TMP}/event"
    make_archive_input "${base}/input"
    export FAKE_DRIVE="${base}/drive" FAKE_RCLONE_LOG="${base}/rclone.log"
    mkdir -p "$FAKE_DRIVE"

    # Act: the real script, as backup-run.sh calls it.
    local out
    out="$(ARCHIVE_INPUT_DIR="${base}/input" BACKUP_REMOTE="fake:" BACKUP_WORK_DIR="${base}/work" \
           BACKUP_CODE_VERSION="v4-12-gabc1234" ARCHIVE_TIMESTAMP=20261021T070102Z \
           "${SCRIPTS}/archive-event.sh")"
    same "$(printf '%s\n' "$out" | tail -1)" "ARCHIVED events/22BR/20261021T070102Z" "last line"

    local folder="${FAKE_DRIVE}/events/22BR/20261021T070102Z" m
    m="${folder}/manifest.json"

    # The manifest records the event, the times, the code version and how
    # many snapshots were frozen.
    same "$(jq -r .event "$m")" 22BR "event"
    same "$(jq -r .request_id "$m")" 7 "request id"
    same "$(jq -r .requested_utc "$m")" 2026-10-20T21:15:00Z "requested_utc"
    same "$(jq -r .archived_utc "$m")" 2026-10-21T07:01:02Z "archived_utc"
    same "$(jq -r .code_version "$m")" v4-12-gabc1234 "code_version"
    same "$(jq -r .snapshot_count "$m")" 3 "snapshot_count"
    same "$(jq -r '.age_recipients | length' "$m")" 2 "recipients"
    # Team results are in the encrypted artifact, never in the manifest.
    if grep -q '598.85' "$m"; then fail "a result value is in the manifest"; fi

    # Manifest last.
    same "$(grep '^copyto' "$FAKE_RCLONE_LOG" | tail -1 | awk '{print $3}')" \
         "fake:events/22BR/20261021T070102Z/manifest.json" "last upload"

    # Either key restores the same snapshot files that went in.
    local key
    for key in escrow verifier; do
        mkdir -p "${base}/restored-${key}"
        age -d -i "${TMP}/keys/${key}.key" "${folder}/results.tar.gz.age" | tar -xzf - -C "${base}/restored-${key}"
        diff -r "${base}/input/snapshots" "${base}/restored-${key}/snapshots" || fail "snapshots differ (${key})"
    done

    # And the weekly verifier passes it.
    export AGE_IDENTITY_VERIFIER="${TMP}/keys/verifier.key" VERIFY_REMOTE="fake:"
    LOG_PREFIX='test'
    # shellcheck source=verify-db.sh
    source "${SCRIPTS}/verify-db.sh"
    WORK_DIR="${base}/verify"
    mkdir -p "$WORK_DIR"
    verify_event_archives >/dev/null 2>&1
    same "${EVENT_RESULTS[*]}" "events/22BR/20261021T070102Z: OK (3 snapshots, v4-12-gabc1234)" "verifier"
    (( ${#HARD_FAILURES[@]} == 0 )) || fail "hard failures: ${HARD_FAILURES[*]}"
}

test_event_archive_refuses_wrong_input() {
    # Three ways the input can be wrong, each of which must upload nothing.
    local base="${TMP}/event-bad"
    export FAKE_DRIVE="${base}/drive"
    mkdir -p "$FAKE_DRIVE"
    run_archive() {
        ARCHIVE_INPUT_DIR="$1" BACKUP_REMOTE="fake:" BACKUP_WORK_DIR="${base}/work" \
            "${SCRIPTS}/archive-event.sh" >/dev/null 2>&1
    }

    # 1. The snapshot folder holds another event too.
    make_archive_input "${base}/two" 22BR
    mkdir -p "${base}/two/snapshots/23BR"
    if run_archive "${base}/two"; then fail "archived a folder holding two events"; fi

    # 2. results-snapshot.php wrote nothing.
    make_archive_input "${base}/empty" 22BR
    rm "${base}/empty/snapshots/22BR/"*.json
    if run_archive "${base}/empty"; then fail "archived an event with no snapshot"; fi

    # 3. An event code that would make a strange folder name.
    make_archive_input "${base}/name" 22BR
    jq '.evento_id = "../x"' "${base}/name/request.json" > "${base}/r" && mv "${base}/r" "${base}/name/request.json"
    if run_archive "${base}/name"; then fail "accepted event code ../x"; fi

    same "$(find "$FAKE_DRIVE" -type f | wc -l)" 0 "files uploaded"
}

echo
echo "backup scripts"
echo
run_test "a file set restores to exactly the files that went in, with either key" test_file_set_round_trip
run_test "a forum folder that is not mounted stops the backup"                    test_required_folder_missing_stops_the_backup
run_test "the timer sounds set may be empty"                                      test_optional_file_set_may_be_empty
run_test "the verifier notices restored files that differ from the manifest"     test_verifier_notices_restored_files_that_differ
run_test "the manifest lists every artifact with its checksum, and no file names" test_manifest_lists_every_artifact
run_test "the manifest refuses anything that looks like personal data"           test_manifest_refuses_personal_data
run_test "the manifest is uploaded last, in daily/, monthly/ and yearly/"        test_manifest_is_uploaded_last_in_every_tier
run_test "the erasure log survives backup and restore with its chain intact"     test_erasure_log_round_trip
run_test "an event archive records event, time, code version and snapshot count, and restores" test_event_archive_manifest_and_restore
run_test "an event archive refuses input that is not one event's snapshots"      test_event_archive_refuses_wrong_input
echo
echo "${PASSED} passed, ${FAILED} failed"
(( FAILED == 0 ))
