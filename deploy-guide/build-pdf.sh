#!/usr/bin/env bash
# Makes pdf/index.pdf, pdf/shared-hosting.pdf and pdf/vps.pdf from the Markdown guides.
# Needs pandoc and Google Chrome (or Chromium). Run from anywhere: ./deploy-guide/build-pdf.sh
set -euo pipefail
cd "$(dirname "$0")"
CHROME="$(command -v google-chrome || command -v chromium || command -v chromium-browser)"
mkdir -p pdf
for doc in index shared-hosting vps; do
  pandoc "$doc.md" --standalone --embed-resources --css pdf.css --no-highlight --metadata lang=en -o "/tmp/hotelms-guide-$doc.html"
  "$CHROME" --headless=new --no-sandbox --disable-gpu --no-pdf-header-footer \
    --print-to-pdf="pdf/$doc.pdf" "file:///tmp/hotelms-guide-$doc.html" >/dev/null 2>&1
  echo "pdf/$doc.pdf"
done
