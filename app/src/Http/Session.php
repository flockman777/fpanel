<?php
declare(strict_types=1);
namespace Fpanel\Http;

use Fpanel\Database\Connection;

/**
 * Session berbasis database (bukan file) supaya bisa dicabut semua perangkat,
 * ditinjau dari UI, dan tidak perlu shared /tmp antar worker php-fpm.
 */
final class Session
{
    public const COOKIE = 'fp_session';
    private ?array $user = null;
    private bool $loaded = false;

    public function __construct(
        private Request $request,
        private string $lifetime = 'P14D'
    ) {}

    private function expiry(): string
    {
        return gmdate('Y-m-d H:i:s', time() + (int)ini_get('session.gc_maxlifetime') ?: 1209600);
    }

    /**
     * Mulai sesi.
     *
     * $kind = 'auth' adalah sesi login biasa. Jenis lain dipakai untuk
     * langkah antara, misalnya 'totp': barisnya ADA tapi belum memberi akses,
     * jadi user() mengabaikannya sampai kind-nya dipromosikan jadi 'auth'.
     */
    public function start(?int $userId = null, string $kind = 'auth', int $seconds = 1209600): string
    {
        $token = bin2hex(random_bytes(32));
        Connection::insert('sessions', [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'ip'         => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'kind'       => $kind,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $seconds),
        ]);
        if (!headers_sent()) {
            setcookie(self::COOKIE, $token, [
                'expires'  => time() + $seconds,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
        }
        $this->loaded = false;
        $this->user = null;
        return $token;
    }

    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return $this->user = null;
        }
        $row = Connection::one(
            "SELECT s.id AS sid, s.expires_at, u.* FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :h AND s.kind = 'auth'
               AND s.expires_at > datetime('now')",
            ['h' => hash('sha256', $token)]
        );
        if ($row === null) {
            return $this->user = null;
        }
        Connection::run(
            "UPDATE sessions SET last_seen_at = datetime('now') WHERE id = :id",
            ['id' => $row['sid']]
        );
        unset($row['sid']);
        return $this->user = $row;
    }

    /**
     * User dari sesi antar-langkah (mis. 'totp'), atau null kalau tidak ada.
     *
     * IP ikut dibandingkan supaya cookie yang dicuri tidak bisa dipakai untuk
     * menyelesaikan login dari jaringan lain.
     */
    public function pendingUser(string $kind): ?array
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return null;
        }
        $row = Connection::one(
            "SELECT s.id AS sid, u.* FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = :h AND s.kind = :k
               AND s.expires_at > datetime('now') AND s.ip = :ip",
            ['h' => hash('sha256', $token), 'k' => $kind, 'ip' => $this->request->ip()]
        );
        if ($row === null) {
            return null;
        }
        unset($row['sid']);
        return $row;
    }

    /**
     * Naikkan sesi antar-langkah menjadi sesi login penuh.
     *
     * Cookie tidak diganti, jadi tidak ada jeda di mana user sempat logout
     * setelah 2FA berhasil.
     */
    public function promotePending(string $kind, int $seconds = 1209600): bool
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return false;
        }
        $st = Connection::run(
            "UPDATE sessions
              SET kind = 'auth', expires_at = :e, last_seen_at = datetime('now')
              WHERE token_hash = :h AND kind = :k AND ip = :ip",
            [
                'e'  => gmdate('Y-m-d H:i:s', time() + $seconds),
                'h'  => hash('sha256', $token),
                'k'  => $kind,
                'ip' => $this->request->ip(),
            ]
        );
        if ($st > 0 && !headers_sent()) {
            // Cookie pending dibuat dengan umur 5 menit. Tanpa diperpanjang di
            // sini, user akan terlempar keluar 5 menit setelah 2FA berhasil.
            setcookie(self::COOKIE, (string)$token, [
                'expires'  => time() + $seconds,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
        }
        // Paksa dibaca ulang supaya user() melihat kind yang baru.
        $this->loaded = false;
        $this->user   = null;
        return $st > 0;
    }

    /** Buang sesi antar-langkah tanpa memengaruhi login lain. */
    public function dropPending(string $kind): void
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token === null || $token === '') {
            return;
        }
        Connection::run(
            'DELETE FROM sessions WHERE token_hash = :h AND kind = :k',
            ['h' => hash('sha256', $token), 'k' => $kind]
        );
    }


    public function destroy(): void
    {
        $token = $this->request->cookie(self::COOKIE);
        if ($token !== null && $token !== '') {
            Connection::run('DELETE FROM sessions WHERE token_hash = :h',
                ['h' => hash('sha256', $token)]);
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        }
        $this->loaded = true;
        $this->user = null;
    }

    public function destroyAllForUser(int $userId): void
    {
        Connection::run('DELETE FROM sessions WHERE user_id = :u', ['u' => $userId]);
    }

    public function cleanup(): void
    {
        Connection::run("DELETE FROM sessions WHERE expires_at <= datetime('now')");
    }
}
