#!/usr/bin/env python3
"""Make the website's small Amharic font again (only the letters of backend/lang/am*): run after changing those texts.

Needs: pip install fonttools brotli.  Run from the repository root: python3 scripts/landing-font.py
"""
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
text = (ROOT / 'backend/lang/am.json').read_text(encoding='utf-8')
text += ''.join(p.read_text(encoding='utf-8') for p in (ROOT / 'backend/lang/am').glob('*.php'))
letters = ''.join(sorted({c for c in text if 0x1200 <= ord(c) <= 0x137F}))
with tempfile.NamedTemporaryFile('w', encoding='utf-8', suffix='.txt', delete=False) as f:
    f.write(letters)
out = ROOT / 'backend/public/landing/fonts/noto-sans-ethiopic-400.woff2'
subprocess.run(['pyftsubset', str(ROOT / 'frontend/public/fonts/noto-sans-ethiopic-400.woff2'), f'--text-file={f.name}',
                '--flavor=woff2', '--layout-features=*', f'--output-file={out}'], check=True)
print(f'{len(letters)} letters -> {out.relative_to(ROOT)} ({out.stat().st_size // 1024} KB)')
