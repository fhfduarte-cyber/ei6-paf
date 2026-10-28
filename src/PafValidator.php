<?php
declare(strict_types=1);

namespace Ei6;

/** Server-side validation of PAF documents. The database re-checks size/type with CHECK constraints. */
final class PafValidator
{
    private static function str(mixed $v, string $label, int $min, int $max): string
    {
        if (!is_string($v)) {
            Http::error(422, "$label must be text");
        }
        $v = trim($v);
        $len = mb_strlen($v, 'UTF-8');
        if ($len < $min || $len > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
            Http::error(422, "$label is invalid (length $min-$max, no control characters)");
        }
        return $v;
    }

    /** Rejects prototype-pollution style keys anywhere in the tree. */
    private static function scan(mixed $v, int $depth = 0): void
    {
        if ($depth > 40) {
            Http::error(422, 'Document is nested too deeply');
        }
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                if (is_string($k) && in_array($k, ['__proto__', 'constructor', 'prototype'], true)) {
                    Http::error(422, 'Document contains a forbidden key');
                }
                self::scan($x, $depth + 1);
            }
        }
    }

    /** @return array{number:string,customer:string,created_on:string,payload:array} */
    public static function paf(array $b): array
    {
        $number = self::str($b['number'] ?? null, 'PAF #', 1, 40);
        $customer = self::str($b['customer'] ?? '', 'Customer', 0, 80);
        $date = (string) ($b['created_on'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            Http::error(422, 'Created date is invalid');
        }
        $parts = $b['parts'] ?? null;
        if (!is_array($parts) || !array_is_list($parts) || count($parts) < 1 || count($parts) > 50) {
            Http::error(422, 'A PAF needs between 1 and 50 parts');
        }
        $clean = [];
        foreach ($parts as $p) {
            if (!is_array($p)) {
                Http::error(422, 'Invalid part');
            }
            $snap = $p['snap'] ?? null;
            if ($snap !== null && !is_array($snap)) {
                Http::error(422, 'Invalid calculator state');
            }
            $clean[] = [
                'desc' => self::str($p['desc'] ?? '', 'Part description', 0, 200),
                'no' => self::str($p['no'] ?? '', 'Part #', 0, 60),
                'snap' => $snap,
            ];
        }
        self::scan($clean);
        return ['number' => $number, 'customer' => $customer, 'created_on' => $date, 'payload' => ['parts' => $clean]];
    }

    public static function uuid(string $s): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) {
            Http::error(404, 'Not found');
        }
        return strtolower($s);
    }
}
