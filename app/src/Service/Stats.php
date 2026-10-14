<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/** Metrik REAL dari database + filesystem. Tidak ada angka dummy. */
final class Stats
{
    public static function usedDomains(int $userId): int
    {
        return (int)Connection::value('SELECT COUNT(*) FROM domains WHERE user_id = :u', ['u' => $userId]);
    }

    public static function usedDatabases(int $userId): int
    {
        return (int)Connection::value('SELECT COUNT(*) FROM databases WHERE user_id = :u', ['u' => $userId]);
    }

    public static function usedWpSites(int $userId): int
    {
        return (int)Connection::value('SELECT COUNT(*) FROM wp_sites WHERE user_id = :u', ['u' => $userId]);
    }

    public static function usedApps(int $userId): int
    {
        return (int)Connection::value('SELECT COUNT(*) FROM apps_installed WHERE user_id = :u', ['u' => $userId]);
    }

    public static function primaryDomain(int $userId): ?string
    {
        $v = Connection::value(
            'SELECT name FROM domains WHERE user_id = :u ORDER BY is_subdomain ASC, id ASC LIMIT 1',
            ['u' => $userId]
        );
        return $v === null ? null : (string)$v;
    }

    public static function diskBytes(int $userId): int
    {
        $row = Connection::one(
            'SELECT disk_bytes FROM resource_usage WHERE user_id = :u', ['u' => $userId]
        );
        if ($row !== null) {
            return (int)$row['disk_bytes'];
        }
        // Fallback: hitung filesystem home user
        $user = Connection::one('SELECT username FROM users WHERE id = :u', ['u' => $userId]);
        if ($user === null) {
            return 0;
        }
        $dir = '/var/www/' . $user['username'];
        if (!is_dir($dir)) {
            return 0;
        }
        return (int)(trim((string)shell_exec("du -sb " . escapeshellarg($dir) . " 2>/dev/null | cut -f1") ?: '0') ?: 0);
    }

    public static function bandwidthBytes(int $userId): int
    {
        return (int)Connection::value(
            'SELECT COALESCE(bandwidth_bytes,0) FROM resource_usage WHERE user_id = :u', ['u' => $userId]
        );
    }

    public static function server(): array
    {
        return [
            'hostname' => trim((string)(shell_exec('hostname') ?: '')),
            'os'      => trim((string)preg_replace('/\s*\(.*\)/', '',
                (string)(shell_exec('. /etc/os-release; echo "$PRETTY_NAME"') ?: ''))),
            'kernel'  => trim((string)(shell_exec('uname -r') ?: '')),
            'php'     => PHP_VERSION,
            'nginx'   => trim((string)(shell_exec('nginx -v 2>&1') ?: '')),
        ];
    }

    public static function publicIp(): string
    {
        $row = Connection::one("SELECT value FROM settings WHERE key = 'public_ip'");
        if ($row !== null) {
            return (string)$row['value'];
        }
        $ip = trim((string)(shell_exec("hostname -I 2>/dev/null | awk '{print $1}'") ?: ''));
        return $ip !== '' ? $ip : '-';
    }

    public static function quota(int $userId): array
    {
        $r = Connection::one(
            'SELECT p.* FROM users u JOIN packages p ON p.id = u.package_id WHERE u.id = :u',
            ['u' => $userId]
        );
        return $r ?? [];
    }

    /** Format byte -> human. */
    public static function bytes(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $f = (float)$b;
        while ($f >= 1024 && $i < 4) { $f /= 1024; $i++; }
        return ($i === 0 ? (string)(int)$f : number_format($f, 1)) . ' ' . $u[$i];
    }
}
