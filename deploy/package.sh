#!/usr/bin/env bash
# Builds one folder ready to upload to shared hosting (cPanel) or a VPS:
#   build/hotelms/            ← upload this folder (NOT into public_html)
#   build/hotelms/public/     ← point the domain's document root here
# Needs: PHP 8.3 + Composer, Node 22 (on your laptop or the build server).
#
# Only the code goes into the package – never files from your own computer's work folder:
# no .env, no uploads, logs, sessions or caches (storage/ and bootstrap/cache/ are created empty),
# no tests, no development Docker files. CI runs this script on pull requests that change the app or this script, and checks that (.github/workflows/package.yml).
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
OUT="$ROOT/build/hotelms"

echo "1/4 Web app"
( cd frontend && npm ci --no-audit --no-fund && VITE_API_URL= npm run build )

echo "2/4 API"
rm -rf "$OUT" && mkdir -p "$OUT"
rsync -a \
  --exclude node_modules --exclude vendor --exclude tests --exclude phpunit.xml \
  --exclude /Dockerfile --exclude /.dockerignore --exclude /docker/ \
  --exclude '.env' --exclude '.env.backup' --exclude '.env.production' --exclude '.env.testing' \
  --exclude '.phpunit.result.cache' --exclude '.phpunit.cache' --exclude '*.log' \
  --exclude '/storage/' --exclude '/bootstrap/cache/*.php' \
  --exclude '/public/storage' --exclude '/public/hot' --exclude '/public/build' \
  backend/ "$OUT/"
# Empty storage folders (Laravel needs them; uploads, logs, sessions and caches are made on the server).
for dir in app/public app/private framework/cache/data framework/sessions framework/views logs; do
  mkdir -p "$OUT/storage/$dir"
  printf '*\n!.gitignore\n' > "$OUT/storage/$dir/.gitignore"   # keeps the empty folder in the zip
done
mkdir -p "$OUT/bootstrap/cache"
( cd "$OUT" && composer install --no-dev --optimize-autoloader --no-interaction )

echo "   Developer docs (Super Admin → Developer docs; not public)"
cp -R docs "$OUT/docs"
# The deployment guide PDFs too (shown there as well; outside public/, so not on the web).
mkdir -p "$OUT/deploy-guide" && cp -R deploy-guide/pdf "$OUT/deploy-guide/pdf"

echo "3/4 Web app into public/ (as app.html, so / opens the app and /api stays Laravel)"
cp frontend/dist/index.html "$OUT/public/app.html"
find frontend/dist -maxdepth 1 -type f ! -name index.html -exec cp {} "$OUT/public/" \;
# Every folder of the build: assets/ (code and styles) and guide/ (staff-guide pictures, Help → How to use).
find frontend/dist -mindepth 1 -maxdepth 1 -type d -exec cp -R {} "$OUT/public/" \;

echo "4/4 Zip"
( cd "$ROOT/build" && rm -f hotelms.zip && zip -qr hotelms.zip hotelms )
echo "Done: build/hotelms.zip – see docs/hosting.md"
