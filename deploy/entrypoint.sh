#!/bin/sh
set -e

# Configure port in Nginx (Render injects $PORT dynamically, default to 80 if empty)
PORT="${PORT:-80}"
echo "==> Configuring Nginx to listen on port ${PORT}..."
sed -i "s/LISTEN_PORT/${PORT}/g" /etc/nginx/nginx.conf

# Ensure Laravel storage and cache directories exist
mkdir -p /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# Fix directory permissions for the web server
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create public storage symlink if not already created
echo "==> Creating storage symlink..."
php artisan storage:link --no-interaction || true

# Run database migrations if RUN_MIGRATIONS is set to "true"
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "==> Running database migrations..."
    php artisan migrate --force --no-interaction || true
fi

# Optimize Laravel cache if APP_KEY is set
if [ -n "${APP_KEY}" ]; then
    echo "==> Optimizing Laravel cache..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
else
    echo "==> NOTICE: APP_KEY is not set. Skipping config/route caching until set in environment."
fi

echo "==> Starting Supervisord (Nginx + PHP-FPM)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
