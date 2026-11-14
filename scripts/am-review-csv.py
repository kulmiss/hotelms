#!/usr/bin/env python3
"""Make docs/i18n/am-review.csv: every Amharic text next to its English original, for a native reviewer.

Run from the repository root: python3 scripts/am-review-csv.py
"""
import csv
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LOC = ROOT / 'frontend/src/i18n/locales'


def flat(tree, prefix=''):
    if isinstance(tree, str):
        yield prefix, tree
    elif isinstance(tree, list):
        for i, x in enumerate(tree):
            yield from flat(x, f'{prefix}[{i}]')
    else:
        for k, v in tree.items():
            if not prefix and k == '_meta':
                continue
            yield from flat(v, f'{prefix}.{k}' if prefix else k)


def load(path):
    return json.loads(path.read_text(encoding='utf-8'))


out = ROOT / 'docs/i18n/am-review.csv'
out.parent.mkdir(parents=True, exist_ok=True)
rows = 0
with out.open('w', newline='', encoding='utf-8-sig') as f:  # with BOM, so Excel shows Amharic
    w = csv.writer(f)
    w.writerow(['file', 'key', 'english', 'amharic', 'status', 'reviewer_note'])
    for en, am in [('en.json', 'am.json'), ('en.guide.json', 'am.guide.json')]:
        amharic = dict(flat(load(LOC / am)))
        for key, text in flat(load(LOC / en)):
            w.writerow([f'frontend/src/i18n/locales/{am}', key, text, amharic.get(key, ''), 'needs native review', ''])
            rows += 1
    for key, text in load(ROOT / 'backend/lang/am.json').items():
        w.writerow(['backend/lang/am.json', key, key, text, 'needs native review', ''])
        rows += 1
print(f'{rows} rows -> {out.relative_to(ROOT)}')
