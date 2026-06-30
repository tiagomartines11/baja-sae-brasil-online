#!/usr/bin/env bash
# migrate.sh — production -> staging data migration (Tailscale pull).
#
# Pulls a full copy of production data — three MySQL databases and two
# phpBB instances' uploaded files — onto the containerized stack on the
# staging VM (baja-teste1) so it can be validated against real data.
#
#   INVARIANT: this script is STRICTLY READ-ONLY with respect to production.
#   It runs mysqldump and rsync PULLS. It must never write to, modify, or
#   rsync TO production. Phases that would do so are not implemented here.
#
# All production access is over Tailscale, authenticated by SSH key.
#
# Usage:
#   ./migrate.sh preflight   # checks only, no changes
#   ./migrate.sh dump        # mysqldump prod -> WORK_DIR
#   ./migrate.sh import      # load dumps into staging DBs (with rename)
#   ./migrate.sh patch       # staging-specific SQL (SAFETY-CRITICAL: disables email)
#   ./migrate.sh files       # rsync prod uploads -> staging bind-mount dirs
#   ./migrate.sh fixup       # chown to UID 82, restart phpBB, purge cache
#   ./migrate.sh verify      # automated sanity checks + manual-check checklist
#   ./migrate.sh all         # preflight -> ... -> fixup, then print verify checklist
#   ./migrate.sh clean       # delete dump files in WORK_DIR (optional, post-verify)
#   ./migrate.sh -h | --help
#
# Preconditions (preflight verifies what it can):
#   - Tailscale up on prod, joined to the same tailnet as staging.
#   - PROD_SSH_KEY present on staging VM (absolute path, chmod 600).
#   - Staging stack running via prod overlay; MySQL healthy.
#   - Both prod phpBB instances use the default `phpbb_` table prefix.
#   - Prod Formula has been upgraded to phpBB 3.3.15 BEFORE the dump.
#     If not, Phase 5 falls back to `db:migrate` but the intended path
#     is upgrade-prod-first.
#   - Either invoke with sudo, or the invoking user has docker-group access
#     and write access to /srv/baja.
#
# Database name mapping (CRITICAL):
#   prod  baja_resultados -> staging  baja_resultados   (same name)
#   prod  baja_forum      -> staging  phpbb_baja        (RENAME)
#   prod  formula         -> staging  phpbb_formula     (RENAME)
# Dumps are taken WITHOUT --databases so the import can pick the target.

set -euo pipefail

# Resolve our own location so paths are CWD-independent.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA_DIR="$(dirname "$SCRIPT_DIR")"
COMPOSE_FILES=(-f "$INFRA_DIR/docker-compose.yml" -f "$INFRA_DIR/docker-compose.prod.yml")

# ----- Logging helpers -----
log()  { printf '[migrate] %s\n' "$*"; }
warn() { printf '[migrate] WARNING: %s\n' "$*" >&2; }
err()  { printf '[migrate] ERROR: %s\n' "$*" >&2; }
die()  { err "$*"; exit 1; }

# ----- Config loading -----
load_config() {
    local stack_env="$INFRA_DIR/.env"
    local mig_env="$SCRIPT_DIR/migration.env"

    [[ -f "$stack_env" ]] || die "stack env not found: $stack_env"
    [[ -f "$mig_env"   ]] || die "migration secrets not found: $mig_env (copy migration.env.example and fill it in)"

    # Source without echoing — both files contain secrets. `set -a` exports
    # everything they define so it's visible to docker compose / mysqldump.
    set -a
    # shellcheck disable=SC1090
    source "$stack_env"
    # shellcheck disable=SC1090
    source "$mig_env"
    set +a

    # Production DB names default to the historical setup. Override via
    # migration.env if prod has been renamed.
    : "${PROD_BAJA_RESULTADOS_DB:=baja_resultados}"
    : "${PROD_BAJA_FORUM_DB:=baja_forum}"
    : "${PROD_FORMULA_DB:=formula}"

    local missing=()
    local var
    for var in \
        MYSQL_ROOT_PASSWORD \
        BAJA_COOKIE_DOMAIN FORMULA_COOKIE_DOMAIN \
        PHPBB_COOKIE_PREFIX \
        PROD_TAILSCALE_IP PROD_SSH_USER PROD_SSH_KEY \
        PROD_BAJA_RESULTADOS_USER PROD_BAJA_RESULTADOS_PASSWORD \
        PROD_BAJA_FORUM_USER      PROD_BAJA_FORUM_PASSWORD \
        PROD_FORMULA_USER         PROD_FORMULA_PASSWORD \
        PROD_BAJA_FORUM_PATH PROD_FORMULA_FORUM_PATH \
        STAGING_BAJA_FORUM_DOMAIN STAGING_FORMULA_FORUM_DOMAIN \
        WORK_DIR
    do
        if [[ -z "${!var:-}" ]]; then
            missing+=("$var")
        fi
    done
    if (( ${#missing[@]} > 0 )); then
        die "required config vars unset: ${missing[*]}"
    fi

    # PROD_SSH_KEY must be absolute — sudo-runs would otherwise expand ~ to /root.
    [[ "$PROD_SSH_KEY" = /* ]] || die "PROD_SSH_KEY must be an absolute path (got: $PROD_SSH_KEY)"
    [[ "$WORK_DIR"     = /* ]] || die "WORK_DIR must be an absolute path (got: $WORK_DIR)"
}

# Run a command on the prod node over SSH. Wrappers exist so the SSH key /
# user / host string isn't repeated in every phase.
prod_ssh() {
    ssh -i "$PROD_SSH_KEY" \
        -o BatchMode=yes -o StrictHostKeyChecking=accept-new \
        "$PROD_SSH_USER@$PROD_TAILSCALE_IP" "$@"
}

# Run mysql in the staging mysql container, sending the script's stdin as
# the SQL to execute. Uses staging's root password from .env.
stage_mysql() {
    docker compose "${COMPOSE_FILES[@]}" exec -T mysql \
        mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$@"
}

# ----- Phase: preflight -----
phase_preflight() {
    log "preflight: checking tools, paths, connectivity..."

    local tool
    for tool in ssh rsync docker; do
        command -v "$tool" >/dev/null 2>&1 || die "$tool not found in PATH"
    done

    [[ -f "$PROD_SSH_KEY" ]] || die "SSH key not found at $PROD_SSH_KEY"
    local perms
    perms=$(stat -c '%a' "$PROD_SSH_KEY")
    [[ "$perms" = "600" ]] || warn "$PROD_SSH_KEY has perms $perms (recommended: 600)"

    # WORK_DIR — create with 0700 if missing.
    if [[ ! -d "$WORK_DIR" ]]; then
        log "creating WORK_DIR $WORK_DIR (0700)"
        mkdir -p "$WORK_DIR"
        chmod 700 "$WORK_DIR"
    else
        perms=$(stat -c '%a' "$WORK_DIR")
        [[ "$perms" = "700" ]] || warn "WORK_DIR perms are $perms (expected 700); tightening"
        chmod 700 "$WORK_DIR"
    fi

    # Bind-mount dirs must exist for the prod overlay to mount cleanly.
    local d
    for d in \
        /srv/baja/phpbb-baja/files /srv/baja/phpbb-baja/avatars \
        /srv/baja/phpbb-formula/files /srv/baja/phpbb-formula/avatars
    do
        [[ -d "$d" ]] || die "missing bind-mount dir: $d (compose prod overlay needs it)"
    done

    log "preflight: testing tailnet reachability to $PROD_TAILSCALE_IP..."
    if command -v tailscale >/dev/null 2>&1; then
        tailscale ping --c 1 --timeout 5s "$PROD_TAILSCALE_IP" >/dev/null \
            || warn "tailscale ping to $PROD_TAILSCALE_IP did not succeed (continuing — ssh may still work)"
    else
        warn "tailscale CLI not on PATH; skipping ping check"
    fi

    log "preflight: testing SSH to prod..."
    prod_ssh -o ConnectTimeout=10 'echo prod-ssh-ok' >/dev/null \
        || die "SSH to $PROD_SSH_USER@$PROD_TAILSCALE_IP failed (key: $PROD_SSH_KEY)"

    log "preflight: checking mysqldump exists on prod..."
    prod_ssh 'command -v mysqldump >/dev/null' \
        || die "mysqldump not found on prod node"

    log "preflight: checking staging MySQL is healthy..."
    if ! stage_mysql -e 'SELECT 1' >/dev/null 2>&1; then
        die "staging MySQL container is not accepting queries as root"
    fi

    log "preflight: checking expected phpbb_ table prefix in prod DBs..."
    # Authenticate per-DB — prod's root user does not have access to the
    # phpBB schemas. Each user only sees its own DB, so the prefix probe
    # queries the DB directly rather than information_schema.
    check_prefix() {
        local db="$1" user="$2" pw="$3"
        local pw_q
        pw_q=$(printf '%q' "$pw")
        # Selecting 1 row from phpbb_config succeeds iff the prefix is right.
        if ! prod_ssh "mysql -u$(printf '%q' "$user") -p${pw_q} -BN -e \"SELECT 1 FROM \\\`${db}\\\`.phpbb_config LIMIT 1\"" >/dev/null 2>&1; then
            die "prod DB '$db' has no phpbb_config (wrong table prefix? expected 'phpbb_'; or wrong creds for '$user')"
        fi
    }
    check_prefix "$PROD_BAJA_FORUM_DB" "$PROD_BAJA_FORUM_USER" "$PROD_BAJA_FORUM_PASSWORD"
    check_prefix "$PROD_FORMULA_DB"    "$PROD_FORMULA_USER"    "$PROD_FORMULA_PASSWORD"

    # Informational: prod Formula's stored phpBB version (3.3.15 expected).
    # Queried as the formula user (root can't read the DB).
    local formula_pw_q formula_version
    formula_pw_q=$(printf '%q' "$PROD_FORMULA_PASSWORD")
    formula_version=$(prod_ssh "mysql -u$(printf '%q' "$PROD_FORMULA_USER") -p${formula_pw_q} -BN -e \"SELECT config_value FROM \\\`${PROD_FORMULA_DB}\\\`.phpbb_config WHERE config_name='version'\"" 2>/dev/null) \
        || formula_version="<unknown>"
    log "preflight: prod Formula phpBB version reports: $formula_version (expected 3.3.15 BEFORE migration)"
    if [[ -n "$formula_version" && "$formula_version" != "3.3.15" && "$formula_version" != "<unknown>" ]]; then
        warn "prod Formula is not 3.3.15. Phase 5 will run db:migrate as a fallback, but upgrade prod first if possible."
    fi

    log "preflight: OK"
}

# ----- Phase: dump -----
# CRITICAL: pass passwords via the remote command string. Never `set -x` here
# (and the script never enables -x globally — but be explicit).
#
# Authenticates per-DB: prod's root has no access to the phpBB schemas, so
# each dump runs as that DB's own user. Standard phpBB grants (SELECT, LOCK
# TABLES, SHOW VIEW) are enough.
dump_one() {
    # $1: source DB name on prod (may have been renamed there)
    # $2: dump filename base (stable — keyed by the import phase)
    # $3: user, $4: password
    local prod_db="$1" dump_base="$2" user="$3" pw="$4"
    local out="$WORK_DIR/${dump_base}.sql"
    local mysqldump_opts="--single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4"
    local user_q pw_q
    user_q=$(printf '%q' "$user")
    pw_q=$(printf '%q' "$pw")

    log "dump: $prod_db (as $user) -> $out"
    # NOTE: not using --databases, so the dump has no CREATE DATABASE/USE
    # and can be loaded into a renamed target on the staging side.
    prod_ssh "mysqldump -u${user_q} -p${pw_q} ${mysqldump_opts} ${prod_db}" > "$out"
    chmod 600 "$out"
    # Sanity: dumps from `mysqldump` end with `-- Dump completed`. If the
    # remote command failed mid-stream the file may be truncated or empty.
    if [[ ! -s "$out" ]]; then
        die "dump of $prod_db produced an empty file"
    fi
    if ! tail -c 4096 "$out" | grep -q 'Dump completed'; then
        warn "dump of $prod_db does not end with 'Dump completed' marker — may be truncated"
    fi
}

phase_dump() {
    log "dump: pulling production database dumps -> $WORK_DIR"
    [[ -d "$WORK_DIR" ]] || die "WORK_DIR $WORK_DIR missing — run preflight first"

    # Dump filenames are stable (the import phase keys off them); only the
    # source DB name on prod is variable.
    dump_one "$PROD_BAJA_RESULTADOS_DB" baja_resultados "$PROD_BAJA_RESULTADOS_USER" "$PROD_BAJA_RESULTADOS_PASSWORD"
    dump_one "$PROD_BAJA_FORUM_DB"      baja_forum      "$PROD_BAJA_FORUM_USER"      "$PROD_BAJA_FORUM_PASSWORD"
    dump_one "$PROD_FORMULA_DB"         formula         "$PROD_FORMULA_USER"         "$PROD_FORMULA_PASSWORD"

    log "dump: OK"
}

# ----- Phase: import -----
# Drops/recreates the target DB then loads the dump. Per-DB user grants
# survive DROP DATABASE (they live in mysql.db / mysql.tables_priv).
import_one() {
    local src="$1" target="$2" check_user="$3" check_pw="$4"
    [[ -f "$src" ]] || die "dump file missing: $src (run 'dump' first)"

    log "import: $src -> $target (drop+recreate, then load)"
    stage_mysql -e "DROP DATABASE IF EXISTS \`$target\`; CREATE DATABASE \`$target\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    docker compose "${COMPOSE_FILES[@]}" exec -T mysql \
        mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$target" < "$src"

    if [[ -n "$check_user" ]]; then
        # Confirm the per-DB user can still authenticate after the drop+recreate.
        log "import: verifying user '$check_user' can still SELECT from $target"
        if ! docker compose "${COMPOSE_FILES[@]}" exec -T mysql \
                mysql -u"$check_user" -p"$check_pw" "$target" -e "SELECT 1" >/dev/null 2>&1; then
            die "user '$check_user' cannot connect to $target after import — grants may need to be re-applied"
        fi
    fi
}

phase_import() {
    log "import: loading dumps into staging (with rename mapping)"

    import_one "$WORK_DIR/baja_resultados.sql" baja_resultados resultados      "$MYSQL_RESULTADOS_PASSWORD"
    import_one "$WORK_DIR/baja_forum.sql"      phpbb_baja      phpbb_baja      "$MYSQL_PHPBB_BAJA_PASSWORD"
    import_one "$WORK_DIR/formula.sql"         phpbb_formula   phpbb_formula   "$MYSQL_PHPBB_FORMULA_PASSWORD"

    log "import: OK"
}

# ----- Phase: patch  (SAFETY-CRITICAL) -----
# Disable email FIRST. Imported config contains prod's real SMTP settings;
# any cron/registration/notification path on staging would otherwise mail
# real users from a staging copy. Non-negotiable.
patch_one() {
    local target_db="$1" cookie_domain="$2" forum_domain="$3"
    log "patch: $target_db (cookie_domain=$cookie_domain, server_name=$forum_domain)"

    # Heredoc piped into mysql via the container. All UPDATEs are idempotent
    # — re-running yields the same end state. The [TEST] marker is guarded
    # by a NOT LIKE so it doesn't stack on re-runs.
    stage_mysql "$target_db" <<SQL
UPDATE phpbb_config SET config_value='0'  WHERE config_name='email_enable';
UPDATE phpbb_config SET config_value='0'  WHERE config_name='smtp_delivery';
UPDATE phpbb_config SET config_value='${cookie_domain}' WHERE config_name='cookie_domain';
UPDATE phpbb_config SET config_value='${forum_domain}'  WHERE config_name='server_name';
UPDATE phpbb_config SET config_value=CONCAT(config_value,' [TEST]')
    WHERE config_name='sitename' AND config_value NOT LIKE '% [TEST]';
-- Optional, for HTTPS staging behind Cloudflare. Leave commented unless
-- sessions misbehave; flipping these on without a fronting cert breaks login.
-- UPDATE phpbb_config SET config_value='https://' WHERE config_name='server_protocol';
-- UPDATE phpbb_config SET config_value='1'        WHERE config_name='cookie_secure';
SQL

    # Cookie-prefix check — surface, don't auto-fix .env. The session shim
    # reads PHPBB_COOKIE_PREFIX; the imported DB carries prod's value.
    local imported_cookie
    imported_cookie=$(stage_mysql -BN "$target_db" \
        -e "SELECT config_value FROM phpbb_config WHERE config_name='cookie_name'" 2>/dev/null | tr -d '\r' || true)
    if [[ "$target_db" = "phpbb_baja" && -n "$imported_cookie" ]]; then
        if [[ "$imported_cookie" != "$PHPBB_COOKIE_PREFIX" ]]; then
            warn "phpbb_baja cookie_name='$imported_cookie' does not match PHPBB_COOKIE_PREFIX='$PHPBB_COOKIE_PREFIX' in .env."
            warn "  -> The session shim will NOT find sessions. Align PHPBB_COOKIE_PREFIX in baja-infra/.env to '$imported_cookie',"
            warn "     or update phpbb_baja's cookie_name. Then 'docker compose ... up -d' the baja-app service."
        fi
    fi
}

phase_patch() {
    log "patch: applying staging-specific SQL (disables email; re-runs are safe)"
    patch_one phpbb_baja    "$BAJA_COOKIE_DOMAIN"    "$STAGING_BAJA_FORUM_DOMAIN"
    patch_one phpbb_formula "$FORMULA_COOKIE_DOMAIN" "$STAGING_FORMULA_FORUM_DOMAIN"

    # Belt-and-suspenders: assert email is actually disabled in both DBs.
    local db
    for db in phpbb_baja phpbb_formula; do
        local ee sd
        ee=$(stage_mysql -BN "$db" -e "SELECT config_value FROM phpbb_config WHERE config_name='email_enable'"  | tr -d '\r')
        sd=$(stage_mysql -BN "$db" -e "SELECT config_value FROM phpbb_config WHERE config_name='smtp_delivery'" | tr -d '\r')
        [[ "$ee" = "0" && "$sd" = "0" ]] || die "$db: email NOT disabled (email_enable=$ee smtp_delivery=$sd) — refusing to continue"
    done

    log "patch: OK (email disabled in phpbb_baja and phpbb_formula)"
}

# ----- Phase: files -----
# Pull only. Trailing slashes are deliberate: sync the CONTENTS of source
# into the named destination. --delete keeps re-runs idempotent.
rsync_pull() {
    local src_remote="$1" dest_local="$2"
    log "rsync: $src_remote -> $dest_local"
    [[ -d "$dest_local" ]] || die "destination missing: $dest_local"
    rsync -avz --delete --info=progress2 \
        -e "ssh -i $PROD_SSH_KEY -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
        "$PROD_SSH_USER@$PROD_TAILSCALE_IP:$src_remote" "$dest_local"
}

phase_files() {
    log "files: pulling phpBB user-content from prod (pull-only, --delete)"

    rsync_pull "$PROD_BAJA_FORUM_PATH/files/"                  /srv/baja/phpbb-baja/files/
    rsync_pull "$PROD_BAJA_FORUM_PATH/images/avatars/upload/"  /srv/baja/phpbb-baja/avatars/
    rsync_pull "$PROD_FORMULA_FORUM_PATH/files/"               /srv/baja/phpbb-formula/files/
    rsync_pull "$PROD_FORMULA_FORUM_PATH/images/avatars/upload/" /srv/baja/phpbb-formula/avatars/

    log "files: OK"
}

# ----- Phase: fixup -----
# Files land owned by prod's www-data (UID 33). Container www-data is UID 82
# on this image. Without normalizing, phpBB can't manage attachments.
phase_fixup() {
    log "fixup: chown bind-mount dirs to 82:82 (container www-data)"
    chown -R 82:82 \
        /srv/baja/phpbb-baja/files /srv/baja/phpbb-baja/avatars \
        /srv/baja/phpbb-formula/files /srv/baja/phpbb-formula/avatars

    log "fixup: restarting phpBB containers (re-runs entrypoint -> enables baja/auth)"
    docker compose "${COMPOSE_FILES[@]}" restart phpbb-baja phpbb-formula

    # Give the entrypoint a moment to finish before issuing CLI commands.
    sleep 5

    log "fixup: purging phpBB cache (belt-and-suspenders)"
    docker compose "${COMPOSE_FILES[@]}" exec -T phpbb-baja \
        su-exec www-data php bin/phpbbcli.php cache:purge || warn "phpbb-baja cache:purge returned nonzero"
    docker compose "${COMPOSE_FILES[@]}" exec -T phpbb-formula \
        su-exec www-data php bin/phpbbcli.php cache:purge || warn "phpbb-formula cache:purge returned nonzero"

    # Formula version-mismatch fallback: only kicks in if prod Formula was
    # not upgraded to 3.3.15 before this run. db:migrate is idempotent.
    log "fixup: running phpbb-formula db:migrate (no-op if schemas already match)"
    docker compose "${COMPOSE_FILES[@]}" exec -T phpbb-formula \
        su-exec www-data php bin/phpbbcli.php db:migrate || warn "phpbb-formula db:migrate returned nonzero"

    log "fixup: OK"
}

# ----- Phase: verify -----
phase_verify() {
    log "verify: automated sanity checks"

    local baja_users formula_users res_count
    baja_users=$(stage_mysql -BN phpbb_baja      -e "SELECT COUNT(*) FROM phpbb_users" | tr -d '\r')
    formula_users=$(stage_mysql -BN phpbb_formula -e "SELECT COUNT(*) FROM phpbb_users" | tr -d '\r')
    # baja_resultados has many tables; pick a stable representative one.
    res_count=$(stage_mysql -BN baja_resultados -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='baja_resultados'" | tr -d '\r')

    log "  phpbb_baja.phpbb_users:    $baja_users rows"
    log "  phpbb_formula.phpbb_users: $formula_users rows"
    log "  baja_resultados:           $res_count tables present"

    # Hard fail if email isn't disabled — last line of defence.
    local db ee sd
    for db in phpbb_baja phpbb_formula; do
        ee=$(stage_mysql -BN "$db" -e "SELECT config_value FROM phpbb_config WHERE config_name='email_enable'"  | tr -d '\r')
        sd=$(stage_mysql -BN "$db" -e "SELECT config_value FROM phpbb_config WHERE config_name='smtp_delivery'" | tr -d '\r')
        [[ "$ee" = "0" && "$sd" = "0" ]] \
            || die "$db: email NOT disabled (email_enable=$ee smtp_delivery=$sd) — STOP. Re-run 'patch' phase."
    done
    log "  email disabled in phpbb_baja and phpbb_formula: confirmed"

    cat <<'EOF'

----- manual checklist (operator) -----
[ ] Both forums load over HTTPS on their staging domains.
[ ] An existing attachment downloads (proves phpbb_baja/files + perms).
[ ] An existing avatar renders (proves images/avatars/upload + perms).
[ ] Logging into the forum, then visiting a baja-app subdomain, works
    (exercises the baja/auth + session shim end-to-end).
[ ] The board title shows the trailing ' [TEST]' marker on both forums.
[ ] Sending a forum PM does NOT email any real user (email_enable=0 above).

If sessions misbehave behind a TLS terminator (e.g. Cloudflare), see the
commented-out server_protocol/cookie_secure lines in the patch SQL.
EOF
}

# ----- Phase: clean (optional) -----
phase_clean() {
    log "clean: removing dump files in $WORK_DIR"
    [[ -d "$WORK_DIR" ]] || { log "clean: WORK_DIR does not exist, nothing to do"; return 0; }
    local f
    for f in baja_resultados.sql baja_forum.sql formula.sql; do
        if [[ -f "$WORK_DIR/$f" ]]; then
            log "  removing $WORK_DIR/$f"
            rm -f "$WORK_DIR/$f"
        fi
    done
    log "clean: OK"
}

# ----- Dispatch -----
usage() {
    sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" \
        | sed -e '/^set -euo pipefail$/d' -e 's/^# \{0,1\}//'
}

main() {
    local cmd="${1:-}"
    case "$cmd" in
        -h|--help|help|"")
            usage
            [[ -z "$cmd" ]] && exit 1 || exit 0
            ;;
        preflight) load_config; phase_preflight ;;
        dump)      load_config; phase_dump      ;;
        import)    load_config; phase_import    ;;
        patch)     load_config; phase_patch     ;;
        files)     load_config; phase_files     ;;
        fixup)     load_config; phase_fixup     ;;
        verify)    load_config; phase_verify    ;;
        clean)     load_config; phase_clean     ;;
        all)
            load_config
            phase_preflight
            phase_dump
            phase_import
            phase_patch
            phase_files
            phase_fixup
            log "all: phases complete. Running verify..."
            phase_verify
            log "all: DONE."
            ;;
        *)
            err "unknown subcommand: $cmd"
            usage
            exit 2
            ;;
    esac
}

main "$@"
