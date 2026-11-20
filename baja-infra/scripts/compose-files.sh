# shellcheck shell=bash
#
# compose-files.sh — which docker-compose files a script runs compose with.
# Sourced, never run: backup-run.sh, restore-db.sh and migrate.sh share it.
#
# WHY
#
# Plain `docker compose` picks its files from COMPOSE_FILE in .env when that
# is set (staging sets it, to add docker-compose.staging.yml), and only falls
# back to docker-compose.yml when it is not. A script that passes its own
# `-f` flags overrides COMPOSE_FILE entirely. These scripts used to pass
# `-f docker-compose.yml -f docker-compose.prod.yml` unconditionally, so on
# staging any of them could recreate nginx WITHOUT the staging file — that
# is, with host ports 80 and 443, which the VPS's Caddy owns.
#
# compose_file_args DIR DEFAULT...
#
#   Sets the array COMPOSE_FILE_ARGS to "-f FILE" pairs:
#     - from COMPOSE_FILE when it is set and not empty (the .env must already
#       be loaded, so that it is in the environment);
#     - otherwise from the DEFAULT file names given, which is each script's
#       behaviour from before COMPOSE_FILE existed.
#   Relative names are taken relative to DIR (baja-infra/), as compose does
#   for the COMPOSE_FILE in that folder's .env. The list is split on
#   COMPOSE_PATH_SEPARATOR, ":" unless set, again as compose does.
#
# Example:
#   compose_file_args "$INFRA_DIR" docker-compose.yml docker-compose.prod.yml
#   docker compose "${COMPOSE_FILE_ARGS[@]}" exec -T mysql ...

COMPOSE_FILE_ARGS=()

compose_file_args() {
    local dir="$1" file
    shift
    local files=("$@")
    if [[ -n "${COMPOSE_FILE:-}" ]]; then
        IFS="${COMPOSE_PATH_SEPARATOR:-:}" read -r -a files <<< "$COMPOSE_FILE"
    fi
    COMPOSE_FILE_ARGS=()
    for file in "${files[@]}"; do
        # "a.yml::b.yml" or a trailing ":" would give an empty name; skip it
        # rather than hand compose a bare "-f".
        [[ -n "$file" ]] || continue
        [[ "$file" == /* ]] || file="${dir}/${file}"
        COMPOSE_FILE_ARGS+=(-f "$file")
    done
}
