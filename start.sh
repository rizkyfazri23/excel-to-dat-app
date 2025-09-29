#!/usr/bin/env bash
set -e

# Honor PORT from Render/host; default 8000 if not set
PHP_PORT="${PORT:-8000}"

# Laravel app key: use existing if provided via env; otherwise try generate
if [ -z "${APP_KEY}" ] || [[ "${APP_KEY}" == base64:* && ${#APP_KEY} -lt 16 ]]; then
  # Attempt to generate key if .env exists
  if [ -f .env ]; then
    php artisan key:generate --force || true
  fi
fi

# Storage symlink (idempotent)
php artisan storage:link || true

# Cache config/routes/views to speed up
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Run migrations (safe for demo; for prod you may manage this via CI)
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force || true
fi

echo "Starting PHP built-in server at 0.0.0.0:${PHP_PORT}"
exec php -S 0.0.0.0:"${PHP_PORT}" -t public
