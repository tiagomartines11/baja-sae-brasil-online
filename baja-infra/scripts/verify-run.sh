#!/usr/bin/env bash
# verify-run.sh — host-side lifecycle for the weekly restore verification.
#
# This is what cron calls. It owns everything that cannot be done from
# inside a container: bringing the scratch stack up, measuring what it
# costs the host, destroying it afterwards, and PROVING the destruction
# happened. The checks themselves live in verify-db.sh, which runs inside
# the verifier container.
#
# Usage:
#   ./verify-run.sh          # the whole cycle
#   ./verify-run.sh -h | --help
#
# Cron (Tuesdays 04:00 America/Sao_Paulo):
#   0 4 * * 2 cd /srv/baja/baja-sae-brasil-online/baja-infra && ./scripts/verify-run.sh >> /var/log/baja-verify.log 2>&1
#
# WHY THE KUMA PING IS SENT FROM HERE
#
# Teardown is a compliance step, not tidiness: the run materialises
# plaintext CPFs and forum private messages on the production host. A
# forgotten scratch database of decrypted competitor data is worse than
# the failure this job was built to catch. So the heartbeat is sent only
# after verification AND teardown have both succeeded — a teardown failure
# alerts, exactly like a corrupt backup would.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INFRA_DIR="$(dirname "$SCRIPT_DIR")"
LOG_PREFIX="verify-run"
# shellcheck source=./backup-lib.sh
source "$SCRIPT_DIR/backup-lib.sh"

PROJECT=baja-verify
COMPOSE=(docker compose -p "$PROJECT" -f "$INFRA_DIR/docker-compose.verify.yml")
SCRATCH_VOLUME="${PROJECT}_verify_scratch"

PEAK_SAMPLER_PID=""
PEAK_FILE=""

load_config() {
    local env_file="$INFRA_DIR/.env"
    [[ -f "$env_file" ]] || die "stack env not found: $env_file"
    set -a
    # shellcheck disable=SC1090
    source "$env_file"
    set +a

    local missing=() var
    for var in MYSQL_VERIFY_ROOT_PASSWORD GDRIVE_SA_READER_JSON AGE_IDENTITY_VERIFIER; do
        [[ -n "${!var:-}" ]] || missing+=("$var")
    done
    (( ${#missing[@]} == 0 )) || die "required config vars unset in ${env_file}: ${missing[*]}"

    # The reader key only has to be real when the destination is really
    # Drive; a VERIFY_REMOTE test run does not touch Google at all. It
    # still has to be a readable path, because compose bind-mounts it.
    if [[ -z "${VERIFY_REMOTE:-}" ]]; then
        [[ -r "$GDRIVE_SA_READER_JSON" ]] \
            || die "reader service-account key not readable on the host at ${GDRIVE_SA_READER_JSON}"
    else
        warn "VERIFY_REMOTE=${VERIFY_REMOTE} — this is a TEST run against a local destination, not the Shared Drive"
    fi
    [[ -r "$AGE_IDENTITY_VERIFIER" ]] \
        || die "verifier age private key not readable on the host at ${AGE_IDENTITY_VERIFIER}"

    # This one IS a private key — the verifier has to decrypt. Catching a
    # public key configured here turns a confusing mid-run age failure
    # into a clear message before anything starts.
    grep -q 'AGE-SECRET-KEY' "$AGE_IDENTITY_VERIFIER" \
        || die "${AGE_IDENTITY_VERIFIER} does not contain an age private key — the verifier needs the verifier IDENTITY, not its public recipient"

    export VERIFY_STATE_DIR="${VERIFY_STATE_DIR:-/var/lib/baja-verify}"
    mkdir -p "$VERIFY_STATE_DIR"
    chmod 700 "$VERIFY_STATE_DIR"
}

# ----- Leftovers -----
#
# A crashed previous run leaves a volume holding DECRYPTED production
# data on the production host. Finding one is a compliance incident, not
# an inconvenience — so it is destroyed immediately and reported loudly,
# rather than being left for someone to notice.
clear_leftovers() {
    local found=no

    if [[ -n "$("${COMPOSE[@]}" ps -aq 2>/dev/null)" ]]; then
        found=yes
    fi
    if docker volume inspect "$SCRATCH_VOLUME" >/dev/null 2>&1; then
        found=yes
    fi

    if [[ "$found" == "yes" ]]; then
        warn "=============================================================="
        warn "LEFTOVERS from a previous run were found and are being"
        warn "destroyed. That volume held DECRYPTED production data on the"
        warn "production host — find out why the last run did not tear"
        warn "itself down. See docs/backup-restore.md."
        warn "=============================================================="
        "${COMPOSE[@]}" down -v --remove-orphans || true
        docker volume rm -f "$SCRATCH_VOLUME" >/dev/null 2>&1 || true
    fi
}

# ----- Peak memory -----
#
# Acceptance criterion 8 is "verify stack peak memory stays inside its cap
# with prod running — measure, don't assume". So measure it, every run,
# and print it. A number in the log beats a number in someone's memory.
start_peak_sampler() {
    PEAK_FILE="$(mktemp)"
    printf '0\n' > "$PEAK_FILE"
    (
        # Samples are normalised to MiB as plain numbers here rather than
        # sorted as strings later: docker prints "412.3MiB" and "1.05GiB",
        # and `sort -h` does not understand the "iB" suffix — it would
        # happily report 412 MiB as the peak of a run that touched a
        # gigabyte.
        while :; do
            docker stats --no-stream --format '{{.Name}}\t{{.MemUsage}}' 2>/dev/null \
                | awk -F'\t' '$1 ~ /verify/ {
                      split($2, parts, " ");
                      v = parts[1];
                      unit = v; sub(/^[0-9.]+/, "", unit);
                      sub(/[A-Za-z]+$/, "", v);
                      if (unit == "GiB") v *= 1024;
                      else if (unit == "KiB") v /= 1024;
                      else if (unit == "B")   v /= 1048576;
                      printf "%.1f\n", v;
                  }' >> "$PEAK_FILE"
            sleep 5
        done
    ) &
    PEAK_SAMPLER_PID=$!
}

stop_peak_sampler() {
    if [[ -n "$PEAK_SAMPLER_PID" ]]; then
        kill "$PEAK_SAMPLER_PID" 2>/dev/null || true
        wait "$PEAK_SAMPLER_PID" 2>/dev/null || true
        PEAK_SAMPLER_PID=""
    fi
    if [[ -n "$PEAK_FILE" && -f "$PEAK_FILE" ]]; then
        local peak
        peak="$(sort -n "$PEAK_FILE" | tail -1)"
        log "peak memory observed across the verify stack: ${peak:-unknown} MiB (mem_limit is 768 MiB)"
        rm -f -- "$PEAK_FILE"
        PEAK_FILE=""
    fi
}

# ----- Teardown -----
#
# Hard failure if it does not work. Asserting the volume is gone is the
# whole reason this script exists on the host rather than in a container.
teardown() {
    log "teardown: destroying the scratch stack and its volume"
    "${COMPOSE[@]}" down -v --remove-orphans --timeout 30 || true
    docker volume rm -f "$SCRATCH_VOLUME" >/dev/null 2>&1 || true

    if docker volume inspect "$SCRATCH_VOLUME" >/dev/null 2>&1; then
        err "TEARDOWN FAILED: docker volume ${SCRATCH_VOLUME} still exists."
        err "It holds decrypted competitor data on the production host."
        err "Remove it by hand: docker volume rm -f ${SCRATCH_VOLUME}"
        return 1
    fi
    local remaining
    remaining="$("${COMPOSE[@]}" ps -aq 2>/dev/null || true)"
    if [[ -n "$remaining" ]]; then
        err "TEARDOWN FAILED: containers from the ${PROJECT} project are still present"
        return 1
    fi

    log "teardown: OK — no volume, no containers, no plaintext"
    return 0
}

main() {
    case "${1:-run}" in
        -h|--help|help)
            sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" \
                | sed -e '/^set -euo pipefail$/d' -e 's/^# \{0,1\}//'
            exit 0
            ;;
        run) : ;;
        *) die "unknown subcommand: $1" ;;
    esac

    load_config
    clear_leftovers

    local verify_rc=0 teardown_rc=0
    local started_epoch
    started_epoch="$(date +%s)"

    # Built explicitly: `compose run` does not build on its own, and a
    # stale verifier image would silently check with last month's rules.
    log "building the verifier image"
    "${COMPOSE[@]}" build verifier

    log "bringing up the scratch stack (project ${PROJECT})"
    if ! "${COMPOSE[@]}" up -d --wait verify-mysql; then
        err "the scratch MySQL did not become healthy"
        teardown || true
        stop_peak_sampler
        exit 1
    fi

    start_peak_sampler

    log "running verify-db.sh"
    "${COMPOSE[@]}" run --rm verifier || verify_rc=$?

    stop_peak_sampler
    teardown || teardown_rc=$?

    local duration=$(( $(date +%s) - started_epoch ))

    if (( verify_rc != 0 )); then
        err "verification FAILED (exit ${verify_rc}) — no Kuma heartbeat; the monitor will alert on silence"
        exit "$verify_rc"
    fi
    if (( teardown_rc != 0 )); then
        err "verification passed but TEARDOWN FAILED — treating the run as failed, because leaving decrypted data on the production host is worse than the failure this job exists to catch"
        exit 1
    fi

    log "verification and teardown both OK in ${duration}s"
    kuma_push "${KUMA_PUSH_URL_VERIFY:-}" "verified and torn down in ${duration}s" "$(( duration * 1000 ))"
}

main "$@"
