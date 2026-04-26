#!/bin/sh
set -e

# Make sure runtime-writable directories exist with right perms
for dir in cache store files images/avatars/upload; do
    mkdir -p "/var/www/html/${dir}"
    chown -R www-data:www-data "/var/www/html/${dir}"
done

# If a seed config.php is baked into the image and the volume doesn't have one yet,
# copy it in. This makes fresh installs work zero-touch.
if [ -f /var/www/html/config.php.seed ] && [ ! -s /var/www/html/config.php ]; then
    echo "Seeding config.php from baked-in seed..."
    cp /var/www/html/config.php.seed /var/www/html/config.php
    chown www-data:www-data /var/www/html/config.php
    chmod 640 /var/www/html/config.php
fi

# Make sure config.php is writable during install if it doesn't exist yet
# (relevant when there's no seed and someone wants to run the installer)
if [ ! -f /var/www/html/config.php ]; then
    touch /var/www/html/config.php
    chown www-data:www-data /var/www/html/config.php
    chmod 660 /var/www/html/config.php
fi

# Make sure install directory is writable for installer state (if still present)
chmod -R u+w /var/www/html/install 2>/dev/null || true

exec "$@"
