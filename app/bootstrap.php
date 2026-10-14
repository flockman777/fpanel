<?php
declare(strict_types=1);

define('FPANEL_ROOT', dirname(__DIR__));
define('FPANEL_VERSION', '0.1.0-dev');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Fpanel\\')) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, 7));
    $file = FPANEL_ROOT . '/app/src/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\Fpanel\Support\Config::boot(FPANEL_ROOT);

date_default_timezone_set((string)\Fpanel\Support\Config::get('app.timezone', 'UTC'));
mb_internal_encoding('UTF-8');

if (\Fpanel\Support\Config::get('app.debug', false)) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
}

if (!function_exists('e')) {
    /** Escape untuk template. */
    function e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
