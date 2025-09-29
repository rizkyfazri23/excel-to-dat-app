#!/usr/bin/env bash
set -e

# Hormati PORT dari PaaS (Render/Heroku/etc)
PHP_PORT="${PORT:-8000}"

# Kalau APP_KEY belum ada, coba generate (idempotent)
if [ -z "${APP_KEY}" ] || [[ "${APP_KEY}" == base64:* && ${#APP_KEY} -lt 16 ]]; then
  if [ -f .env ]; then
    php artisan key:generate --force || true
  fi
fi

# Pastikan dependency env ada (opsional, biar verbose di log)
php --version
php -m | grep -E "intl|mbstring|zip|gd|pdo_pgsql|pdo_mysql" || true

# Symlink storage (idempotent)
php artisan storage:link || true

# Discover packages (ini yang tadinya gagal di build)
php artisan package:discover --ansi || true

# Cache config/routes/views (ignore error agar tidak crash kalau pertama kali)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Jalankan migrasi (bisa dimatikan via RUN_MIGRATIONS=0)
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force || true
fi

echo "Starting PHP built-in server at 0.0.0.0:${PHP_PORT}"
exec php -S 0.0.0.0:"${PHP_PORT}" -t public
