# =====================================================================
# Zorvex | Network — PHP-FPM container
# PHP 8.2 (Debian-based) with every extension the bot needs.
# Debian base is more reliable for building extensions than Alpine.
# =====================================================================

FROM php:8.2-fpm

# --- System dependencies ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        git \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        libssl-dev \
        default-mysql-client \
        unzip \
        zip \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ---
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

# --- Install Composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- PHP production configuration ---
COPY docker/php.ini /usr/local/etc/php/conf.d/99-zorvex.ini

# --- App source ---
WORKDIR /var/www/html
COPY . .

# --- Install dependencies (no dev) and optimize the autoloader ---
RUN set -eux; \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts || \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# --- Runtime user & storage ---
RUN chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/storage/logs \
    && chmod -R 775 /var/www/html/storage

# --- Entrypoint ---
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]