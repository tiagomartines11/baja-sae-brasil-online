#!/usr/bin/env bash
# shellcheck disable=SC2034
# shellcheck source-path=SCRIPTDIR/../../scripts
#   (SC2034: variables set here are read by the scripts it sources.)
#
# The backup MySQL user, and the dumps it makes, against a real MySQL.
#
# WHAT THIS CHECKS, IN PLAIN WORDS
#
# The nightly backup connects to MySQL as a restricted user, `backup`,
# created by baja-infra/mysql/init/02-create-users.sh. This script logs in
# as that user and checks, against a real server:
#
#   1. its grants are exactly the per-database, read-only ones (printed, so
#      the CI log shows them);
#   2. it CANNOT read mysql.user, where every account's password hash is,
#      nor anything else in the mysql schema;
#   3. it cannot write anywhere;
#   4. with only those grants, the backup's own dump code (dump_one in
#      scripts/backup-db.sh) still dumps all three databases completely;
#   5. each dump starts by dropping its database, so restoring one REPLACES
#      what is there instead of merging into it. That is what makes a
#      restore onto a fresh server safe: the first MySQL boot has loaded the
#      dev seed, and an old-style import would have mixed the two. Shown by
#      adding a row after the dump, restoring, and finding the row gone.
#
# Needs a MySQL whose databases were made by mysql/init/01 to 04 and whose
# users were made by 02 (CI does this), and the mysql and mysqldump
# clients. Point it at the server with:
#
#   MYSQL_HOST, MYSQL_PORT           (default 127.0.0.1, 3306)
#   MYSQL_ROOT_PASSWORD              to undo step 5's test row and restore
#   MYSQL_BACKUP_USER/PASSWORD       the backup user, as in .env
#
# Step 5 restores baja_resultados over itself from a dump taken seconds
# before, so the database ends as it started. Never point this at
# production.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS="$(cd "${HERE}/../../scripts" && pwd)"

MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}"
MYSQL_PORT="${MYSQL_PORT:-3306}"
: "${MYSQL_ROOT_PASSWORD:?}" "${MYSQL_BACKUP_USER:?}" "${MYSQL_BACKUP_PASSWORD:?}"

fail() { echo "FAILED: $*" >&2; exit 1; }
ok()   { echo "  ok   $*"; }

TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT

# Credentials in option files, as the backup itself does, never on the
# command line.
LOG_PREFIX='test'
# shellcheck source=backup-lib.sh
source "${SCRIPTS}/backup-lib.sh"
write_defaults_file "${TMP}/backup.cnf" "$MYSQL_HOST" "$MYSQL_PORT" "$MYSQL_BACKUP_USER" "$MYSQL_BACKUP_PASSWORD"
write_defaults_file "${TMP}/root.cnf" "$MYSQL_HOST" "$MYSQL_PORT" root "$MYSQL_ROOT_PASSWORD"
as_backup() { mysql --defaults-file="${TMP}/backup.cnf" -BN "$@"; }
as_root()   { mysql --defaults-file="${TMP}/root.cnf" -BN "$@"; }

echo
echo "backup MySQL user (${MYSQL_BACKUP_USER}@${MYSQL_HOST})"
echo

# 1. The grants, printed for the record, and checked: no ON *.* except
#    the read-only SHOW_ROUTINE, and nothing beyond the expected list.
grants="$(as_backup -e "SHOW GRANTS")"
printf '%s\n' "$grants" | sed 's/^/         /'
if printf '%s\n' "$grants" | grep -F ' ON *.* ' | grep -Ev "^GRANT (USAGE|SHOW_ROUTINE) ON \*\.\* " | grep -q .; then
    fail "the backup user has a global grant other than USAGE / SHOW_ROUTINE"
fi
for db in baja_resultados phpbb_baja phpbb_formula; do
    printf '%s\n' "$grants" | grep -q "^GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON \`${db}\`\.\*" \
        || fail "no read-only grant on ${db}"
done
ok "grants are per database, read-only, plus the global SHOW_ROUTINE"

# 2. No password hashes, and nothing else in the mysql schema.
if as_backup -e "SELECT authentication_string FROM mysql.user LIMIT 1" >/dev/null 2>&1; then
    fail "the backup user can read mysql.user"
fi
if as_backup -e "SELECT COUNT(*) FROM mysql.db" >/dev/null 2>&1; then
    fail "the backup user can read the mysql schema"
fi
if [[ "$(as_backup -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'mysql'")" != "0" ]]; then
    fail "the backup user can see the mysql schema at all"
fi
ok "cannot read mysql.user (password hashes) or anything in the mysql schema"

# 3. No writes, anywhere it can read.
for statement in \
    "CREATE TABLE baja_resultados.should_fail (i INT)" \
    "DELETE FROM baja_resultados.evento WHERE 1 = 0" \
    "UPDATE phpbb_baja.phpbb_config SET config_value = config_value WHERE 1 = 0" \
    "CREATE USER 'should_fail'@'%' IDENTIFIED BY 'x'"; do
    if as_backup -e "$statement" >/dev/null 2>&1; then
        fail "the backup user could run: ${statement}"
    fi
done
ok "cannot create, delete, update or make users"

# 4. The real dump code, with only these grants.
# shellcheck source=backup-db.sh
source "${SCRIPTS}/backup-db.sh"
# backup-db.sh installs its own exit trap (it wipes RUN_DIR); keep ours too.
trap 'cleanup; rm -rf -- "$TMP"' EXIT
RUN_DIR="${TMP}/run"
mkdir -p "$RUN_DIR"
MYSQL_DEFAULTS_FILE="${TMP}/backup.cnf"
for db in "${BACKUP_DATABASES[@]}"; do
    dump_one "$db" > /dev/null
    [[ -s "${RUN_DIR}/${db}.sql" ]] || fail "no dump of ${db}"
    jq -e '.table_counts | length > 0' "${RUN_DIR}/dbmeta.${db}.json" >/dev/null || fail "no table counts for ${db}"
done
ok "dump_one dumps all three databases, with counts, as the backup user"

# 5. Restoring replaces; it never merges.
for db in "${BACKUP_DATABASES[@]}"; do
    # mysqldump writes it inside a version comment: /*!40000 DROP ... */;
    grep -Eq "DROP DATABASE IF EXISTS \`${db}\`" "${RUN_DIR}/${db}.sql" \
        || fail "the dump of ${db} does not start by dropping the database"
done
# A row the dump does not have, standing in for the dev seed's data.
as_root -e "INSERT INTO baja_resultados.evento (evento_id, titulo, nome, tipo, ano, ativo, finalizado, spoilers, programa_id)
            VALUES ('99MG', 'not in the dump', 'not in the dump', 0, 2099, 0, 0, 0, 'BAJA')"
as_root < "${RUN_DIR}/baja_resultados.sql"
if [[ "$(as_root -e "SELECT COUNT(*) FROM baja_resultados.evento WHERE evento_id = '99MG'")" != "0" ]]; then
    as_root -e "DELETE FROM baja_resultados.evento WHERE evento_id = '99MG'"
    fail "restoring the dump kept a row the dump does not have: it merged instead of replacing"
fi
[[ "$(as_root -e "SELECT COUNT(*) FROM baja_resultados.evento")" == "$(jq -r '.table_counts.evento' "${RUN_DIR}/dbmeta.baja_resultados.json")" ]] \
    || fail "after the restore, evento does not have the dump's row count"
ok "restoring a dump replaces the database: a row added after the dump is gone"

echo
echo "all checks passed"
