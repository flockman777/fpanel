<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Homepage feature catalog. `href` = REAL route.
 * `requires` = package resource key; null = always available.
 */
final class Catalog
{
    public const CATEGORIES = [
        ['key' => 'cat.files',     'icon' => 'folder-open',  'tiles' => [
            ['tile' => 'tile.fileManager', 'icon' => 'folder-cog',      'href' => '/files',       'requires' => null],
            ['tile' => 'tile.backup',      'icon' => 'archive',         'href' => '/backups',     'requires' => null],
            ['tile' => 'tile.ftpAccounts',         'icon' => 'network',         'href' => '/security',    'requires' => null],
            ['tile' => 'tile.diskUsage',   'icon' => 'hard-drive',      'href' => '/files',       'requires' => null],
        ]],
        ['key' => 'cat.databases', 'icon' => 'database',    'tiles' => [
            ['tile' => 'tile.mariadb', 'icon' => 'database',   'href' => '/databases', 'requires' => 'database'],
            ['tile' => 'tile.dbUsers', 'icon' => 'users',      'href' => '/databases', 'requires' => 'database'],
            ['tile' => 'tile.phpmyadmin', 'icon' => 'table',   'href' => '/databases', 'requires' => 'database'],
        ]],
        ['key' => 'cat.domains',  'icon' => 'globe',       'tiles' => [
            ['tile' => 'tile.domains',    'icon' => 'globe',     'href' => '/domains', 'requires' => 'domain'],
            ['tile' => 'tile.subdomains', 'icon' => 'git-branch','href' => '/domains', 'requires' => 'domain'],
            ['tile' => 'tile.redirects',  'icon' => 'shuffle',   'href' => '/domains', 'requires' => 'domain'],
            ['tile' => 'tile.dnsZone',    'icon' => 'list',      'href' => '/domains', 'requires' => 'domain'],
        ]],
        ['key' => 'cat.email',    'icon' => 'mail',        'tiles' => [
            ['tile' => 'tile.emailAccounts',   'icon' => 'mail',       'href' => '/email', 'requires' => 'email'],
            ['tile' => 'tile.forwarders',       'icon' => 'forward',    'href' => '/email', 'requires' => 'email'],
            ['tile' => 'tile.autoresponders',  'icon' => 'reply',      'href' => '/email', 'requires' => 'email'],
            ['tile' => 'tile.spamFilters',      'icon' => 'shield',     'href' => '/email', 'requires' => 'email'],
        ]],
        ['key' => 'cat.security', 'icon' => 'shield',      'tiles' => [
            ['tile' => 'tile.sslTls',     'icon' => 'lock',       'href' => '/security', 'requires' => 'domain'],
            ['tile' => 'tile.ipBlocker', 'icon' => 'ban',        'href' => '/security', 'requires' => null],
            ['tile' => 'tile.hotlink', 'icon' => 'link',       'href' => '/security', 'requires' => null],
        ]],
        ['key' => 'cat.software', 'icon' => 'package',     'tiles' => [
            ['tile' => 'tile.phpSelector', 'icon' => 'file-code',  'href' => '/software', 'requires' => 'php'],
            ['tile' => 'tile.cronJobs',    'icon' => 'timer',      'href' => '/software', 'requires' => 'cron'],
            ['tile' => 'tile.terminal',    'icon' => 'terminal',   'href' => '/software', 'requires' => null],
            ['tile' => 'tile.errorLogs',   'icon' => 'scroll-text','href' => '/software', 'requires' => null],
        ]],
    ];

    public static function forUser(array $quota, string $toggles): array
    {
        $t   = json_decode($toggles !== '' ? $toggles : '{}', true) ?: [];
        $out = [];
        foreach (self::CATEGORIES as $c) {
            $tiles = [];
            foreach ($c['tiles'] as $tile) {
                $req = $tile['requires'];
                if ($req !== null && array_key_exists($req, $t) && $t[$req] === false) {
                    continue; // resource disabled in the package
                }
                $tiles[] = $tile;
            }
            if ($tiles !== []) {
                $out[] = $c + ['tiles' => $tiles];
            }
        }
        return $out;
    }
}
