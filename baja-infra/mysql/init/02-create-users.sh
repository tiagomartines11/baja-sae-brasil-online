#!/bin/bash
# Run by mysql:8.4 as part of /docker-entrypoint-initdb.d/ on first
# boot, after the .sql files in this dir have been applied.
# Purpose: create application users with passwords sourced from env vars,
# so credentials live in baja-infra/.env (single source of truth) rather
# than being hardcoded in this script.
#
# Env vars are inherited from the mysql container (see compose).
# `set -u` makes a missing password error early instead of running with
# an empty password and creating a wide-open user.
#
# Wrapped in a subshell because MySQL's docker-entrypoint *sources* this
# script when the executable bit is missing (common with Windows-host
# bind mounts). Without the subshell, `set -euo pipefail` leaks into the
# parent entrypoint shell and aborts it before the seed scripts (03+) run.
(
    set -euo pipefail

    mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" <<EOF
-- phpBB Baja user (full access to its own DB only)
CREATE USER IF NOT EXISTS 'phpbb_baja'@'%' IDENTIFIED BY '${MYSQL_PHPBB_BAJA_PASSWORD}';
GRANT ALL PRIVILEGES ON phpbb_baja.* TO 'phpbb_baja'@'%';

-- phpBB Formula user (full access to its own DB only)
CREATE USER IF NOT EXISTS 'phpbb_formula'@'%' IDENTIFIED BY '${MYSQL_PHPBB_FORMULA_PASSWORD}';
GRANT ALL PRIVILEGES ON phpbb_formula.* TO 'phpbb_formula'@'%';

-- Baja resultados app user (matches prod username 'resultados').
-- Dual-role: full grants on baja_resultados (its own DB) plus SELECT on
-- phpbb_baja for the session shim.
CREATE USER IF NOT EXISTS 'resultados'@'%' IDENTIFIED BY '${MYSQL_RESULTADOS_PASSWORD}';
GRANT ALL PRIVILEGES ON baja_resultados.* TO 'resultados'@'%';
GRANT SELECT ON phpbb_baja.* TO 'resultados'@'%';

-- Backup user (baja-infra/scripts/backup-db.sh). Read-only by
-- construction: no INSERT, UPDATE, DELETE, CREATE or DROP anywhere, so a
-- compromised backup container cannot alter production.
--
-- Each grant earns its place against a mysqldump flag:
--   SELECT       read the rows
--   LOCK TABLES  historically required; harmless alongside
--                --single-transaction, which does not take table locks
--   SHOW VIEW    dump view definitions
--   TRIGGER      --triggers (SHOW TRIGGERS is gated on it)
--   EVENT        --events
--
-- TRIGGER is the one that is not purely read-only: it also permits
-- CREATE/DROP TRIGGER. It is nonetheless the minimum MySQL offers for
-- dumping triggers, so it stays, and is called out here rather than being
-- quietly widened to root the first time --triggers errors out.
--
-- ON *.* rather than per-database: the dump reads information_schema
-- across all three schemas, and a per-schema grant list silently rots
-- every time a database is added.
CREATE USER IF NOT EXISTS '${MYSQL_BACKUP_USER}'@'%' IDENTIFIED BY '${MYSQL_BACKUP_PASSWORD}';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON *.* TO '${MYSQL_BACKUP_USER}'@'%';

FLUSH PRIVILEGES;
EOF

    echo "Application users created."
)
