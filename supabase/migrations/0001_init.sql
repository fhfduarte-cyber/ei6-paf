-- Ei6 PAF tool · schema, Row Level Security and audit trail
-- Run in the Supabase SQL editor (or `supabase db push`). Idempotent where practical.
-- Security model: only users that have a row in public.profiles can touch any data
-- (signing up in Supabase Auth alone gives NO access). Disable public sign-ups in
-- Authentication > Providers > Email ("Allow new users to sign up" = off) and invite users from the dashboard.

create extension if not exists pgcrypto;

-- ---------------------------------------------------------------- profiles
create table if not exists public.profiles (
  id         uuid primary key references auth.users(id) on delete cascade,
  email      text not null,
  role       text not null default 'member' check (role in ('admin','member')),
  created_at timestamptz not null default now()
);
alter table public.profiles enable row level security;

-- helpers (SECURITY DEFINER so policies can read profiles without recursion)
create or replace function public.is_member() returns boolean
  language sql stable security definer set search_path = '' as
$$ select exists (select 1 from public.profiles p where p.id = (select auth.uid())) $$;

create or replace function public.is_admin() returns boolean
  language sql stable security definer set search_path = '' as
$$ select exists (select 1 from public.profiles p where p.id = (select auth.uid()) and p.role = 'admin') $$;

revoke all on function public.is_member() from public, anon;
revoke all on function public.is_admin()  from public, anon;
grant execute on function public.is_member() to authenticated;
grant execute on function public.is_admin()  to authenticated;

drop policy if exists profiles_select on public.profiles;
create policy profiles_select on public.profiles for select to authenticated
  using (id = (select auth.uid()) or public.is_admin() or public.is_member());
-- no insert/update/delete policies for clients: managed only via grant_access()/service role.

-- Grant access to an existing Supabase Auth user (run from the SQL editor, never exposed to clients):
--   select public.grant_access('someone@ei6.com', 'member');
create or replace function public.grant_access(p_email text, p_role text default 'member') returns void
  language plpgsql security definer set search_path = '' as
$$
declare v_id uuid;
begin
  select id into v_id from auth.users where lower(email) = lower(p_email);
  if v_id is null then raise exception 'No auth user with e-mail %. Invite the user first.', p_email; end if;
  insert into public.profiles(id, email, role) values (v_id, lower(p_email), p_role)
  on conflict (id) do update set role = excluded.role, email = excluded.email;
end $$;
revoke all on function public.grant_access(text, text) from public, anon, authenticated;

-- ---------------------------------------------------------------- reference data
create table if not exists public.datasets (
  key        text primary key check (key in ('prices_blocks','prices_rods','prices_rounds','devaluation','parma_stock')),
  version    integer not null default 1,
  data       jsonb not null check (pg_column_size(data) < 3000000),
  updated_at timestamptz not null default now(),
  updated_by uuid references public.profiles(id) on delete set null
);
alter table public.datasets enable row level security;
drop policy if exists datasets_select on public.datasets;
create policy datasets_select on public.datasets for select to authenticated using (public.is_member());
drop policy if exists datasets_update on public.datasets;
create policy datasets_update on public.datasets for update to authenticated
  using (public.is_admin()) with check (public.is_admin());
-- inserts/deletes only through migrations (seed), not through the API.

-- ---------------------------------------------------------------- PAFs
create table if not exists public.pafs (
  id          uuid primary key default gen_random_uuid(),
  number      text not null check (char_length(btrim(number)) between 1 and 40),
  customer    text not null default '' check (char_length(customer) <= 80),
  created_on  date not null default current_date,
  payload     jsonb not null check (jsonb_typeof(payload) = 'object' and pg_column_size(payload) < 3000000),
  version     integer not null default 1,
  created_by  uuid references public.profiles(id) on delete set null default auth.uid(),
  updated_by  uuid references public.profiles(id) on delete set null default auth.uid(),
  created_at  timestamptz not null default now(),
  updated_at  timestamptz not null default now()
);
create unique index if not exists pafs_number_uq on public.pafs (lower(btrim(number)));
create index if not exists pafs_updated_idx on public.pafs (updated_at desc);
alter table public.pafs enable row level security;

drop policy if exists pafs_select on public.pafs;
create policy pafs_select on public.pafs for select to authenticated using (public.is_member());
drop policy if exists pafs_insert on public.pafs;
create policy pafs_insert on public.pafs for insert to authenticated
  with check (public.is_member() and created_by = (select auth.uid()));
drop policy if exists pafs_update on public.pafs;
create policy pafs_update on public.pafs for update to authenticated
  using (public.is_member()) with check (public.is_member());
drop policy if exists pafs_delete on public.pafs;
create policy pafs_delete on public.pafs for delete to authenticated
  using (public.is_admin() or created_by = (select auth.uid()));

create table if not exists public.paf_versions (
  paf_id   uuid not null references public.pafs(id) on delete cascade,
  version  integer not null,
  payload  jsonb not null,
  saved_by uuid references public.profiles(id) on delete set null,
  saved_at timestamptz not null default now(),
  primary key (paf_id, version)
);
alter table public.paf_versions enable row level security;
drop policy if exists paf_versions_select on public.paf_versions;
create policy paf_versions_select on public.paf_versions for select to authenticated using (public.is_member());

create table if not exists public.audit_log (
  id         bigint generated always as identity primary key,
  at         timestamptz not null default now(),
  user_id    uuid,
  action     text not null,
  table_name text not null,
  row_id     text,
  detail     jsonb
);
alter table public.audit_log enable row level security;
drop policy if exists audit_select on public.audit_log;
create policy audit_select on public.audit_log for select to authenticated using (public.is_admin());

-- ---------------------------------------------------------------- triggers
-- Server-controlled columns: clients cannot forge versions, timestamps or authorship.
create or replace function public.trg_pafs_before_update() returns trigger
  language plpgsql security definer set search_path = '' as
$$
begin
  new.version    := old.version + 1;          -- optimistic locking: API sends ?version=eq.N
  new.updated_at := now();
  new.updated_by := (select auth.uid());
  new.created_by := old.created_by;
  new.created_at := old.created_at;
  new.id         := old.id;
  return new;
end $$;
drop trigger if exists pafs_bu on public.pafs;
create trigger pafs_bu before update on public.pafs for each row execute function public.trg_pafs_before_update();

create or replace function public.trg_pafs_after() returns trigger
  language plpgsql security definer set search_path = '' as
$$
begin
  if tg_op = 'UPDATE' then
    insert into public.paf_versions(paf_id, version, payload, saved_by) values (old.id, old.version, old.payload, old.updated_by)
      on conflict do nothing;
    insert into public.audit_log(user_id, action, table_name, row_id, detail)
      values ((select auth.uid()), 'update', 'pafs', new.id::text, jsonb_build_object('number', new.number, 'version', new.version));
  elsif tg_op = 'INSERT' then
    insert into public.audit_log(user_id, action, table_name, row_id, detail)
      values ((select auth.uid()), 'insert', 'pafs', new.id::text, jsonb_build_object('number', new.number));
  elsif tg_op = 'DELETE' then
    insert into public.audit_log(user_id, action, table_name, row_id, detail)
      values ((select auth.uid()), 'delete', 'pafs', old.id::text, jsonb_build_object('number', old.number));
  end if;
  return null;
end $$;
drop trigger if exists pafs_ai on public.pafs;
create trigger pafs_ai after insert or update or delete on public.pafs for each row execute function public.trg_pafs_after();

create or replace function public.trg_datasets_before_update() returns trigger
  language plpgsql security definer set search_path = '' as
$$
begin
  new.version := old.version + 1; new.updated_at := now(); new.updated_by := (select auth.uid()); new.key := old.key;
  insert into public.audit_log(user_id, action, table_name, row_id, detail)
    values ((select auth.uid()), 'update', 'datasets', old.key, jsonb_build_object('version', new.version));
  return new;
end $$;
drop trigger if exists datasets_bu on public.datasets;
create trigger datasets_bu before update on public.datasets for each row execute function public.trg_datasets_before_update();

-- ---------------------------------------------------------------- privileges (defense in depth)
revoke all on public.profiles, public.datasets, public.pafs, public.paf_versions, public.audit_log from anon, public;
grant select on public.profiles, public.paf_versions, public.audit_log to authenticated;
grant select, update on public.datasets to authenticated;
grant select, insert, update, delete on public.pafs to authenticated;
