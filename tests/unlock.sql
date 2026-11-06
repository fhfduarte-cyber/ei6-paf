-- Unlock-password checks for 0003_upload_unlock.sql (same scratch-DB stub as rls.sql).
\set ON_ERROR_STOP 0
\echo == password not configured yet (expect not_configured)
set role authenticated; set request.jwt.claim.sub = '00000000-0000-4000-8000-000000000002';
select public.update_datasets('{"prices_rods":[]}', 'whatever');
\echo == members/anon cannot set the password nor read secrets (expect permission denied x4)
select public.set_upload_password('hacked-password-123');
select * from public.app_secrets;
select * from public.unlock_attempts;
reset role; set role anon; select public.update_datasets('{"prices_rods":[]}', 'x');
reset role; select public.set_upload_password('short');   -- expect: needs at least 10 chars
select public.set_upload_password('correct-horse-battery');
select key, length(hash)=60 as bcrypt_len, hash <> 'correct-horse-battery' as hashed from public.app_secrets;
set role authenticated; set request.jwt.claim.sub = '00000000-0000-4000-8000-000000000003';
\echo == eve (not a member), right password (expect forbidden)
select public.update_datasets('{"prices_rods":[]}', 'correct-horse-battery');
set request.jwt.claim.sub = '00000000-0000-4000-8000-000000000002';
\echo == bob wrong password (expect {"error": "invalid_password"}), data untouched
select public.update_datasets('{"prices_rods":[{"name":"x"}]}', 'wrong');
select jsonb_array_length(data) > 5 as rods_untouched, version from public.datasets where key='prices_rods';
\echo == bob direct table update still blocked (expect 0 rows)
update public.datasets set data='[]' where key='prices_rods';
\echo == bad key / bad type with right password (expect invalid_payload; nothing changes - one transaction)
select public.update_datasets('{"prices_rods":[{"a":1}],"evil":[]}', 'correct-horse-battery');
select public.update_datasets('{"prices_rods":"text"}', 'correct-horse-battery');
select jsonb_array_length(data) > 5 as rods_untouched, version from public.datasets where key='prices_rods';
\echo == good upload of two datasets (expect versions)
select public.update_datasets('{"prices_rods":[{"name":"t"}],"prices_rounds":[{"name":"u"}]}', 'correct-horse-battery');
select key, version, data from public.datasets where key in ('prices_rods','prices_rounds') order by key;
\echo == lockout: 5 wrong attempts then even the right password is refused (expect locked)
select public.update_datasets('{"prices_rods":[]}', 'w1'); select public.update_datasets('{"prices_rods":[]}', 'w2'); select public.update_datasets('{"prices_rods":[]}', 'w3');
select public.update_datasets('{"prices_rods":[]}', 'w4'); select public.update_datasets('{"prices_rods":[]}', 'w5');
select public.update_datasets('{"prices_rods":[{"name":"t2"}]}', 'correct-horse-battery');
reset role; select count(*) as failures_recorded from public.unlock_attempts where not ok;
select action, row_id from public.audit_log where action='upload';
delete from public.unlock_attempts;
