# -----------------------------
# Runtime image (Debian, lebih stabil)
# -----------------------------
FROM php:8.3-cli-bookworm

# Non-interactive APT
ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1

# System deps (git, unzip, zip, ICU untuk intl, libpq untuk pgsql, libzip)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip zip libicu-dev libzip-dev libpq-dev \
 && docker-php-ext-install intl zip pdo pdo_pgsql pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

# Install Composer (copy dari official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy hanya file composer dulu supaya cache build efektif
COPY composer.json composer.lock ./

# Install dependencies PHP (tanpa dev)
RUN composer install --no-dev --prefer-dist --optimize-autoloader

# Copy seluruh source code
COPY . .

# (Optional) Re-run composer untuk autoload optimize setelah source full
RUN composer dump-autoload -o

# Permissions untuk storage & cache (jika perlu)
RUN chown -R www-data:www-data storage bootstrap/cache || true

# Copy start script (harus executable di git; jika perlu, chmod lagi di sini)
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Expose (Render pakai env PORT, jadi EXPOSE hanya dokumentasi)
EXPOSE 8000

# Jalankan start script (handle key, cache, migrate, serve)
CMD ["/usr/local/bin/start.sh"]
