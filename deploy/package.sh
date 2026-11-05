#!/usr/bin/env bash
# Builds one folder ready to upload to shared hosting (cPanel) or a VPS:
#   build/hotelms/            ← upload this folder (NOT into public_html)
#   build/hotelms/public/     ← point the domain's document root here
# Needs: PHP 8.3 + Composer, Node 22 (on your laptop or the build server).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
OUT="$ROOT/build/hotelms"

echo "1/4 Web app"
( cd frontend && npm ci --no-audit --no-fund && VITE_API_URL= npm run build )

echo "2/4 API"
rm -rf "$OUT" && mkdir -p "$OUT"
rsync -a --exclude node_modules --exclude vendor --exclude .env --exclude 'storage/logs/*' --exclude 'storage/app/backups' --exclude tests backend/ "$OUT/"
( cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction )

echo "3/4 Web app into public/ (as app.html, so / opens the app and /api stays Laravel)"
cp -R frontend/dist/assets "$OUT/public/assets"
cp frontend/dist/index.html "$OUT/public/app.html"
find frontend/dist -maxdepth 1 -type f ! -name index.html -exec cp {} "$OUT/public/" \;

echo "4/4 Zip"
( cd "$ROOT/build" && rm -f hotelms.zip && zip -qr hotelms.zip hotelms )
echo "Done: build/hotelms.zip – see docs/hosting.md"
