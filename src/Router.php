<?php
declare(strict_types=1);

namespace Ei6;

final class Router
{
    public static function dispatch(): never
    {
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            error_log("PHP[$no] $str in $file:$line");
            return true;
        });
        set_exception_handler(static function (\Throwable $e): void {
            error_log('Unhandled: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            // Details are never leaked, except while troubleshooting with APP_DEBUG=1 (remove it afterwards).
            Http::error(500, Config::get('APP_DEBUG') === '1' ? 'Server error: ' . get_class($e) . ': ' . $e->getMessage() : 'Server error');
        });

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') ?: '/';

        if ($method !== 'GET' && $method !== 'HEAD' && str_starts_with($path, '/api/')) {
            Http::requireSameOrigin();
        }

        // ---- pages
        if ($path === '/' && $method === 'GET') {
            $s = Session::current();
            $s->ok() ? Http::redirect('/app') : App::login();
        }
        if ($path === '/app' && $method === 'GET') {
            $s = Session::current();
            $s->ok() ? App::shell($s) : Http::redirect('/');
        }
        if ($path === '/healthz') {
            // Safe diagnostics: booleans and status codes only, never values.
            $url = Config::get('SUPABASE_URL');
            $auth = null;
            if ($url !== null && function_exists('curl_init')) {
                try {
                    [$auth] = Supabase::call('GET', '/auth/v1/health', null);
                } catch (\Throwable) {
                    $auth = -1;
                }
            }
            Http::json([
                'ok' => true,
                'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'curl' => function_exists('curl_init'),
                'env' => ['SUPABASE_URL' => $url !== null, 'SUPABASE_ANON_KEY' => Config::get('SUPABASE_ANON_KEY') !== null, 'SUPABASE_JWT_SECRET' => Config::get('SUPABASE_JWT_SECRET') !== null],
                'supabase_auth_http' => $auth,
            ]);
        }

        // ---- auth
        if ($path === '/api/login' && $method === 'POST') {
            self::login();
        }
        if ($path === '/api/logout' && $method === 'POST') {
            $s = Session::current();
            if ($s->ok()) {
                Supabase::logout((string) $s->token);
            }
            Session::clear();
            Http::json(['ok' => true]);
        }

        // ---- everything below needs a member
        if (str_starts_with($path, '/api/')) {
            $s = Session::current();
            if (!$s->ok()) {
                Http::error(401, 'Not authenticated');
            }
            if ($path === '/api/me' && $method === 'GET') {
                Http::json($s->user);
            }
            if ($path === '/api/pafs') {
                $method === 'GET' ? Pafs::list($s) : ($method === 'POST' ? Pafs::create($s) : Http::error(405, 'Method not allowed'));
            }
            if (preg_match('#^/api/pafs/([^/]+)$#', $path, $m)) {
                $id = PafValidator::uuid($m[1]);
                match ($method) {
                    'GET' => Pafs::get($s, $id),
                    'PUT' => Pafs::update($s, $id),
                    'DELETE' => Pafs::delete($s, $id),
                    default => Http::error(405, 'Method not allowed'),
                };
            }
            if (preg_match('#^/api/admin/datasets/([a-z_]+)$#', $path, $m)) {
                if (!$s->isAdmin()) {
                    Http::error(403, 'Forbidden');
                }
                $method === 'GET' ? Pafs::datasetGet($s, $m[1]) : ($method === 'PUT' ? Pafs::datasetPut($s, $m[1]) : Http::error(405, 'Method not allowed'));
            }
        }
        Http::error(404, 'Not found');
    }

    private static function login(): never
    {
        $b = Http::body();
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $pw = (string) ($b['password'] ?? '');
        $generic = 'Invalid e-mail or password';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $pw === '' || strlen($pw) > 200) {
            usleep(random_int(150000, 350000));
            Http::error(401, $generic);
        }
        [$st, $d] = Supabase::passwordLogin($email, $pw);
        if ($st !== 200 || !is_array($d) || empty($d['access_token']) || empty($d['refresh_token'])) {
            usleep(random_int(150000, 350000));
            Http::error($st === 429 ? 429 : 401, $st === 429 ? 'Too many attempts. Try again later.' : $generic);
        }
        // verify membership (profile row) before issuing any cookie
        $uid = (string) ($d['user']['id'] ?? '');
        $mine = null;
        if (preg_match('/^[0-9a-f-]{36}$/i', $uid)) {
            [$pst, $prow] = Supabase::rest('GET', 'profiles?select=id,email,role&id=eq.' . $uid, (string) $d['access_token']);
            if ($pst === 200 && is_array($prow) && count($prow) === 1) {
                $mine = $prow[0];
            }
        }
        if ($mine === null) {
            Supabase::logout((string) $d['access_token']);
            Http::error(403, 'This account has no access to the PAF tool. Ask an administrator.');
        }
        Session::setTokens((string) $d['access_token'], (string) $d['refresh_token'], (int) ($d['expires_in'] ?? 3600));
        Http::json(['ok' => true, 'user' => ['email' => $mine['email'], 'role' => $mine['role']]]);
    }
}
