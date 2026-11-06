#!/bin/sh
set -eu
: "${APP_KEY:?Set a persistent APP_KEY in Vercel environment variables}"
: "${PASSPORT_PRIVATE_KEY:?Set the persistent Passport private key in Vercel}"
: "${PASSPORT_PUBLIC_KEY:?Set the matching Passport public key in Vercel}"
php artisan package:discover --no-interaction
php artisan config:cache --no-interaction
# Opt in only for database provisioning or a reviewed schema deployment.
if [ "${INITIALIZE_DATABASE:-false}" = "true" ]; then
    php docker/initialize-database.php
fi
exec apache2-foreground
