<?php
declare(strict_types=1);

namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Pemakaian resource per domain: disk, bandwidth, jumlah request, dan error.
 *
 * Angka diambil dari sumber nyata: `du` untuk disk dan access log nginx untuk
 * bandwidth. Access log dirotasi harian (logrotate compress), jadi file
 * hasil rotasi juga ikut dihitung agar angkanya tidak reset setiap hari.
 *
 * Penghitungan ini relatif berat, jadi dijalankan dari cron per jam lewat CLI
 * (bin/fpanel usage:collect) dan hasilnya disimpan di tabel domain_usage. Halaman
 * cukup membaca tabel, tidak menjalankan `du`/awk per visitor.
 */
final class Usage
{
    /** Logrotate menyimpan 14 hari; pakai 45 supaya aman kalau config berubah. */
    private const MAX_LOG_AGE_DAYS = 45;

    /** Berapa banyak path teratas yang disimpan per domain. */
    private const TOP_PATHS = 5;

    public const LOG_DIR = '/var/log/nginx/fpanel';

    // ------------------------------------------------------------------ public

    /**
     * Kumpulkan semua statistik domain lalu simpan ke tabel.
     *
     * @return array{ok: bool, error: string|null, message: string|null, domains?: int}
     */
    public static function collect(): array
    {
        $domains = Connection::all(
            'SELECT d.id, d.name, d.docroot, d.user_id, u.username
               FROM domains d JOIN users u ON u.id = d.user_id
              ORDER BY d.id'
        );

        $touched = 0;
        foreach ($domains as $d) {
            $docroot = (string)$d['docroot'];
            $disk    = self::diskBytes($docroot);
            $log     = self::fromLogs((string)$d['name']);

            Connection::run(
                'INSERT INTO domain_usage
                    (domain_id, measured_at, disk_bytes, bandwidth_bytes, requests,
                     errors_4xx, errors_5xx, log_updated_at, top_paths)
                 VALUES (:d, :m, :disk, :bw, :req, :e4, :e5, :lu, :top)
                 ON CONFLICT(domain_id) DO UPDATE SET
                    measured_at = :m2, disk_bytes = :disk2, bandwidth_bytes = :bw2,
                    requests = :req2, errors_4xx = :e42, errors_5xx = :e52,
                    log_updated_at = :lu2, top_paths = :top2',
                [
                    'd' => (int)$d['id'],
                    'm' => gmdate('Y-m-d H:i:s'),
                    'disk' => $disk,
                    'bw' => $log['bandwidth'],
                    'req' => $log['requests'],
                    'e4' => $log['e4'],
                    'e5' => $log['e5'],
                    'lu' => $log['updated'],
                    'top' => (string)json_encode($log['top_paths']),
                    'm2' => gmdate('Y-m-d H:i:s'),
                    'disk2' => $disk,
                    'bw2' => $log['bandwidth'],
                    'req2' => $log['requests'],
                    'e42' => $log['e4'],
                    'e52' => $log['e5'],
                    'lu2' => $log['updated'],
                    'top2' => (string)json_encode($log['top_paths']),
                ]
            );
            $touched++;
        }

        // Domain yang dihapus tidak boleh meninggalkan baris usage yatim.
        $ids = array_map(static fn (array $d): int => (int)$d['id'], $domains);
        if ($ids === []) {
            Connection::run('DELETE FROM domain_usage');
        } else {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            Connection::run('DELETE FROM domain_usage WHERE domain_id NOT IN (' . $ph . ')', $ids);
        }

        self::refreshUserTotals();

        return ['ok' => true, 'error' => null, 'message' => "Statistik $touched domain dikumpulkan.", 'domains' => $touched];
    }

    /**
     * Daftar pemakaian per domain milik satu user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listing(string $username): array
    {
        $rows = Connection::all(
            'SELECT d.id, d.name, d.type, d.docroot, d.status,
                    COALESCE(g.disk_bytes, 0)      AS disk_bytes,
                    COALESCE(g.bandwidth_bytes, 0) AS bandwidth_bytes,
                    COALESCE(g.requests, 0)        AS requests,
                    COALESCE(g.errors_4xx, 0)      AS errors_4xx,
                    COALESCE(g.errors_5xx, 0)      AS errors_5xx,
                    g.measured_at, g.log_updated_at, COALESCE(g.top_paths, "[]") AS top_paths
               FROM domains d
               LEFT JOIN domain_usage g ON g.domain_id = d.id
               JOIN users u ON u.id = d.user_id
              WHERE u.username = :u
              ORDER BY disk_bytes DESC, d.name',
            ['u' => $username]
        );
        foreach ($rows as $i => $r) {
            $decoded = json_decode((string)$r['top_paths'], true);
            $rows[$i]['top_paths'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    /**
     * Total pemakaian + kuota paket user.
     *
     * @return array<string, int>
     */
    public static function summary(string $username): array
    {
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        $row = Connection::one(
            'SELECT p.max_disk_mb, p.max_bandwidth_mb FROM users u
               LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = :u',
            ['u' => $uid]
        );
        $sum = Connection::one(
            'SELECT COALESCE(SUM(g.disk_bytes), 0)      AS disk,
                    COALESCE(SUM(g.bandwidth_bytes), 0) AS bandwidth,
                    COALESCE(SUM(g.requests), 0)        AS requests,
                    COALESCE(SUM(g.errors_4xx), 0)      AS e4,
                    COALESCE(SUM(g.errors_5xx), 0)      AS e5
               FROM domains d JOIN users u ON u.id = d.user_id
               LEFT JOIN domain_usage g ON g.domain_id = d.id
              WHERE u.username = :u',
            ['u' => $username]
        ) ?? [];

        $maxDisk = (int)($row['max_disk_mb'] ?? 0) * 1024 * 1024;
        $maxBw   = (int)($row['max_bandwidth_mb'] ?? 0) * 1024 * 1024;

        return [
            'disk'      => (int)($sum['disk'] ?? 0),
            'bandwidth' => (int)($sum['bandwidth'] ?? 0),
            'requests'  => (int)($sum['requests'] ?? 0),
            'errors_4xx'=> (int)($sum['e4'] ?? 0),
            'errors_5xx'=> (int)($sum['e5'] ?? 0),
            'max_disk'      => $maxDisk,
            'max_bandwidth' => $maxBw,
        ];
    }

    /** Waktu pengukuran terakhir (UTC), atau null kalau belum pernah. */
    public static function lastCollected(): ?string
    {
        $v = Connection::value('SELECT MAX(measured_at) FROM domain_usage');
        return $v === null ? null : (string)$v;
    }

    // ------------------------------------------------------------------ helpers

    /** Ukuran docroot dalam byte. Path diambil dari database, bukan dari user. */
    public static function diskBytes(string $docroot): int
    {
        if ($docroot === '' || str_contains($docroot, '..')) {
            return 0;
        }
        if (!is_dir($docroot)) {
            return 0;
        }
        $out = self::shell('du -sb ' . escapeshellarg($docroot) . ' 2>/dev/null | cut -f1');
        return (int)trim($out);
    }

    /**
     * Bandwidth, jumlah request, dan error dari access log nginx.
     *
     * File hasil rotasi (`.1`, `.1.gz`) ikut dihitung, kalau tidak bandwidth
     * akan terlihat turun setiap kali log dirotasi.
     *
     * @return array{bandwidth: int, requests: int, e4: int, e5: int,
     *               updated: string|null, top_paths: array<int, array{path: string, bytes: int}>}
     */
    public static function fromLogs(string $domain): array
    {
        $out = ['bandwidth' => 0, 'requests' => 0, 'e4' => 0, 'e5' => 0,
                'updated' => null, 'top_paths' => []];

        // Nama domain dipakai untuk menyusun nama file; karakter yang bisa
        // menembus path dicegah di sini.
        if (preg_match('/^[a-z0-9.-]{1,190}$/i', $domain) !== 1) {
            return $out;
        }
        $base = self::LOG_DIR . '/' . $domain . '.access.log';

        $files = [];
        if (is_file($base)) {
            $files[] = $base;
            foreach (glob($base . '.*') ?: [] as $rotated) {
                $files[] = $rotated;
            }
        }
        if ($files === []) {
            return $out;
        }

        $cut  = time() - (self::MAX_LOG_AGE_DAYS * 86400);
        $newest = 0;
        $paths  = [];

        foreach ($files as $f) {
            $mtime = (int)@filemtime($f);
            if ($mtime < $cut) {
                continue;   // terlalu lama, sudah melewati batas retensi
            }
            $newest = max($newest, $mtime);

            $isGz = str_ends_with($f, '.gz');
            // Kolom: $9 = status, $10 = byte yang dikirim.
            $awk = 'BEGIN{FS=" "} {n++; b+=($10=="-"?0:$10); '
                 . 's=$9+0; if(s>=500) e5++; else if(s>=400) e4++; '
                 . 'p=$7; if(p!="" && ($10!="-")) {agg[p]+=($10+0); cnt[p]++}} '
                 . 'END{print n+0, b+0, e4+0, e5+0}';
            $cmd = ($isGz ? 'zcat ' : 'cat ') . escapeshellarg($f) . ' 2>/dev/null | awk \'' . $awk . '\'';
            $line = trim(self::shell($cmd));
            if ($line === '') {
                continue;
            }
            [$n, $b, $e4, $e5] = array_pad(array_map('intval', (array)explode(' ', $line)), 4, 0);
            $out['requests']  += $n;
            $out['bandwidth'] += $b;
            $out['e4']        += $e4;
            $out['e5']        += $e5;

            // Path teratas: agregasi per path lalu ambil yang terbesar.
            $topCmd = ($isGz ? 'zcat ' : 'cat ') . escapeshellarg($f) . ' 2>/dev/null'
                . ' | awk \'{ if ($7 != "" && $10 != "-") print $10+0 "\\t" $7 }\''
                . ' | sort -rn | head -n ' . self::TOP_PATHS;
            foreach (explode("\n", trim(self::shell($topCmd))) as $row) {
                if ($row === '') {
                    continue;
                }
                [$bytes, $path] = array_pad(preg_split('/\t/', $row, 2) ?: [], 2, '0');
                $path = (string)$path;
                if ($path === '') {
                    continue;
                }
                $paths[$path] = ($paths[$path] ?? 0) + (int)$bytes;
            }
        }

        arsort($paths);
        $i = 0;
        foreach ($paths as $path => $bytes) {
            if ($i++ >= self::TOP_PATHS) {
                break;
            }
            $out['top_paths'][] = ['path' => $path, 'bytes' => (int)$bytes];
        }
        $out['updated'] = $newest > 0 ? gmdate('Y-m-d H:i:s', $newest) : null;

        return $out;
    }

    /**
     * Jumlahkan domain_usage ke resource_usage per user.
     *
     * Tabel resource_usage sudah dipakai dashboard, jadi nilainya ikut
     * diperbarui agar dua sumber tidak berbeda.
     */
    public static function refreshUserTotals(): void
    {
        $rows = Connection::all(
            'SELECT u.id AS uid, u.username,
                    COALESCE(SUM(g.disk_bytes), 0)      AS disk,
                    COALESCE(SUM(g.bandwidth_bytes), 0) AS bandwidth
               FROM users u
               LEFT JOIN domains d ON d.user_id = u.id
               LEFT JOIN domain_usage g ON g.domain_id = d.id
              GROUP BY u.id'
        );
        foreach ($rows as $r) {
            Connection::run(
                'INSERT INTO resource_usage (user_id, disk_bytes, bandwidth_bytes, measured_at)
                 VALUES (:u, :d, :b, :m)
                 ON CONFLICT(user_id) DO UPDATE SET
                    disk_bytes = :d2, bandwidth_bytes = :b2, measured_at = :m2',
                [
                    'u' => (int)$r['uid'], 'd' => (int)$r['disk'], 'b' => (int)$r['bandwidth'],
                    'm' => gmdate('Y-m-d H:i:s'),
                    'd2' => (int)$r['disk'], 'b2' => (int)$r['bandwidth'],
                    'm2' => gmdate('Y-m-d H:i:s'),
                ]
            );
        }
    }

    /**
     * Jalankan perintah dan kembalikan stdout.
     *
     * Perintah dibungkus kurung kurung agar stderr SELURUH pipeline hilang.
     * Tanpa itu, `sort` yang dipotong `head` menulis "Broken pipe" ke stderr
     * dan jadi membocorkan pesan ke output CLI.
     */
    private static function shell(string $cmd): string
    {
        $out = [];
        $rc  = 0;
        @exec('( ' . $cmd . ' ) 2>/dev/null', $out, $rc);
        return implode("\n", $out);
    }

    /** Format byte -> human, sama seperti Stats::bytes(). */
    public static function bytes(int $b): string
    {
        return Stats::bytes($b);
    }

    /** Persentase pemakaian, 0-100 (dibatasi agar bar tidak meluber). */
    public static function percent(int $used, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }
        return max(0, min(100, (int)round(($used / $max) * 100)));
    }
}
