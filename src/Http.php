<?php
declare(strict_types=1);

namespace Ei6;

final class Http
{
    public const MAX_BODY = 3_000_000; // Vercel limit is 4.5 MB; payload CHECK in the DB is < 3 MB

    private static string $nonce = '';

    public static function nonce(): string
    {
        return self::$nonce ?: (self::$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='));
    }

    /** Headers sent on every response. CSP uses a per-response nonce: no inline script can run without it. */
    public static function securityHeaders(bool $html = false): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        if (Config::secure()) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        }
        header('Cache-Control: no-store');
        if ($html) {
            $n = self::nonce();
            header("Content-Security-Policy: default-src 'none'; "
                . "script-src 'nonce-$n' https://cdnjs.cloudflare.com; "
                . "style-src 'nonce-$n' https://fonts.googleapis.com; style-src-attr 'unsafe-inline'; "
                . "font-src https://fonts.gstatic.com; img-src 'self' data: blob:; "
                . "connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
        } else {
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        }
    }

    /** @param mixed $data */
    public static function json($data, int $status = 200): never
    {
        self::securityHeaders();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function error(int $status, string $message): never
    {
        self::json(['error' => $message], $status);
    }

    public static function redirect(string $to): never
    {
        self::securityHeaders();
        http_response_code(302);
        header('Location: ' . $to);
        exit;
    }

    public static function html(string $body, int $status = 200): never
    {
        self::securityHeaders(true);
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $body;
        exit;
    }

    public static function header(string $name): string
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$k] ?? '');
    }

    /** Reads a JSON object body with a hard size limit. */
    public static function body(): array
    {
        $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($len > self::MAX_BODY) {
            self::error(413, 'Payload too large');
        }
        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($ct, 'application/json')) {
            self::error(415, 'Content-Type must be application/json');
        }
        $raw = file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if ($raw === false || strlen($raw) > self::MAX_BODY) {
            self::error(413, 'Payload too large');
        }
        try {
            $d = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::error(400, 'Invalid JSON');
        }
        if (!is_array($d)) {
            self::error(400, 'JSON object expected');
        }
        return $d;
    }

    /**
     * CSRF defence for state-changing requests: cookies are SameSite=Strict, and on top of that we require a custom
     * header (cannot be sent cross-site without CORS preflight, which we never grant) and a same-origin fetch metadata.
     */
    public static function requireSameOrigin(): void
    {
        if (self::header('X-Requested-With') !== 'ei6') {
            self::error(403, 'Forbidden');
        }
        $site = self::header('Sec-Fetch-Site');
        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            self::error(403, 'Forbidden');
        }
        $origin = self::header('Origin');
        if ($origin !== '') {
            $oh = parse_url($origin, PHP_URL_HOST);
            $op = parse_url($origin, PHP_URL_PORT);
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $oh = $oh . ($op ? ':' . $op : '');
            if (!hash_equals(strtolower($host), strtolower((string) $oh))) {
                self::error(403, 'Forbidden');
            }
        }
    }
}
