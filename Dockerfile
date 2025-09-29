# =========================================================
# Laravel on PHP 8.3 (Debian bookworm) – with GD & Postgres
# =========================================================
FROM php:8.3-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1
ENV COMPOSER_MEMORY_LIMIT=-1

# System deps for PHP extensions & composer
# - intl      : libicu-dev
# - zip       : libzip-dev
# - pgsql     : libpq-dev
# - gd        : libfreetype6-dev libjpeg62-turbo-dev libpng-dev
# - mbstring  : libonig-dev (oniguruma)
# - pkg-config: helps configure checks
# - git/unzip/zip: composer helpers
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

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 1) Copy composer files dulu (agar layer cache efektif)
COPY composer.json composer.lock ./

# 2) Install dependencies TANPA scripts (artisan belum ada di tahap ini)
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-progress --no-scripts

# 3) Copy seluruh source code
COPY . .

# 4) Optimize autoload (tanpa scripts juga agar build tidak menjalankan artisan)
RUN composer dump-autoload -o

# Permission untuk storage & cache
RUN chown -R www-data:www-data storage bootstrap/cache || true

# Start script saat runtime (semua artisan dijalankan di sini)
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8000
CMD ["/usr/local/bin/start.sh"]
