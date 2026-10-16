<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Symmetric encryption for secrets that must be readable again (passwords
 * database, TOTP seed), using sodium secretbox.
 *
 * Unlike passwords, which only need a hash: the TOTP seed cannot
 * be hashed, because every login must compare the code with that seed. So
 * the seed must be stored encrypted, never in plain text in the database.
 */
final class SecretBox
{
    /** Prefix marking a value that is already encrypted. */
    public const PREFIX = 'sbx1:';

    private const KEYFILE = '/etc/fpanel/app-key';

    private static ?string $cache = null;
    private static bool $cacheLoaded = false;

    /**
     * 32-byte key, or null when the key file is missing or corrupt.
     *
     * Deliberately not created automatically: a missing key means every secret
     * becomes unreadable. That has to be obvious, not silently turned into a fallback.
     */
    public static function key(): ?string
    {
        if (self::$cacheLoaded) {
            return self::$cache;
        }
        self::$cacheLoaded = true;
        self::$cache = null;

        if (!is_readable(self::KEYFILE)) {
            return null;
        }
        $raw = trim((string)@file_get_contents(self::KEYFILE));
        if ($raw === '') {
            return null;
        }
        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }
        return self::$cache = $key;
    }

    /** True when encryption is available on this machine. */
    public static function available(): bool
    {
        return self::key() !== null;
    }

    /**
     * Encrypts text. The nonce is randomized per operation and stored with the ciphertext;
     * reusing the same nonce for the same message leaks its contents.
     */
    public static function encrypt(string $plain): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box   = sodium_crypto_secretbox($plain, $nonce, $key);
        return self::PREFIX . base64_encode($nonce . $box);
    }

    /** Decrypt. Null when the format is not ours, the key is missing, or the MAC fails. */
    public static function decrypt(string $blob): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $raw = base64_decode(substr($blob, strlen(self::PREFIX)), true);
        $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false || strlen($raw) < $min) {
            return null;
        }
        $nonce   = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain   = sodium_crypto_secretbox_open($payload, $nonce, $key);
        return $plain === false ? null : $plain;
    }

    public static function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::PREFIX);
    }
}
