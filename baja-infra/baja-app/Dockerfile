FROM php:8.3-fpm-alpine

# Install runtime deps + build deps for PHP extensions.
# Build deps are tagged via --virtual .build-deps so we can remove them after.
RUN apk add --no-cache \
        curl \
        ca-certificates \
        git \
        unzip \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libxml2-dev \
        oniguruma-dev \
        icu-dev \
        libzip-dev \
    && apk add --no-cache --virtual .build-deps \
        autoconf \
        gcc \
        g++ \
        make \
        pkgconf \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        pdo_mysql \
        mbstring \
        xml \
        intl \
        opcache \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/pear

# Install composer from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Entrypoint: ensures vendor exists, then starts php-fpm
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
