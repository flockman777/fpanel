<?php
declare(strict_types=1);
namespace Fpanel\Http;

/** Token per sesi, dibandingkan dengan hash_equals. */
final class Csrf
{
    public const COOKIE = 'fp_csrf';

    public static function issue(string $token): void
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, $token, [
                'expires'  => time() + 86400 * 14,
                'path'     => '/',
                'httponly' => false,   // form membaca via cookie
                'samesite' => 'Lax',
                'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            ]);
        }
    }

    public static function check(?string $cookie, ?string $field): bool
    {
        if ($cookie === null || $cookie === '' || $field === null || $field === '') {
            return false;
        }
        return hash_equals($cookie, $field);
    }
}
