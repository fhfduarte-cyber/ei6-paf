#!/usr/bin/env bash
# starts the mock Supabase (54321) and the PHP app (8081) for local tests
cd "$(dirname "$0")/.."
node tests/mock_supabase.mjs > /tmp/mock.log 2>&1 &
APP_ENV=local SUPABASE_URL=http://127.0.0.1:54321 SUPABASE_ANON_KEY=anon-key SUPABASE_JWT_SECRET=${JWT:-test-secret} \
  php -S 127.0.0.1:8081 api/index.php > /tmp/php.log 2>&1 &
sleep 1.2
