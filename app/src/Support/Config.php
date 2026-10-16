<?php
declare(strict_types=1);
namespace Fpanel\Support;

/** Configuration: PHP file + env overrides. No external dependencies. */
final class Config
{
    private static array $data = [];
    private static string $root;

    public static function boot(string $root): void
    {
        self::$root = $root;
        $file = $root . '/config/fpanel.php';
        $cfg  = is_file($file) ? (require $file) : [];
        $cfg  = is_array($cfg) ? $cfg : [];

        // Per-server local overrides (panel domain, email, etc). This file
        // is written by the installer and is NOT committed to Git, so the
        // defaults in the repository stay generic for everyone.
        $local = $root . '/config/local.php';
        if (is_file($local)) {
            $ov = require $local;
            if (is_array($ov)) {
                $cfg = array_replace_recursive($cfg, $ov);
            }
        }

        self::$data = $cfg;
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
