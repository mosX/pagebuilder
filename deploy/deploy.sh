#!/usr/bin/env bash
#
# Deploys a release of Pagebuilder with near-zero downtime:
#   /var/www/pagebuilder/releases/<timestamp>   each release
#   /var/www/pagebuilder/shared/.env            shared configuration
#   /var/www/pagebuilder/shared/storage         uploads, logs, sessions
#   /var/www/pagebuilder/current -> releases/…  what nginx and supervisor use
#
# Usage: deploy/deploy.sh <git-ref>      (default: main)
set -euo pipefail

APP=/var/www/pagebuilder
REPO=${REPO:-git@github.com:example/pagebuilder.git}
REF=${1:-main}
RELEASE="$APP/releases/$(date +%Y%m%d%H%M%S)"
KEEP=5

echo "→ Release $RELEASE ($REF)"
git clone --depth 1 --branch "$REF" "$REPO" "$RELEASE"
cd "$RELEASE"

# Shared state lives outside releases.
rm -rf storage
ln -s "$APP/shared/storage" storage
ln -s "$APP/shared/.env" .env

composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
# The SSR server imports Vue from node_modules at runtime, so they stay installed.
npm ci
npm run build:ssr

php artisan storage:link
php artisan migrate --force
# Adds system fields introduced by new versions to every company's pages.
php artisan tenants:provision-pages
php artisan optimize        # config, routes, events and views caches

echo "→ Switching current"
ln -sfn "$RELEASE" "$APP/current.tmp"
mv -Tf "$APP/current.tmp" "$APP/current"

sudo systemctl reload php8.4-fpm
php artisan queue:restart
sudo supervisorctl restart pagebuilder-ssr:*

echo "→ Removing old releases"
ls -1dt "$APP"/releases/* | tail -n +$((KEEP + 1)) | xargs -r rm -rf

echo "✓ Deployed $REF"
