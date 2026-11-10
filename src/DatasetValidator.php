<?php
declare(strict_types=1);

namespace Ei6;

/** Strict shape checks for the reference datasets before they reach the database (defence in depth). */
final class DatasetValidator
{
    private const KEYS = ['prices_blocks', 'prices_rods', 'prices_rounds', 'devaluation', 'parma_stock'];

    private static function bad(string $key, string $why): never
    {
        Http::error(422, "Dataset $key rejected: $why");
    }

    private static function n(mixed $v, string $key, string $what, float $min = 0.0, float $max = 1e9, bool $minInclusive = true): float
    {
        if (!(is_int($v) || is_float($v)) || !is_finite((float) $v) || ($minInclusive ? $v < $min : $v <= $min) || $v > $max) {
            self::bad($key, "$what must be a number between $min and $max");
        }
        return (float) $v;
    }

    private static function s(mixed $v, string $key, string $what, int $max): string
    {
        if (!is_string($v) || $v === '' || mb_strlen($v, 'UTF-8') > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
            self::bad($key, "$what must be text (1-$max chars)");
        }
        return $v;
    }

    private static function lst(mixed $v, string $key, string $what, int $min, int $max): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) < $min || count($v) > $max) {
            self::bad($key, "$what must be a list of $min-$max items");
        }
        return $v;
    }

    /** @return array<string,mixed> the validated sets, only known keys */
    public static function sets(mixed $sets): array
    {
        if (!is_array($sets) || array_is_list($sets) || $sets === []) {
            Http::error(422, 'sets must be an object with at least one dataset');
        }
        $out = [];
        foreach ($sets as $k => $data) {
            if (!is_string($k) || !in_array($k, self::KEYS, true)) {
                Http::error(422, 'Unknown dataset');
            }
            $out[$k] = match ($k) {
                'prices_blocks' => self::prices($k, $data, 3),
                'prices_rods', 'prices_rounds' => self::prices($k, $data, 2),
                'devaluation' => self::devaluation($k, $data),
                'parma_stock' => self::parma($k, $data),
            };
        }
        return $out;
    }

    private static function prices(string $k, mixed $data, int $dims): array
    {
        $rows = self::lst($data, $k, 'data', 1, 1000);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                self::bad($k, 'invalid item');
            }
            $o = ['name' => self::s($r['name'] ?? null, $k, 'name', 120)];
            if ($dims === 3) {
                $o['grp'] = self::s($r['grp'] ?? null, $k, 'grp', 40);
            }
            $o['lb'] = self::n($r['lb'] ?? null, $k, 'price per lb', 0, 10000, false);
            if ($dims === 2) {
                $o['L'] = self::n($r['L'] ?? null, $k, 'L', 0, 100000, false);
            }
            $o['kg'] = self::n($r['kg'] ?? null, $k, 'price per kg', 0, 30000, false);
            $o['w'] = self::n($r['w'] ?? null, $k, 'weight', 0, 1e7, false);
            $o['d'] = array_map(fn ($x) => self::n($x, $k, 'dimension', 0, 100000, false), self::lst($r['d'] ?? null, $k, 'dimensions', $dims, $dims));
            $out[] = $o;
        }
        return $out;
    }

    private const DEP_BLOCK = ['vnom', 'ra', 'rb', 'rmin', 'va', 'vb', 'floor', 'bulk', 'remT', 'remV', 'scrT', 'scrV'];
    private const DEP_LEN = ['lnom', 'ra', 'rb', 'rmin', 'xa', 'xb', 'floor', 'ageDays', 'raAged', 'remL', 'remV', 'scrL', 'scrV', 'w'];

    private static function devaluation(string $k, mixed $data): array
    {
        if (!is_array($data) || array_is_list($data)) {
            self::bad($k, 'object expected');
        }
        if (($data['version'] ?? null) !== 5) {
            self::bad($k, 'depreciation model version 5 expected (sheets PFR, PRD-N, PDF-N)');
        }
        $src = self::s($data['source'] ?? 'Depreciation workbook', $k, 'source', 200);
        return [
            'source' => $src,
            'version' => 5,
            'blocks' => self::depParams($k, $data['blocks'] ?? null, 'blocks', self::DEP_BLOCK),
            'rods' => self::depParams($k, $data['rods'] ?? null, 'rods', self::DEP_LEN),
            'rounds' => self::depParams($k, $data['rounds'] ?? null, 'rounds', self::DEP_LEN),
        ];
    }

    private static function depParams(string $k, mixed $v, string $what, array $fields): array
    {
        if (!is_array($v) || array_is_list($v)) {
            self::bad($k, "$what: object expected");
        }
        $out = [];
        foreach ($fields as $f) {
            $out[$f] = self::n($v[$f] ?? null, $k, "$what $f", 0, 1e7);
        }
        $rows = self::lst($v['p0'] ?? null, $k, "$what p0", 1, 30);
        $p0 = [];
        $prev = -1.0;
        foreach ($rows as $r) {
            if (!is_array($r) || !array_is_list($r) || count($r) !== 2) {
                self::bad($k, "$what p0 row must be [from, price]");
            }
            $a = self::n($r[0], $k, "$what p0 from", 0, 1e5);
            if ($a <= $prev) {
                self::bad($k, "$what p0: 'from' must be increasing");
            }
            $prev = $a;
            $p0[] = [$a, self::n($r[1], $k, "$what p0 price", 0, 1000)];
        }
        $out['p0'] = $p0;
        foreach (['ra', 'rb', 'rmin'] as $f) {
            if ($out[$f] > 1) {
                self::bad($k, "$what $f must be between 0 and 1");
            }
        }
        return $out;
    }

    private static function parma(string $k, mixed $data): array
    {
        if (!is_array($data) || array_is_list($data)) {
            self::bad($k, 'object expected');
        }
        $src = self::s($data['source'] ?? 'Parma M Aged Report', $k, 'source', 200);
        $rows = self::lst($data['rows'] ?? null, $k, 'rows', 1, 30000);
        foreach ($rows as $r) {
            if (!is_array($r) || !array_is_list($r) || count($r) !== 8) {
                self::bad($k, 'row must have 8 fields');
            }
            if ($r[0] !== 0 && $r[0] !== 1) {
                self::bad($k, 'row kind must be 0 or 1');
            }
            if (!in_array($r[1], ['BL', 'RD', 'RN', 'CL'], true)) {
                self::bad($k, 'row category must be BL, RD, RN or CL');
            }
            $d = self::lst($r[2], $k, 'row dimensions', 2, 4);
            foreach ($d as $x) {
                self::n($x, $k, 'row dimension', 0, 100000, false);
            }
            self::s($r[3], $k, 'lot', 200);
            self::s($r[4], $k, 'family', 80);
            self::n($r[5], $k, 'qty', 0, 1e9, false);
            self::s($r[6], $k, 'location', 80);
            self::n($r[7], $k, 'unit cost', 0, 1e12);
        }
        return ['source' => $src, 'rows' => $rows];
    }
}
