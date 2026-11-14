#!/usr/bin/env bash
# The app page's Content-Security-Policy allows exactly one inline script (the theme script in frontend/index.html)
# by its sha256 hash. When that script changes, the hash must change in every server config too – this checks it.
# Run in CI (Package workflow) and by hand: ./deploy/check-csp.sh
set -euo pipefail
cd "$(dirname "$0")/.."
HASH=$(python3 - <<'PY'
import base64, hashlib, re
html = open('frontend/index.html', encoding='utf-8').read()
scripts = re.findall(r'<script>(.*?)</script>', html, re.S)
assert len(scripts) == 1, f'expected exactly one inline <script> in frontend/index.html, found {len(scripts)}'
print(base64.b64encode(hashlib.sha256(scripts[0].encode()).digest()).decode())
PY
)
fail=0
csp_of() { grep -o "Content-Security-Policy \"default-src 'self';[^\"]*\"" "$1" | head -1; }
for f in backend/public/.htaccess deploy-guide/files/nginx/hotelms.conf docs/hosting.md; do
  if grep -q "'sha256-$HASH'" "$f"; then echo "ok   $f"; else echo "FAIL $f: the app CSP must allow 'sha256-$HASH'"; fail=1; fi
done
a=$(csp_of backend/public/.htaccess); b=$(csp_of deploy-guide/files/nginx/hotelms.conf); c=$(csp_of docs/hosting.md)
if [ "$a" = "$b" ] && [ "$a" = "$c" ]; then echo "ok   the CSP is the same in .htaccess, the Nginx example and docs/hosting.md"; else echo "FAIL the CSP differs between .htaccess, the Nginx example and docs/hosting.md"; fail=1; fi
exit $fail
