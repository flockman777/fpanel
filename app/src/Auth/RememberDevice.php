<?php
declare(strict_types=1);
namespace Fpanel\Auth;

use Fpanel\Database\Connection;

/**
 * "Ingat perangkat ini": cookie kedua yang bertahan lama.
 *
 * Cookie session (fp_session) tetap cookie's utama: itu yang memberi akses.
 * Cookie ini hanya menandai perangkat sebagai tepercaya, dan hanya berguna
 * kalau session sudah tidak ada.
 *
 * Yang membuatnya aman untuk diperpanjang:
 *   - token diacak penuh, hanya hash-nya yang disimpan
 *   - dibatasi satu token per perangkat, jadi orang yang mencuri cookie lama
 *     tidak bisa memakainya berdampingan dengan pemilik
 *   - bisa dicabut per perangkat atau sekaligus dari halaman Security
 */
final class RememberDevice
{
    public const COOKIE = 'fp_device';

    /** Hari sebelum token perangkat dianggap kedaluwarsa. */
    public const TTL_DAYS = 90;

    private const PREFIX = 'fpd_';

    private function __construct() {}

    /** Set cookie perangkatingat. Dipanggil setelah login berhasil. */
    public static function issue(int $userId, string $ip, string $userAgent): string
    {
        $token = self::PREFIX . bin2hex(random_bytes(32));
        Connection::insert('remember_devices', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'ip'         => substr($ip, 0, 45),
            'user_agent' => substr($userAgent, 0, 255),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (self::TTL_DAYS * 86400)),
        ]);
        if (!headers_sent()) {
            setcookie(self::COOKIE, $token, [
                'expires'  => time() + (self::TTL_DAYS * 86400),
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
        }
        return $token;
    }

    /**
     * Device key dari cookie, atau 0 kalau tidak ada/tidak cocok.
     */
    public static function deviceId(?string $token): int
    {
        $token = (string)$token;
        if ($token === '' || !str_starts_with($token, self::PREFIX)) {
            return 0;
        }
        return (int)Connection::value(
            'SELECT id FROM remember_devices
              WHERE token_hash = :h AND revoked_at IS NULL
                AND expires_at > datetime("now")',
            ['h' => hash('sha256', $token)]
        );
    }

    /**
     * Perpanjang masa berlaku satu perangkat.
     *
     * Dipanggil saat memakai remember-me, supaya perangkat yang benar-benar
     * dipakai tidak diam-diam habis.
     */
    public static function touch(int $deviceId): bool
    {
        return Connection::run(
            'UPDATE remember_devices
                SET expires_at = :e, last_used_at = datetime("now")
              WHERE id = :i AND revoked_at IS NULL AND expires_at > datetime("now")',
            ['e' => gmdate('Y-m-d H:i:s', time() + (self::TTL_DAYS * 86400)),
             'i' => $deviceId]
        ) > 0;
    }

    /** Cabut satu perangkat (dipakai tombol "hapus perangkat ini"). */
    public static function revoke(int $deviceId): bool
    {
        if ($deviceId < 1) {
            return false;
        }
        Connection::update('remember_devices', ['revoked_at' => gmdate('Y-m-d H:i:s')],
            'id = :i AND revoked_at IS NULL', ['i' => $deviceId]);
        self::clearCookie();
        return true;
    }

    /** Cabut semua perangkat satu user, lalu hentikan issuance. */
    public static function revokeAll(int $userId): int
    {
        return Connection::run(
            'UPDATE remember_devices SET revoked_at = datetime("now")
              WHERE user_id = :u AND revoked_at IS NULL',
            ['u' => $userId]
        );
    }

    /** Daftar perangkat aktif untuk UI. */
    public static function listing(int $userId): array
    {
        $rows = Connection::all(
            'SELECT id, ip, user_agent, created_at, last_used_at
               FROM remember_devices
              WHERE user_id = :u AND revoked_at IS NULL
                AND expires_at > datetime("now")
              ORDER BY COALESCE(last_used_at, created_at) DESC',
            ['u' => $userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'ip'           => (string)($r['ip'] ?? '-'),
                'label'        => self::describe((string)($r['user_agent'] ?? '')),
                'last_used_at' => (string)($r['last_used_at'] ?? $r['created_at']),
            ];
        }
        return $out;
    }

    public static function activeCount(): int
    {
        return (int)Connection::value(
            'SELECT COUNT(*) FROM remember_devices
              WHERE revoked_at IS NULL AND expires_at > datetime("now")'
        );
    }

    /** Buang cookie di sisi browser. */
    public static function clearCookie(): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
        }
    }

    /** Nama perangkat ringkas dari user agent, tanpa mengirimnya ke mana pun. */
    public static function describe(string $ua): string
    {
        if ($ua === '') {
            return 'Perangkat tidak dikenal';
        }
        $os = 'Sistem tidak dikenal';
        if (str_contains($ua, 'Windows'))  { $os = 'Windows'; }
        elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) { $os = 'iOS'; }
        elseif (str_contains($ua, 'Android')) { $os = 'Android'; }
        elseif (str_contains($ua, 'Mac OS')) { $os = 'macOS'; }
        elseif (str_contains($ua, 'Linux'))  { $os = 'Linux'; }

        $browser = 'Browser';
        if (preg_match('/Edg\/([\d.]+)/', $ua, $m))      { $browser = 'Edge ' . self::major($m[1]); }
        elseif (preg_match('/OPR\/([\d.]+)/', $ua, $m))  { $browser = 'Opera ' . self::major($m[1]); }
        elseif (preg_match('/Firefox\/([\d.]+)/', $ua, $m)) { $browser = 'Firefox ' . self::major($m[1]); }
        elseif (preg_match('/Chrome\/([\d.]+)/', $ua, $m))  { $browser = 'Chrome ' . self::major($m[1]); }
        elseif (preg_match('/Safari\/([\d.]+)/', $ua, $m))  { $browser = 'Safari'; }
        return $os . ' - ' . $browser;
    }

    private static function major(string $v): string
    {
        return explode('.', $v)[0] ?? '';
    }

    /** Buang token kedaluwarsa dan yang sudah dicabut lama. */
    public static function prune(int $keepRevokedDays = 30): int
    {
        return Connection::run(
            'DELETE FROM remember_devices
              WHERE expires_at < datetime("now")
                 OR (revoked_at IS NOT NULL AND revoked_at < datetime("now", :win))',
            ['win' => '-' . $keepRevokedDays . ' days']
        )->rowCount();
    }
}
