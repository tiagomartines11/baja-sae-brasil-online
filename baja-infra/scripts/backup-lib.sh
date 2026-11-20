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
# shellcheck disable=SC2034  # consumed by the scripts that source this file
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

# ----- Event codes -----
#
# An event code (evento_id) is two digits for the year and two capitals or
# digits for the competition: 26BR, 25AR, 24H2. It becomes a folder name
# on the Shared Drive (events/<EVT>/), so it is checked before use. The
# letters are not listed: they once were (BR, SE, SU, NE), and every event
# with others was refused (BAJAAPP-74).
#
# The PHP side has the same rule in baja-php/src/Baja/Evento/EventCode.php.
# Change both together. LC_ALL=C so that A-Z means A to Z and nothing else,
# whatever the caller's locale.
backup_is_event_code() {
    local LC_ALL=C
    [[ "$1" =~ ^[0-9]{2}[A-Z0-9]{2}$ ]]
}

# ----- The stack's .env -----
#
# load_env_file FILE: reads FILE as docker compose reads it and exports
# every KEY=value in it. Later lines win, as in compose.
#
# Never `source` it. The .env is written for compose, not bash:
# `SMTP_FROM_NAME=SAE BRASIL - Certificados` is a fine compose value and,
# sourced, runs a command called BRASIL, which under `set -e` kills the
# nightly backup before it starts. Sourcing would also run any `$(...)` a
# value happens to hold.
#
# What compose does and this repeats: blank lines and lines starting with
# `#` are skipped; an optional `export ` prefix is dropped; '...' is taken
# literally; "..." and unquoted values expand ${VAR} and ${VAR:-default}
# from what is already loaded or exported; an unquoted value ends at
# whitespace followed by `#`. Not repeated: escapes inside "...", and
# `$VAR` without braces (no .env.example value uses either).
_env_expand() {
    local s="$1" out="" name val
    while [[ "$s" == *"\${"* ]]; do
        out+="${s%%"\${"*}"
        s="${s#*"\${"}"
        if [[ "$s" =~ ^([A-Za-z_][A-Za-z0-9_]*)(:-([^}]*))?\}(.*)$ ]]; then
            name="${BASH_REMATCH[1]}"
            if [[ -n "${BASH_REMATCH[2]}" ]]; then
                val="${!name:-${BASH_REMATCH[3]}}"
            else
                val="${!name-}"
            fi
            out+="$val"
            s="${BASH_REMATCH[4]}"
        else
            out+="\${"
        fi
    done
    printf '%s' "${out}${s}"
}

load_env_file() {
    local file="$1" line key value n=0
    [[ -r "$file" ]] || die "cannot read ${file}"
    while IFS= read -r line || [[ -n "$line" ]]; do
        n=$((n + 1))
        line="${line%$'\r'}"
        [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
        if [[ ! "$line" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=[[:space:]]*(.*)$ ]]; then
            die "${file}:${n}: not a KEY=value line"
        fi
        key="${BASH_REMATCH[2]}"
        value="${BASH_REMATCH[3]}"
        if [[ "$value" =~ ^\'([^\']*)\'[[:space:]]*(#.*)?$ ]]; then
            value="${BASH_REMATCH[1]}"
        elif [[ "$value" =~ ^\"([^\"]*)\"[[:space:]]*(#.*)?$ ]]; then
            value="$(_env_expand "${BASH_REMATCH[1]}")"
        else
            value="${value%%[[:space:]]#*}"
            value="${value%"${value##*[![:space:]]}"}"
            value="$(_env_expand "$value")"
        fi
        printf -v "$key" '%s' "$value"
        # shellcheck disable=SC2163  # exports the variable named by $key
        export "$key"
    done < "$file"
}

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

# ----- File sets -----
#
# The state that lives in files rather than in MySQL. Each set becomes one
# tar.gz.age artifact per run, beside the database dumps. Compose mounts
# every root below READ-ONLY into the backup container (see the `backup`
# service in docker-compose.yml and its additions in
# docker-compose.prod.yml), so the paths are the same in dev, where the
# phpBB uploads live inside the phpbb_*_html volumes, and in production,
# where they are bind mounts under /srv/baja.
#
#   phpbb_*-files   forum attachments (files/) and uploaded avatars
#                   (images/avatars/upload/). Nothing else in the forum
#                   volume: code, ext/, cache/ and store/ come from the
#                   image or are regenerated, and config.php holds a
#                   database password that must not ride along.
#   baja-app-sons   timer sounds generated on the server by text to
#                   speech (baja-php/juiz/sons/gerados/). The other sounds
#                   in that folder are committed to git.
#
# Named after the database they belong to on purpose: the retention pruner
# drops phpbb_* forum history from monthly/ after six months, and a forum's
# attachments go with its posts.
# shellcheck disable=SC2034  # consumed by the scripts that source this file
BACKUP_FILE_SETS=(phpbb_baja-files phpbb_formula-files baja-app-sons)

BACKUP_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

backup_file_set_root() {
    local state="${BACKUP_STATE_DIR:-/state}"
    case "$1" in
        phpbb_baja-files)    printf '%s' "${state}/phpbb-baja" ;;
        phpbb_formula-files) printf '%s' "${state}/phpbb-formula" ;;
        baja-app-sons)       printf '%s' "${state}/baja-app-sons" ;;
        *) return 1 ;;
    esac
}

# Folders inside the root that the set holds.
backup_file_set_members() {
    case "$1" in
        phpbb_baja-files|phpbb_formula-files)
            printf '%s\n' files images/avatars/upload ;;
        baja-app-sons)
            printf '%s\n' gerados ;;
        *) return 1 ;;
    esac
}

# A required set whose folders are missing means a mount is wrong, and the
# run must fail rather than upload an empty archive that looks fine. The
# generated sounds folder only exists once someone has generated a sound.
backup_file_set_required() {
    case "$1" in
        baja-app-sons) return 1 ;;
        *) return 0 ;;
    esac
}

# ----- Describing a tree of files -----
#
# The manifest records, for each file set, a digest over one line per
# regular file:
#
#     <sha256 of the content> <size in bytes> <path inside the set>
#
# sorted bytewise and hashed. The backup computes it from the ARCHIVE it
# just wrote (so files changing on disk while tar read them cannot make the
# manifest disagree with the artifact); the verifier computes it from the
# files it actually extracted. A match means every file came back, with the
# same content, under the same name. Only the digest, the count and the
# total size are stored; no file name goes into the manifest.
#
# These two functions, and only these, produce the lines. They must stay
# byte-for-byte identical in format.

# Lines for the files under a directory.
backup_tree_lines_from_dir() {
    local dir="$1" f
    ( cd "$dir" && find . -type f -print0 ) | while IFS= read -r -d '' f; do
        f="${f#./}"
        printf '%s %s %s\n' \
            "$(sha256sum < "${dir}/${f}" | cut -d' ' -f1)" \
            "$(stat -c %s "${dir}/${f}")" \
            "$f"
    done
}

# Lines for the regular files inside a .tar.gz, read without writing any of
# them to disk: GNU tar pipes each member to tar-entry-sha256.sh. It runs
# in an empty scratch directory because tar still creates the DIRECTORY
# members of the archive where it stands.
backup_tree_lines_from_tar() {
    local archive scratch
    # Absolute, because the extraction below runs from another directory.
    archive="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
    scratch="$(mktemp -d)"
    ( cd "$scratch" && tar -xzf "$archive" --to-command="'${BACKUP_LIB_DIR}/tar-entry-sha256.sh'" )
    rm -rf -- "$scratch"
}

# "<count>\t<total bytes>\t<digest>" from the lines on stdin.
backup_tree_summary() {
    local lines
    lines="$(LC_ALL=C sort)"
    if [[ -z "$lines" ]]; then
        printf '0\t0\t%s\n' "$(printf '' | sha256sum | cut -d' ' -f1)"
        return 0
    fi
    printf '%s\t%s\t%s\n' \
        "$(printf '%s\n' "$lines" | wc -l)" \
        "$(printf '%s\n' "$lines" | awk '{ s += $2 } END { printf "%d", s }')" \
        "$(printf '%s\n' "$lines" | sha256sum | cut -d' ' -f1)"
}

# Archive one file set as <out_dir>/<name>.tar.gz and describe it in
# <out_dir>/fileset.<name>.json. Dies on anything that would make the
# artifact not a faithful copy.
backup_file_set_archive() {
    local name="$1" out_dir="$2"
    local root tarball="${out_dir}/${name}.tar.gz" meta="${out_dir}/fileset.${name}.json"
    root="$(backup_file_set_root "$name")" || die "unknown file set ${name}"

    local -a present=() absent=()
    local m
    while IFS= read -r m; do
        if [[ -d "${root}/${m}" ]]; then present+=("$m"); else absent+=("$m"); fi
    done < <(backup_file_set_members "$name")

    if (( ${#absent[@]} > 0 )) && backup_file_set_required "$name"; then
        die "${name}: ${root}/${absent[0]} does not exist — the folder is not mounted into the backup container (check the backup service's volumes)"
    fi

    # --numeric-owner keeps phpBB's www-data uid (82) as a number, so the
    # restored files belong to the right user in the forum container
    # whatever the restoring host calls that uid. tar exits 1 when a file
    # changed while it was read; that is not an error here, because the
    # manifest describes what the archive holds, computed from the archive.
    local rc=0
    if (( ${#present[@]} > 0 )); then
        tar --create --gzip --file "$tarball" --directory "$root" \
            --numeric-owner --warning=no-file-changed -- "${present[@]}" || rc=$?
    else
        tar --create --gzip --file "$tarball" --files-from /dev/null || rc=$?
    fi
    (( rc == 0 || rc == 1 )) || die "${name}: tar failed (exit ${rc})"
    gzip -t "$tarball" || die "${name}: the archive failed its own gzip test"

    local count bytes digest
    IFS=$'\t' read -r count bytes digest < <(backup_tree_lines_from_tar "$tarball" | backup_tree_summary)

    jq -n \
        --arg file "${name}.tar.gz.age" \
        --argjson members "$(printf '%s\n' "${present[@]}" | jq -Rn '[inputs | select(length > 0)]')" \
        --argjson count "$count" \
        --argjson bytes "$bytes" \
        --arg digest "$digest" \
        '{ file: $file, members: $members, file_count: $count,
           total_bytes: $bytes, tree_sha256: $digest }' > "$meta"
}

# ----- Manifest -----
#
# Assembled from the facts each phase of backup-db.sh left in the run
# directory, so that it can be built (and tested) without a database:
#
#   dbmeta.<db>.json    + artifact.<db>.json      per database
#   fileset.<set>.json  + artifact.<set>.json     per file set
#   erasure.json        + artifact.erasure-log.json
#
# schema_version history:
#   2  the three database artifacts only (feature/db-backups, 2026-08)
#   3  adds file_sets, erasure_log and code_version.
#   4  adds env, the copy of the stack's .env that matches this run.
#   The verifier reads all of them; an older manifest simply has fewer
#   things to check.
backup_assemble_manifest() {
    local run_dir="$1" run_id="$2" created="$3" script_version="$4" mysql_version="$5" code_version="$6"
    local dbs sets erasure name

    dbs="$(for name in "${BACKUP_DATABASES[@]}"; do
        jq -n --arg k "$name" \
              --slurpfile m "${run_dir}/dbmeta.${name}.json" \
              --slurpfile a "${run_dir}/artifact.${name}.json" \
              '{ ($k): ($m[0] + $a[0]) }'
    done | jq -s 'add')" || die "manifest: a database's facts are missing from ${run_dir}"

    sets="$(for name in "${BACKUP_FILE_SETS[@]}"; do
        jq -n --arg k "$name" \
              --slurpfile m "${run_dir}/fileset.${name}.json" \
              --slurpfile a "${run_dir}/artifact.${name}.json" \
              '{ ($k): ($m[0] + $a[0]) }'
    done | jq -s 'add')" || die "manifest: a file set's facts are missing from ${run_dir}"

    erasure="$(jq -s 'add' "${run_dir}/erasure.json" "${run_dir}/artifact.erasure-log.json")" \
        || die "manifest: the erasure log's facts are missing from ${run_dir}"
    local env
    env="$(jq -c . "${run_dir}/env.manifest.json")" \
        || die "manifest: the .env copy's facts are missing from ${run_dir}"

    # The $names are jq variables bound by --arg/--argjson, not shell
    # expansions; the single quotes are what keeps them that way.
    # shellcheck disable=SC2016
    jq -n \
        --arg run_id "$run_id" --arg created "$created" \
        --arg script_version "$script_version" --arg mysql_version "$mysql_version" \
        --arg code_version "$code_version" \
        --arg escrow "$AGE_RECIPIENT_ESCROW" --arg verifier "$AGE_RECIPIENT_VERIFIER" \
        --argjson dbs "$dbs" --argjson sets "$sets" --argjson erasure "$erasure" \
        --argjson env "$env" \
        '{
            schema_version: 4,
            run_id: $run_id,
            created_utc: $created,
            script_version: $script_version,
            code_version: $code_version,
            mysql_version: $mysql_version,
            age_recipients: [$escrow, $verifier],
            artifacts: $dbs,
            file_sets: $sets,
            erasure_log: $erasure,
            env: $env
        }'
}

# Last line of defence on the no-personal-data rule: a manifest is the one
# thing that goes to Drive in the clear, so refuse one carrying a field
# named like personal data with a text value, or anything shaped like an
# email address.
backup_manifest_check_clean() {
    local manifest="$1"
    jq empty "$manifest" 2>/dev/null || die "assembled manifest is not valid JSON"
    if grep -Eq '"[^"]*(cpf|email|nome|username)[^"]*"[[:space:]]*:[[:space:]]*"' "$manifest"; then
        die "manifest appears to contain a personal-data field — refusing to upload it unencrypted"
    fi
    if grep -Eq '"[^" @]+@[^" @]+\.[A-Za-z]{2,}"' "$manifest"; then
        die "manifest appears to contain an email address — refusing to upload it unencrypted"
    fi
}

# ----- Encryption -----
#
# Every artifact is encrypted to BOTH recipients, always, through this one
# function. The escrow key is exercised only by the human drill and its
# private half never lands on a server — which is exactly why it must be a
# recipient on every artifact: the day it is needed, nobody will be able to
# add it retroactively.
#
# Writes <artifact> and <artifact>.sha256 (in `sha256sum -c` format, so a
# restore on a machine with nothing but coreutils can check a download),
# and prints the artifact's sha256.
backup_encrypt() {
    local src="$1" artifact="$2" sha
    age -r "$AGE_RECIPIENT_ESCROW" -r "$AGE_RECIPIENT_VERIFIER" -o "$artifact" "$src" \
        || die "age encryption of $(basename "$src") failed"
    sha="$(sha256sum "$artifact" | cut -d' ' -f1)"
    printf '%s  %s\n' "$sha" "$(basename "$artifact")" > "${artifact}.sha256"
    printf '%s' "$sha"
}

# Catch a private key pasted where a public one belongs. A private key on
# the VPS defeats the entire escrow design, and age itself would happily
# accept the string as a file path and fail later with something
# unhelpful.
backup_check_recipients() {
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
}

# ----- The stack's .env (secrets recovery) -----
#
# Every run backs up baja-infra/.env, encrypted to the ESCROW recipient
# ONLY. The verifier key is deliberately not a recipient: the verify
# stack, and anything else on the server, must never be able to read it,
# and nothing on the server ever tries. Only an escrow holder, restoring a
# server from nothing, decrypts it. See "Secrets" in docs/backup-restore.md.
#
# It is not stored in the run folder. A copy goes to secrets/<run_id>/
# only when the .env has changed since the last copy:
#
#     secrets/<run_id>/env.age  env.age.sha256  env.json   <- env.json last
#
# env.json says what the copy is: the sha256 of the PLAINTEXT .env and of
# the artifact. Comparing plaintexts is the only way to tell "unchanged",
# because age output differs on every run. Publishing that hash is safe
# for this file: it is a digest over dozens of long random secrets, which
# no one can guess their way to. Every run's manifest names the copy that
# matches the .env of that night, uploaded or not.

# Number of X25519 recipients an age file is encrypted to, read from its
# header without any key. age may add a random "grease" stanza; only
# X25519 ones are recipients.
backup_age_x25519_recipients() {
    head -c 65536 "$1" | LC_ALL=C awk '/^---/ { exit } /^-> X25519 / { n++ } END { print n + 0 }'
}

# The newest COMPLETE copy under secrets/ (one with its env.json), as
# "<folder>\t<plaintext sha256>\t<artifact sha256>", or nothing.
backup_env_last_uploaded() {
    local folders folder desc
    folders="$(rclone lsf --dirs-only "${BACKUP_REMOTE}secrets/" 2>/dev/null \
        | sed 's:/*$::' | grep -E '^[0-9]{8}T[0-9]{6}Z$' | sort -r)" || true
    for folder in $folders; do
        desc="$(rclone cat "${BACKUP_REMOTE}secrets/${folder}/env.json" 2>/dev/null)" || continue
        jq -e '.plaintext_sha256 and .sha256' >/dev/null 2>&1 <<< "$desc" || continue
        printf 'secrets/%s\t%s\t%s\n' "$folder" \
            "$(jq -r .plaintext_sha256 <<< "$desc")" "$(jq -r .sha256 <<< "$desc")"
        return 0
    done
}

# Decide whether tonight's .env needs a new copy, and stage it if so.
# Writes <run_dir>/env.manifest.json (for the manifest) and, when a copy
# is needed, <run_dir>/env.age, env.age.sha256 and env.json.
backup_env_stage() {
    local env_file="$1" run_dir="$2" run_id="$3"
    [[ -f "$env_file" && -r "$env_file" ]] \
        || die "the stack's .env is not readable at ${env_file} — it is mounted into the backup container by compose"

    local plain last folder sha uploaded
    plain="$(sha256sum < "$env_file" | cut -d' ' -f1)"
    last="$(backup_env_last_uploaded)"

    if [[ -n "$last" && "$(cut -f2 <<< "$last")" == "$plain" ]]; then
        folder="$(cut -f1 <<< "$last")"
        sha="$(cut -f3 <<< "$last")"
        uploaded=false
    else
        # ESCROW ONLY. Not backup_encrypt, which always adds the verifier.
        age -r "$AGE_RECIPIENT_ESCROW" -o "${run_dir}/env.age" "$env_file" \
            || die "age encryption of the .env failed"
        sha="$(sha256sum "${run_dir}/env.age" | cut -d' ' -f1)"
        printf '%s  %s\n' "$sha" env.age > "${run_dir}/env.age.sha256"
        folder="secrets/${run_id}"
        uploaded=true
        jq -n --arg run_id "$run_id" --arg plain "$plain" --arg sha "$sha" \
            '{ run_id: $run_id, file: "env.age", recipients: "escrow only",
               plaintext_sha256: $plain, sha256: $sha }' > "${run_dir}/env.json"
    fi

    jq -n --arg folder "$folder" --arg plain "$plain" --arg sha "$sha" --argjson uploaded "$uploaded" \
        '{ file: "env.age", folder: $folder, recipients: "escrow only",
           plaintext_sha256: $plain, sha256: $sha, uploaded_this_run: $uploaded }' \
        > "${run_dir}/env.manifest.json"
}

# Upload the staged copy, if there is one, env.json last. Before the run's
# own folders, so a manifest never names a copy that is not there yet.
backup_env_upload() {
    local run_dir="$1" folder
    [[ "$(jq -r .uploaded_this_run "${run_dir}/env.manifest.json")" == "true" ]] || return 0
    folder="$(jq -r .folder "${run_dir}/env.manifest.json")"
    rclone_put "${run_dir}/env.age"        "${folder}/env.age"
    rclone_put "${run_dir}/env.age.sha256" "${folder}/env.age.sha256"
    rclone_put "${run_dir}/env.json"       "${folder}/env.json"
}

# ----- Upload -----
#
# rclone is configured entirely through the environment, so no rclone.conf
# is written and the service-account path is the only thing on disk.
# RCLONE_CONFIG=/dev/null makes "no config file" explicit rather than
# accidental — otherwise a stray ~/.config/rclone/rclone.conf would
# silently take part.
#
# Callers set BACKUP_REMOTE ("gdrive:" in production) before calling.
backup_configure_rclone_writer() {
    export RCLONE_CONFIG=/dev/null
    if [[ "$BACKUP_REMOTE" == "gdrive:" ]]; then
        # -f, not -r: when the host file is missing, docker bind-mounts an
        # empty DIRECTORY in its place, which is readable and would only
        # fail later inside rclone with a confusing JSON error.
        [[ -f "$GDRIVE_SA_WRITER_JSON" && -r "$GDRIVE_SA_WRITER_JSON" ]] \
            || die "service-account key not readable at ${GDRIVE_SA_WRITER_JSON} (is it bind-mounted into the container, and does the host file exist?)"
        export RCLONE_CONFIG_GDRIVE_TYPE=drive
        export RCLONE_CONFIG_GDRIVE_SCOPE=drive
        export RCLONE_CONFIG_GDRIVE_SERVICE_ACCOUNT_FILE="$GDRIVE_SA_WRITER_JSON"
        export RCLONE_CONFIG_GDRIVE_TEAM_DRIVE="$GDRIVE_SHARED_DRIVE_ID"
    fi
}

rclone_put() {
    local src="$1" dest="$2"
    rclone copyto --retries 3 --low-level-retries 10 --checksum "$src" "${BACKUP_REMOTE}${dest}" \
        || die "upload of ${dest} failed — the folder is incomplete and MUST NOT be treated as a backup"
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
