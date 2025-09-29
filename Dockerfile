# =========================
# 1) Node stage: build Vite
# =========================
FROM node:20-bullseye AS node-builder
WORKDIR /app

# Salin file lock dulu biar cache build optimal
COPY package.json package-lock.json* yarn.lock* pnpm-lock.yaml* ./
# Install deps (otomatis pilih manager yang ada)
RUN if [ -f package-lock.json ]; then npm ci; \
    elif [ -f yarn.lock ]; then corepack enable && yarn --frozen-lockfile; \
    elif [ -f pnpm-lock.yaml ]; then corepack enable && pnpm i --frozen-lockfile; \
    else npm i; fi

# Salin sumber front-end yang diperlukan untuk build
COPY vite.config.* ./
COPY resources ./resources
COPY public ./public

# Build Vite → hasil ke public/build (default Laravel)
RUN npm run build

# ===================================
# 2) PHP runtime: Laravel + extensions
# ===================================
FROM php:8.3-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_NO_INTERACTION=1
ENV COMPOSER_MEMORY_LIMIT=-1

# System deps utk ekstensi PHP & composer
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

# Composer CLI
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 1) Composer install (tanpa scripts; artisan belum ada di tahap ini)
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-progress --no-scripts

# 2) Salin seluruh source Laravel
COPY . .

# 3) Copy hasil build Vite dari stage Node
COPY --from=node-builder /app/public/build ./public/build

# 4) Optimize autoload (tanpa menjalankan artisan di fase build)
RUN composer dump-autoload -o

# Permission storage & cache
RUN chown -R www-data:www-data storage bootstrap/cache || true

# Start script
COPY start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 8000
CMD ["/usr/local/bin/start.sh"]
