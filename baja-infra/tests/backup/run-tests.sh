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
#   - the copy of the stack's .env is readable by the escrow key ALONE,
#     is uploaded again only when the .env changed, and the manifest still
#     names a valid copy when it did not;
#   - an event archive records the event, the time, the code version and
#     the number of snapshots, restores, and passes the verifier, for the
#     events of every competition (BR, AR, H2...), not only some;
#   - restoring a dump into a scratch database with scripts/restore-db.sh
#     leaves the live database alone, and the helper refuses the mistakes
#     that would not (see "Restoring a database" below);
#   - the weekly verifier fails a backup whose certificate table is
#     suspiciously small.
#
# Nothing here needs Docker or a Google account. It needs bash, GNU tar, jq
# and age (`sudo apt install age jq` on Ubuntu/Debian). The restore and
# floor tests also need a throwaway MySQL server and the mysql, mysqldump
# and perl commands; without one they are skipped (see "A real MySQL").
#
# HOW TO RUN, from the repository root:
#
#     baja-infra/tests/backup/run-tests.sh
#
# It prints "ok", "FAIL" or "skip" for each test and exits non-zero if any
# failed. Everything it creates is in a temporary folder that is removed at
# the end, and in databases on the throwaway server that it drops.

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

# ----- A real MySQL, for the restore and floor tests -----
#
# The restore tests guard against something MySQL itself does (it runs a
# statement that looks like a comment; see "Restoring a database"), so
# only a real MySQL can show the danger is gone. They run when
# TEST_MYSQL_HOST names a THROWAWAY server you may log in to as root, for
# example one made just for this:
#
#     docker run -d --rm --name baja-test-mysql -e MYSQL_ROOT_PASSWORD=root \
#         -p 127.0.0.1:33306:3306 mysql:8.4
#     TEST_MYSQL_HOST=127.0.0.1 TEST_MYSQL_PORT=33306 TEST_MYSQL_ROOT_PASSWORD=root \
#         baja-infra/tests/backup/run-tests.sh
#
# Without TEST_MYSQL_HOST they are listed as "skip". CI also sets
# TEST_MYSQL_REQUIRED=1, which turns a skip into a failure: a test that
# quietly did not run is a check nobody made.
#
# These tests create and drop a database called baja_resultados. So that it
# can never be a real one, they refuse to start if the server already holds
# a database of that name (or phpbb_baja, phpbb_formula, or the scratch
# name below) that they did not make. Theirs always carry a table named
# fixture_made_by_run_tests.
TEST_CNF=""
SCRATCH_DB=baja_restore_check
if [[ -n "${TEST_MYSQL_HOST:-}" ]]; then
    for tool in mysql mysqldump perl; do
        if ! command -v "$tool" >/dev/null 2>&1; then
            echo "missing tool: ${tool} (on Ubuntu/Debian: sudo apt install mysql-client perl)" >&2
            exit 2
        fi
    done
    TEST_CNF="${TMP}/root.cnf"
    # The same option file the backup writes, so no password is on a
    # command line here either.
    ( LOG_PREFIX='test'
      # shellcheck source=backup-lib.sh
      source "${SCRIPTS}/backup-lib.sh"
      write_defaults_file "$TEST_CNF" "$TEST_MYSQL_HOST" "${TEST_MYSQL_PORT:-3306}" root "${TEST_MYSQL_ROOT_PASSWORD:-}" )
    if ! mysql --defaults-file="$TEST_CNF" -BN -e "SELECT 1" >/dev/null 2>&1; then
        echo "cannot log in to MySQL at ${TEST_MYSQL_HOST}:${TEST_MYSQL_PORT:-3306} as root (TEST_MYSQL_ROOT_PASSWORD)" >&2
        exit 2
    fi
    not_ours="$(mysql --defaults-file="$TEST_CNF" -BN -e "
        SELECT s.SCHEMA_NAME FROM information_schema.SCHEMATA s
        WHERE s.SCHEMA_NAME IN ('baja_resultados', 'phpbb_baja', 'phpbb_formula', '${SCRATCH_DB}')
          AND NOT EXISTS (SELECT 1 FROM information_schema.TABLES t
                          WHERE t.TABLE_SCHEMA = s.SCHEMA_NAME AND t.TABLE_NAME = 'fixture_made_by_run_tests')")"
    if [[ -n "$not_ours" ]]; then
        echo "MySQL at ${TEST_MYSQL_HOST} already holds: $(tr '\n' ' ' <<< "$not_ours")— these tests drop databases of those names; point TEST_MYSQL_HOST at a throwaway server" >&2
        exit 2
    fi
fi

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
# The same for a test that needs the MySQL above: skipped without one,
# failed without one when TEST_MYSQL_REQUIRED=1.
SKIPPED=0
run_mysql_test() {
    if [[ -n "$TEST_CNF" ]]; then
        run_test "$@"
    elif [[ "${TEST_MYSQL_REQUIRED:-0}" == 1 ]]; then
        echo "  FAIL $1"
        echo "         TEST_MYSQL_REQUIRED=1 but TEST_MYSQL_HOST is not set"
        FAILED=$(( FAILED + 1 ))
    else
        echo "  skip $1 (no TEST_MYSQL_HOST)"
        SKIPPED=$(( SKIPPED + 1 ))
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

    # What backup_env_stage records about the .env copy (here: unchanged,
    # pointing at an earlier copy; the .env tests below make real ones).
    jq -n '{file: "env.age", folder: "secrets/20251201T070000Z", recipients: "escrow only",
        plaintext_sha256: "aa", sha256: "bb", uploaded_this_run: false}' > "${run}/env.manifest.json"

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

    same "$(jq -r .schema_version "$m")" 4 "schema_version"
    same "$(jq -r .env.folder "$m")" secrets/20251201T070000Z "the .env copy it names"
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
# The stack's .env, for recovering every secret from nothing
# ======================================================================

test_env_file_is_read_as_compose_reads_it() {
    # backup-run.sh and verify-run.sh read the stack's .env. It is written
    # for compose: sourced by bash, `SMTP_FROM_NAME=SAE BRASIL - ...` runs
    # a command and the nightly backup dies before it starts.
    local base="${TMP}/env-read"
    mkdir -p "$base"
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"

    # The whole of .env.example loads, with compose's values.
    ( load_env_file "${HERE}/../../.env.example"
      same "$SMTP_FROM_NAME" "SAE BRASIL - Certificados" "SMTP_FROM_NAME"
      same "$COMPOSE_PROFILES" "dev" "COMPOSE_PROFILES (\${ENV})"
      same "$ERASURE_LOG_DIR" "./backup-local/erasure" "ERASURE_LOG_DIR"
      same "$(env | grep -c '^BAJA_DOMAIN=baja.local$')" 1 "BAJA_DOMAIN exported"
    ) || fail ".env.example"

    {
        printf '%s\n' '# a comment' '' \
            'SPACES=a b  c' \
            'TRAILING=value # a comment' \
            'HASH=a#b' \
            "SINGLE='\${SPACES} # kept'" \
            'DOUBLE="${SPACES} # kept"' \
            'DEFAULTED=${UNSET_IN_TEST:-fallback}' \
            'export EXPORTED=yes' \
            'RUN=$(touch '"${base}"'/ran)' \
            'LATER=first' 'LATER=second'
        printf 'CRLF=dos\r\n'
    } > "${base}/.env"
    load_env_file "${base}/.env"
    same "$SPACES" "a b  c" "SPACES"
    same "$TRAILING" "value" "TRAILING"
    same "$HASH" "a#b" "HASH"
    same "$SINGLE" '${SPACES} # kept' "SINGLE"
    same "$DOUBLE" "a b  c # kept" "DOUBLE"
    same "$DEFAULTED" "fallback" "DEFAULTED"
    same "$EXPORTED" "yes" "EXPORTED"
    same "$RUN" "\$(touch ${base}/ran)" "RUN"
    [[ ! -e "${base}/ran" ]] || fail "a value was run as a command"
    same "$LATER" "second" "LATER"
    same "$CRLF" "dos" "CRLF"

    printf 'not an assignment\n' > "${base}/bad.env"
    if ( load_env_file "${base}/bad.env" ) 2>/dev/null; then fail "accepted a line that is not KEY=value"; fi
}

# A made-up .env. Real ones hold the production passwords; this one holds
# obviously fake values.
make_env_file() {
    printf 'ENV=prod\nMYSQL_ROOT_PASSWORD=%s\nSTORAGE_ENCRYPTION_KEY=%s\n' "$1" "fake-key-for-tests" > "$2"
}

test_env_copy_is_for_the_escrow_key_only() {
    # The .env must be readable by the escrow key alone. The verifier key
    # lives on the server, and the server must never be able to read this.
    local base="${TMP}/env-escrow"
    mkdir -p "${base}/run" "${base}/drive"
    export FAKE_DRIVE="${base}/drive" BACKUP_REMOTE="fake:"
    make_env_file "not-a-real-password" "${base}/.env"
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"

    backup_env_stage "${base}/.env" "${base}/run" 20261025T070000Z

    # It is encrypted to exactly ONE recipient, read from the file's header
    # (no key needed to see how many there are)...
    same "$(backup_age_x25519_recipients "${base}/run/env.age")" 1 "number of recipients"
    # ...and that recipient is the escrow key: it decrypts to the .env...
    age -d -i "${TMP}/keys/escrow.key" "${base}/run/env.age" > "${base}/decrypted"
    diff "${base}/.env" "${base}/decrypted" || fail "the escrow key does not give back the .env"
    # ...while the verifier key cannot open it.
    if age -d -i "${TMP}/keys/verifier.key" "${base}/run/env.age" >/dev/null 2>&1; then
        fail "the verifier key can decrypt the .env copy"
    fi
    # For comparison, every other artifact has two recipients.
    backup_encrypt "${base}/.env" "${base}/other.age" >/dev/null
    same "$(backup_age_x25519_recipients "${base}/other.age")" 2 "recipients of an ordinary artifact"

    # Nothing in the clear-text descriptors holds a secret.
    if grep -q 'not-a-real-password' "${base}/run/env.json" "${base}/run/env.manifest.json"; then
        fail "a secret from the .env is in a clear-text file"
    fi
}

test_unchanged_env_is_not_uploaded_again() {
    # Night 1 uploads a copy. Night 2, same .env: nothing new is uploaded,
    # but the manifest still names night 1's copy, and the verifier's
    # check of it passes. Night 3, changed .env: a new copy.
    local base="${TMP}/env-unchanged"
    mkdir -p "${base}/n1" "${base}/n2" "${base}/n3" "${base}/drive"
    export FAKE_DRIVE="${base}/drive" FAKE_RCLONE_LOG="${base}/rclone.log" BACKUP_REMOTE="fake:"
    make_env_file "first-password" "${base}/.env"
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"

    # Night 1.
    backup_env_stage "${base}/.env" "${base}/n1" 20261024T070000Z
    backup_env_upload "${base}/n1"
    same "$(jq -r .uploaded_this_run "${base}/n1/env.manifest.json")" true "night 1 uploads"
    same "$(grep '^copyto' "${base}/rclone.log" | tail -1 | awk '{print $3}')" \
         "fake:secrets/20261024T070000Z/env.json" "env.json is the last upload of a copy"

    # A copy that died before its env.json is not "the last uploaded copy".
    mkdir -p "${FAKE_DRIVE}/secrets/20261024T080000Z"
    : > "${FAKE_DRIVE}/secrets/20261024T080000Z/env.age"

    # Night 2: the same .env.
    backup_env_stage "${base}/.env" "${base}/n2" 20261025T070000Z
    backup_env_upload "${base}/n2"
    same "$(jq -r .uploaded_this_run "${base}/n2/env.manifest.json")" false "night 2 uploads"
    same "$(jq -r .folder "${base}/n2/env.manifest.json")" secrets/20261024T070000Z "the copy night 2 names"
    same "$(jq -r .sha256 "${base}/n2/env.manifest.json")" \
         "$(jq -r .sha256 "${base}/n1/env.manifest.json")" "the checksum night 2 lists"
    [[ ! -e "${FAKE_DRIVE}/secrets/20261025T070000Z" ]] || fail "night 2 uploaded a copy of an unchanged .env"

    # The weekly verifier checks the copy night 2's manifest names: on the
    # Drive, intact, one recipient. It never decrypts it.
    jq -n --slurpfile e "${base}/n2/env.manifest.json" '{env: $e[0]}' > "${base}/n2/manifest.json"
    export AGE_IDENTITY_VERIFIER="${TMP}/keys/verifier.key" VERIFY_REMOTE="fake:"
    # shellcheck source=verify-db.sh
    source "${SCRIPTS}/verify-db.sh"
    WORK_DIR="${base}/n2"
    verify_env_copy
    same "${OTHER_RESULT[env]}" "OK (secrets/20261024T070000Z, escrow only, not decrypted)" "verifier"

    # Night 3: the .env changed (a password was rotated).
    make_env_file "rotated-password" "${base}/.env"
    backup_env_stage "${base}/.env" "${base}/n3" 20261026T070000Z
    same "$(jq -r .uploaded_this_run "${base}/n3/env.manifest.json")" true "night 3 uploads"
    same "$(jq -r .folder "${base}/n3/env.manifest.json")" secrets/20261026T070000Z "the copy night 3 names"
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

    # 3. An event code that would make a strange folder name, or is not
    #    one at all.
    local bad n=0
    for bad in "../x" "26BR/../x" "26br" "226BR" ""; do
        n=$(( n + 1 ))
        make_archive_input "${base}/name${n}" 22BR
        jq --arg e "$bad" '.evento_id = $e' "${base}/name${n}/request.json" > "${base}/r" && mv "${base}/r" "${base}/name${n}/request.json"
        if run_archive "${base}/name${n}"; then fail "accepted event code '${bad}'"; fi
    done

    same "$(find "$FAKE_DRIVE" -type f | wc -l)" 0 "files uploaded"
}

# An event code is two digits for the year and two capitals or digits for
# the competition: 26BR, 25AR, 24H2. The scripts once accepted only BR,
# SE, SU and NE after the year, so the events of every other competition
# could not be archived, and the backup alerted every night (BAJAAPP-74).
# Now every script asks one function in backup-lib.sh, with the same rule
# as the PHP side (Baja\Evento\EventCode): one rule in one place, so they
# cannot disagree.
test_event_code_rule() {
    LOG_PREFIX='test'
    # shellcheck source=backup-lib.sh
    source "${SCRIPTS}/backup-lib.sh"
    local code
    # Every competition seen on production, and one nobody has made yet.
    for code in 22BR 23SE 24SU 25NE 25AR 24FA 23FO 26EQ 24H2 27ZZ; do
        backup_is_event_code "$code" || fail "refused ${code}"
    done
    # Not codes: lowercase (also in a locale where [A-Z] could match it),
    # three digits, empty, extra characters, and paths.
    for code in 26br 26Br 226BR 6BR 26B 26BRX "" " 26BR" "26BR " $'26BR\n' "26BR/../x" "../x" ".." "26ÁR"; do
        if backup_is_event_code "$code"; then fail "accepted '${code}'"; fi
        if LC_ALL=en_US.UTF-8 backup_is_event_code "$code" 2>/dev/null; then fail "accepted '${code}' in en_US.UTF-8"; fi
    done
}

# The whole path for two events of other competitions, H2 and AR: each is
# archived under its own events/<EVT>/ folder, and the weekly verifier
# restores both. A folder whose name is not an event code is skipped with
# a note, not restored.
test_event_archive_of_other_competitions() {
    local base="${TMP}/event-other"
    export FAKE_DRIVE="${base}/drive" FAKE_RCLONE_LOG="${base}/rclone.log"
    mkdir -p "$FAKE_DRIVE"

    local event out
    for event in 24H2 25AR; do
        make_archive_input "${base}/input-${event}" "$event"
        out="$(ARCHIVE_INPUT_DIR="${base}/input-${event}" BACKUP_REMOTE="fake:" BACKUP_WORK_DIR="${base}/work" \
               BACKUP_CODE_VERSION="v4-12-gabc1234" ARCHIVE_TIMESTAMP=20261021T070102Z \
               "${SCRIPTS}/archive-event.sh")"
        same "$(printf '%s\n' "$out" | tail -1)" "ARCHIVED events/${event}/20261021T070102Z" "${event}: last line"
        same "$(jq -r .event "${FAKE_DRIVE}/events/${event}/20261021T070102Z/manifest.json")" "$event" "${event}: manifest event"
    done
    mkdir -p "${FAKE_DRIVE}/events/not-a-code/20261021T070102Z"

    export AGE_IDENTITY_VERIFIER="${TMP}/keys/verifier.key" VERIFY_REMOTE="fake:"
    LOG_PREFIX='test'
    # shellcheck source=verify-db.sh
    source "${SCRIPTS}/verify-db.sh"
    WORK_DIR="${base}/verify"
    mkdir -p "$WORK_DIR"
    verify_event_archives >/dev/null 2>&1
    same "${EVENT_RESULTS[*]}" \
         "events/24H2/20261021T070102Z: OK (3 snapshots, v4-12-gabc1234) events/25AR/20261021T070102Z: OK (3 snapshots, v4-12-gabc1234)" \
         "verifier"
    (( ${#HARD_FAILURES[@]} == 0 )) || fail "hard failures: ${HARD_FAILURES[*]}"
    same "${SOFT_NOTES[*]}" "events/not-a-code: not an event code, skipped" "soft notes"
}

# ======================================================================
# Restoring a database (BAJAAPP-4)
# ======================================================================
#
# SOME BACKGROUND, FOR SOMEONE WHO HAS NEVER LOOKED INSIDE A DUMP
#
# A "dump" is a plain text file of SQL statements, written by the
# mysqldump program, that rebuilds a database when you feed it back to
# MySQL: create the database, create each table, insert the rows. The
# nightly backup's dumps start like this:
#
#     /*!40000 DROP DATABASE IF EXISTS `baja_resultados`*/;
#     CREATE DATABASE /*!32312 IF NOT EXISTS*/ `baja_resultados` ...;
#     USE `baja_resultados`;
#
# In SQL, text between /* and */ is normally a comment, which the database
# ignores. But when it starts with an exclamation mark and a number, like
# /*!40000 ... */, it is a MySQL "conditional comment" (also called a
# version comment): other database programs skip it as a comment, while
# MySQL, if its version is at least the number (40000 means 4.0.0), RUNS
# the statement inside. Every MySQL in use today is far newer than that,
# so for us the first line simply means
#
#     DROP DATABASE IF EXISTS `baja_resultados`;
#
# that is, "delete the whole baja_resultados database". That is on
# purpose: restoring a backup should REPLACE the database, never mix the
# backup's rows into whatever is there.
#
# The trap: to look at a backup BESIDE the live database, someone renamed
# the database in the CREATE DATABASE and USE lines and read the first
# line as a comment. MySQL deleted the live baja_resultados, then created
# the copy under the new name and filled it. That happened on staging.
# scripts/restore-db.sh renames the database in all three statements,
# conditional comments included, and refuses any dump it cannot rename
# safely. These tests prove it on a real MySQL.
#
# They never touch a real baja_resultados: they build a small fake one on
# the throwaway server (see "A real MySQL" at the top), back it up with the
# backup's own code, and restore that.

as_root() { mysql --defaults-file="$TEST_CNF" -BN "$@"; }
count_rows() { as_root -e "SELECT COUNT(*) FROM \`$1\`.participantes"; }
db_exists() { [[ "$(as_root -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$1'")" == 1 ]]; }
drop_test_dbs() { as_root -e "DROP DATABASE IF EXISTS baja_resultados; DROP DATABASE IF EXISTS ${SCRATCH_DB}"; }

# The helper, as a person would run it, but connecting to the throwaway
# server directly instead of through the stack's mysql container.
restore() { RESTORE_DEFAULTS_FILE="$TEST_CNF" "${SCRIPTS}/restore-db.sh" "$@"; }

# make_fake_resultados N: a tiny stand-in for baja_resultados, made from
# nothing (any earlier one and any scratch copy are dropped first), with N
# rows in participantes (one per certificate in the real one), one event in
# evento (the backup reads accented text from it, see the charset canary
# in backup-lib.sh), and enough extra empty tables to reach the 13 that
# the verifier expects a real baja_resultados to have.
make_fake_resultados() {
    local n="$1" i extra=""
    for i in 01 02 03 04 05 06 07 08 09 10; do
        extra+="CREATE TABLE extra_${i} (i INT PRIMARY KEY); "
    done
    drop_test_dbs
    as_root <<SQL
CREATE DATABASE baja_resultados CHARACTER SET utf8mb4;
USE baja_resultados;
CREATE TABLE fixture_made_by_run_tests (i INT PRIMARY KEY);
CREATE TABLE evento (evento_id VARCHAR(8) PRIMARY KEY, titulo VARCHAR(100), nome VARCHAR(100),
                     \`local\` VARCHAR(100), presidente VARCHAR(100));
INSERT INTO evento VALUES ('26BR', 'Competição Baja SAE BRASIL', 'Baja SAE BRASIL', 'São José dos Campos', 'Fulana');
CREATE TABLE participantes (token VARCHAR(32) PRIMARY KEY, nome VARCHAR(100), evento VARCHAR(8));
INSERT INTO participantes (token, nome, evento)
    WITH RECURSIVE k (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM k WHERE n < ${n})
    SELECT CONCAT('T', LPAD(n, 8, '0')), CONCAT('Participante ', n), '26BR' FROM k;
${extra}
SQL
}

# dump_fake DIR: backs the fake up exactly as the nightly job backs up the
# real one (dump_one from backup-db.sh, the same mysqldump options) into
# DIR/baja_resultados.sql, with the row counts the backup records for the
# manifest in DIR/dbmeta.baja_resultados.json.
dump_fake() {
    local dir="$1"
    mkdir -p "$dir"
    ( LOG_PREFIX='test'
      # shellcheck source=backup-db.sh
      source "${SCRIPTS}/backup-db.sh"
      # backup-db.sh deletes its work folder when it exits. Here that
      # folder holds the dump the test needs, so keep it.
      trap - EXIT
      RUN_DIR="$dir"
      MYSQL_DEFAULTS_FILE="$TEST_CNF"
      dump_one baja_resultados >/dev/null )
}

# A certificate issued AFTER the backup was taken. The backup does not
# have it, so if a restore touched the live database in any way (dropped
# it, or replaced it with the backup), this row is gone. Its presence is
# the proof the live database was left alone.
add_row_after_the_backup() {
    as_root -e "INSERT INTO baja_resultados.participantes VALUES ('AFTER-THE-BACKUP', 'Issued today', '26BR')"
}

test_restore_into_scratch_leaves_the_original_alone() {
    # Arrange: a "live" database with 5 certificates, last night's backup
    # of it, then one more certificate today (6 live, 5 in the backup).
    local dir="${TMP}/restore-scratch" out
    make_fake_resultados 5
    dump_fake "${dir}/run"
    add_row_after_the_backup
    # The manifest the backup uploads beside the dump. restore-db.sh only
    # reads its row counts, so this one holds just those.
    jq -n --slurpfile m "${dir}/run/dbmeta.baja_resultados.json" \
        '{artifacts: {baja_resultados: $m[0]}}' > "${dir}/manifest.json"

    # Act: restore the backup BESIDE the live database, under another name.
    out="$(restore "${dir}/run/baja_resultados.sql" --into "$SCRATCH_DB" --manifest "${dir}/manifest.json" 2>&1)" \
        || fail "restore-db.sh failed: ${out}"

    # Assert: the live database is untouched. It still exists, with all 6
    # rows, today's included...
    db_exists baja_resultados || fail "the live database is GONE"
    same "$(count_rows baja_resultados)" 6 "rows in the live database"
    same "$(as_root -e "SELECT COUNT(*) FROM baja_resultados.participantes WHERE token = 'AFTER-THE-BACKUP'")" 1 \
        "today's row in the live database"
    # ...while the copy holds what the backup holds: 5 rows, not today's.
    same "$(count_rows "$SCRATCH_DB")" 5 "rows in the scratch copy"
    # And it printed each table's restored row count beside the manifest's
    # ("participantes   5   5"), so a person can see the restore is whole.
    grep -Eq '^ +participantes +5 +5 *$' <<< "$out" || fail "no 'participantes 5 5' line in: ${out}"
    drop_test_dbs
}

test_restore_refuses_a_live_name_without_replace() {
    # Restoring INTO baja_resultados replaces the live database with the
    # backup, and everything written since the backup is lost. Sometimes
    # that is exactly the job (the live one is broken), so it is allowed,
    # but only when you say so with --replace. Typing the live name by
    # habit must stop before anything is sent to MySQL.
    local dir="${TMP}/restore-live" out
    make_fake_resultados 5
    dump_fake "${dir}/run"
    add_row_after_the_backup

    if out="$(restore "${dir}/run/baja_resultados.sql" --into baja_resultados 2>&1)"; then
        fail "restored over the live database without --replace"
    fi
    # The refusal tells the person how to mean it.
    grep -q -- '--replace' <<< "$out" || fail "the refusal does not mention --replace: ${out}"
    # Today's row is still there: nothing happened.
    same "$(count_rows baja_resultados)" 6 "rows in the live database"

    # For contrast: with --replace it is a real restore. The live database
    # becomes the backup, 5 rows, and today's row is gone, as intended.
    restore "${dir}/run/baja_resultados.sql" --into baja_resultados --replace >/dev/null 2>&1 \
        || fail "--replace failed"
    same "$(count_rows baja_resultados)" 5 "rows after --replace"
    drop_test_dbs
}

test_restore_refuses_a_truncated_dump() {
    # A dump cut short (a full disk, a download that stopped halfway) is
    # still valid SQL up to the point where it stops, so MySQL imports that
    # first part without a single error, and you would think you had the
    # whole database. mysqldump writes "-- Dump completed on <date>" as
    # its very last line. No last line, no import.
    local dir="${TMP}/restore-truncated" dump half
    make_fake_resultados 5
    dump_fake "${dir}/run"
    dump="${dir}/run/baja_resultados.sql"
    half="${dir}/half.sql"
    head -c "$(( $(stat -c %s "$dump") / 2 ))" "$dump" > "$half"

    if restore "$half" --into "$SCRATCH_DB" >/dev/null 2>&1; then
        fail "imported a truncated dump"
    fi
    # Nothing was imported at all, and the live database is as it was.
    if db_exists "$SCRATCH_DB"; then fail "part of the truncated dump was imported"; fi
    same "$(count_rows baja_resultados)" 5 "rows in the live database"
    drop_test_dbs
}

test_restore_rewrites_the_conditional_drop() {
    local dir="${TMP}/restore-conditional" dump copy="${TMP}/restore-conditional/rewritten.sql"
    make_fake_resultados 5
    dump_fake "${dir}/run"
    dump="${dir}/run/baja_resultados.sql"

    # 1. The backup's dump really does start with the conditional DROP
    #    described above.
    grep -qxF '/*!40000 DROP DATABASE IF EXISTS `baja_resultados`*/;' "$dump" \
        || fail "the dump has no /*!40000 DROP DATABASE line; the test no longer matches the backup"

    # 2. What went wrong on staging, replayed on the fake: rename the
    #    database in the CREATE DATABASE and USE lines only, and import.
    #    MySQL runs the "comment", and the live database is gone. This is
    #    the danger the helper exists for; if MySQL ever stopped running
    #    /*!40000 ... */, this would say so.
    sed -e '/^CREATE DATABASE /s/`baja_resultados`/`'"${SCRATCH_DB}"'`/' \
        -e '/^USE /s/`baja_resultados`/`'"${SCRATCH_DB}"'`/' "$dump" > "${dir}/renamed-by-hand.sql"
    as_root < "${dir}/renamed-by-hand.sql"
    if db_exists baja_resultados; then
        fail "the hand-renamed dump left baja_resultados alone: does MySQL no longer run /*!40000 ... */?"
    fi

    # 3. The helper's rewritten copy: the DROP inside the conditional
    #    comment now names the scratch database, as CREATE DATABASE and
    #    USE do, and no line that MySQL would run names baja_resultados any
    #    more. (Lines starting with "--" are ordinary comments, never run;
    #    mysqldump writes the database name in a few of them.)
    ( LOG_PREFIX='test'
      # shellcheck source=restore-db.sh
      source "${SCRIPTS}/restore-db.sh"
      restore_rewrite "$dump" "$copy" "$SCRATCH_DB" >/dev/null )
    grep -qxF "/*!40000 DROP DATABASE IF EXISTS \`${SCRATCH_DB}\`*/;" "$copy" \
        || fail "the conditional DROP was not rewritten"
    grep -qE "^CREATE DATABASE /\*!32312 IF NOT EXISTS\*/ \`${SCRATCH_DB}\` " "$copy" \
        || fail "CREATE DATABASE was not rewritten"
    grep -qxF "USE \`${SCRATCH_DB}\`;" "$copy" || fail "USE was not rewritten"
    if grep -v '^--' "$copy" | grep -q 'baja_resultados'; then
        fail "a statement in the rewritten dump still names baja_resultados"
    fi
    drop_test_dbs
}

test_restore_refuses_another_statement_naming_the_original() {
    # Today those three are the only statements in a dump that name the
    # database. If a future dump had another (a view that reads
    # `baja_resultados`.`participantes`, an ALTER DATABASE...), renaming
    # the three would leave that one aimed at the live database. The
    # helper refuses rather than guess, and says on which line.
    local dir="${TMP}/restore-other" out
    make_fake_resultados 5
    dump_fake "${dir}/run"
    # One such statement, added right after the USE line.
    awk '{ print } /^USE `baja_resultados`;$/ && !done { print "ALTER DATABASE `baja_resultados` CHARACTER SET utf8mb4;"; done = 1 }' \
        "${dir}/run/baja_resultados.sql" > "${dir}/extra.sql"

    if out="$(restore "${dir}/extra.sql" --into "$SCRATCH_DB" 2>&1)"; then
        fail "imported a dump with an ALTER DATABASE naming the original"
    fi
    grep -Eq 'line [0-9]+: names the original database' <<< "$out" || fail "the refusal does not name the line: ${out}"
    if db_exists "$SCRATCH_DB"; then fail "something was imported"; fi
    same "$(count_rows baja_resultados)" 5 "rows in the live database"
    drop_test_dbs
}

# ======================================================================
# The verifier's floor on participantes
# ======================================================================
#
# Every week the verifier restores the newest backup and compares it with
# the manifest the backup wrote beside it. That catches damage on the way
# (a flipped byte in an upload), but not a backup that was wrong from the
# start: if the backup's MySQL user lost permission to read a table, the
# backup would count the rows it could see, write that count in the
# manifest, and backup and manifest would agree perfectly.
#
# So the verifier also has "floors": minimums known from outside, which no
# manifest can talk it out of. One is on participantes, one row per
# certificate. Production has about 31,000 and the number only grows, so
# VERIFY_MIN_ROWS_PARTICIPANTES defaults to 20000. (It used to be a floor
# on `user`, which holds 3 staff accounts in production: that default
# failed every real run.) A restored backup below the floor is not the
# database we think it is.
#
# Here the floor is set to 3, and the fake database is backed up with 2
# rows, and then with 4.

# floor_failures N: backs up a fake with N rows, imports the backup as the
# verifier does, runs the verifier's floor check, and prints the hard
# failures it recorded (nothing, if it passed).
floor_failures() {
    local n="$1" dir="${TMP}/floor-${1}"
    make_fake_resultados "$n"
    dump_fake "${dir}/run"
    # The verifier imports into an empty scratch server. Here: drop the
    # fake, and import its backup in its place.
    as_root -e "DROP DATABASE baja_resultados"
    as_root < "${dir}/run/baja_resultados.sql"

    export VERIFY_MIN_ROWS_PARTICIPANTES=3
    export AGE_IDENTITY_VERIFIER="${TMP}/keys/verifier.key"
    LOG_PREFIX='test'
    # shellcheck source=verify-db.sh
    source "${SCRIPTS}/verify-db.sh"
    MYSQL_DEFAULTS_FILE="$TEST_CNF"
    check_floors baja_resultados 2>/dev/null
    drop_test_dbs
    printf '%s\n' "${HARD_FAILURES[@]}"
}

test_floor_fails_a_backup_below_it() {
    local failures
    failures="$(floor_failures 2)"
    # Exactly one failure, and it is this one. (The fake has the 13
    # tables the other floor wants, so that one passes.)
    same "$(grep -c . <<< "$failures")" 1 "number of hard failures"
    [[ "$failures" == "baja_resultados.participantes has 2 rows, floor is 3 "* ]] \
        || fail "unexpected failure: ${failures}"
}

test_floor_passes_a_backup_above_it() {
    same "$(floor_failures 4)" "" "hard failures"
    # Without the variable, the floor is the production one.
    same "$( unset VERIFY_MIN_ROWS_PARTICIPANTES
             LOG_PREFIX='test'
             # shellcheck source=verify-db.sh
             source "${SCRIPTS}/verify-db.sh"
             echo "$VERIFY_MIN_ROWS_PARTICIPANTES" )" 20000 "default floor"
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
run_test "the stack's .env is read as compose reads it, and never run"         test_env_file_is_read_as_compose_reads_it
run_test "the .env copy is encrypted to exactly one recipient, the escrow key" test_env_copy_is_for_the_escrow_key_only
run_test "an unchanged .env is not uploaded again, and its copy still checks"  test_unchanged_env_is_not_uploaded_again
run_test "an event archive records event, time, code version and snapshot count, and restores" test_event_archive_manifest_and_restore
run_test "an event archive refuses input that is not one event's snapshots"      test_event_archive_refuses_wrong_input
run_test "an event code is two digits and two capitals or digits, any competition" test_event_code_rule
run_test "events of other competitions (H2, AR) are archived and verified"        test_event_archive_of_other_competitions
run_mysql_test "restoring into a scratch name leaves the live database and its rows alone" test_restore_into_scratch_leaves_the_original_alone
run_mysql_test "restoring into a live database's name refuses without --replace" test_restore_refuses_a_live_name_without_replace
run_mysql_test "restoring a truncated dump refuses, and imports nothing"          test_restore_refuses_a_truncated_dump
run_mysql_test "the /*!40000 DROP DATABASE in a dump is rewritten (and is not a comment)" test_restore_rewrites_the_conditional_drop
run_mysql_test "restoring refuses a dump that names the original anywhere else"    test_restore_refuses_another_statement_naming_the_original
run_mysql_test "the verifier fails a backup with fewer participantes than the floor" test_floor_fails_a_backup_below_it
run_mysql_test "the verifier passes a backup with more participantes than the floor" test_floor_passes_a_backup_above_it
if [[ -n "$TEST_CNF" ]]; then
    drop_test_dbs
fi
echo
echo "${PASSED} passed, ${FAILED} failed, ${SKIPPED} skipped"
(( FAILED == 0 ))
