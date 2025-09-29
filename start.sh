#!/usr/bin/env bash
set -e

# Honor provided PORT (Render/other PaaS); default 8000
PHP_PORT="${PORT:-8000}"

# Ensure APP_KEY exists (generate if missing and .env is present)
if [ -z "${APP_KEY}" ] || [[ "${APP_KEY}" == base64:* && ${#APP_KEY} -lt 16 ]]; then
  if [ -f .env ]; then
    php artisan key:generate --force || true
  fi
fi

# Storage symlink (idempotent)
php artisan storage:link || true

# Cache config/routes/views
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Run migrations (toggle via RUN_MIGRATIONS=0 to skip)
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force || true
fi

echo "Starting PHP built-in server at 0.0.0.0:${PHP_PORT}"
exec php -S 0.0.0.0:"${PHP_PORT}" -t public
