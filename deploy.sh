#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
#  Regenesis - Incremental deployment script (runs on the Hostinger host).
#
#  Mirrors C:\Dev\enhanceify-V2\deploy.sh; differs only in APP_DIR.
#
#  Usage (on server): ~/laravel/deploy.sh
#  Usage (locally):   pwsh ./deploy.ps1   (companion PowerShell wrapper)
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

PHP=/opt/alt/php84/usr/bin/php
COMPOSER=/usr/local/bin/composer2
APP_DIR="${APP_DIR:-/home/u408983312/domains/regenesis.enhanceify.co.uk/laravel}"

cd "$APP_DIR" || { echo "Cannot find $APP_DIR"; exit 1; }

echo ""
echo "=================================================="
echo " regenesis.enhanceify.co.uk - Deploy  $(date '+%Y-%m-%d %H:%M %Z')"
echo "=================================================="

echo ""
echo "[1/7] Pulling latest code from main..."
git pull origin main

echo ""
echo "[2/7] Installing production dependencies..."
$PHP $COMPOSER install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

echo ""
echo "[3/7] Running database migrations..."
$PHP artisan migrate --force

echo ""
echo "[3.5/7] Ensuring storage symlink..."
$PHP artisan storage:link 2>&1 | grep -v "already exists" || true

echo ""
echo "[4/7] Clearing caches..."
$PHP artisan optimize:clear

echo ""
echo "[5/7] Warming caches..."
$PHP artisan optimize

echo ""
echo "[6/7] Restarting queue workers..."
# Database queue driver. Telling any running workers to exit so the next
# cron kick picks up new code. Safe to run even if no workers are
# currently active.
$PHP artisan queue:restart || true

echo ""
echo "[7/7] Running smoke test..."
# Laravel's built-in /up health endpoint. Always present; returns 200
# when the app is bootable.
if curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1/up 2>/dev/null | grep -q 200; then
    echo "  /up returned 200"
fi

echo ""
echo "=================================================="
echo " Deployment complete  $(date '+%H:%M %Z')"
echo "=================================================="
echo ""
