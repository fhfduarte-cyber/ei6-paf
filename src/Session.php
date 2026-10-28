<?php
declare(strict_types=1);

namespace Ei6;

/** Cookie session backed by Supabase Auth tokens. Tokens never reach JavaScript (HttpOnly). */
final class Session
{
    public ?string $token = null;
    /** @var array{id:string,email:string,role:string}|null */
    public ?array $user = null;

    private static function name(string $n): string
    {
        return (Config::secure() ? '__Host-' : '') . $n;
    }

    private static function set(string $n, string $v, int $ttl): void
    {
        setcookie(self::name($n), $v, [
            'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
            'path' => '/', 'secure' => Config::secure(), 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }

    public static function setTokens(string $access, string $refresh, int $expiresIn): void
    {
        self::set('ei6_at', $access, max(60, min($expiresIn, 86400)));
        self::set('ei6_rt', $refresh, 60 * 60 * 24 * 7);
    }

    public static function clear(): void
    {
        self::set('ei6_at', '', 0);
        self::set('ei6_rt', '', 0);
    }

    private static function cookie(string $n): string
    {
        return (string) ($_COOKIE[self::name($n)] ?? '');
    }

    /** Validates the token (locally if SUPABASE_JWT_SECRET is set, otherwise asking Auth) and loads the profile. */
    private function authenticate(string $token): bool
    {
        $uid = null;
        $secret = Config::get('SUPABASE_JWT_SECRET');
        if ($secret !== null) {
            $c = Jwt::verifyHs256($token, $secret);
            $uid = $c['sub'] ?? null;
        } else {
            [$st, $u] = Supabase::user($token);
            $uid = ($st === 200 && is_array($u)) ? ($u['id'] ?? null) : null;
        }
        if (!is_string($uid) || !preg_match('/^[0-9a-f-]{36}$/i', $uid)) {
            return false;
        }
        // Membership gate: no profile row = no access, even for a valid Supabase user.
        [$st, $rows] = Supabase::rest('GET', 'profiles?select=id,email,role&id=eq.' . rawurlencode($uid), $token);
        if ($st !== 200 || !is_array($rows) || count($rows) !== 1) {
            return false;
        }
        $this->token = $token;
        $this->user = ['id' => (string) $rows[0]['id'], 'email' => (string) $rows[0]['email'], 'role' => (string) $rows[0]['role']];
        return true;
    }

    /** Resolves the current user, refreshing the access token transparently when needed. */
    public static function current(): self
    {
        $s = new self();
        $at = self::cookie('ei6_at');
        if ($at !== '' && $s->authenticate($at)) {
            return $s;
        }
        $rt = self::cookie('ei6_rt');
        if ($rt !== '' && strlen($rt) < 512) {
            [$st, $d] = Supabase::refresh($rt);
            if ($st === 200 && is_array($d) && !empty($d['access_token']) && !empty($d['refresh_token'])) {
                if ($s->authenticate((string) $d['access_token'])) {
                    self::setTokens((string) $d['access_token'], (string) $d['refresh_token'], (int) ($d['expires_in'] ?? 3600));
                    return $s;
                }
            }
        }
        return $s;
    }

    public function ok(): bool
    {
        return $this->user !== null;
    }

    public function isAdmin(): bool
    {
        return ($this->user['role'] ?? '') === 'admin';
    }
}
