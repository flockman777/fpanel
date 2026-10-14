<?php
declare(strict_types=1);
namespace Fpanel\Auth;

use Fpanel\Database\Connection;
use Fpanel\Http\Session;
use Fpanel\Service\Totp;
use Fpanel\Support\Audit;

final class Auth
{
    public const MAX_FAILS   = 5;      // percobaan per IP
    public const WINDOW_MIN = 15;     // dalam rentang ini
    public const LOCK_MIN   = 15;     // lama dikunci

    /**
     * Batas per nama akun, jauh lebih longgar dari batas per IP.
     *
     * Batas per IP saja bisa dilewati dengan memakai IP berbeda sekaligus, jadi
     * satu nama akun juga punya batas sendiri. Nilainya jauh lebih tinggi supaya
     * tidak mudah dikunci hanya karena satu orang salah ketik berkali-kali.
     */
    public const MAX_FAILS_USER = 25;

    public function __construct(private Session $session) {}

    private function recentFails(string $ip): int
    {
        return (int)Connection::value(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip = :ip AND success = 0
               AND created_at > datetime('now', :win)",
            ['ip' => $ip, 'win' => '-' . self::WINDOW_MIN . ' minutes']
        );
    }

    /** Gagal terbaru untuk satu nama akun, dari IP berapa pun. */
    private function recentFailsForUser(?string $username): int
    {
        if ($username === null || trim($username) === '') {
            return 0;
        }
        return (int)Connection::value(
            "SELECT COUNT(*) FROM login_attempts
             WHERE username = :u AND success = 0
               AND created_at > datetime('now', :win)",
            ['u' => $username, 'win' => '-' . self::WINDOW_MIN . ' minutes']
        );
    }

    public function isLocked(string $ip): bool
    {
        return $this->recentFails($ip) >= self::MAX_FAILS;
    }

    /**
     * Kunci berbasis nama akun.
     *
     * Ini yang menutup serangan dari banyak IP sekaligus: satu IP hanya boleh
     * dicoba MAX_FAILS kali, tapi dari 25 IP berbeda satu akun tetap terkunci.
     * Namanya dinormalisasi dulu karena form menerima email sebagai identitas.
     */
    public function isUserLocked(?string $username): bool
    {
        return $this->recentFailsForUser(self::normalizeUser($username))
            >= self::MAX_FAILS_USER;
    }

    /** Samakan email dan nama akun supaya tidak terhitung sebagai dua target. */
    private static function normalizeUser(?string $username): ?string
    {
        $u = trim((string)$username);
        if ($u === '') {
            return null;
        }
        // Bedakan hanya berdasarkan bagian sebelum "@": dua email di domain sama
        // tetap dihitung terpisah, tapi alamat yang sama tidak.
        return str_contains($u, '@') ? substr($u, 0, (int)strpos($u, '@')) : $u;
    }

    public function lockRemaining(string $ip): int
    {
        return (int)Connection::value(
            "SELECT CAST(MAX(CAST((julianday(datetime('now')) - julianday(created_at)) * 1440 AS INTEGER)) AS INTEGER)
             FROM login_attempts
             WHERE ip = :ip AND success = 0
               AND created_at > datetime('now', :win)",
            ['ip' => $ip, 'win' => '-' . self::WINDOW_MIN . ' minutes']
        );
    }

    private function record(string $ip, ?string $username, bool $ok): void
    {
        Connection::insert('login_attempts', [
            'ip' => $ip, 'username' => self::normalizeUser($username), 'success' => $ok ? 1 : 0,
        ]);
        Connection::run(
            "DELETE FROM login_attempts WHERE created_at < datetime('now','-1 day')"
        );
    }

    /** @return array{ok:bool,error?:string,need_totp?:bool,user_id?:int} */
    public function attempt(string $username, string $password, string $ip): array
    {
        if ($this->isLocked($ip) || $this->isUserLocked($username)) {
            return ['ok' => false, 'error' => 'Terlalu banyak percobaan. Coba lagi dalam '
                . self::LOCK_MIN . ' menit.'];
        }
        $user = Connection::one(
            'SELECT * FROM users WHERE username = :u OR email = :u',
            ['u' => $username]
        );
        // Selalu jalankan verify agar waktu respons tidak membocorkan keberadaan user
        $hash = $user['password_hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
        $pass = PasswordHasher::verify($password, $hash);

        if ($user === null || !$pass) {
            $this->record($ip, $username, false);
            Audit::log('auth.login', $user['id'] ?? null, $username, 'user',
                $user['id'] ?? null, $ip, 'denied');
            return ['ok' => false, 'error' => 'Username atau password salah.'];
        }
        if ($user['status'] !== 'active') {
            $this->record($ip, $username, false);
            Audit::log('auth.login', (int)$user['id'], $user['username'], 'user',
                $user['id'], $ip, 'denied', ['reason' => 'suspended']);
            return ['ok' => false, 'error' => 'Akun ditangguhkan. Hubungi admin.'];
        }
        if (PasswordHasher::needsRehash($user['password_hash'])) {
            Connection::update('users',
                ['password_hash' => PasswordHasher::hash($password), 'updated_at' => gmdate('Y-m-d H:i:s')],
                'id = :id', ['id' => $user['id']]);
        }

        $needsTotp = (int)($user['totp_enabled'] ?? 0) === 1
            && trim((string)($user['totp_secret'] ?? '')) !== '';

        // Rekaman percobaan sukses baru ditulis setelah 2FA benar-benar lolos,
        // supaya password yang benar tapi kode salah tidak tercatat "sukses".
        if (!$needsTotp) {
            $this->record($ip, $username, true);
            $this->session->start((int)$user['id']);
            $this->finalize((int)$user['id'], (string)$user['username'], $ip, 'password');
        }

        if ($needsTotp) {
            // Sesi antar-langkah: JENIS 'totp' berarti belum login. Durasi
            // pendek supaya tidak bisa dibiarkan menggantung.
            $this->session->start((int)$user['id'], 'totp', 300);
            return ['ok' => true, 'need_totp' => true, 'error' => null,
                    'user_id' => (int)$user['id']];
        }

        // user_id dikembalikan supaya pemanggil bisa langsung memakainya.
        // $this->session->user() tidak bisa di sini: ia membaca cookie dari
        // Request yang dibuat sebelum response, sedangkan cookie sesi baru
        // dibuat di request yang sama ini.
        return ['ok' => true, 'need_totp' => false, 'error' => null,
                'user_id' => (int)$user['id']];
    }

    /**
     * Langkah kedua login: verifikasi kode TOTP atau kode pemulihan.
     *
     * Dipanggil hanya bila attempt() sudah mengembalikan need_totp, sehingga
     * user_id diambil dari sesi antar-langkah, bukan dari input request.
     */
    public function confirmTotp(string $code, string $ip): array
    {
        $pending = $this->session->pendingUser('totp');
        if ($pending === null) {
            return ['ok' => false, 'error' => 'Sesi verifikasi kedaluwarsa. Silakan login ulang.'];
        }
        if ($this->isLocked($ip) || $this->isUserLocked((string)$pending['username'])) {
            return ['ok' => false, 'error' => 'Terlalu banyak percobaan. Coba lagi dalam '
                . self::LOCK_MIN . ' menit.'];
        }

        $username = (string)$pending['username'];
        $r = Totp::verifyLogin($username, $code);
        if (empty($r['ok'])) {
            $this->record($ip, $username, false);
            Audit::log('auth.totp', (int)$pending['id'], $username, 'user',
                $pending['id'], $ip, 'denied');
            return ['ok' => false, 'error' => (string)$r['error']];
        }

        $this->record($ip, $username, true);
        $this->session->promotePending('totp');
        $this->finalize((int)$pending['id'], $username, $ip, (string)$r['method']);
        return ['ok' => true, 'error' => null];
    }

    /**
     * Bagian akhir login yang sama untuk langkah password dan langkah 2FA.
     *
     * Tidak menyentuh sesi: pada langkah password sesi baru dibuat di attempt(),
     * pada langkah 2FA sesi antar-langkah sudah dipromosikan promotePending().
     * Kalau sesi dibuat ulang di sini, baris hasil promosi akan menggantung
     * sebagai sesi sah yang tidak pernah dibersihkan.
     */
    private function finalize(int $userId, string $username, string $ip, string $method): void
    {
        Connection::update('users', [
            'last_login_at' => gmdate('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
            'failed_logins' => 0,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $userId]);
        Audit::log('auth.login', $userId, $username, 'user', $userId, $ip, 'ok',
            ['method' => $method]);
    }

    public function user(): ?array  { return $this->session->user(); }
    public function check(): bool  { return $this->session->user() !== null; }
    public function isAdmin(): bool
    {
        $u = $this->session->user();
        return $u !== null && $u['role'] === 'admin';
    }
    public function id(): ?int
    {
        $u = $this->session->user();
        return $u === null ? null : (int)$u['id'];
    }
    public function logout(): void
    {
        $u = $this->session->user();
        if ($u !== null) {
            Audit::log('auth.logout', (int)$u['id'], $u['username'], 'user', $u['id'], '');
        }
        $this->session->destroy();
    }
}
