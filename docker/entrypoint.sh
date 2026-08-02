#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# Wait for database if DB_HOST is set
if [ -n "${DB_HOST:-}" ]; then
    echo "Waiting for database at ${DB_HOST}:${DB_PORT:-3306}..."
    i=0
    until php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT:-3306};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}'); exit(0); } catch (Exception \$e) { exit(1); }" 2>/dev/null; do
        i=$((i + 1))
        if [ "$i" -ge 30 ]; then
            echo "Database not ready after 30s, continuing anyway..."
            break
        fi
        sleep 1
    done
fi

php artisan package:discover --ansi || true
php artisan storage:link --force || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction || true
fi

php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true
php artisan filament:optimize || true

exec "$@"

