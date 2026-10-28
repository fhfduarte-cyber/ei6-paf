<?php
declare(strict_types=1);

namespace Ei6;

/** Thin curl client for Supabase Auth (GoTrue) and PostgREST. Always TLS-verified, short timeouts. */
final class Supabase
{
    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:mixed} status and decoded JSON (null when empty/invalid)
     */
    public static function call(string $method, string $path, ?string $token, ?array $json = null, array $headers = []): array
    {
        $ch = curl_init(Config::supabaseUrl() . $path);
        $h = ['apikey: ' . Config::require('SUPABASE_ANON_KEY'), 'Accept: application/json'];
        // New-style keys (sb_publishable_...) are not JWTs and must never be sent as a Bearer token:
        // anonymous calls carry only the apikey header; user calls carry the user's JWT.
        if ($token !== null) {
            $h[] = 'Authorization: Bearer ' . $token;
        }
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (Config::get('APP_ENV') === 'local' ? CURLPROTO_HTTP : 0),
        ];
        if ($json !== null) {
            $h[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($out === false) {
            return [0, null];
        }
        $d = $out === '' ? null : json_decode((string) $out, true);
        return [$st, $d];
    }

    /** @return array{0:int,1:mixed} */
    public static function passwordLogin(string $email, string $password): array
    {
        return self::call('POST', '/auth/v1/token?grant_type=password', null, ['email' => $email, 'password' => $password]);
    }

    /** @return array{0:int,1:mixed} */
    public static function refresh(string $refreshToken): array
    {
        return self::call('POST', '/auth/v1/token?grant_type=refresh_token', null, ['refresh_token' => $refreshToken]);
    }

    /** @return array{0:int,1:mixed} */
    public static function user(string $token): array
    {
        return self::call('GET', '/auth/v1/user', $token);
    }

    public static function logout(string $token): void
    {
        self::call('POST', '/auth/v1/logout?scope=local', $token, []);
    }

    /** PostgREST with the USER's token: Row Level Security in the database is the real authorization layer. */
    public static function rest(string $method, string $query, string $token, ?array $json = null, array $headers = []): array
    {
        return self::call($method, '/rest/v1/' . $query, $token, $json, $headers);
    }
}
