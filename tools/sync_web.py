#!/usr/bin/env python3
"""Inlines web/importer.js and web/update_ui.js into templates/app.html (between the markers) so the page needs no extra
script file (strict CSP: only the nonce'd inline script and the pinned ExcelJS CDN file are allowed). Re-run after editing web/*.js."""
import re, pathlib
root = pathlib.Path(__file__).resolve().parent.parent
t = root / 'templates' / 'app.html'
s = t.read_text()
block = '/*WEB:BEGIN*/\n' + (root / 'web' / 'importer.js').read_text() + '\n' + (root / 'web' / 'update_ui.js').read_text() + '\n/*WEB:END*/'
if '/*WEB:BEGIN*/' in s:
    s = re.sub(r'/\*WEB:BEGIN\*/.*?/\*WEB:END\*/', lambda m: block, s, flags=re.S)
else:
    i = s.rindex('</script>')
    s = s[:i] + block + '\n' + s[i:]
t.write_text(s)
print('synced', len(block), 'bytes')
