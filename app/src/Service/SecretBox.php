<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Enkripsi simetris untuk rahasia yang harus bisa dibaca lagi (password
 * database, seed TOTP), memakai sodium secretbox.
 *
 * Berbeda dengan password yang hanya butuh hash: TOTP seed tidak bisa
 * di-hash, karena tiap login harus membandingkan kode dengan seed itu. Jadi
 * seed harus disimpan terenkripsi, bukan polos di database.
 */
final class SecretBox
{
    /** Prefix menandai nilai yang sudah terenkripsi. */
    public const PREFIX = 'sbx1:';

    private const KEYFILE = '/etc/fpanel/app-key';

    private static ?string $cache = null;
    private static bool $cacheLoaded = false;

    /**
     * Kunci 32 byte, atau null kalau file kunci tidak ada/rusak.
     *
     * Sengaja tidak dibuat otomatis: kunci yang hilang berarti semua rahasia
     * tidak bisa dibaca. Itu harus terlihat jelas, bukan diam-diam jadi fallback.
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

    /** True kalau enkripsi tersedia di mesin ini. */
    public static function available(): bool
    {
        return self::key() !== null;
    }

    /**
     * Enkripsi teks. Nonce diacak per operasi dan disimpan bersama ciphertext;
     * memakai nonce yang sama untuk pesan yang sama akan membocorkan isi.
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

    /** Dekripsi. Null kalau format bukan milik kita, kunci hilang, atau MAC gagal. */
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
