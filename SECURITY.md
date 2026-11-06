# Security notes

## Threat model in one paragraph
Internal tool for the Ei6 team. It holds commercial data (prices, margins, vendor quotes). Goals: only invited staff can read or
write; one user cannot corrupt another's work silently; no secrets in the browser; resilient to the usual web attacks.

## Controls implemented (and where)
| Risk | Control | Where |
|---|---|---|
| Unauthorised access | Supabase Auth (e-mail + password); **membership gate**: a valid Supabase user without a row in `profiles` gets nothing (no cookie issued, RLS returns no rows) | `Router::login`, `Session`, SQL `is_member()` |
| Authorisation bypass | **Row Level Security** on every table, evaluated by Postgres with the caller's own JWT. The PHP layer never uses the service-role key, so a bug in PHP cannot exceed what the user is allowed | `supabase/migrations/0001_init.sql` |
| Token theft via XSS | Tokens only in `HttpOnly; Secure; SameSite=Strict` `__Host-` cookies; never exposed to JavaScript | `Session` |
| XSS | Per-response **CSP nonce** (no inline script without it, no `unsafe-inline` scripts, `object-src 'none'`, `base-uri 'none'`); datasets injected as inert JSON with `JSON_HEX_*`; saved states sanitised before they reach templates; user text set with `.value`/`textContent` | `Http::securityHeaders`, `App`, `snapClean` in `templates/app.html` |
| CSRF | SameSite=Strict + required `X-Requested-With: ei6` header + `Sec-Fetch-Site`/`Origin` same-origin check on every write | `Http::requireSameOrigin` |
| Clickjacking | `frame-ancestors 'none'`, `X-Frame-Options: DENY` | headers |
| Supply chain | Only one third-party script (ExcelJS 4.4.0) loaded with **Subresource Integrity**; allow-listed in CSP | `loadJs` |
| Lost updates | **Optimistic locking** (`version`), server-controlled timestamps/authorship in a trigger, full **version history** (`paf_versions`) and **audit log** | SQL triggers |
| Injection | No SQL built by hand: PostgREST with URL-encoded filters; ids validated as UUID; payload schema validated; `__proto__`/`constructor` keys rejected | `PafValidator`, `Pafs` |
| Resource abuse | 3 MB body cap in PHP and in DB `CHECK`; ≤ 50 parts/PAF; bounded JSON depth | `Http::body`, SQL |
| Info leak | Generic login error (no user enumeration, jittered delay), no stack traces, `no-store` caching, `no-referrer` | `Router`, `Http` |
| Transport | HSTS (preload), TLS verification forced for outgoing curl | `Http`, `Supabase` |

## Operational checklist (do these once)
1. Supabase > Authentication > Providers > Email: **turn OFF "Allow new users to sign up"**; enable "Confirm e-mail"; set a strong
   password policy (min 12 chars, leaked-password protection if your plan has it).
2. Supabase > Authentication > Rate Limits: keep defaults or tighten sign-in attempts.
3. Invite users from the dashboard, then run `select public.grant_access('user@ei6.com','member');` (or `'admin'`).
4. Enable MFA for admin accounts (Supabase supports TOTP).
5. Vercel: enable **Deployment Protection** for preview deployments; set env vars only for the environments that need them.
6. Add `Allowed Redirect URLs` in Supabase only for your real domain.
7. Rotate the JWT secret / keys if they were ever pasted anywhere public.
8. Supabase > Database > Backups (Pro: PITR). Export `datasets` and `pafs` regularly if on the free plan.

## Known limits (be aware)
* No per-IP rate limiting of our own: serverless instances share no memory. Login throttling relies on Supabase Auth's limits
  (and optionally Vercel WAF rules / Firewall).
* All members can read and edit all PAFs by design (small trusted team). Tighten the `pafs_*` policies if that changes.
* The ExcelJS file is produced in the browser: the data in it is whatever the signed-in user can already see.

## Reference-data upload (unlock password)
* Any member can *attempt* an upload; only the holder of the unlock password succeeds. The check happens inside the database
  (`update_datasets`, SECURITY DEFINER, `search_path=''`), not in the browser or PHP: a member cannot write `datasets` directly (RLS stays admin-only).
* The password is stored as a bcrypt hash in `app_secrets`, which no API role can read; `set_upload_password` is not callable from the API.
* Brute force: 5 failures per user per 15 min and 30 overall per hour lock the function (failures are recorded because a wrong password
  returns a value instead of raising, so the transaction commits). Successful and failed uploads leave an `audit_log` row (`upload`).
* PHP forwards the password once, over TLS, never logs or stores it; the payload shape is validated (`DatasetValidator`) and rebuilt from a whitelist
  before it reaches the database. All datasets in one upload are replaced in a single transaction.
* The password protects against mistakes and casual misuse by signed-in colleagues; anyone who knows it can replace the data. Change it when someone leaves.
