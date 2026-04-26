#!/bin/sh
# Invoked by the base phpBB entrypoint after config.php seeding and dir
# perms. Enables the baja/auth extension and refreshes its phpbb_config
# rows from env vars so prod/dev values stay in sync without manual ACP
# edits, then hands off to php-fpm.
set -e

if [ -f /var/www/html/config.php ] && [ -d /var/www/html/ext/baja/auth ]; then
    cd /var/www/html

    # ----- Wait for MySQL to actually be query-ready -----
    # mysql's depends_on:condition:service_healthy only verifies that the
    # daemon is listening + the seed has loaded. But on cold start the
    # init scripts (CREATE DATABASE, dumps, CREATE USER) run AFTER
    # mysqld accepts connections, and there's a brief window where the
    # phpbb_baja DB user we're about to authenticate as doesn't exist
    # yet. Without this loop, phpbbcli below errors + set -e kills the
    # script + restart: unless-stopped cycles us 5-6 times until the
    # timing happens to line up.
    #
    # Probe via PHP's mysqli (already in this image — phpBB uses it) so
    # we don't need to pull in mariadb-client (which on alpine lacks the
    # caching_sha2_password plugin MySQL 8.x defaults to). The query
    # succeeds only after both seed load AND user creation are done.
    echo "phpbb-baja: waiting for mysql to be query-ready..."
    RETRIES=60
    until php -r 'exit(@(new mysqli("mysql", "phpbb_baja", getenv("MYSQL_PHPBB_BAJA_PASSWORD"), "phpbb_baja"))->query("SELECT 1 FROM phpbb_config LIMIT 1") ? 0 : 1);' 2>/dev/null; do
        RETRIES=$((RETRIES - 1))
        if [ $RETRIES -le 0 ]; then
            echo "phpbb-baja: mysql did not become query-ready in 60s, giving up"
            exit 1
        fi
        sleep 1
    done
    echo "phpbb-baja: mysql is query-ready"

    # `|| true` — the CLI returns nonzero when the extension is already
    # enabled, which is the steady state on container restart. We don't
    # want a crash-loop on what is effectively a no-op.
    su-exec www-data php bin/phpbbcli.php --safe-mode extension:enable baja/auth || true

    # Only push values that are actually set; an empty env var must not
    # overwrite an admin-customized phpbb_config row.
    if [ -n "$BAJA_AUTH_ALLOWED_DOMAIN_SUFFIX" ]; then
        su-exec www-data php bin/phpbbcli.php --safe-mode config:set \
            baja_auth_allowed_domain_suffix "$BAJA_AUTH_ALLOWED_DOMAIN_SUFFIX"
    fi
    if [ -n "$BAJA_AUTH_DEFAULT_REDIRECT" ]; then
        su-exec www-data php bin/phpbbcli.php --safe-mode config:set \
            baja_auth_default_redirect "$BAJA_AUTH_DEFAULT_REDIRECT"
    fi
fi

exec php-fpm
