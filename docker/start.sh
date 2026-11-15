#!/bin/sh
set -eu

cd /var/www/html

# File sessions/cache and compiled views need these directories in a fresh image.
mkdir -p storage/framework/sessions storage/framework/cache/data storage/framework/views storage/logs bootstrap/cache

# Match the port assigned by the hosting service.
http_port="${PORT:-80}"
case "$http_port" in
    ''|*[!0-9]*) echo "PORT must be a number between 1 and 65535." >&2; exit 1 ;;
esac
if [ "$http_port" -lt 1 ] || [ "$http_port" -gt 65535 ]; then
    echo "PORT must be a number between 1 and 65535." >&2
    exit 1
fi
sed -i -E "s/listen 80;/listen ${http_port};/; s/listen \[::\]:80 default_server;/listen [::]:${http_port} default_server;/" conf/nginx/nginx-site.conf

# Build configuration at runtime, with Render's environment, not at image build.
php artisan config:clear --no-interaction
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $app->make("encrypter");
} catch (Throwable $error) {
    fwrite(STDERR, "Deployment configuration error: APP_KEY is missing or invalid. Set a persistent APP_KEY in the Render service environment and redeploy.\n");
    exit(1);
}
'

# PHP-FPM reads the resolved configuration even if its environment is filtered.
php artisan config:cache --no-interaction

exec /start.sh
