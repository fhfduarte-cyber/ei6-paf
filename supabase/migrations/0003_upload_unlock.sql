-- 0003 · Reference-data upload protected by an UNLOCK PASSWORD (no admin profile needed).
-- Any member of the team may upload the source spreadsheets from the PAF page, but the database only accepts the
-- change when the correct unlock password is supplied. The password is stored as a bcrypt hash that no API role can read.
--
-- After running this file, set the password ONCE, in this SQL editor (never in chat, never in the app):
--     select public.set_upload_password('choose-a-long-password-here');
-- To change it later, run the same statement again.

create extension if not exists pgcrypto with schema extensions;

-- ---------------------------------------------------------------- secret + attempts (invisible to the API)
create table if not exists public.app_secrets (
  key        text primary key,
  hash       text not null,
  updated_at timestamptz not null default now()
);
create table if not exists public.unlock_attempts (
  id      bigserial primary key,
  user_id uuid,
  at      timestamptz not null default now(),
  ok      boolean not null
);
create index if not exists unlock_attempts_at_idx on public.unlock_attempts(at);
alter table public.app_secrets enable row level security;
alter table public.unlock_attempts enable row level security;     -- no policies: nobody but the owner role can touch them
revoke all on public.app_secrets, public.unlock_attempts from anon, authenticated, public;
revoke all on sequence public.unlock_attempts_id_seq from anon, authenticated, public;

-- ---------------------------------------------------------------- set / change the password (SQL editor only)
create or replace function public.set_upload_password(p_password text) returns void
  language plpgsql security definer set search_path = '' as
$$
begin
  if p_password is null or char_length(p_password) < 10 then
    raise exception 'The unlock password needs at least 10 characters';
  end if;
  insert into public.app_secrets(key, hash) values ('upload_password', extensions.crypt(p_password, extensions.gen_salt('bf', 10)))
    on conflict (key) do update set hash = excluded.hash, updated_at = now();
  delete from public.unlock_attempts;
end $$;
revoke all on function public.set_upload_password(text) from public, anon, authenticated;

-- ---------------------------------------------------------------- the guarded upload
-- p_sets: {"prices_blocks": [...], "devaluation": {...}, ...}  (all keys are replaced in ONE transaction)
create or replace function public.update_datasets(p_sets jsonb, p_password text) returns jsonb
  language plpgsql security definer set search_path = '' as
$$
declare
  v_uid   uuid := (select auth.uid());
  v_hash  text;
  v_key   text;
  v_val   jsonb;
  v_res   jsonb := '{}'::jsonb;
  v_ver   integer;
begin
  if v_uid is null or not public.is_member() then raise exception 'forbidden'; end if;
  if p_sets is null or jsonb_typeof(p_sets) <> 'object' or p_sets = '{}'::jsonb then raise exception 'invalid_payload'; end if;
  select hash into v_hash from public.app_secrets where key = 'upload_password';
  if v_hash is null then raise exception 'not_configured'; end if;

  -- brute-force guard: 5 failures per user / 15 min, 30 failures overall / hour
  if (select count(*) from public.unlock_attempts where user_id = v_uid and not ok and at > now() - interval '15 minutes') >= 5
     or (select count(*) from public.unlock_attempts where not ok and at > now() - interval '1 hour') >= 30 then
    raise exception 'locked';
  end if;

  if p_password is null or char_length(p_password) > 200 or extensions.crypt(p_password, v_hash) <> v_hash then
    insert into public.unlock_attempts(user_id, ok) values (v_uid, false);
    perform pg_sleep(0.4);
    return jsonb_build_object('error', 'invalid_password');   -- returned (not raised) so the failed attempt is kept
  end if;
  delete from public.unlock_attempts where user_id = v_uid and not ok;
  delete from public.unlock_attempts where at < now() - interval '2 days';

  for v_key, v_val in select * from jsonb_each(p_sets) loop
    if v_key not in ('prices_blocks','prices_rods','prices_rounds','devaluation','parma_stock') then raise exception 'invalid_payload'; end if;
    if jsonb_typeof(v_val) not in ('array','object') or pg_column_size(v_val) >= 3000000 then raise exception 'invalid_payload'; end if;
    update public.datasets set data = v_val where key = v_key returning version into v_ver;
    if not found then raise exception 'invalid_payload'; end if;
    v_res := v_res || jsonb_build_object(v_key, v_ver);
  end loop;
  insert into public.audit_log(user_id, action, table_name, row_id, detail)
    values (v_uid, 'upload', 'datasets', array_to_string(array(select jsonb_object_keys(p_sets)), ','), v_res);
  return v_res;
end $$;
revoke all on function public.update_datasets(jsonb, text) from public, anon;
grant execute on function public.update_datasets(jsonb, text) to authenticated;
