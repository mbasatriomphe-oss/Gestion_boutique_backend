#!/bin/sh
set -eu

cd /var/www/html

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
