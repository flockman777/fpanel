<?php
declare(strict_types=1);
namespace Fpanel\View;

final class View
{
    public static function render(string $tpl, array $vars = []): string
    {
        $file = FPANEL_ROOT . '/app/views/' . $tpl . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Template tidak ada: $tpl");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
