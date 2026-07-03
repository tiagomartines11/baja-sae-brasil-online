#!/bin/sh
# phpBB cron loop for the containerized stack. Runs the phpBB CLI cron
# runner on a fixed interval so queued email, session GC, cache tidying,
# etc. fire reliably — web-triggered cron is traffic-dependent and
# unreliable on low-traffic / containerized setups.
#
# Runs as a sidecar sharing the forum's code volume, so it reads the same
# cache/queue.php that php-fpm writes when a page generates mail.
set -eu

INTERVAL="${CRON_INTERVAL_SECONDS:-300}"

# The fpm container's entrypoint generates config.php into the shared
# volume; wait for it before running the CLI (which needs DB creds from it).
echo "phpbb-cron: waiting for config.php in shared volume..."
until [ -s /var/www/html/config.php ]; do sleep 2; done

echo "phpbb-cron: starting loop (interval ${INTERVAL}s)"
cd /var/www/html
while true; do
    # timeout guards against a hung task stalling the loop forever.
    # || echo — a failed run (e.g. transient DB blip) is logged and retried
    # next interval rather than killing the loop (note: set -e is satisfied
    # because the || branch succeeds).
    timeout 240 su-exec www-data php bin/phpbbcli.php cron:run \
        || echo "phpbb-cron: cron:run exited nonzero (will retry next interval)"
    sleep "$INTERVAL"
done
