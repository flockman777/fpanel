<?php
declare(strict_types=1);
namespace Fpanel\Service;

final class Validator
{

    public static function domain(string $d): ?string
    {
        $d = strtolower(trim($d));
        if ($d === '') return 'Domain wajib diisi.';
        if (strlen($d) > 253) return 'Domain terlalu panjang.';
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d)) {
            return "Format domain tidak valid: $d";
        }
        return null;
    }

    public static function username(string $u): ?string
    {
        $u = strtolower(trim($u));
        if ($u === '') return 'Username wajib diisi.';
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,31}$/', $u)) {
            return 'Username 2-32 karakter: huruf, angka, titik, underscore, strip.';
        }
        return null;
    }

    public static function email(string $e): ?string
    {
        $e = trim($e);
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? null : "Email tidak valid: $e";
    }

    public static function password(string $p): ?string
    {
        if (strlen($p) < 10) return 'Password minimal 10 karakter.';
        if (!preg_match('/[a-z]/', $p)) return 'Password harus punya huruf kecil.';
        if (!preg_match('/[A-Z]/', $p)) return 'Password harus punya huruf besar.';
        if (!preg_match('/[0-9]/', $p)) return 'Password harus punya angka.';
        return null;
    }

    public static function dbName(string $n): ?string
    {
        $n = strtolower(trim($n));
        if ($n === '') return 'Nama database wajib diisi.';
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $n)) {
            return 'Nama database 1-32 karakter: huruf kecil, angka, underscore.';
        }
        return null;
    }
}
