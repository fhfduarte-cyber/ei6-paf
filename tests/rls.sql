-- RLS / trigger checks. Run on a scratch Postgres after creating a stub of Supabase's auth schema:
--   create role anon nologin; create role authenticated nologin; create schema auth;
--   create table auth.users(id uuid primary key, email text);
--   create function auth.uid() returns uuid language sql stable as $$ select nullif(current_setting('request.jwt.claim.sub', true),'')::uuid $$;
--   (grant usage on schema auth/public to anon, authenticated; insert users 000...1 alice, 000...2 bob, 000...3 eve;
--    run migrations; select public.grant_access('alice@ei6.test','admin'); select public.grant_access('bob@ei6.test','member');)
\set ON_ERROR_STOP 0
\echo == bob (member): insert PAF
set role authenticated; set request.jwt.claim.sub = '00000000-0000-4000-8000-000000000002';
insert into public.pafs(number,payload) values ('PAF-1','{"parts":[]}') returning number, version, created_by;
\echo == duplicate number differing in case/space (expect unique violation)
insert into public.pafs(number,payload) values (' paf-1 ','{}');
\echo == forge created_by as alice (expect RLS violation)
insert into public.pafs(number,payload,created_by) values ('PAF-2','{}','00000000-0000-4000-8000-000000000001');
\echo == non-object payload (expect check violation)
insert into public.pafs(number,payload) values ('PAF-3','[]');
\echo == update with version forge attempt: version=99, created_by=alice (trigger must override)
update public.pafs set payload='{"parts":[1]}', version=99, created_by='00000000-0000-4000-8000-000000000001' where number='PAF-1' returning version, created_by;
\echo == optimistic lock: update where version=1 now matches 0 rows
update public.pafs set customer='x' where number='PAF-1' and version=1 returning version;
\echo == bob cannot delete? bob is creator so can; test alice-created later. bob tries datasets update (expect 0 rows)
update public.datasets set data='[]' where key='prices_rods';
\echo == bob cannot read audit_log (expect 0 rows)
select count(*) from public.audit_log;
\echo == eve (no profile): everything empty/denied
reset role; set role authenticated; set request.jwt.claim.sub = '00000000-0000-4000-8000-000000000003';
select count(*) as eve_pafs from public.pafs; select count(*) as eve_datasets from public.datasets; select count(*) as eve_profiles from public.profiles;
insert into public.pafs(number,payload) values ('EVIL','{}');
\echo == anon: no access at all
reset role; set role anon; select count(*) from public.pafs;
\echo == alice (admin) sees audit and versions, updates datasets, deletes a PAF
reset role; set role authenticated; set request.jwt.claim.sub = '00000000-0000-4000-8000-000000000001';
select count(*) as audit_rows from public.audit_log; select version, saved_by is not null as has_author from public.paf_versions;
update public.datasets set data='[{"name":"t"}]' where key='prices_rods' returning version;
delete from public.pafs where number='PAF-1' returning number;
select action, table_name from public.audit_log order by id;
\echo == members cannot call grant_access
reset role; set role authenticated; set request.jwt.claim.sub = '00000000-0000-4000-8000-000000000002';
select public.grant_access('eve@ei6.test','admin');
