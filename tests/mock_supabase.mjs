// Minimal in-memory stand-in for Supabase Auth + PostgREST, enforcing the same rules as the SQL policies.
import http from 'node:http'; import crypto from 'node:crypto'; import fs from 'node:fs'; import path from 'node:path';
const SECRET = process.env.JWT_SECRET || 'test-secret', PORT = +(process.env.PORT || 54321), ANON = 'anon-key';
const b64 = (b) => Buffer.from(b).toString('base64url');
const sign = (claims) => { const h = b64(JSON.stringify({ alg: 'HS256', typ: 'JWT' })), p = b64(JSON.stringify(claims)); return `${h}.${p}.${crypto.createHmac('sha256', SECRET).update(h + '.' + p).digest('base64url')}`; };
const uid = (n) => `00000000-0000-4000-8000-00000000000${n}`;
const users = [
  { id: uid(1), email: 'alice@ei6.test', password: 'alice-pass', role: 'admin' },
  { id: uid(2), email: 'bob@ei6.test', password: 'bob-pass', role: 'member' },
  { id: uid(3), email: 'eve@ei6.test', password: 'eve-pass', role: null }, // valid auth user, NO profile
];
const profiles = users.filter((u) => u.role).map((u) => ({ id: u.id, email: u.email, role: u.role }));
const seedDir = path.resolve('supabase/seed');
const datasets = Object.fromEntries(['prices_blocks', 'prices_rods', 'prices_rounds', 'devaluation', 'parma_stock'].map((k) => [k, { key: k, version: 1, updated_at: new Date().toISOString(), data: JSON.parse(fs.readFileSync(path.join(seedDir, k + '.json'), 'utf8')) }]));
const pafs = []; const refresh = new Map(); let log = [];
const claims = (u) => ({ sub: u.id, aud: 'authenticated', role: 'authenticated', email: u.email, exp: Math.floor(Date.now() / 1000) + 3600 });
const issue = (u) => { const rt = crypto.randomBytes(16).toString('hex'); refresh.set(rt, u.id); return { access_token: sign(claims(u)), refresh_token: rt, expires_in: 3600, user: { id: u.id, email: u.email } }; };
function who(req) {
  if (req.headers.apikey !== ANON) return null;
  const t = (req.headers.authorization || '').replace(/^Bearer /, ''); const p = t.split('.');
  if (p.length !== 3) return null;
  const ok = crypto.createHmac('sha256', SECRET).update(p[0] + '.' + p[1]).digest('base64url') === p[2];
  if (!ok) return null; const c = JSON.parse(Buffer.from(p[1], 'base64url')); if (c.exp < Date.now() / 1000) return null;
  return users.find((u) => u.id === c.sub) || null;
}
const send = (res, st, body) => { res.writeHead(st, { 'content-type': 'application/json' }); res.end(body === undefined ? '' : JSON.stringify(body)); };
const embed = (r) => ({ ...r, creator: { email: (profiles.find((p) => p.id === r.created_by) || {}).email }, updater: { email: (profiles.find((p) => p.id === r.updated_by) || {}).email } });
const filt = (rows, q) => { for (const [k, v] of q) { if (v.startsWith('eq.')) rows = rows.filter((r) => String(r[k]) === v.slice(3)); } return rows; };
http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x'); let raw = ''; for await (const c of req) raw += c; const body = raw ? JSON.parse(raw) : null;
  const q = [...url.searchParams.entries()].filter(([k]) => !['select', 'order', 'limit'].includes(k));
  if (url.pathname === '/__log') return send(res, 200, log);
  if (url.pathname === '/__reset') { pafs.length = 0; log = []; return send(res, 200, {}); }
  if (url.pathname === '/auth/v1/token') {
    if (req.headers.apikey !== ANON) return send(res, 401, { msg: 'bad key' });
    if (url.searchParams.get('grant_type') === 'password') { const u = users.find((x) => x.email === body.email && x.password === body.password); return u ? send(res, 200, issue(u)) : send(res, 400, { error: 'invalid_grant' }); }
    const id = refresh.get(body.refresh_token); const u = users.find((x) => x.id === id); if (!u) return send(res, 400, { error: 'invalid_grant' }); refresh.delete(body.refresh_token); return send(res, 200, issue(u));
  }
  const me = who(req);
  if (url.pathname === '/auth/v1/user') return me ? send(res, 200, { id: me.id, email: me.email }) : send(res, 401, { msg: 'bad jwt' });
  if (url.pathname === '/auth/v1/logout') { log.push('logout:' + (me && me.email)); return send(res, 204); }
  if (!url.pathname.startsWith('/rest/v1/')) return send(res, 404, {});
  if (!me) return send(res, 401, { message: 'JWT invalid' });
  const t = url.pathname.slice(9), member = !!me.role, admin = me.role === 'admin';
  if (t === 'profiles') { const own = profiles.filter((p) => member || p.id === me.id); return send(res, 200, filt(own, q)); }
  if (t === 'datasets') {
    if (!member) return send(res, 200, []);
    if (req.method === 'GET') return send(res, 200, filt(Object.values(datasets), q));
    if (req.method === 'PATCH') { if (!admin) return send(res, 200, []); const k = q.find(([a]) => a === 'key')[1].slice(3); datasets[k].data = body.data; datasets[k].version++; return send(res, 200, [datasets[k]]); }
  }
  if (t === 'pafs') {
    if (req.method === 'GET') return send(res, 200, member ? filt(pafs, q).map(embed) : []);
    if (req.method === 'POST') {
      if (!member) return send(res, 403, { code: '42501' });
      if (pafs.some((p) => p.number.trim().toLowerCase() === body.number.trim().toLowerCase())) return send(res, 409, { code: '23505' });
      const now = new Date().toISOString(); const r = { id: crypto.randomUUID(), ...body, version: 1, created_by: me.id, updated_by: me.id, created_at: now, updated_at: now }; pafs.push(r); log.push('insert:' + r.number); return send(res, 201, [embed(r)]);
    }
    if (req.method === 'PATCH') {
      if (!member) return send(res, 200, []); const rows = filt(pafs, q); if (!rows.length) return send(res, 200, []);
      const r = rows[0]; if (pafs.some((p) => p !== r && p.number.toLowerCase() === body.number.toLowerCase())) return send(res, 409, { code: '23505' });
      Object.assign(r, body, { version: r.version + 1, updated_by: me.id, updated_at: new Date().toISOString() }); log.push('update:' + r.number + ':v' + r.version); return send(res, 200, [embed(r)]);
    }
    if (req.method === 'DELETE') { const rows = filt(pafs, q).filter((r) => admin || r.created_by === me.id); rows.forEach((r) => pafs.splice(pafs.indexOf(r), 1)); return send(res, 200, rows); }
  }
  send(res, 404, {});
}).listen(PORT, '127.0.0.1', () => console.log('mock supabase on', PORT));
