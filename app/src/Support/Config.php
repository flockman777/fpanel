<?php
declare(strict_types=1);
namespace Fpanel\Support;

/** Konfigurasi: file PHP + override env. Tidak ada dependency luar. */
final class Config
{
    private static array $data = [];
    private static string $root;

    public static function boot(string $root): void
    {
        self::$root = $root;
        $file = $root . '/config/fpanel.php';
        $cfg  = is_file($file) ? (require $file) : [];
        self::$data = is_array($cfg) ? $cfg : [];
    }

    public static function root(string $append = ''): string
    {
        return rtrim(self::$root . $append, '/');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $envKey = 'FPANEL_' . strtoupper(str_replace('.', '_', $key));
        $env = getenv($envKey);
        if ($env !== false && $env !== '') {
            return $env;
        }
        $val = self::$data;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($val) || !array_key_exists($seg, $val)) {
                return $default;
            }
            $val = $val[$seg];
        }
        return $val;
    }
}
