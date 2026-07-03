# Formula SAE Brasil phpBB instance — self-contained Dockerfile.
#
# This image is built from scratch (FROM php:8.3-fpm-alpine), no shared base.
# The duplication with phpbb-baja's Dockerfile is intentional: it lets us
# upgrade the two phpBB instances on independent cadences (e.g., bump Baja
# to phpBB 3.4 first, verify in prod, then bump Formula). With a shared
# base image, both would have to move together unless we played careful
# version-pinning games.
FROM php:8.3-fpm-alpine

# phpBB version - matched to production
ARG PHPBB_VERSION=3.3.15

# Install build deps and runtime PHP extensions phpBB needs.
RUN apk add --no-cache \
        curl \
        ca-certificates \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libxml2-dev \
        oniguruma-dev \
        icu-dev \
        libzip-dev \
        zip \
        unzip \
        gnu-libiconv \
        su-exec \
        gettext \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        mbstring \
        xml \
        intl \
        opcache \
        zip

# Download and extract phpBB. The mv of install/ -> install.off prevents
# phpBB from locking down the front controller as "board unavailable" —
# we use seed data, the installer is unused.
WORKDIR /var/www/html
RUN curl -fsSL "https://download.phpbb.com/pub/release/3.3/${PHPBB_VERSION}/phpBB-${PHPBB_VERSION}.tar.bz2" \
        -o /tmp/phpbb.tar.bz2 \
    && tar -xjf /tmp/phpbb.tar.bz2 --strip-components=1 \
    && rm /tmp/phpbb.tar.bz2 \
    && mv /var/www/html/install /var/www/html/install.off \
    && chown -R www-data:www-data /var/www/html

# Brazilian Portuguese language pack (pt_br). Formula currently ships
# English, but the pack is baked here so a future ACP switch to pt_br is a
# setting change rather than an image change — and keeps the two phpBB
# images in lockstep on this front. The baja image needs it now (its
# migrated DB references pt_br in phpbb_lang / default_lang).
#
# Sourced from suportephpbb/phpbb — the community-maintained pack the phpbb.com
# customise DB points at. phpbb.com is not on the build network's allowlist;
# github.com is, so this follows the extension-fetch pattern below. Pinned
# to a commit on the pack's 3.3.x branch (no versioned releases on that
# line). Version-match: the branch tracks phpBB 3.3.x — revisit when
# ARG PHPBB_VERSION is bumped; strings missing at the pinned commit fall
# back to English rather than breaking.
RUN curl -fsSL "https://github.com/suportephpbb/phpbb/archive/f4c24709c957d1c2a577c07208aac9cd59a85666.tar.gz" \
        -o /tmp/lang_ptbr.tar.gz \
    && mkdir -p /tmp/lang_ptbr \
    && tar -xzf /tmp/lang_ptbr.tar.gz -C /tmp/lang_ptbr --strip-components=1 \
    && cp -r /tmp/lang_ptbr/language/pt_br /var/www/html/language/pt_br \
    && cp -r /tmp/lang_ptbr/styles/prosilver/theme/pt_br \
            /var/www/html/styles/prosilver/theme/pt_br \
    && rm -rf /tmp/lang_ptbr /tmp/lang_ptbr.tar.gz \
    && chown -R www-data:www-data \
        /var/www/html/language/pt_br \
        /var/www/html/styles/prosilver/theme/pt_br

# Third-party extensions.
# Note: bakasura/xforwardedfor has no versioned releases — pinned to master.
# If the author pushes breaking changes this build will silently pick them up.
RUN curl -fsSL "https://github.com/BakasuraRCE/phpbb-ext-xforwardedfor/archive/refs/heads/master.tar.gz" \
        -o /tmp/xforwardedfor.tar.gz \
    && mkdir -p /var/www/html/ext/bakasura/xforwardedfor \
    && tar -xzf /tmp/xforwardedfor.tar.gz --strip-components=3 \
        -C /var/www/html/ext/bakasura/xforwardedfor \
    && rm /tmp/xforwardedfor.tar.gz \
    \
    && curl -fsSL "https://github.com/iMattPro/abbc3/archive/refs/tags/v3.3.8.tar.gz" \
        -o /tmp/abbc3.tar.gz \
    && mkdir -p /var/www/html/ext/vse/abbc3 \
    && tar -xzf /tmp/abbc3.tar.gz --strip-components=1 \
        -C /var/www/html/ext/vse/abbc3 \
    && rm /tmp/abbc3.tar.gz \
    && chown -R www-data:www-data /var/www/html/ext

# Bake in the dev seed config.php — entrypoint copies it to the volume on
# first boot if no config.php exists yet.
#
# COUPLING NOTE: this file contains DB password as a string literal. Changes
# to MYSQL_PHPBB_FORMULA_PASSWORD in .env require rebuilding this image
# (docker compose down -v && docker compose build phpbb-formula && up -d).
COPY baja-infra/phpbb-formula/config.php.template /var/www/html/config.php.template

# Single entrypoint: handles dir perms, config.php seeding, then exec's
# php-fpm. Formula has no custom extension, so the entrypoint is simpler
# than phpbb-baja's.
COPY baja-infra/phpbb-formula/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Cron loop used by the cron sidecar service (see docker-compose.prod.yml).
COPY baja-infra/phpbb-formula/cron-loop.sh /usr/local/bin/cron-loop.sh
RUN chmod +x /usr/local/bin/cron-loop.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
