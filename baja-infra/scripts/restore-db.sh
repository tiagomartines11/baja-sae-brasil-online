#!/usr/bin/env bash
# restore-db.sh — import one decrypted database dump under the name you
# choose, without touching the database it was taken from.
#
# Usage:
#   restore-db.sh <decrypted .sql> --into <name> [--replace] [--manifest <manifest.json>]
#   restore-db.sh -h | --help
#
# Example, from the host, to look at last night's baja_resultados beside
# the live one (docs/backup-restore.md, "Restore into a scratch database"):
#
#   scripts/restore-db.sh baja_resultados.sql --into baja_resultados_20261025 --manifest manifest.json
#
# WHY THIS EXISTS (BAJAAPP-4)
#
# Every dump is made with --add-drop-database (see dump_one in
# backup-db.sh), so it begins:
#
#     /*!40000 DROP DATABASE IF EXISTS `baja_resultados`*/;
#     CREATE DATABASE /*!32312 IF NOT EXISTS*/ `baja_resultados` ...;
#     USE `baja_resultados`;
#
# The first line looks like a comment and is not one: MySQL RUNS whatever
# is inside /*!NNNNN ... */ when its own version is NNNNN or later.
# Renaming only the CREATE DATABASE and USE lines to restore "into a copy"
# therefore still drops the live database, then loads the backup somewhere
# else. That happened on staging. Never edit a dump by hand to rename it;
# use this.
#
# WHAT IT DOES, IN ORDER. Nothing is sent to MySQL until every check passed.
#
#   1. Refuses a dump without mysqldump's "-- Dump completed" trailer: a
#      truncated dump imports part of the data and exits 0.
#   2. Refuses <name> if it is one of the live databases (baja_resultados,
#      phpbb_baja, phpbb_formula) unless --replace is given, and always
#      refuses MySQL's own schemas.
#   3. Writes a copy of the dump with the database name changed in every
#      DROP DATABASE, CREATE DATABASE and USE statement, the /*!NNNNN ... */
#      forms included.
#   4. Refuses if anything else executable in that copy still names the
#      original database (a view, a trigger, an ALTER DATABASE...), or if
#      the dump drops, creates or switches to any other database. The whole
#      copy is checked BEFORE the import starts: streaming it into mysql
#      would run every statement above the bad one first.
#   5. Imports the copy through the stack's mysql container. The rewritten
#      DROP replaces <name> if it already exists.
#   6. Prints each restored table's row count, next to the one the backup
#      recorded when --manifest is given.
#
# --replace is a real restore: <name> is dropped and recreated from the
# backup, as in step 4 of "Restore a database". It refuses to load one live
# database's dump into ANOTHER live database.
#
# Environment:
#   BAJA_ENV_FILE           the stack's .env (default: baja-infra/.env);
#                           read for ENV, which decides the compose files
#   RESTORE_DEFAULTS_FILE   test hook: a MySQL option file to connect with
#                           directly instead of through the mysql
#                           container (tests/backup/run-tests.sh)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA_DIR="$(dirname "$SCRIPT_DIR")"
LOG_PREFIX="restore"
# shellcheck source-path=SCRIPTDIR
# shellcheck source=./backup-lib.sh
source "$SCRIPT_DIR/backup-lib.sh"

COMPOSE=()
WORK_DIR=""

cleanup() {
    if [[ -n "$WORK_DIR" && -d "$WORK_DIR" ]]; then
        rm -rf -- "$WORK_DIR"
    fi
}

# ----- MySQL -----
#
# Through the stack's mysql container, as root. The password is expanded
# inside the container from its own environment, so it is never on this
# host's command line; MYSQL_PWD rather than -p keeps it off the
# container's too.
restore_mysql() {
    if [[ -n "${RESTORE_DEFAULTS_FILE:-}" ]]; then
        mysql --defaults-file="$RESTORE_DEFAULTS_FILE" "$@"
    else
        # shellcheck disable=SC2016
        "${COMPOSE[@]}" exec -T mysql sh -c \
            'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 "$@"' mysql "$@"
    fi
}

# backup-lib.sh's row counts go through these two. Redefined to use the
# connection above, so the counts printed here are taken exactly as the
# backup took the ones in the manifest.
db_query()      { restore_mysql -BN "$1" -e "$2"; }
db_query_nodb() { restore_mysql -BN -e "$1"; }

load_config() {
    [[ -n "${RESTORE_DEFAULTS_FILE:-}" ]] && return 0
    local env_file="${BAJA_ENV_FILE:-${INFRA_DIR}/.env}"
    [[ -f "$env_file" ]] || die "stack env not found: ${env_file}"
    load_env_file "$env_file"
    COMPOSE=(docker compose --project-directory "$INFRA_DIR" -f "${INFRA_DIR}/docker-compose.yml")
    if [[ "${ENV:-dev}" != "dev" ]]; then
        COMPOSE+=(-f "${INFRA_DIR}/docker-compose.prod.yml")
    fi
}

# ----- The rewrite -----
#
# restore_rewrite IN OUT TARGET: writes IN to OUT with the database renamed
# to TARGET, and prints "<original name>\t<drops>\t<creates>\t<uses>", the
# number of statements of each kind it rewrote. Fails, naming line numbers
# only (never their content: a dump line can be rows of personal data), if
# anything it does not rewrite would still act on a database.
#
# Perl, not sed or awk: deciding whether a name is inside a string needs a
# real regular expression engine, and the host's awk is mawk.
restore_rewrite() {
    local in="$1" out="$2" target="$3"
    ( umask 077; : > "$out" )
    # The perl program is in single quotes on purpose: its $ are its own.
    # shellcheck disable=SC2016
    perl -e '
        use strict; use warnings;
        my ($in, $out, $target) = @ARGV;

        # The three statements mysqldump writes about a database, one per
        # line, each optionally wrapped in a version comment:
        #   /*!40000 DROP DATABASE IF EXISTS `x`*/;
        #   CREATE DATABASE /*!32312 IF NOT EXISTS*/ `x` /*!40100 ... */;
        #   USE `x`;
        my $db_statement = qr{
            ^ (?:/\*!\d{5}\s*)?
            (?<kind> DROP\s+(?:DATABASE|SCHEMA)
                   | CREATE\s+(?:DATABASE|SCHEMA)
                   | USE )
            (?:\s+IF\s+(?:NOT\s+)?EXISTS)?
            (?:\s*/\*!\d{5}\s*IF\s+NOT\s+EXISTS\s*\*/)?
            \s+ `(?<name>[^`]+)`
        }xi;

        # Pass 1: the database the dump is of is the first one it names.
        my $orig;
        open(my $fh, "<", $in) or die "cannot read $in: $!\n";
        while (my $line = <$fh>) {
            if ($line =~ $db_statement) { $orig = $+{name}; last; }
        }
        close $fh;
        defined $orig or do {
            print STDERR "no DROP DATABASE, CREATE DATABASE or USE statement: not a dump made with --databases\n";
            exit 1;
        };

        # Pass 2.
        my %count = (DROP => 0, CREATE => 0, USE => 0);
        my @problems;
        open($fh, "<", $in) or die "cannot read $in: $!\n";
        open(my $oh, ">", $out) or die "cannot write $out: $!\n";
        while (my $line = <$fh>) {
            if ($line =~ /^--(?:\s|$)/) { print $oh $line; next; }

            if ($line =~ $db_statement) {
                # Read the match before anything else runs a regex.
                my ($name, $at, $kind) = ($+{name}, $-[2], $+{kind});
                $kind = uc((split /\s+/, $kind)[0]);
                if ($name ne $orig) {
                    push @problems, "line $.: $kind names `$name`, a second database";
                } else {
                    # Replace exactly the backquoted name that matched.
                    substr($line, $at - 1, length($name) + 2) = "`$target`";
                    $count{$kind}++;
                }
                print $oh $line;
                next;
            }

            # Everything else: blank out string literals, keep backquoted
            # names, then look for the original name and for any other
            # statement about a database.
            (my $code = $line) =~ s/(`[^`]*`)|\x27(?:[^\x27\\]|\\.)*\x27|"(?:[^"\\]|\\.)*"/defined $1 ? $1 : "\x27\x27"/ge;
            if ($code =~ /`\Q$orig\E`/ || $code =~ /(?<![\w\$`])\Q$orig\E(?![\w\$`])/) {
                push @problems, "line $.: names the original database outside DROP/CREATE DATABASE and USE";
            } elsif ($code =~ /\b(?:CREATE|DROP|ALTER)\s+(?:DATABASE|SCHEMA)\b/i
                     || $code =~ m{^\s*(?:/\*!\d{5}\s*)?USE\s}i) {
                push @problems, "line $.: a database statement this script cannot rewrite";
            }
            print $oh $line;
        }
        close $fh;
        close $oh or die "cannot write $out: $!\n";

        if (@problems) {
            print STDERR "$_\n" for @problems[0 .. ($#problems < 9 ? $#problems : 9)];
            print STDERR "... and ", @problems - 10, " more\n" if @problems > 10;
            exit 1;
        }
        print join("\t", $orig, $count{DROP}, $count{CREATE}, $count{USE}), "\n";
    ' "$in" "$out" "$target"
}

# ----- Row counts -----
#
# "<table>  <restored>  <manifest>" for every table in either, flagging the
# ones that differ. Without a manifest, the restored counts alone.
print_counts() {
    local target="$1" orig="$2" manifest="$3"
    local restored expected='{}'
    restored="$(backup_table_counts "$target" \
        | jq -Rn '[inputs | select(length > 0) | split("\t") | {(.[0]): (.[1] | tonumber)}] | add // {}')"
    if [[ -n "$manifest" ]]; then
        expected="$(jq -c --arg d "$orig" '.artifacts[$d].table_counts // {}' "$manifest")"
        if [[ "$expected" == "{}" ]]; then
            warn "${manifest} has no row counts for ${orig}; it is not the manifest of this dump's run"
        fi
    fi
    local table
    # shellcheck disable=SC2016  # jq variables, not shell ones
    table="$(jq -rn --argjson a "$expected" --argjson b "$restored" --arg m "${manifest:+manifest}" '
        (["table", "restored", $m] | @tsv),
        ( ((($a | keys) + ($b | keys)) | unique)[] as $k
          | ($b[$k] // "-") as $r | ($a[$k] // "-") as $e
          | [$k, ($r | tostring), (if $m == "" then "" else ($e | tostring) end),
             (if $m != "" and $r != $e then "DIFFERS" else "" end)] | @tsv )' \
        | awk -F'\t' '{ printf "  %-32s %10s %10s  %s\n", $1, $2, $3, $4 }')"
    printf '%s\n' "$table"
    if grep -q 'DIFFERS$' <<< "$table"; then
        warn "row counts differ from the manifest (see DIFFERS above)"
    fi
}

usage() {
    sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" \
        | sed -e '/^set -euo pipefail$/d' -e 's/^# \{0,1\}//'
}

main() {
    local dump="" target="" replace=no manifest=""
    while (( $# > 0 )); do
        case "$1" in
            -h|--help) usage; exit 0 ;;
            --into) [[ $# -ge 2 ]] || die "--into needs a database name"; target="$2"; shift 2 ;;
            --replace) replace=yes; shift ;;
            --manifest) [[ $# -ge 2 ]] || die "--manifest needs a file"; manifest="$2"; shift 2 ;;
            -*) err "unknown option: $1"; usage >&2; exit 2 ;;
            *) [[ -z "$dump" ]] || die "one dump at a time (got '${dump}' and '$1')"; dump="$1"; shift ;;
        esac
    done
    [[ -n "$dump" && -n "$target" ]] || { usage >&2; exit 2; }

    [[ -f "$dump" && -r "$dump" ]] || die "cannot read ${dump}"
    if [[ "$(head -c 2 "$dump" | od -An -tx1 | tr -d ' ')" == "1f8b" ]]; then
        die "${dump} is still gzip-compressed — decompress it first: gunzip -c ${dump} > ${dump%.gz}"
    fi
    tail -c 4096 "$dump" | grep -q '^-- Dump completed' \
        || die "${dump} has no '-- Dump completed' trailer — it is TRUNCATED; importing it would restore part of the data without an error. Download and decrypt it again, or use the previous run"

    [[ "$target" =~ ^[A-Za-z0-9_]{1,64}$ ]] \
        || die "--into '${target}': use letters, digits and underscores only (at most 64)"
    case "$target" in
        mysql|sys|information_schema|performance_schema)
            die "--into ${target}: that is MySQL's own schema" ;;
    esac
    local live=no db
    for db in "${BACKUP_DATABASES[@]}"; do
        if [[ "$target" == "$db" ]]; then live=yes; fi
    done
    if [[ "$live" == yes && "$replace" != yes ]]; then
        die "--into ${target}: that is a LIVE database. Restoring into it DROPS it and replaces it with the backup. If that is what you mean, add --replace; to look at the backup beside it, choose another name, e.g. --into ${target}_$(date -u +%Y%m%d)"
    fi
    if [[ -n "$manifest" ]]; then
        jq empty "$manifest" 2>/dev/null || die "${manifest} is not valid JSON"
    fi

    load_config
    WORK_DIR="$(mktemp -d "$(dirname "$dump")/.restore-db.XXXXXX")"
    chmod 700 "$WORK_DIR"
    trap cleanup EXIT
    trap 'exit 143' TERM
    trap 'exit 130' INT

    local rewritten="${WORK_DIR}/rewritten.sql" summary orig drops creates uses
    summary="$(restore_rewrite "$dump" "$rewritten" "$target")" \
        || die "refusing to import ${dump}: see above. Nothing was sent to MySQL"
    IFS=$'\t' read -r orig drops creates uses <<< "$summary"
    log "${dump} is a dump of ${orig}; rewrote ${drops} DROP DATABASE, ${creates} CREATE DATABASE and ${uses} USE to ${target}"

    if [[ "$replace" == yes && "$live" == yes && "$orig" != "$target" ]]; then
        die "--into ${target} --replace with a dump of ${orig}: that would put one live database's data in another. Nothing was sent to MySQL"
    fi
    (( creates > 0 || uses > 0 )) || die "the dump never creates or selects ${orig}; nothing would land in ${target}"

    restore_mysql -BN -e "SELECT 1" >/dev/null \
        || die "cannot reach MySQL through the stack's mysql container — is the stack up?"
    local exists orig_existed
    exists="$(db_query_nodb "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${target}'")"
    orig_existed="$(db_query_nodb "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${orig}'")"
    if (( exists > 0 )); then
        if (( drops == 0 )); then
            die "${target} exists and this dump does not drop it (made without --add-drop-database): importing would MERGE into it. Drop it yourself first, or choose another name"
        fi
        log "${target} exists; the import drops it and recreates it from the dump"
    fi

    log "importing into ${target}"
    restore_mysql < "$rewritten" || die "import into ${target} failed — ${target} is incomplete; drop it"
    rm -f -- "$rewritten"

    # Belt and braces: the database the dump came from must still be there.
    if [[ "$orig" != "$target" ]] && (( orig_existed > 0 )) \
       && [[ "$(db_query_nodb "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '${orig}'")" != "1" ]]; then
        die "${orig} existed before the import and is GONE now. Restore it from the backup at once (docs/backup-restore.md, Restore a database), and report this as a bug in restore-db.sh"
    fi

    log "restored ${orig} as ${target}; row counts:"
    print_counts "$target" "$orig" "$manifest"
    if [[ "$orig" != "$target" ]]; then
        log "this is decrypted backup data on a live server: DROP DATABASE ${target} as soon as you are done"
    fi
}

# Sourced by baja-infra/tests/backup/run-tests.sh, which calls
# restore_rewrite on its own; run as a command otherwise.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
