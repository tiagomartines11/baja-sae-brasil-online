#!/usr/bin/env bash
# End-to-end test of the backups on a running stack: dev, or staging.
#
# WHAT IT DOES, IN PLAIN WORDS
#
# run-tests.sh (next to this file) checks the backup scripts on made-up
# files. This one runs the real thing, the way cron will, against the
# databases and forum volumes of the stack you have running — but with
# the "Shared Drive" replaced by a folder on this machine and with
# throwaway encryption keys, so it needs no Google account and touches no
# real backup:
#
#   1. makes two age key pairs (inside the backup image, so this machine
#      needs no age of its own);
#   2. asks for an archive of event 22BR, as ticking "Finalizado?" in
#      juiz/evento.php would (one pending row in evento_arquivamento);
#   3. runs scripts/backup-run.sh: the nightly backup of the three
#      databases, the forum files, the timer sounds and the erasure log,
#      then the pending event archive;
#   4. checks that the night's folder is complete (manifest last, every
#      artifact), that the event archive is there with its manifest, and
#      that the request was consumed (marked arquivado);
#   5. runs scripts/verify-run.sh, the weekly verification: it restores
#      every database into a scratch MySQL, extracts every file set and
#      the event archive, and compares all of it with the manifests;
#   6. removes everything it created.
#
# It refuses to run against production (BAJA_DOMAIN=bajasaebrasil.net).
#
# BEFORE THE FIRST RUN on an existing dev database (a fresh one has both):
#   - the read-only backup MySQL user: created by mysql/init/02 on first
#     boot only; see "MySQL backup user" in docs/backup-restore.md;
#   - the evento_arquivamento table: `./propel migration:migrate` in the
#     baja-app container (docs/dev-workflow.md shows the full command).
#
# HOW TO RUN, from baja-infra/, with the stack up:
#
#     tests/backup/stack-test.sh
#
# It takes a few minutes (the verify step starts its own MySQL). Exit code
# 0 means every step passed.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA="$(cd "${HERE}/../.." && pwd)"
EVENT=22BR

say()  { printf '\n[stack-test] %s\n' "$*"; }
fail() { printf '\n[stack-test] FAILED: %s\n' "$*" >&2; exit 1; }

[[ -f "${INFRA}/.env" ]] || fail "no ${INFRA}/.env — copy .env.example first"
LOG_PREFIX="stack-test"
# shellcheck source-path=SCRIPTDIR/../../scripts
# shellcheck source=backup-lib.sh
source "${INFRA}/scripts/backup-lib.sh"
load_env_file "${INFRA}/.env"
[[ "${BAJA_DOMAIN:-}" != "bajasaebrasil.net" ]] || fail "this is the production .env; run this on dev or staging only"

COMPOSE=(docker compose --project-directory "$INFRA" -f "${INFRA}/docker-compose.yml")
if [[ "${ENV:-dev}" != "dev" ]]; then
    COMPOSE+=(-f "${INFRA}/docker-compose.prod.yml")
fi
COMPOSE+=(--profile tools)

# The password is expanded inside the mysql container, from its own
# environment, so it never appears on this host's command line.
sql() {
    # shellcheck disable=SC2016
    "${COMPOSE[@]}" exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -BN baja_resultados' <<< "$1" 2>/dev/null
}

# ----- Playground -----
# Under baja-infra/backup-local/ (gitignored), because the containers
# write into it as root and docker needs a path it may bind.
WORK="${INFRA}/backup-local/stack-test.$$"
mkdir -p "${WORK}/drive" "${WORK}/erasure" "${WORK}/verify-state" "${WORK}/host-work"
REQUEST_ID=""
# Every `compose run backup` below binds these; point them at the
# playground from the start, so not even the key generation touches the
# real paths in .env.
: > "${WORK}/no-google.json"
export GDRIVE_SA_WRITER_JSON="${WORK}/no-google.json" ERASURE_LOG_DIR="${WORK}/erasure" \
       BACKUP_LOCAL_DRIVE="${WORK}/drive"

cleanup() {
    if [[ -n "$REQUEST_ID" ]]; then
        sql "DELETE FROM evento_arquivamento WHERE arquivamento_id = ${REQUEST_ID};" || true
    fi
    # Files the containers wrote are root's; remove them from a container.
    "${COMPOSE[@]}" run --rm -T --no-deps -v "${WORK}:/work" --entrypoint rm backup -rf /work/drive /work/erasure /work/verify-state >/dev/null 2>&1 || true
    rm -rf -- "$WORK" 2>/dev/null || true
}
trap cleanup EXIT

say "building the backup image"
"${COMPOSE[@]}" build backup >/dev/null

say "making throwaway age keys"
for name in escrow verifier; do
    "${COMPOSE[@]}" run --rm -T --no-deps --entrypoint age-keygen backup 2>/dev/null > "${WORK}/${name}.key"
    grep -q AGE-SECRET-KEY "${WORK}/${name}.key" || fail "could not make the ${name} key"
done
recipient() { grep '^# public key: ' "$1" | cut -d' ' -f4; }

# The copy of .env both wrappers read (BAJA_ENV_FILE): local destination,
# throwaway keys, a scratch erasure log. Later lines win.
cp "${INFRA}/.env" "${WORK}/test.env"
cat >> "${WORK}/test.env" <<EOF
BACKUP_REMOTE=:local:/drive/
BACKUP_LOCAL_DRIVE=${WORK}/drive
VERIFY_REMOTE=:local:/drive/
VERIFY_LOCAL_DRIVE=${WORK}/drive
ERASURE_LOG_DIR=${WORK}/erasure
BACKUP_HOST_WORK_DIR=${WORK}/host-work
AGE_RECIPIENT_ESCROW=$(recipient "${WORK}/escrow.key")
AGE_RECIPIENT_VERIFIER=$(recipient "${WORK}/verifier.key")
AGE_IDENTITY_VERIFIER=${WORK}/verifier.key
GDRIVE_SA_WRITER_JSON=${WORK}/no-google.json
GDRIVE_SA_READER_JSON=${WORK}/no-google.json
VERIFY_STATE_DIR=${WORK}/verify-state
VERIFY_MIN_ROWS_USER=1
KUMA_PUSH_URL_BACKUP=
KUMA_PUSH_URL_VERIFY=
KUMA_PUSH_URL_ARCHIVE=
EOF
export BAJA_ENV_FILE="${WORK}/test.env"

say "asking for an archive of ${EVENT}, as ticking Finalizado? would"
sql "SELECT 1 FROM evento_arquivamento LIMIT 1;" >/dev/null \
    || fail "no evento_arquivamento table — run the Propel migrations (see the header)"
[[ "$(sql "SELECT finalizado FROM evento WHERE evento_id = '${EVENT}';")" == "1" ]] \
    || fail "${EVENT} is not finalizado in this database"
REQUEST_ID="$(sql "INSERT INTO evento_arquivamento (evento_id, pedido_em_ms, pedido_por, estado)
                   VALUES ('${EVENT}', UNIX_TIMESTAMP() * 1000, 'stack-test', 'pendente');
                   SELECT LAST_INSERT_ID();")"
[[ "$REQUEST_ID" =~ ^[0-9]+$ ]] || fail "could not insert the request"

say "running backup-run.sh (nightly backup, then the event archives)"
"${INFRA}/scripts/backup-run.sh" || fail "backup-run.sh failed"

say "checking what landed on the fake Drive"
run_dir="$(find "${WORK}/drive/daily" -mindepth 1 -maxdepth 1 -type d | sort | tail -1)"
[[ -n "$run_dir" && -f "${run_dir}/manifest.json" ]] || fail "no complete daily/ folder"
manifest="${run_dir}/manifest.json"
[[ "$(jq -r .schema_version "$manifest")" == 4 ]] || fail "manifest is not schema 4"
[[ -f "${WORK}/drive/$(jq -r .env.folder "$manifest")/env.json" ]] || fail "the .env copy the manifest names is not there"
for name in baja_resultados.sql.gz.age phpbb_baja.sql.gz.age phpbb_formula.sql.gz.age \
            phpbb_baja-files.tar.gz.age phpbb_formula-files.tar.gz.age baja-app-sons.tar.gz.age \
            erasure-log.jsonl.age; do
    [[ -f "${run_dir}/${name}" && -f "${run_dir}/${name}.sha256" ]] || fail "${name} or its sidecar is missing"
done
echo "    daily/$(basename "$run_dir"): every artifact present; phpbb_baja files: $(jq -r '.file_sets["phpbb_baja-files"].file_count' "$manifest")"

event_dir="$(find "${WORK}/drive/events/${EVENT}" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | sort | tail -1)"
[[ -n "$event_dir" && -f "${event_dir}/manifest.json" ]] || fail "no complete events/${EVENT}/ folder"
echo "    events/${EVENT}/$(basename "$event_dir"): $(jq -r .snapshot_count "${event_dir}/manifest.json") snapshots, code $(jq -r .code_version "${event_dir}/manifest.json")"
[[ "$(sql "SELECT estado FROM evento_arquivamento WHERE arquivamento_id = ${REQUEST_ID};")" == "arquivado" ]] \
    || fail "the request was not marked arquivado"
echo "    request ${REQUEST_ID}: arquivado"

say "running verify-run.sh (restores everything into scratch space and compares)"
"${INFRA}/scripts/verify-run.sh" || fail "verify-run.sh failed"

say "PASSED"
