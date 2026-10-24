#!/bin/sh
set -e

cd /app

if [ ! -f .env ]; then
  cp .env.example .env
fi

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
  php artisan key:generate --force
fi

if [ ! -f database/database.sqlite ]; then
  touch database/database.sqlite
fi

php artisan migrate --force --seed
php artisan storage:link 2>/dev/null || true

if [ -n "$APP_URL" ]; then
  php artisan config:cache
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
