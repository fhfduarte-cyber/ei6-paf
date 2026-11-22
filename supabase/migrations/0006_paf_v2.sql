-- PAF v2 (shared list with status, locked quote, production scenarios).
-- Adds a small summary column used by the "All PAFs" list. The full document stays in payload (v2: {v:2, meta, quote, prod}).
-- PAFs saved before this migration keep working: payload.parts is read as an unlocked Draft.
alter table public.pafs add column if not exists meta jsonb;
do $$ begin
  if not exists (select 1 from pg_constraint where conname = 'pafs_meta_chk') then
    alter table public.pafs add constraint pafs_meta_chk
      check (meta is null or (jsonb_typeof(meta) = 'object' and pg_column_size(meta) < 200000));
  end if;
end $$;
