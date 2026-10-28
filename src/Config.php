<?php
declare(strict_types=1);

namespace Ei6;

/** Environment-backed configuration. Secrets live only in Vercel environment variables. */
final class Config
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            $v = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }
        $v = ($v === null || $v === false) ? null : trim((string) $v);
        return ($v === null || $v === '') ? $default : $v;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null) {
            throw new \RuntimeException("Missing environment variable $key");
        }
        return $v;
    }

    public static function supabaseUrl(): string
    {
        return rtrim(self::require('SUPABASE_URL'), '/');
    }

    /** True when served over HTTPS (Vercel always is; local dev is not). Controls cookie hardening. */
    public static function secure(): bool
    {
        if (self::get('APP_ENV') === 'local') {
            return false;
        }
        return true;
    }
}
