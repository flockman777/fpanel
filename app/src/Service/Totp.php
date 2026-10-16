<?php
declare(strict_types=1);

namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Two-Factor Authentication (TOTP, RFC 6238).
 *
 * Implemented here without a library: all it needs is HMAC-SHA1 from PHP's
 * built-in hash functions. The QR code is rendered on the server with the
 * qrencode binary, so the TOTP SECRET never goes to a third-party service.
 *
 * The secret is stored in the users table (used to verify codes). That is
 * the standard TOTP trade-off; what matters is that the column is never
 * rendered on any page and never backed up to a public place.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';  // RFC 4648 base32
    private const PERIOD   = 30;
    private const DIGITS   = 6;

    /** How many steps before/after are accepted (clock tolerance). */
    private const WINDOW = 1;

    /** How many recovery codes are generated. */
    private const RECOVERY_COUNT = 10;

    /** Validity window of an unconfirmed setup. */
    private const SETUP_TTL_MIN = 15;

    public const ISSUER = 'FPanel';

    // ------------------------------------------------------------------ secret

    /** Random base32, default length 32 (160 bit, as recommended). */
    public static function generateSecret(int $length = 32): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Decode base32 into binary bytes. */
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

    /** TOTP code for a single timestep. */
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

    /** Check a code, accepting one step before and after. */
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

    /** Provisioning URI for Google Authenticator, Aegis, and similar apps. */
    public static function provisioningUri(string $secret, string $username): string
    {
        return 'otpauth://totp/' . rawurlencode(self::ISSUER . ':' . $username)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode(self::ISSUER)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * Render a QR code to SVG with the qrencode binary on the server.
     *
     * The TOTP secret never leaves the server at all.
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
        // Drop qrencode metadata comments; unnecessary and leaks the version.
        return (string)preg_replace('/<!--.*?-->/s', '', $svg);
    }

    // ------------------------------------------------------------------- setup

    /**
     * Start setup: store a fresh secret with 2FA still disabled.
     *
     * @return array{ok: bool, error: string|null, secret?: string, uri?: string}
     */
    public static function startSetup(string $username): array
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return ['ok' => false, 'error' => 'User not found.'];
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

    /** The secret awaiting confirmation (2FA not active yet). */
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
     * Enable 2FA once the code is confirmed, then generate recovery codes.
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
            return ['ok' => false, 'error' => 'User not found.'];
        }
        if ((int)($row['totp_enabled'] ?? 0) === 1) {
            return ['ok' => false, 'error' => '2FA is already active.'];
        }
        $secret = self::decodeSecret((string)($row['totp_secret'] ?? ''));
        if ($secret === '') {
            return ['ok' => false, 'error' => 'No secret yet. Start the setup first.'];
        }
        if (!self::verify($secret, $code)) {
            return ['ok' => false, 'error' => 'Wrong or expired code.'];
        }

        Connection::update('users', [
            'totp_enabled' => 1,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int)$row['id']]);

        $recovery = self::regenerateRecovery($username);
        return ['ok' => true, 'error' => null, 'recovery' => $recovery];
    }

    /** Disable 2FA. The password is still checked in the route. */
    public static function disable(string $username): array
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return ['ok' => false, 'error' => 'User not found.'];
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
     * Create new single-use recovery codes and return their plaintext values.
     *
     * Only hashes are stored; the plaintext is shown just once.
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

    /** Use one recovery code. True when it matches and was unused. */
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
     * Second-factor check at login: a TOTP code, or a recovery code.
     */
    public static function verifyLogin(string $username, string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return ['ok' => false, 'error' => 'Enter the code from your authenticator app.', 'method' => null];
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
        return ['ok' => false, 'error' => 'Wrong code, or already used.', 'method' => null];
    }

    public static function setupTtlMinutes(): int
    {
        return self::SETUP_TTL_MIN;
    }

    // ----------------------------------------------------------------- internal

    /**
     * Store the seed in encrypted form.
     *
     * When the key is unavailable the seed is stored in plain text so the user is
     * not locked out of their own account. That state is reported through
     * securityNotice() so it can surface in the UI.
     */
    private static function encodeSecret(string $secret): string
    {
        return SecretBox::encrypt($secret) ?? $secret;
    }

    /**
     * Read the seed, accepting two forms: encrypted (sbx1:) and plain.
     *
     * The plain form must still be accepted because the migration CLI uses it
     * to fill values that were not encrypted yet.
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

    /** Seed encryption warning to show on the Security page. */
    public static function securityNotice(): ?string
    {
        return SecretBox::available() ? null
            : 'The encryption key is unavailable (/etc/fpanel/app-key); TOTP seeds are stored unencrypted.';
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