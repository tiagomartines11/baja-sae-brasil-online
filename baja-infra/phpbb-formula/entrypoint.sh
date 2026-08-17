#!/bin/sh
# phpbb-formula entrypoint.
# Simpler than phpbb-baja's because Formula has no custom extension to
# enable / configure. Sets up dirs, installs branded prosilver assets,
# seeds config.php, waits for MySQL to be query-ready, then runs php-fpm.
set -e

# ----- 1. Runtime-writable directories -----
# phpBB writes cache, session, and uploaded files to these paths.
# Regenerable dirs (live in the code volume) — safe to recursively chown.
for dir in cache store; do
    mkdir -p "/var/www/html/${dir}"
    chown -R www-data:www-data "/var/www/html/${dir}"
done

# User-content dirs — bind-mounted in prod and potentially huge. chown only
# the mount point, not the tree; host-side ownership is normalized once at
# post-migration time (to the container's www-data UID).
for dir in files images/avatars/upload; do
    mkdir -p "/var/www/html/${dir}"
    chown www-data:www-data "/var/www/html/${dir}"
done

# ----- 2. Install branded prosilver assets -----
# The forum logo is baked into the image at /usr/local/share/baja/branding,
# deliberately OUTSIDE /var/www/html, and copied into the theme on every
# boot. The indirection is the whole point: /var/www/html is a named volume
# that is seeded from the image only when the volume is FIRST created, so a
# logo COPYed straight into the theme would need a `down -v` to ever change
# (same trap as the extensions — see docs/dev-workflow.md FAQ). Installing
# from outside the volume at boot makes a logo swap `build` + `up -d`.
#
# We only replace prosilver's site_logo.svg, matching filename and format,
# because stock colours.css already resolves .site_logo to
# ./images/site_logo.svg — so no CSS or template override is needed. We
# have exactly one theme (prosilver); if that ever stops being true this
# needs to grow a loop over the installed styles.
#
# No branding baked in is a supported state, not an error: phpBB's stock
# logo stays and the build/boot succeeds.
BRANDING_DIR=/usr/local/share/baja/branding
THEME_IMAGES=/var/www/html/styles/prosilver/theme/images
if [ ! -d "$THEME_IMAGES" ]; then
    echo "phpbb-formula: WARNING: $THEME_IMAGES not found, skipping branding install"
elif [ -f "$BRANDING_DIR/site_logo.svg" ]; then
    echo "phpbb-formula: installing branded site_logo.svg into prosilver..."
    cp "$BRANDING_DIR/site_logo.svg" "$THEME_IMAGES/site_logo.svg"
    chown www-data:www-data "$THEME_IMAGES/site_logo.svg"
    chmod 644 "$THEME_IMAGES/site_logo.svg"
else
    echo "phpbb-formula: no branding/site_logo.svg baked in, keeping phpBB's stock logo"
fi

# ----- 3. Generate config.php on first boot -----
# If a template is baked into the image and the volume doesn't have a
# config.php yet (fresh volume), render it from env vars. Makes
# "docker compose up -d" from a clean clone work zero-touch, and means
# rotating MYSQL_PHPBB_FORMULA_PASSWORD in .env no longer requires a rebuild
# — just `down -v && up -d`.
if [ -f /var/www/html/config.php.template ] && [ ! -s /var/www/html/config.php ]; then
    echo "phpbb-formula: generating config.php from template..."
    envsubst '${MYSQL_PHPBB_FORMULA_PASSWORD}' < /var/www/html/config.php.template > /var/www/html/config.php
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

# ----- 4. Wait for MySQL to actually be query-ready -----
# mysql's depends_on:condition:service_healthy only verifies that the
# daemon is listening + the seed has loaded. But on cold start the
# init scripts (CREATE DATABASE, dumps, CREATE USER) run AFTER
# mysqld accepts connections, and there's a brief window where the
# phpbb_formula DB user we authenticate as doesn't exist yet.
# Without this loop, the first HTTP request to phpBB fails with a
# "Connection refused" error until the timing happens to line up.
#
# Probe via PHP's mysqli (already in this image — phpBB uses it) so
# we don't need to pull in mariadb-client (which on alpine lacks the
# caching_sha2_password plugin MySQL 8.x defaults to). The query
# succeeds only after both seed load AND user creation are done.
echo "phpbb-formula: waiting for mysql to be query-ready..."
RETRIES=60
until php -r 'exit(@(new mysqli("mysql", "phpbb_formula", getenv("MYSQL_PHPBB_FORMULA_PASSWORD"), "phpbb_formula"))->query("SELECT 1 FROM phpbb_config LIMIT 1") ? 0 : 1);' 2>/dev/null; do
    RETRIES=$((RETRIES - 1))
    if [ $RETRIES -le 0 ]; then
        echo "phpbb-formula: mysql did not become query-ready in 60s, giving up"
        exit 1
    fi
    sleep 1
done
echo "phpbb-formula: mysql is query-ready"

# ----- 5. Enable extension and refresh its config -----
# `|| true` — the CLI returns nonzero when the extension is already
# enabled, which is the steady state on container restart. We don't
# want a crash-loop on what is effectively a no-op.
su-exec www-data php bin/phpbbcli.php extension:enable bakasura/xforwardedfor || true
su-exec www-data php bin/phpbbcli.php extension:enable vse/abbc3 || true
su-exec www-data php bin/phpbbcli.php --safe-mode extension:disable phpbb/viglink || true

# ----- 6. Hand off to CMD -----
exec "$@"