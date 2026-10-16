<?php
declare(strict_types=1);

namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Resource usage per domain: disk, bandwidth, request count, and errors.
 *
 * Numbers come from real sources: `du` for disk and the nginx access log
 * for bandwidth. The access log rotates daily (logrotate compress), so
 * rotated files count too, keeping the numbers from resetting every day.
 *
 * This computation is relatively heavy, so it runs hourly from cron through
 * the CLI (bin/fpanel usage:collect) and the result lands in the domain_usage
 * table. The page only reads the table; it never runs `du`/awk per visitor.
 */
final class Usage
{
    /** Logrotate keeps 14 days; 45 is used for safety when the config changes. */
    private const MAX_LOG_AGE_DAYS = 45;

    /** How many top paths are stored per domain. */
    private const TOP_PATHS = 5;

    public const LOG_DIR = '/var/log/nginx/fpanel';

    // ------------------------------------------------------------------ public

    /**
     * Collect every domain's statistics and store them in the table.
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

        // A deleted domain must not leave an orphaned usage row behind.
        $ids = array_map(static fn (array $d): int => (int)$d['id'], $domains);
        if ($ids === []) {
            Connection::run('DELETE FROM domain_usage');
        } else {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            Connection::run('DELETE FROM domain_usage WHERE domain_id NOT IN (' . $ph . ')', $ids);
        }

        self::refreshUserTotals();

        return ['ok' => true, 'error' => null, 'message' => "Statistics collected for $touched domain(s).", 'domains' => $touched];
    }

    /**
     * Usage listing per domain for a single user.
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
     * Total usage + the user's plan quota.
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

    /** Time of the last measurement (UTC), or null when there is none. */
    public static function lastCollected(): ?string
    {
        $v = Connection::value('SELECT MAX(measured_at) FROM domain_usage');
        return $v === null ? null : (string)$v;
    }

    // ------------------------------------------------------------------ helpers

    /** Docroot size in bytes. The path comes from the database, not from the user. */
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
     * Bandwidth, request count, and errors from the nginx access log.
     *
     * Rotated files (`.1`, `.1.gz`) count too, otherwise bandwidth would
     * appear to drop every time the log rotates.
     *
     * @return array{bandwidth: int, requests: int, e4: int, e5: int,
     *               updated: string|null, top_paths: array<int, array{path: string, bytes: int}>}
     */
    public static function fromLogs(string $domain): array
    {
        $out = ['bandwidth' => 0, 'requests' => 0, 'e4' => 0, 'e5' => 0,
                'updated' => null, 'top_paths' => []];

        // The domain name is used to build the file name; characters that
        // could break out of the path are blocked here.
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
                continue;   // too old, past the retention limit
            }
            $newest = max($newest, $mtime);

            $isGz = str_ends_with($f, '.gz');
            // Fields: $9 = status, $10 = bytes sent.
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

            // Top paths: aggregate per path, then take the largest.
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
     * Sum domain_usage into resource_usage per user.
     *
     * The dashboard already reads resource_usage, so it is updated too
     * so the two sources do not disagree.
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
     * Run a command and return stdout.
     *
     * The command is wrapped in parentheses so stderr from the WHOLE pipeline
     * disappears. Without that, a `sort` cut off by `head` writes "Broken
     * pipe" to stderr and leaks that message into the CLI output.
     */
    private static function shell(string $cmd): string
    {
        $out = [];
        $rc  = 0;
        @exec('( ' . $cmd . ' ) 2>/dev/null', $out, $rc);
        return implode("\n", $out);
    }

    /** Format bytes -> human, same as Stats::bytes(). */
    public static function bytes(int $b): string
    {
        return Stats::bytes($b);
    }

    /** Usage percentage, 0-100 (clamped so the bar does not overflow). */
    public static function percent(int $used, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }
        return max(0, min(100, (int)round(($used / $max) * 100)));
    }
}
