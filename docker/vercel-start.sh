#!/bin/sh
set -eu
# These must stay stable across deployments and instances. Never create keys here.
: "${APP_KEY:?Set a persistent APP_KEY in Vercel environment variables}"
: "${PASSPORT_PRIVATE_KEY:?Set the persistent Passport private key in Vercel}"
: "${PASSPORT_PUBLIC_KEY:?Set the matching Passport public key in Vercel}"
php artisan package:discover --no-interaction
php artisan config:cache --no-interaction
# Do not run migrations/client creation on each cold start.
exec apache2-foreground
