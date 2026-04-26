FROM php:8.3-fpm-alpine

# phpBB version - matched to production
ARG PHPBB_VERSION=3.3.15

# Install build deps and runtime PHP extensions phpBB needs
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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        mysqli \
        mbstring \
        xml \
        intl \
        opcache \
        zip

# Download and extract phpBB
WORKDIR /var/www/html
RUN curl -fsSL "https://download.phpbb.com/pub/release/3.3/${PHPBB_VERSION}/phpBB-${PHPBB_VERSION}.tar.bz2" \
        -o /tmp/phpbb.tar.bz2 \
    && tar -xjf /tmp/phpbb.tar.bz2 --strip-components=1 \
    && rm /tmp/phpbb.tar.bz2 \
    && chown -R www-data:www-data /var/www/html

# Common entrypoint that sets up writable directories
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
