<?php
declare(strict_types=1);
namespace Fpanel\Service;

final class Validator
{

    public static function domain(string $d): ?string
    {
        $d = strtolower(trim($d));
        if ($d === '') return 'Domain is required.';
        if (strlen($d) > 253) return 'Domain is too long.';
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d)) {
            return "Invalid domain format: $d";
        }
        return null;
    }

    public static function username(string $u): ?string
    {
        $u = strtolower(trim($u));
        if ($u === '') return 'Username is required.';
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,31}$/', $u)) {
            return 'Username must be 2-32 characters: letters, digits, dot, underscore, hyphen.';
        }
        return null;
    }

    public static function email(string $e): ?string
    {
        $e = trim($e);
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? null : "Invalid email: $e";
    }

    public static function password(string $p): ?string
    {
        if (strlen($p) < 10) return 'Password must be at least 10 characters.';
        if (!preg_match('/[a-z]/', $p)) return 'Password must contain a lowercase letter.';
        if (!preg_match('/[A-Z]/', $p)) return 'Password must contain an uppercase letter.';
        if (!preg_match('/[0-9]/', $p)) return 'Password must contain a digit.';
        return null;
    }

    public static function dbName(string $n): ?string
    {
        $n = strtolower(trim($n));
        if ($n === '') return 'Database name is required.';
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $n)) {
            return 'Database name must be 1-32 characters: lowercase letters, digits, underscore.';
        }
        return null;
    }
}
