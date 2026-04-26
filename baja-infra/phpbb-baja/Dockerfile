# Baja SAE Brasil phpBB instance
FROM baja-phpbb-base:latest

# su-exec lets the entrypoint run phpBB CLI commands as www-data so the
# generated cache/store files don't end up root-owned and unwritable by
# php-fpm.
RUN apk add --no-cache su-exec

# We use seed data; the installer is unused. Renaming prevents phpBB from
# locking down the front controller as a "board unavailable" safety measure.
RUN mv /var/www/html/install /var/www/html/install.off

# Bake in the dev seed config.php — base entrypoint copies it to the
# volume on first boot if no config.php exists yet.
COPY config.php.seed /var/www/html/config.php.seed

# Bake the baja/auth extension into the image. Lands in the phpbb_baja_html
# volume on first volume creation; on subsequent boots the volume already
# has the prior content, so updating the extension requires `docker compose
# down -v && docker compose up -d`.
#
# Build context is the monorepo root (compose sets context: ..) so this
# COPY reaches across into the PHP app's tree. Source-of-truth lives with
# the application code, not with this infra Dockerfile.
COPY baja-php/phpbb-extensions/baja-auth /var/www/html/ext/baja/auth

# CMD becomes the argument to the base entrypoint (which does config.php
# seed + dir perms then exec's its argument). Our script picks up after
# the base setup, enables the extension, refreshes config from env, and
# hands off to php-fpm.
COPY entrypoint.sh /usr/local/bin/baja-init.sh
RUN chmod +x /usr/local/bin/baja-init.sh
CMD ["/usr/local/bin/baja-init.sh"]
