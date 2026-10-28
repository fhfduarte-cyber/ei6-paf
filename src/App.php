<?php
declare(strict_types=1);

namespace Ei6;

/** Page rendering: login page and the calculator shell (datasets injected as inert JSON). */
final class App
{
    private const KEYS = ['prices_blocks', 'prices_rods', 'prices_rounds', 'devaluation', 'parma_stock'];

    private static function tpl(string $name): string
    {
        $f = dirname(__DIR__) . '/templates/' . $name;
        $s = file_get_contents($f);
        if ($s === false) {
            throw new \RuntimeException("Template $name missing");
        }
        return $s;
    }

    private static function jsonForHtml(mixed $v): string
    {
        // HEX_* flags make </script>, <!-- and quotes impossible inside the JSON island
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    public static function login(): never
    {
        Http::html(strtr(self::tpl('login.html'), ['{{NONCE}}' => Http::nonce()]));
    }

    /** @return array<string,mixed> */
    public static function datasets(Session $s): array
    {
        [$st, $rows] = Supabase::rest('GET', 'datasets?select=key,version,data', (string) $s->token);
        if ($st !== 200 || !is_array($rows)) {
            Http::error(502, 'Could not load reference data');
        }
        $out = [];
        foreach ($rows as $r) {
            if (in_array($r['key'] ?? '', self::KEYS, true)) {
                $out[$r['key']] = $r['data'];
            }
        }
        foreach (self::KEYS as $k) {
            if (!array_key_exists($k, $out)) {
                Http::error(500, "Reference data '$k' is missing. Run supabase/migrations/0002_seed_datasets.sql");
            }
        }
        return $out;
    }

    public static function shell(Session $s): never
    {
        $html = strtr(self::tpl('app.html'), [
            '{{NONCE}}' => Http::nonce(),
            '{{DATA_JSON}}' => self::jsonForHtml(self::datasets($s)),
            '{{USER_JSON}}' => self::jsonForHtml(['email' => $s->user['email'], 'role' => $s->user['role']]),
        ]);
        Http::html($html);
    }
}
