<?php
declare(strict_types=1);

namespace Ei6;

/** Minimal HS256 verification for Supabase access tokens (legacy JWT secret). */
final class Jwt
{
    private static function b64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** @return array<string,mixed>|null claims when signature, expiry and audience are valid */
    public static function verifyHs256(string $jwt, string $secret): ?array
    {
        $p = explode('.', $jwt);
        if (count($p) !== 3) {
            return null;
        }
        $h = json_decode((string) self::b64($p[0]), true);
        if (!is_array($h) || ($h['alg'] ?? '') !== 'HS256') { // never trust the alg header for anything else
            return null;
        }
        $sig = self::b64($p[2]);
        if ($sig === false || !hash_equals(hash_hmac('sha256', $p[0] . '.' . $p[1], $secret, true), $sig)) {
            return null;
        }
        $c = json_decode((string) self::b64($p[1]), true);
        if (!is_array($c) || (int) ($c['exp'] ?? 0) < time() || ($c['aud'] ?? '') !== 'authenticated' || empty($c['sub'])) {
            return null;
        }
        return $c;
    }
}
