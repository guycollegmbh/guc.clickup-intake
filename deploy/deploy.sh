#!/usr/bin/env bash
# Deploy guc.clickup-intake on vps06 from origin/main.
# Run as the admin user (guycolle) on the server:  /var/www/clickup-intake/deploy/deploy.sh
# The app runs as the system user "clickupintake" (PHP-FPM pool, worker, scheduler).
set -euo pipefail

APP_DIR=/var/www/clickup-intake
APP_USER=clickupintake

cd "$APP_DIR"

echo "== fetch"
git fetch --quiet origin main
if [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && [ "${1:-}" != "--force" ]; then
    echo "Already at $(git rev-parse --short HEAD). Use --force to redeploy."
    exit 0
fi

echo "== maintenance on"
sudo -u "$APP_USER" php artisan down --retry=30 || true

trap 'echo "!! deploy failed, app stays in maintenance mode. Fix and rerun, or: sudo -u '"$APP_USER"' php artisan up"' ERR

git merge --ff-only --quiet origin/main
echo "now at $(git log --oneline -1)"

echo "== dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress
npm ci --no-audit --no-fund --silent
npm run build --silent

echo "== permissions"
sudo chgrp -R "$APP_USER" storage bootstrap/cache database
sudo chmod -R g+rwX storage bootstrap/cache database

echo "== migrate and cache"
sudo -u "$APP_USER" php artisan migrate --force
sudo -u "$APP_USER" php artisan optimize
sudo -u "$APP_USER" php artisan filament:optimize

echo "== restart"
sudo systemctl reload php8.4-fpm
sudo -u "$APP_USER" php artisan queue:restart

sudo -u "$APP_USER" php artisan up
trap - ERR
echo "== done: $(git log --oneline -1)"
