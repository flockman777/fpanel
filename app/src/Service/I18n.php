<?php
declare(strict_types=1);
namespace Fpanel\Service;

/** Server-side i18n — no JS dependency. */
final class I18n
{
    private static ?array $dict = null;

    public static function dict(): array
    {
        if (self::$dict === null) {
            $f = FPANEL_ROOT . '/app/i18n/en.json';
            $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
            self::$dict = is_array($d) ? $d : [];
        }
        return self::$dict;
    }

    public static function t(string $key, string $fallback = ''): string
    {
        return self::dict()[$key] ?? ($fallback !== '' ? $fallback : $key);
    }
}
