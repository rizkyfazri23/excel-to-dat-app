#!/usr/bin/env bash
set -e

# Pastikan APP_KEY ada (Render: isi di env. Kalau kosong, generate)
if [ -z "$APP_KEY" ] || [[ "$APP_KEY" == base64:* ]]; then
  if [ -f .env ]; then
    # kalau ada .env tapi tanpa APP_KEY, generate
    php artisan key:generate --force || true
  fi
fi

# Link storage (idempotent)
php artisan storage:link || true

# Cache config/routes/views (aman di container)
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Jalankan migrasi (demo: aman; production sebaiknya manual/CI)
php artisan migrate --force || true

# Jalankan server PHP built-in, pakai port yang disediakan Render
PHP_PORT=${PORT:-8000}
echo "Starting PHP server on 0.0.0.0:${PHP_PORT}"
php -S 0.0.0.0:${PHP_PORT} -t public
