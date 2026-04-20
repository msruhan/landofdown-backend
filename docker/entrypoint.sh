#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  if [ -f .env.sqlite ]; then
    cp .env.sqlite .env
  else
    cp .env.example .env
  fi
fi

if ! grep -q "^APP_KEY=base64:" .env; then
  php artisan key:generate --force
fi

mkdir -p database
touch database/database.sqlite

php artisan config:clear
php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8000
