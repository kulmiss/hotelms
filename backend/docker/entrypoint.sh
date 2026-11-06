#!/bin/sh
# Prepares the API on container start: packages, .env, app key, database.
# MySQL is already healthy here (docker-compose waits for it).
set -e

composer install --no-interaction --no-progress --optimize-autoloader
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
php artisan migrate --force --no-interaction
[ -e public/storage ] || php artisan storage:link
php artisan db:seed --force --no-interaction

exec "$@"
