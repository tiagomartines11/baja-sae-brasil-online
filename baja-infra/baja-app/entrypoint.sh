#!/bin/sh
set -e

# Git in the container needs to trust this bind-mounted path
# (owner UID inside container differs from host)
git config --global --add safe.directory /var/www/html

# Make sure runtime-writable directories exist
for dir in default/cache resultados/cache; do
    mkdir -p "/var/www/html/${dir}" 2>/dev/null || true
done

# Install vendor if missing — non-fatal if it fails (dev convenience)
if [ ! -d /var/www/html/vendor ] || [ ! -f /var/www/html/vendor/autoload.php ]; then
    echo "vendor/ not found, running composer install..."
    cd /var/www/html
    if composer install --no-interaction --prefer-dist --no-progress --ignore-platform-reqs; then
        echo "composer install succeeded"
    else
        echo ""
        echo "============================================"
        echo "WARNING: composer install failed."
        echo "Container will start anyway so you can debug."
        echo "Run: docker compose exec baja-app sh"
        echo "Then: composer install --ignore-platform-reqs"
        echo "============================================"
        echo ""
    fi
fi

exec "$@"
