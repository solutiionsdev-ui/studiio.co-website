"""STUDIIO admin: read a Reels Montage PRO project's automatic plan (phrases, big words and the
effect its SMART PICK chose for each phrase) and save it as studiio-auto.json in the project.
The admin turns that into beats.json. Run it with the package's own Python:

    ~/reels-montage-pro/.venv/bin/python admin/tools/reels_auto.py ~/reels-montage-pro/projects/NAME
"""
import json
import os
import sys

proj = os.path.abspath(sys.argv[1])
root = os.path.dirname(os.path.dirname(proj))
sys.path.insert(0, os.path.join(root, 'engine'))

from playwright.sync_api import sync_playwright  # noqa: E402  (installed in the package's .venv)
from common import page_setup  # noqa: E402

with sync_playwright() as pw:
    browser, page, meta = page_setup(pw, proj, 'front')
    data = page.evaluate("""() => window.STUDIIO_AUTO || {
        big: BIG.map(r => r.slice()), kinds: PH.map(p => p.kind), raw: PH.map(p => p.raw)}""")
    browser.close()

data['dur'] = meta['dur']
with open(os.path.join(proj, 'studiio-auto.json'), 'w', encoding='utf-8') as fh:
    json.dump(data, fh, ensure_ascii=False)
print('auto beats:', len(data['big']))
