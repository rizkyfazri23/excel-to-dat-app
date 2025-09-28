# ===== Build stage (Composer dependencies) =====
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts -o
# Copy source (tanpa vendor) supaya autoload optimize jalan setelah copy penuh
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist -o

# ===== Runtime stage =====
FROM php:8.3-cli-alpine

# System deps
RUN apk add --no-cache \
    icu-dev oniguruma-dev libzip-dev git unzip bash \
    && docker-php-ext-install intl zip mbstring pdo pdo_mysql pdo_pgsql

# Working dir
WORKDIR /app

# Copy app files & vendor from build stage
COPY --from=vendor /app /app

# Composer kebawa dari stage sebelumnya; optional kalau mau:
# COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Permissions untuk storage & cache
RUN chown -R www-data:www-data storage bootstrap/cache || true

# Copy start script
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Expose port (Render pakai $PORT env, jadi EXPOSE sekadar dokumentasi)
EXPOSE 8000

# Jalankan start script
CMD ["/usr/local/bin/start.sh"]
