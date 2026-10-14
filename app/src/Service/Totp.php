<?php
declare(strict_types=1);

namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Two-Factor Authentication (TOTP, RFC 6238).
 *
 * Dihitung sendiri tanpa library: yang dibutuhkan cuma HMAC-SHA1 dari hash
 * bawaan PHP. QR code dirender di server memakai biner qrencode, jadi RAHASIA
 * TOTP tidak pernah dikirim ke layanan pihak ketiga.
 *
 * Rahasia disimpan polos di tabel users (dipakai untuk memverifikasi kode).
 * Itu konsekuensi standar TOTP; yang penting kolom itu tidak pernah tampil di
 * halaman mana pun dan tidak ikut dibackup ke tempat publik.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';  // RFC 4648 base32
    private const PERIOD   = 30;
    private const DIGITS   = 6;

    /** Berapa langkah sebelum/sesudah yang diterima (untuk toleransi jam). */
    private const WINDOW = 1;

    /** Berapa kode pemulihan yang dibuat. */
    private const RECOVERY_COUNT = 10;

    /** Masa berlaku setup yang belum dikonfirmasi. */
    private const SETUP_TTL_MIN = 15;

    public const ISSUER = 'FPanel';

    // ------------------------------------------------------------------ secret

    /** Base32 acak, panjang default 32 (160 bit, sesuai rekomendasi). */
    public static function generateSecret(int $length = 32): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Decode base32 ke byte biner. */
    public static function base32Decode(string $input): string
    {
        $input = strtoupper(preg_replace('/[\s=-]+/', '', $input) ?? '');
        if ($input === '') {
            return '';
        }
        $bits = '';
        $len  = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $input[$i]);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }
        return $bytes;
    }

    /** Kode TOTP untuk satu timestep. */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '';
        }
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $bin     = pack('J', $counter);          // 64-bit big endian
        $hash    = hash_hmac('sha1', $bin, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value  = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Cek kode, menerima satu langkah sebelum dan sesudah. */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($secret === '' || strlen($code) !== self::DIGITS) {
            return false;
        }
        $now = $timestamp ?? time();
        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $expect = self::code($secret, $now + ($i * self::PERIOD));
            if ($expect !== '' && hash_equals($expect, $code)) {
                return true;
            }
        }
        return false;
    }

    /** URI provisioning untuk Google Authenticator, Aegis, dan aplikasi sejenis. */
    public static function provisioningUri(string $secret, string $username): string
    {
        return 'otpauth://totp/' . rawurlencode(self::ISSUER . ':' . $username)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode(self::ISSUER)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * Render QR code ke SVG memakai biner qrencode di server.
     *
     * Rahasia TOTP tidak keluar dari server sama sekali.
     */
    public static function qrSvg(string $uri): ?string
    {
        $bin = self::which('qrencode');
        if ($bin === null) {
            return null;
        }
        $cmd = [$bin, '-t', 'SVG', '-o', '-', '-m', '1', '-s', '4', $uri];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        @fclose($pipes[0]);
        $svg = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        if (proc_close($proc) !== 0 || trim($svg) === '' || !str_contains($svg, '<svg')) {
            return null;
        }
        // Buang komentar metadata qrencode; tidak perlu dan membocorkan versi.
        return (string)preg_replace('/<!--.*?-->/s', '', $svg);
    }

    // ------------------------------------------------------------------- setup

    /**
     * Mulai setup: simpan secret baru dengan 2FA masih nonaktif.
     *
     * @return array{ok: bool, error: string|null, secret?: string, uri?: string}
     */
    public static function startSetup(string $username): array
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
        }
        $secret = self::generateSecret();
        Connection::update('users', [
            'totp_secret'  => self::encodeSecret($secret),
            'totp_enabled' => 0,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $uid]);

        return [
            'ok'     => true,
            'error'  => null,
            'secret' => $secret,
            'uri'    => self::provisioningUri($secret, $username),
        ];
    }

    /** Secret yang menunggu konfirmasi (2FA belum aktif). */
    public static function pendingSecret(string $username): ?string
    {
        $v = Connection::value(
            'SELECT totp_secret FROM users WHERE username = :u AND totp_enabled = 0',
            ['u' => $username]
        );
        if ($v === null || (string)$v === '') {
            return null;
        }
        return self::decodeSecret((string)$v);
    }

    public static function isEnabled(string $username): bool
    {
        return (int)Connection::value(
            'SELECT COALESCE(totp_enabled, 0) FROM users WHERE username = :u',
            ['u' => $username]
        ) === 1;
    }

    /**
     * Aktifkan 2FA setelah kode dikonfirmasi, lalu buat kode pemulihan.
     *
     * @return array{ok: bool, error: string|null, recovery?: array<int, string>}
     */
    public static function activate(string $username, string $code): array
    {
        $row = Connection::one(
            'SELECT id, totp_secret, totp_enabled FROM users WHERE username = :u',
            ['u' => $username]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
        }
        if ((int)($row['totp_enabled'] ?? 0) === 1) {
            return ['ok' => false, 'error' => '2FA sudah aktif.'];
        }
        $secret = self::decodeSecret((string)($row['totp_secret'] ?? ''));
        if ($secret === '') {
            return ['ok' => false, 'error' => 'Belum ada secret. Mulai setup dulu.'];
        }
        if (!self::verify($secret, $code)) {
            return ['ok' => false, 'error' => 'Kode salah atau kedaluwarsa.'];
        }

        Connection::update('users', [
            'totp_enabled' => 1,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int)$row['id']]);

        $recovery = self::regenerateRecovery($username);
        return ['ok' => true, 'error' => null, 'recovery' => $recovery];
    }

    /** Matikan 2FA. Password tetap dicek di route. */
    public static function disable(string $username): array
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
        }
        Connection::update('users', [
            'totp_secret'  => null,
            'totp_enabled' => 0,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $uid]);
        Connection::run('DELETE FROM totp_recovery WHERE user_id = :u', ['u' => $uid]);
        return ['ok' => true, 'error' => null];
    }

    // ---------------------------------------------------------------- recovery

    /**
     * Buat kode pemulihan baru (sekali pakai), kembalikan versi plaintextnya.
     *
     * Hanya hash yang disimpan; plaintext hanya ditampilkan sekali.
     *
     * @return array<int, string>
     */
    public static function regenerateRecovery(string $username): array
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return [];
        }
        Connection::run('DELETE FROM totp_recovery WHERE user_id = :u', ['u' => $uid]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $code = strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $codes[] = $code;
            Connection::run(
                'INSERT INTO totp_recovery (user_id, code_hash, created_at) VALUES (:u, :h, :t)',
                ['u' => $uid, 'h' => self::hashRecovery($code), 't' => gmdate('Y-m-d H:i:s')]
            );
        }
        return $codes;
    }

    public static function recoveryLeft(string $username): int
    {
        return (int)Connection::value(
            'SELECT COUNT(*) FROM totp_recovery r JOIN users u ON u.id = r.user_id
              WHERE u.username = :u AND r.used_at IS NULL',
            ['u' => $username]
        );
    }

    /** Pakai satu kode pemulihan. True kalau cocok dan belum dipakai. */
    public static function useRecovery(string $username, string $code): bool
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return false;
        }
        $code = strtoupper(trim($code));
        if (preg_match('/^[0-9A-F]{6}-[0-9A-F]{6}$/', $code) !== 1) {
            return false;
        }
        $row = Connection::one(
            'SELECT id FROM totp_recovery WHERE user_id = :u AND code_hash = :h AND used_at IS NULL LIMIT 1',
            ['u' => $uid, 'h' => self::hashRecovery($code)]
        );
        if ($row === null) {
            return false;
        }
        Connection::run(
            'UPDATE totp_recovery SET used_at = datetime(\'now\') WHERE id = :id',
            ['id' => (int)$row['id']]
        );
        return true;
    }

    private static function hashRecovery(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    // ------------------------------------------------------------------- login

    /**
     * Verifikasi kedua di saat login: kode TOTP, atau kode pemulihan.
     */
    public static function verifyLogin(string $username, string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['ok' => false, 'error' => 'Masukkan kode dari aplikasi autentikator.', 'method' => null];
        }
        $secret = self::decodeSecret((string)(Connection::value(
            'SELECT totp_secret FROM users WHERE username = :u',
            ['u' => $username]
        ) ?? ''));
        if ($secret !== '' && self::verify($secret, $input)) {
            return ['ok' => true, 'error' => null, 'method' => 'totp'];
        }
        if (preg_match('/^[0-9A-F]{6}-[0-9A-F]{6}$/i', $input) === 1 && self::useRecovery($username, $input)) {
            return ['ok' => true, 'error' => null, 'method' => 'recovery'];
        }
        return ['ok' => false, 'error' => 'Kode salah atau sudah dipakai.', 'method' => null];
    }

    public static function setupTtlMinutes(): int
    {
        return self::SETUP_TTL_MIN;
    }

    // ----------------------------------------------------------------- internal

    /**
     * Simpan seed dalam bentuk terenkripsi.
     *
     * Kalau kunci tidak tersedia, seed disimpan polos supaya user tidak
     * terkunci keluar dari akunnya sendiri. Keadaan itu dilaporkan lewat
     * securityNotice() supaya bisa terlihat di UI.
     */
    private static function encodeSecret(string $secret): string
    {
        return SecretBox::encrypt($secret) ?? $secret;
    }

    /**
     * Baca seed, menerima dua bentuk: terenkripsi (sbx1:) dan polos.
     *
     * Bentuk polos harus tetap diterima karena dipakai CLI migrasi untuk
     * mengisi nilai yang belum sempat terenkripsi.
     */
    private static function decodeSecret(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        if (!SecretBox::isEncrypted($raw)) {
            return $raw;
        }
        return SecretBox::decrypt($raw) ?? '';
    }

    /** Peringatan enkripsi seed untuk ditampilkan di halaman Security. */
    public static function securityNotice(): ?string
    {
        return SecretBox::available() ? null
            : 'Kunci enkripsi tidak tersedia (/etc/fpanel/app-key); seed TOTP disimpan tanpa enkripsi.';
    }

    private static function userId(string $username): int
    {
        return (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
    }

    private static function which(string $bin): ?string
    {
        $path = (string)(getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        foreach (explode(':', $path) as $dir) {
            $p = rtrim($dir, '/') . '/' . $bin;
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return null;
    }
}