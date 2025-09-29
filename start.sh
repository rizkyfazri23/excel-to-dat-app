#!/usr/bin/env bash
set -e

PHP_PORT="${PORT:-8000}"

# Generate APP_KEY jika kosong (idempotent)
if [ -z "${APP_KEY}" ] || [[ "${APP_KEY}" == base64:* && ${#APP_KEY} -lt 16 ]]; then
  if [ -f .env ]; then
    php artisan key:generate --force || true
  fi
fi

# Cek ekstensi (debug info)
php --version
php -m | grep -E "intl|mbstring|zip|gd|pdo_pgsql|pdo_mysql" || true

# Pastikan storage link & paket ter-discover
php artisan storage:link || true
php artisan package:discover --ansi || true

# Cache untuk performa
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Migrasi otomatis (set RUN_MIGRATIONS=0 untuk menonaktifkan)
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force || true
fi

echo "Starting PHP built-in server at 0.0.0.0:${PHP_PORT}"
exec php -S 0.0.0.0:"${PHP_PORT}" -t public
