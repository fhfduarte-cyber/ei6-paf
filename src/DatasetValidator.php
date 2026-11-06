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

    private static function devaluation(string $k, mixed $data): array
    {
        if (!is_array($data) || array_is_list($data)) {
            self::bad($k, 'object expected');
        }
        $src = self::s($data['source'] ?? 'Devaluation workbook', $k, 'source', 200);
        $blocks = self::lst($data['blocks'] ?? null, $k, 'blocks', 2, 20);
        $cb = [];
        foreach ($blocks as $b) {
            if (!is_array($b)) {
                self::bad($k, 'invalid block band');
            }
            $row = [];
            foreach (['n', 'minIn', 'maxIn', 'mid', 'big', 'ok', 'no'] as $f) {
                $row[$f] = self::n($b[$f] ?? null, $k, "block $f", 0, 1e7);
            }
            $cb[] = $row;
        }
        return ['source' => $src, 'blocks' => $cb, 'rods' => self::odRows($k, $data['rods'] ?? null, 'rods'), 'rounds' => self::odRows($k, $data['rounds'] ?? null, 'rounds')];
    }

    private static function odRows(string $k, mixed $v, string $what): array
    {
        $rows = self::lst($v, $k, $what, 1, 200);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !array_is_list($r) || count($r) !== 2) {
                self::bad($k, "$what row must be [OD, [5 values]]");
            }
            $b = self::lst($r[1], $k, "$what bands", 5, 5);
            $out[] = [self::n($r[0], $k, "$what OD", 0, 1000, false), array_map(fn ($x) => self::n($x, $k, "$what value", 0, 1000), $b)];
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
