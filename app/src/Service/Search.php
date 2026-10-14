<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Pencarian global panel: mencocokkan fitur (katalog), domain, dan
 * database milik user yang sedang login. Hasil dikirim ke topbar
 * sebagai dropdown. Tidak ada data user lain yang bisa bocor.
 */
final class Search
{
    /** @return array<int, array{href:string,icon:string,title:string,sub:string}> */
    public static function query(array $user, array $quota, string $q, int $limit = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $uid   = (int)($user['id'] ?? 0);
        $res   = (string)($quota['resources'] ?? '{}');
        $tiles = Catalog::forUser($quota, $res !== '' ? $res : '{}');
        $out   = [];

        foreach ($tiles as $cat) {
            foreach ($cat['tiles'] as $t) {
                $title = I18n::t($t['tile']);
                if (self::hit($title, $q)) {
                    $out[] = [
                        'href'  => $t['href'],
                        'icon'  => $t['icon'],
                        'title' => $title,
                        'sub'   => I18n::t($cat['key']),
                    ];
                }
            }
        }

        $domains = Connection::all(
            'SELECT name, type FROM domains WHERE user_id = :u
             ORDER BY name LIMIT 40',
            ['u' => $uid]
        );
        foreach ($domains as $d) {
            if (self::hit((string)$d['name'], $q)) {
                $out[] = [
                    'href'  => '/domains',
                    'icon'  => 'globe',
                    'title' => (string)$d['name'],
                    'sub'   => 'Domain · ' . $d['type'],
                ];
            }
        }

        $dbs = Connection::all(
            'SELECT name FROM databases WHERE user_id = :u ORDER BY name LIMIT 40',
            ['u' => $uid]
        );
        foreach ($dbs as $d) {
            if (self::hit((string)$d['name'], $q)) {
                $out[] = [
                    'href'  => '/databases',
                    'icon'  => 'database',
                    'title' => (string)$d['name'],
                    'sub'   => 'Database',
                ];
            }
        }

        return array_slice($out, 0, $limit);
    }

    private static function hit(string $haystack, string $needle): bool
    {
        return mb_stripos($haystack, $needle) !== false;
    }
}
