<?php
declare(strict_types=1);
namespace Fpanel\Auth;

use Fpanel\Database\Connection;

/**
 * Link login sekali pakai.
 *
 * Dipakai untuk masuk dari perangkat atau browser baru tanpa mengetik password:
 * panel mengirim tautan, pengguna membukanya, lalu langsung masuk.
 *
 * Dua aturan yang membuat ini tetap aman meski tautannya bocor:
 *   - hanya hash token yang disimpan, jadi bocornya isi database tidak langsung
 *     berarti punya akses
 *   - token hangus begitu dipakai (DELETE di dalam transaksi yang sama), jadi
 *     tautan yang ikut ter-copy tidak bisa dipakai ulang
 *
 * Masa berlaku pendek (15 menit) supaya tautan yang tertinggal di inbox atau
 * riwayat unduhan cepat kehilangan artinya.
 */
final class LoginLink
{
    /** Menit sebelum tautan kedaluwarsa. */
    public const TTL_MIN = 15;

    private const PREFIX = 'fpl_';

    private function __construct() {}

    /**
     * Terbitkan tautan baru untuk satu user.
     *
     * @return array{ok:bool,error?:string,token?:string,expires_at?:string}
     */
    public static function issue(string $username, string $ip = ''): array
    {
        $uid = (int)Connection::value(
            'SELECT id FROM users WHERE username = :u', ['u' => $username]
        );
        if ($uid < 1) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
        }
        // Token lama dicabut dulu: hanya satu tautan aktif per akun, supaya
        // tautan yang bocor tidak bisa dipakai bareng dengan tautan baru.
        Connection::run('DELETE FROM login_links WHERE user_id = :u', ['u' => $uid]);

        $token = self::PREFIX . bin2hex(random_bytes(24));
        Connection::insert('login_links', [
            'user_id'    => $uid,
            'token_hash' => hash('sha256', $token),
            'ip_hint'    => substr($ip, 0, 45),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + (self::TTL_MIN * 60)),
        ]);
        return ['ok' => true, 'error' => null, 'token' => $token,
                'expires_at' => gmdate('Y-m-d H:i:s', time() + (self::TTL_MIN * 60))];
    }

    /**
     * Tukar token jadi user id, sekaligus membakar tokennya.
     *
     * Penghapusan dan pembacaan terjadi dalam satu transaksi: kalau dua
     * permintaan tiba bersamaan, hanya yang pertama yang dapat user id.
     */
    public static function redeem(string $token, string $ip = ''): ?int
    {
        $token = trim($token);
        if (!str_starts_with($token, self::PREFIX)) {
            return null;
        }
        $hash = hash('sha256', $token);

        $pdo = Connection::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'SELECT id, user_id FROM login_links
                  WHERE token_hash = :h AND used_at IS NULL
                    AND expires_at > datetime("now")'
            );
            $st->execute(['h' => $hash]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->rollBack();
                return null;
            }
            $del = $pdo->prepare(
                'UPDATE login_links SET used_at = datetime("now") WHERE id = :i'
            );
            $del->execute(['i' => (int)$row['id']]);
            $pdo->commit();
            return (int)$row['user_id'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return null;
        }
    }

    /** Jumlah tautan yang masih bisa dipakai. */
    public static function activeCount(): int
    {
        return (int)Connection::value(
            'SELECT COUNT(*) FROM login_links
              WHERE used_at IS NULL AND expires_at > datetime("now")'
        );
    }

    /** Cabut semua tautan aktif satu user (mis. setelah ganti password). */
    public static function revokeAll(int $userId): int
    {
        return Connection::run(
            'DELETE FROM login_links WHERE user_id = :u AND used_at IS NULL',
            ['u' => $userId]
        );
    }

    /**
     * Bersihkan tautan kedaluwarsa / sudah dipakai.
     *
     * Dipanggil housekeeping: baris `used_at` perlu disimpan sebentar supaya
     * percobaan pakai ulang bisa dibedakan dari token yang salah, tapi tidak
     * perlu disimpan selamanya.
     */
    public static function prune(int $keepUsedDays = 7): int
    {
        return Connection::run(
            'DELETE FROM login_links
              WHERE (used_at IS NOT NULL AND used_at < datetime("now", :win))
                 OR (expires_at < datetime("now", :win2))',
            ['win' => '-' . $keepUsedDays . ' days', 'win2' => '-' . $keepUsedDays . ' days']
        )->rowCount();
    }
}
