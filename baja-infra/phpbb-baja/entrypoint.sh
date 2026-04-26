#!/bin/sh
# Invoked by the base phpBB entrypoint after config.php seeding and dir
# perms. Enables the baja/auth extension and refreshes its phpbb_config
# rows from env vars so prod/dev values stay in sync without manual ACP
# edits, then hands off to php-fpm.
set -e

if [ -f /var/www/html/config.php ] && [ -d /var/www/html/ext/baja/auth ]; then
    cd /var/www/html

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
