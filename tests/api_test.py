"""API + security tests against the local PHP app and the mock Supabase."""
import json, sys, urllib.request, urllib.error, http.cookiejar
B = 'http://127.0.0.1:8081'
H = {'X-Requested-With': 'ei6', 'Content-Type': 'application/json'}
fails = []
def check(name, cond, extra=''):
    print(('PASS ' if cond else 'FAIL ') + name + (' ' + str(extra) if not cond else ''))
    if not cond: fails.append(name)
class C:
    def __init__(s):
        s.cj = http.cookiejar.CookieJar()
        s.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(s.cj), type('NR', (urllib.request.HTTPRedirectHandler,), {'redirect_request': lambda *a, **k: None}))
    def req(s, m, p, body=None, headers=None, raw=None):
        h = dict(H if headers is None else headers)
        data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
        r = urllib.request.Request(B + p, data=data, method=m, headers=h)
        try:
            x = s.op.open(r); return x.status, x.read().decode(), x.headers
        except urllib.error.HTTPError as e: return e.code, e.read().decode(), e.headers
    def j(s, *a, **k):
        st, b, h = s.req(*a, **k)
        try: return st, json.loads(b)
        except Exception: return st, b
urllib.request.urlopen('http://127.0.0.1:54321/__reset').read()
anon = C()
st, _, h = anon.req('GET', '/app'); check('anon /app redirects to /', st == 302 and h['Location'] == '/')
st, d = anon.j('GET', '/api/pafs'); check('anon API is 401', st == 401)
st, d = anon.j('POST', '/api/login', {'email': 'alice@ei6.test', 'password': 'x'}); check('bad password 401 generic', st == 401 and d['error'] == 'Invalid e-mail or password', d)
st, d = anon.j('POST', '/api/login', {'email': 'nobody@x.test', 'password': 'x'}); check('unknown user same message', st == 401 and d['error'] == 'Invalid e-mail or password')
st, d = anon.j('POST', '/api/login', {'email': 'alice@ei6.test', 'password': 'alice-pass'}, headers={'Content-Type': 'application/json'}); check('login without CSRF header refused', st == 403)
st, d = anon.j('POST', '/api/login', {'email': 'alice@ei6.test', 'password': 'alice-pass'}, headers={**H, 'Origin': 'https://evil.example'}); check('cross-origin login refused', st == 403)
st, d = anon.j('POST', '/api/login', {'email': 'alice@ei6.test', 'password': 'alice-pass'}, headers={**H, 'Sec-Fetch-Site': 'cross-site'}); check('cross-site fetch metadata refused', st == 403)
eve = C(); st, d = eve.j('POST', '/api/login', {'email': 'eve@ei6.test', 'password': 'eve-pass'}); check('valid auth user WITHOUT profile is denied', st == 403, d)
check('no cookie issued for non-member', len(list(eve.cj)) == 0)
st, d = eve.j('GET', '/api/pafs'); check('non-member API 401', st == 401)

alice = C(); st, d = alice.j('POST', '/api/login', {'email': 'alice@ei6.test', 'password': 'alice-pass'}); check('alice login ok', st == 200 and d['user']['role'] == 'admin', d)
ck = {c.name: c for c in alice.cj}
check('cookies HttpOnly + SameSite=Strict', all(c.has_nonstandard_attr('HttpOnly') for c in ck.values()) and True)
st, b, h = alice.req('GET', '/app'); check('/app renders for member', st == 200 and 'id="ei6-data"' in b and '{{' not in b)
import re
nonce = re.search(r"script-src 'nonce-([^']+)'", h['Content-Security-Policy']).group(1)
check('CSP nonce used on inline script', f'<script nonce="{nonce}">' in b)
check('no unreplaced placeholders / secrets in page', 'anon-key' not in b and 'test-secret' not in b)
# data island must not allow script breakout
st, d = alice.j('PUT', '/api/admin/datasets/prices_rods', {'data': [{'name': '</script><script>alert(1)</script>', 'lb': 1, 'kg': 1}]})
st, b, h = alice.req('GET', '/app'); check('dataset with </script> cannot break out of JSON island', '</script><script>alert(1)' not in b)
# restore dataset
orig = json.load(open('supabase/seed/prices_rods.json')); alice.j('PUT', '/api/admin/datasets/prices_rods', {'data': orig})

body = {'number': 'PAF-001', 'customer': 'ACME', 'created_on': '2026-10-28', 'parts': [{'desc': 'Seal', 'no': 'A1', 'snap': {'v': {'x': 1}}}]}
st, d = alice.j('POST', '/api/pafs', body); check('create PAF', st == 201 and d['version'] == 1, d); pid = d.get('id')
st, d = alice.j('POST', '/api/pafs', {**body, 'number': ' paf-001 '}); check('duplicate number (case/space) -> 409', st == 409, d)
st, d = alice.j('POST', '/api/pafs', {**body, 'number': ''}); check('empty number -> 422', st == 422)
st, d = alice.j('POST', '/api/pafs', {**body, 'created_on': '2026-02-31'}); check('invalid date -> 422', st == 422)
st, d = alice.j('POST', '/api/pafs', {**body, 'number': 'P2', 'parts': []}); check('no parts -> 422', st == 422)
st, d = alice.j('POST', '/api/pafs', {**body, 'number': 'P3', 'parts': [{'desc': 'x', 'no': 'y', 'snap': {'__proto__': {'a': 1}}}]}, raw=b'{"number":"P3","customer":"","created_on":"2026-10-28","parts":[{"desc":"x","no":"y","snap":{"__proto__":{"a":1}}}]}'); check('__proto__ key rejected', st == 422, d)
st, d = alice.j('POST', '/api/pafs', {**body, 'number': 'P4', 'customer': 'x' * 81}); check('customer too long -> 422', st == 422)
st, d = alice.j('POST', '/api/pafs', raw=b'{not json', headers=H); check('malformed JSON -> 400', st == 400)
st, d = alice.j('POST', '/api/pafs', raw=b'{}', headers={'X-Requested-With': 'ei6', 'Content-Type': 'text/plain'}); check('wrong content-type -> 415', st == 415)
big = {**body, 'number': 'BIG', 'parts': [{'desc': 'x', 'no': 'y', 'snap': {'blob': 'a' * 3_100_000}}]}
st, d = alice.j('POST', '/api/pafs', big); check('oversize payload -> 413', st == 413, st)
st, d = alice.j('GET', '/api/pafs'); check('list PAFs shows creator email', st == 200 and d['items'][0]['created_by'] == 'alice@ei6.test', d)
st, d = alice.j('GET', '/api/pafs/' + pid); check('get PAF with payload', st == 200 and d['payload']['parts'][0]['no'] == 'A1')
st, d = alice.j('GET', '/api/pafs/not-a-uuid'); check('non-uuid id -> 404', st == 404)
st, d = alice.j('GET', "/api/pafs/" + pid + "?x=1%27%20or%201=1"); check('query junk ignored', st == 200)

bob = C(); bob.j('POST', '/api/login', {'email': 'bob@ei6.test', 'password': 'bob-pass'})
st, d = bob.j('GET', '/api/pafs'); check('member sees team PAFs', st == 200 and len(d['items']) == 1)
st, d = bob.j('PUT', '/api/pafs/' + pid, {**body, 'customer': 'ACME 2', 'version': 1}); check('member edits PAF', st == 200 and d['version'] == 2, d)
st, d = alice.j('PUT', '/api/pafs/' + pid, {**body, 'customer': 'stale', 'version': 1}); check('stale version -> 409 conflict', st == 409, d)
st, d = alice.j('PUT', '/api/pafs/' + pid, {**body}); check('missing version -> 422', st == 422)
st, d = bob.j('DELETE', '/api/pafs/' + pid); check('member cannot delete others PAF', st == 403, d)
st, d = bob.j('GET', '/api/admin/datasets/devaluation'); check('member cannot read admin dataset API', st == 403)
st, d = bob.j('PUT', '/api/admin/datasets/devaluation', {'data': []}); check('member cannot write datasets', st == 403)
st, d = alice.j('PUT', '/api/admin/datasets/evil_table', {'data': []}); check('unknown dataset key 404', st == 404)
st, d = alice.j('DELETE', '/api/pafs/' + pid); check('admin deletes', st == 200)
st, b, h = alice.req('POST', '/api/logout', {}); check('logout ok', st == 200)
st, d = alice.j('GET', '/api/pafs'); check('after logout API is 401', st == 401)
st, b, h = anon.req('GET', '/healthz'); check('healthz', st == 200)
st, b, h = anon.req('GET', '/api/nope'); check('unknown route 401/404 without details', st in (401, 404) and 'Fatal' not in b)
st, b, h = anon.req('GET', '/'); check('security headers present', all(x in h for x in ['X-Content-Type-Options', 'Content-Security-Policy', 'X-Frame-Options', 'Referrer-Policy']))
print('\nFAILED:' if fails else '\nALL PASSED', fails)
sys.exit(1 if fails else 0)
