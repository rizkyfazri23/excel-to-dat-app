# =========================================================
# Laravel on PHP 8.3 (Debian bookworm) – with GD & Postgres
# =========================================================
FROM php:8.3-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1
ENV COMPOSER_MEMORY_LIMIT=-1

# System dependencies for PHP extensions
# - intl      : libicu-dev
# - zip       : libzip-dev
# - pgsql     : libpq-dev
# - gd        : libfreetype6-dev libjpeg62-turbo-dev libpng-dev
# - mbstring  : libonig-dev (oniguruma)
# - pkg-config: detect libs during configure
# - git/unzip/zip: needed by composer
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip zip pkg-config \
    libicu-dev libzip-dev libpq-dev \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev \
    libonig-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" \
    intl zip mbstring bcmath exif pcntl \
    pdo pdo_pgsql pdo_mysql gd \
 && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./

# Install PHP deps (no dev); optimize autoload; no progress for quieter logs
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-progress

# Copy entire application
COPY . .

# Optimize autoload once full source is present
RUN composer dump-autoload -o

# Ensure storage & cache are writable
RUN chown -R www-data:www-data storage bootstrap/cache || true

# Start script
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8000
CMD ["/usr/local/bin/start.sh"]
