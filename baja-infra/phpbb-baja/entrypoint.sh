#!/bin/sh
# phpbb-baja entrypoint.
# Runs in this order:
#   1. Set up writable directories (cache, store, etc.)
#   2. Seed config.php from baked-in template if needed
#   3. Wait for MySQL to be query-ready
#   4. Enable extensions and refresh baja/auth config from env
#   5. Hand off to php-fpm (or whatever CMD was passed)
set -e

# ----- 1. Runtime-writable directories -----
# phpBB writes cache, session, and uploaded files to these paths.
for dir in cache store files images/avatars/upload; do
    mkdir -p "/var/www/html/${dir}"
    chown -R www-data:www-data "/var/www/html/${dir}"
done

# ----- 2. Seed config.php on first boot -----
# If a seed config.php is baked into the image and the volume doesn't have
# one yet (fresh volume), copy it in. Makes "docker compose up -d" from a
# clean clone work zero-touch.
if [ -f /var/www/html/config.php.seed ] && [ ! -s /var/www/html/config.php ]; then
    echo "phpbb-baja: seeding config.php from baked-in seed..."
    cp /var/www/html/config.php.seed /var/www/html/config.php
    chown www-data:www-data /var/www/html/config.php
    chmod 640 /var/www/html/config.php
fi

# Make sure config.php is writable during install if it doesn't exist yet
# (relevant only when there's no seed and someone wants to run the installer
# manually — not our normal flow, but cheap defense).
if [ ! -f /var/www/html/config.php ]; then
    touch /var/www/html/config.php
    chown www-data:www-data /var/www/html/config.php
    chmod 660 /var/www/html/config.php
fi

# Make sure install directory is writable (if still present — we move it
# to install.off in the Dockerfile, so this is mostly defensive).
chmod -R u+w /var/www/html/install 2>/dev/null || true

cd /var/www/html

# ----- 3. Wait for MySQL to actually be query-ready -----
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

# ----- 4. Enable extensions and refresh baja/auth config -----
# `|| true` — the CLI returns nonzero when the extension is already
# enabled, which is the steady state on container restart. We don't
# want a crash-loop on what is effectively a no-op.
su-exec www-data php bin/phpbbcli.php --safe-mode extension:enable baja/auth || true
su-exec www-data php bin/phpbbcli.php extension:enable bakasura/xforwardedfor || true
su-exec www-data php bin/phpbbcli.php extension:enable vse/abbc3 || true
su-exec www-data php bin/phpbbcli.php --safe-mode extension:disable phpbb/viglink || true

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

# ----- 5. Hand off to CMD -----
exec "$@"