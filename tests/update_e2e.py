"""UI test of the 'Update data' dialog against the local app + mock Supabase, using the real source workbooks."""
import json, urllib.request, sys
from playwright.sync_api import sync_playwright
urllib.request.urlopen('http://127.0.0.1:54321/__reset').read()
B = 'http://127.0.0.1:8081'; X = '/tmp/xin/'
def route(r):
    u = r.request.url
    if 'exceljs' in u: r.fulfill(path='/tmp/xl/node_modules/exceljs/dist/exceljs.min.js', content_type='text/javascript')
    elif u.startswith('http://127.0.0.1'): r.continue_()
    else: r.abort()
ok = True
def check(n, c, x=''):
    global ok; print(('PASS ' if c else 'FAIL ') + n + ('' if c else ' ' + str(x))); ok = ok and c
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={'width': 1300, 'height': 900}); errs = []; csp = []
    pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.on('console', lambda m: (csp.append(m.text) if 'Content Security Policy' in m.text else (errs.append(m.text) if m.type == 'error' and 'ERR_FAILED' not in m.text and 'status of 403' not in m.text else None)))
    pg.route('**/*', route)
    pg.goto(B + '/'); pg.fill('#em', 'bob@ei6.test'); pg.fill('#pw', 'bob-pass'); pg.click('#go'); pg.wait_for_url('**/app'); pg.wait_for_timeout(800)
    check('member (not admin) sees the Update data button on the PAF page', pg.is_visible('#upd-open'))
    box_u = pg.locator('.units').bounding_box(); box_b = pg.locator('#upd-open').bounding_box()
    check('button sits above the Metric / US Units switch', box_b['y'] + box_b['height'] <= box_u['y'] + 2, (box_b, box_u))
    pg.click('#upd-open'); pg.wait_for_timeout(300)
    check('Update disabled with nothing chosen', pg.is_disabled('#upd-go'))
    pg.locator('input[data-k=prices]').set_input_files(X + 'mach.xlsx'); pg.locator('input[data-k=devaluation]').set_input_files(X + 'dev.xlsx'); pg.locator('input[data-k=parma]').set_input_files(X + 'parma.xlsx')
    pg.locator('.upd-st.ok').nth(2).wait_for(timeout=60000)
    st = [t.replace('\n', ' ') for t in pg.locator('.upd-st').all_inner_texts()]; print('  ', st)
    check('summaries show counts', '34 block prices' in st[0] and '57 rod prices' in st[0] and 'model v5' in st[1] and '1339 stock lines' in st[2], st)
    check('Update still disabled without a password', pg.is_disabled('#upd-go'))
    pg.fill('#upd-pw', 'wrong-password'); pg.click('#upd-go'); pg.wait_for_timeout(800)
    check('wrong password refused with a clear message', 'Wrong unlock password' in pg.inner_text('.upd-dlg .msg'), pg.inner_text('.upd-dlg .msg'))
    check('password field is masked', pg.get_attribute('#upd-pw', 'type') == 'password')
    pg.fill('#upd-pw', 'unlock-pass-123'); pg.click('#upd-go'); pg.wait_for_selector('.upd-reload', timeout=20000)
    check('correct password updates', 'Data updated' in pg.inner_text('.upd-dlg .msg'))
    log = json.load(urllib.request.urlopen('http://127.0.0.1:54321/__log')); 
    pg.reload(); pg.wait_for_timeout(800)
    d = pg.evaluate("() => { const d = JSON.parse(document.getElementById('ei6-data').textContent); return {n: d.prices_blocks.length, r: d.prices_rods.length, q: d.prices_rounds.length, p: d.parma_stock.rows.length, s: d.devaluation.source}; }")
    check('tool now runs on the uploaded data', d['n'] == 34 and d['r'] == 57 and d['q'] == 28 and d['p'] == 1339 and d['s'] == 'dev.xlsx', d)
    pg.click('#pafnav button[data-pg="0"]'); pg.wait_for_timeout(400)
    check('button is hidden inside a part calculator', not pg.is_visible('#upd-open'))
    pg.click('#pafnav button[data-pg="-1"]'); pg.wait_for_timeout(300)
    check('button is back on the PAF page', pg.is_visible('#upd-open'))
    pg.click('#upd-open'); pg.locator('input[data-k=parma]').set_input_files(X + 'dev.xlsx'); pg.wait_for_selector('.upd-st.bad', timeout=20000)
    check('wrong file gives a readable error', 'Could not read this file' in pg.inner_text('.upd-st.bad'), pg.inner_text('.upd-st.bad'))
    pg.keyboard.press('Escape'); pg.wait_for_timeout(200)
    check('Esc closes the dialog', pg.locator('.upd-dlg').count() == 0)
    check('no CSP violations', not csp, csp); check('no console errors', not errs, errs)
    b.close()
print('ALL PASSED' if ok else 'FAILED'); sys.exit(0 if ok else 1)
