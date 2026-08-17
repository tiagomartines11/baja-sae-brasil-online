# shellcheck shell=bash
#
# backup-lib.sh — definitions shared by backup-db.sh and verify-db.sh.
#
# Sourced, never executed. It exists for exactly one reason: the verifier
# compares a restored database against a manifest the backup wrote, so any
# definition that both sides depend on — which databases exist, which
# tables are excluded, how a row count is taken, how the charset canary is
# built — MUST be written once. If these drifted, the verifier would
# either pass on a broken backup or fail on a healthy one, and both
# failure modes are silent until the day you need the restore.
#
# Callers must set, before sourcing:
#   LOG_PREFIX    tag for log lines, e.g. "backup" / "verify"
# Callers must set, before calling any db_* function:
#   MYSQL_DEFAULTS_FILE   path to a 0600 option file holding the
#                         [client] credentials (see write_defaults_file)

# ----- The three production databases -----
# Order is deliberate: baja_resultados first. It is the certificate
# archive — certificates are generated on demand, so a lost row is a
# credential a former student can no longer verify — and if a run is
# going to die partway through, it should die having already secured the
# database that cannot be reconstructed from anywhere else.
BACKUP_DATABASES=(baja_resultados phpbb_baja phpbb_formula)

# Expected number of BASE TABLEs per database, used by the verifier as an
# absolute floor (see verify-db.sh). Not an equality check: adding tables
# is normal, losing them is not.
backup_min_tables() {
    case "$1" in
        baja_resultados) echo 13 ;;
        phpbb_baja|phpbb_formula) echo 60 ;;
        *) echo 1 ;;
    esac
}

# ----- Table exclusions -----
#
# Two categories only, and the boundary is not negotiable:
#
#   ephemeral   — session state and transient rate-limiting rows. Restoring
#                 them would restore stale sessions, not user data.
#   regenerable — the phpBB search index, rebuilt from the ACP
#                 (Maintenance -> Search index). See docs/backup-restore.md;
#                 a restore is not complete until that rebuild runs.
#
# AUDIT TABLES STAY IN. phpbb_log carries LOG_ADMIN / LOG_MOD / LOG_USERS /
# LOG_CRITICAL — who banned whom, what changed in the ACP. That is
# disputed-decision evidence and post-incident forensics, and an attacker
# with ACP access can prune the live copy, which can leave the backup as
# the only surviving record. The same applies to any audit or history
# table in baja_resultados. Do not add them here to save space.
#
# THIS LIST ACHIEVES NO IP MINIMISATION and must never be described as if
# it does. phpBB keeps poster_ip on posts, author_ip on private messages
# and user_ip on users; every one of them is retained by these dumps. If
# IP retention needs addressing under LGPD it belongs in the application
# and the live database. Pruning only at dump time satisfies nothing: the
# rows stay in production, the obligation is unmet, and all you have done
# is stop being able to see them. Retention is enforced where the data
# lives; backups reflect it.
backup_excluded_tables() {
    case "$1" in
        phpbb_baja|phpbb_formula)
            printf '%s\n' \
                phpbb_sessions \
                phpbb_sessions_keys \
                phpbb_login_attempts \
                phpbb_search_wordlist \
                phpbb_search_wordmatch \
                phpbb_search_results
            ;;
        *)
            : # baja_resultados: nothing excluded.
            ;;
    esac
}

# ----- Charset canary -----
#
# Accent mangling is the failure this exists to catch, and it is the one
# corruption that imports without a single error: a latin1/utf8mb4
# round-trip fault turns "José" into "JosÃ©" and everything exits 0. You
# would otherwise discover it when a name renders wrong on a certificate.
#
# The query must be deterministic and stable: ordered by primary key,
# capped, and over a table that does not churn. Only the hash of the
# result is ever stored, so the manifest stays free of personal data.
#
# Source tables were chosen for accent density, not importance:
#   baja_resultados -> evento      (titles and venues: "Competição",
#                                   "São José dos Campos")
#   phpbb_*         -> phpbb_posts (Portuguese prose; the forums'
#                                   structural tables turned out to be
#                                   pure ASCII on a fresh install, which
#                                   would have made the canary vacuous)
# The comparison is always within one run — the backup's value against the
# value recomputed from the restored copy — so a later edit to either table
# is harmless. What matters is that the rows contain non-ASCII bytes at
# all, which preflight checks and warns about.
backup_canary_sql() {
    case "$1" in
        baja_resultados)
            printf '%s' "SELECT CONCAT_WS('|', evento_id, COALESCE(titulo,''), COALESCE(nome,''), COALESCE(\`local\`,''), COALESCE(presidente,'')) FROM evento ORDER BY evento_id LIMIT 50"
            ;;
        phpbb_baja|phpbb_formula)
            printf '%s' "SELECT CONCAT_WS('|', post_id, post_subject, post_text) FROM phpbb_posts ORDER BY post_id LIMIT 50"
            ;;
        *)
            return 1
            ;;
    esac
}

# ----- Logging -----
log()  { printf '[%s] %s\n' "${LOG_PREFIX:-backup}" "$*"; }
warn() { printf '[%s] WARNING: %s\n' "${LOG_PREFIX:-backup}" "$*" >&2; }
err()  { printf '[%s] ERROR: %s\n' "${LOG_PREFIX:-backup}" "$*" >&2; }
die()  { err "$*"; exit 1; }

# ----- MySQL access -----
#
# Credentials go through a 0600 option file, never on the command line:
# `mysql -p<secret>` is visible in `ps` to every process on the host, and
# this container shares a kernel with production.
write_defaults_file() {
    local path="$1" host="$2" port="$3" user="$4" password="$5"

    # MySQL option files accept double-quoted values with backslash
    # escapes. Escape backslash first, then the quote, or the escaping of
    # the escape is itself wrong.
    local esc="${password//\\/\\\\}"
    esc="${esc//\"/\\\"}"

    ( umask 077; : > "$path" )
    # connect-timeout goes in [mysql], NOT [client]: mysqldump also reads
    # [client] and does not recognise the option, so putting it there
    # makes every dump fail with "unknown variable 'connect-timeout=15'".
    #
    # It bounds the handshake only. Neither client can bound an
    # ESTABLISHED connection that stops answering — mysqldump's
    # --network-timeout actually lengthens those waits — so every
    # long-running invocation is additionally wrapped in `timeout` by its
    # caller. Found by cutting the network mid-dump: mysqldump blocked
    # forever instead of failing, which would have stacked up cron runs
    # indefinitely while Kuma alerted on the silence.
    cat > "$path" <<EOF
[client]
host = ${host}
port = ${port}
user = ${user}
password = "${esc}"
default-character-set = utf8mb4

[mysql]
connect-timeout = 15
EOF
    chmod 600 "$path"
}

# Run SQL against a named database, batch mode, no column headers.
#
# Every query is wrapped in `timeout` for the reason given above: an
# established MySQL connection that stops answering hangs the client
# forever. COUNT(*) across a large schema is the slow case here, so the
# default is generous rather than tight.
db_query() {
    local db="$1" sql="$2"
    timeout --kill-after=15s "${BACKUP_QUERY_TIMEOUT:-300s}" \
        mysql --defaults-file="$MYSQL_DEFAULTS_FILE" -BN "$db" -e "$sql"
}

# Run SQL with no database selected (information_schema lookups).
db_query_nodb() {
    local sql="$1"
    timeout --kill-after=15s "${BACKUP_QUERY_TIMEOUT:-300s}" \
        mysql --defaults-file="$MYSQL_DEFAULTS_FILE" -BN -e "$sql"
}

# List the BASE TABLEs of a database that the dump is expected to contain,
# i.e. everything minus the exclusions above.
backup_included_tables() {
    local db="$1"
    local -a excluded=()
    mapfile -t excluded < <(backup_excluded_tables "$db")

    # Always a non-empty list so the NOT IN clause is valid even when
    # nothing is excluded.
    local not_in="''"
    local t
    for t in "${excluded[@]}"; do
        not_in+=",'${t}'"
    done

    db_query_nodb "SELECT TABLE_NAME FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA='${db}'
                     AND TABLE_TYPE='BASE TABLE'
                     AND TABLE_NAME NOT IN (${not_in})
                   ORDER BY TABLE_NAME"
}

# Emit "<table>\t<count>" for every included table.
#
# COUNT(*), never information_schema.TABLE_ROWS. TABLE_ROWS is an
# estimate for InnoDB — it can be off by double-digit percentages — and
# using it would produce verification failures that look exactly like data
# loss. Costs a full index scan per table; at our sizes that is seconds.
#
# One round trip rather than one per table, so the whole set is as close
# to a single instant as this approach gets.
backup_table_counts() {
    local db="$1"
    local -a tables=()
    mapfile -t tables < <(backup_included_tables "$db")
    (( ${#tables[@]} > 0 )) || die "no base tables found in ${db} — wrong database, or the backup user cannot see it"

    local sql="" t
    for t in "${tables[@]}"; do
        sql+="SELECT '${t}' AS t, COUNT(*) AS c FROM \`${t}\` UNION ALL "
    done
    db_query "$db" "${sql% UNION ALL }"
}

# Emit "<procedures>\t<functions>\t<triggers>\t<events>".
# Verified so that someone quietly dropping --routines in a refactor shows
# up as a failed verification rather than as a restore that is missing its
# stored logic.
backup_object_counts() {
    local db="$1"
    db_query_nodb "SELECT
        (SELECT COUNT(*) FROM information_schema.ROUTINES
          WHERE ROUTINE_SCHEMA='${db}' AND ROUTINE_TYPE='PROCEDURE'),
        (SELECT COUNT(*) FROM information_schema.ROUTINES
          WHERE ROUTINE_SCHEMA='${db}' AND ROUTINE_TYPE='FUNCTION'),
        (SELECT COUNT(*) FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA='${db}'),
        (SELECT COUNT(*) FROM information_schema.EVENTS
          WHERE EVENT_SCHEMA='${db}')"
}

# Hash of the canary rows. Only this value is ever recorded.
backup_canary_hash() {
    local db="$1" sql
    sql="$(backup_canary_sql "$db")" || die "no charset canary defined for ${db}"
    db_query "$db" "$sql" | sha256sum | cut -d' ' -f1
}

# Bytes currently occupied by the tables the dump will include. Used to
# size the free-space check and to report growth; it is an estimate of the
# dump's size, not a measurement of it.
backup_estimated_bytes() {
    local db="$1"
    local -a excluded=()
    mapfile -t excluded < <(backup_excluded_tables "$db")
    local not_in="''" t
    for t in "${excluded[@]}"; do
        not_in+=",'${t}'"
    done
    db_query_nodb "SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0)
                   FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA='${db}'
                     AND TABLE_TYPE='BASE TABLE'
                     AND TABLE_NAME NOT IN (${not_in})"
}

# ----- Uptime Kuma -----
#
# Push monitors alert on SILENCE. Nothing here ever reports a failure —
# a failed run simply does not ping, and Kuma fires when the heartbeat
# does not arrive. That is the whole point: a bug that crashes the script
# still trips the alarm, whereas a script that has to successfully report
# its own failure can fail to do that too.
kuma_push() {
    local url="$1" msg="$2" ping_ms="$3"
    [[ -n "$url" ]] || { warn "no Kuma push URL configured — success will not be reported"; return 0; }

    # --get moves the urlencoded fields into the query string, appending
    # with '?' or '&' as the URL requires. Kuma push URLs already carry a
    # token path segment and sometimes a query, so do not hand-build this.
    if ! curl -fsS -m 15 -o /dev/null \
            --data-urlencode "status=up" \
            --data-urlencode "msg=${msg}" \
            --data-urlencode "ping=${ping_ms}" \
            --get "${url}"; then
        # A working backup that could not reach Kuma is still a working
        # backup. Kuma will alert on the missing heartbeat, which is the
        # correct outcome — a human looks, and finds the backup fine.
        warn "Kuma push to ${url%%\?*} failed; the run itself succeeded"
    fi
}

# Human-readable byte counts for log lines.
human_bytes() {
    local b="$1"
    if   (( b >= 1073741824 )); then awk -v b="$b" 'BEGIN{printf "%.2f GiB", b/1073741824}'
    elif (( b >= 1048576    )); then awk -v b="$b" 'BEGIN{printf "%.1f MiB", b/1048576}'
    elif (( b >= 1024       )); then awk -v b="$b" 'BEGIN{printf "%.1f KiB", b/1024}'
    else printf '%d B' "$b"
    fi
}
