<?php
declare(strict_types=1);

namespace Ei6;

/** PAF + dataset endpoints. Authorization is enforced by Postgres RLS using the caller's own JWT. */
final class Pafs
{
    private const COLS = 'id,number,customer,created_on,version,updated_at,created_at';
    private const EMBED = ',creator:profiles!pafs_created_by_fkey(email),updater:profiles!pafs_updated_by_fkey(email)';

    private static function shape(array $r): array
    {
        $r['created_by'] = $r['creator']['email'] ?? null;
        $r['updated_by'] = $r['updater']['email'] ?? null;
        unset($r['creator'], $r['updater']);
        return $r;
    }

    public static function list(Session $s): never
    {
        [$st, $rows] = Supabase::rest('GET', 'pafs?select=' . self::COLS . self::EMBED . '&order=updated_at.desc&limit=300', (string) $s->token);
        if ($st !== 200 || !is_array($rows)) {
            Http::error(502, 'Could not list PAFs');
        }
        Http::json(['items' => array_map([self::class, 'shape'], $rows)]);
    }

    public static function get(Session $s, string $id): never
    {
        [$st, $rows] = Supabase::rest('GET', 'pafs?select=' . self::COLS . ',payload' . self::EMBED . '&id=eq.' . $id, (string) $s->token);
        if ($st !== 200 || !is_array($rows) || count($rows) !== 1) {
            Http::error(404, 'Not found');
        }
        Http::json(self::shape($rows[0]));
    }

    public static function create(Session $s): never
    {
        $v = PafValidator::paf(Http::body());
        [$st, $rows] = Supabase::rest('POST', 'pafs?select=' . self::COLS, (string) $s->token, $v, ['Prefer' => 'return=representation']);
        if ($st === 409) {
            Http::error(409, 'A PAF with this number already exists');
        }
        if ($st !== 201 || !is_array($rows) || count($rows) !== 1) {
            Http::error($st === 403 ? 403 : 502, 'Could not save the PAF');
        }
        Http::json($rows[0], 201);
    }

    /** Optimistic locking: the client sends the version it loaded; a stale write is refused (409). */
    public static function update(Session $s, string $id): never
    {
        $b = Http::body();
        $ver = $b['version'] ?? null;
        if (!is_int($ver) || $ver < 1) {
            Http::error(422, 'version is required');
        }
        $v = PafValidator::paf($b);
        [$st, $rows] = Supabase::rest('PATCH', 'pafs?select=' . self::COLS . '&id=eq.' . $id . '&version=eq.' . $ver, (string) $s->token, $v, ['Prefer' => 'return=representation']);
        if ($st === 409) {
            Http::error(409, 'A PAF with this number already exists');
        }
        if ($st !== 200 || !is_array($rows)) {
            Http::error(502, 'Could not save the PAF');
        }
        if (count($rows) !== 1) {
            Http::error(409, 'This PAF was changed by someone else. Reload it before saving.');
        }
        Http::json($rows[0]);
    }

    public static function delete(Session $s, string $id): never
    {
        [$st, $rows] = Supabase::rest('DELETE', 'pafs?select=id&id=eq.' . $id, (string) $s->token, null, ['Prefer' => 'return=representation']);
        if ($st !== 200 || !is_array($rows)) {
            Http::error(502, 'Could not delete');
        }
        if (count($rows) !== 1) {
            Http::error(403, 'Not allowed (only the creator or an administrator can delete a PAF)');
        }
        Http::json(['ok' => true]);
    }

    private static function key(string $k): string
    {
        if (!in_array($k, ['prices_blocks', 'prices_rods', 'prices_rounds', 'devaluation', 'parma_stock'], true)) {
            Http::error(404, 'Not found');
        }
        return $k;
    }

    public static function datasetGet(Session $s, string $key): never
    {
        $k = self::key($key);
        [$st, $rows] = Supabase::rest('GET', 'datasets?select=key,version,updated_at,data&key=eq.' . $k, (string) $s->token);
        if ($st !== 200 || !is_array($rows) || count($rows) !== 1) {
            Http::error(404, 'Not found');
        }
        Http::json($rows[0]);
    }

    public static function datasetPut(Session $s, string $key): never
    {
        $k = self::key($key);
        $b = Http::body();
        if (!array_key_exists('data', $b) || !is_array($b['data'])) {
            Http::error(422, 'data (JSON array/object) is required');
        }
        [$st, $rows] = Supabase::rest('PATCH', 'datasets?select=key,version,updated_at&key=eq.' . $k, (string) $s->token, ['data' => $b['data']], ['Prefer' => 'return=representation']);
        if ($st !== 200 || !is_array($rows) || count($rows) !== 1) {
            Http::error(403, 'Not allowed');
        }
        Http::json($rows[0]);
    }

    /**
     * Upload of the reference data, protected by the unlock password. The password is never stored or logged here:
     * it is forwarded once, over TLS, to a database function that compares it with a bcrypt hash and rate-limits failures.
     */
    public static function datasetsUpload(Session $s): never
    {
        $b = Http::body();
        $pw = $b['password'] ?? null;
        if (!is_string($pw) || $pw === '' || strlen($pw) > 200) {
            Http::error(422, 'Enter the unlock password');
        }
        $sets = DatasetValidator::sets($b['sets'] ?? null);
        [$st, $d] = Supabase::rest('POST', 'rpc/update_datasets', (string) $s->token, ['p_sets' => $sets, 'p_password' => $pw]);
        if ($st === 200 && is_array($d) && ($d['error'] ?? null) === 'invalid_password') {
            Http::error(403, 'Wrong unlock password');
        }
        if ($st === 200 && is_array($d) && !isset($d['error'])) {
            Http::json(['ok' => true, 'versions' => $d]);
        }
        $msg = is_array($d) ? (string) ($d['message'] ?? '') : '';
        match (true) {
            str_contains($msg, 'locked') => Http::error(429, 'Too many wrong attempts. Try again in 15 minutes.'),
            str_contains($msg, 'not_configured') => Http::error(409, 'The unlock password has not been set yet (run set_upload_password in Supabase).'),
            str_contains($msg, 'forbidden') => Http::error(403, 'Forbidden'),
            str_contains($msg, 'invalid_payload') => Http::error(422, 'The database rejected the data'),
            default => Http::error(502, 'Upload failed'),
        };
    }
}
