# =====================================================================
# Zorvex | Network — PHP-FPM container
# PHP 8.2 with every extension the bot needs, Composer, and supervisor.
# =====================================================================

FROM php:8.2-fpm-alpine AS base

# --- Build-time dependencies for pecl installs ---
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        curl-dev \
        libxml2-dev \
        openssl-dev \
    && apk add --no-cache \
        curl \
        fcgi \
        git \
        icu-dev \
        libzip-dev \
        mysql-client \
        oniguruma-dev \
        openssl \
        zip \
        gnu-libiconv

# PHP extensions required by the application.
RUN docker-php-ext-configure intl \
    && docker-php-ext-install \
        bcmath \
        exif \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_mysql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

# Install Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Runtime dependencies (kept, build-deps removed) ---
RUN apk del .build-deps \
    && rm -rf /var/cache/apk/*

# PHP production configuration.
COPY docker/php.ini /usr/local/etc/php/conf.d/99-zorvex.ini

# --- App source ---
WORKDIR /var/www/html
COPY . .

# Install dependencies (no dev) and optimize the autoloader.
RUN set -eux; \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
        --no-scripts --ignore-platform-req=ext-intl || \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Runtime user.
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/storage/logs \
    && chmod -R 775 /var/www/html/storage

# --- Entrypoint ---
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]