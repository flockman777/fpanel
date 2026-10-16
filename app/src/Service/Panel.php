<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Support\Config;

/** Builds the sidebar data & dashboard badges from real sources. */
final class Panel
{
    public static function data(array $me): array
    {
        $uid  = (int)$me['id'];
        $q    = Stats::quota($uid);
        $srv  = Stats::server();
        $mb   = 1024 * 1024;
        $disk = Stats::diskBytes($uid);
        $bw   = Stats::bandwidthBytes($uid);
        $dDom = Stats::usedDomains($uid);
        $dDb  = Stats::usedDatabases($uid);

        $maxDisk = ((int)($q['max_disk_mb']      ?: 0)) * $mb;
        $maxBw   = ((int)($q['max_bandwidth_mb'] ?: 0)) * $mb;
        $maxDom  = (int)($q['max_domains'] ?: 0);
        $maxDb   = (int)($q['max_databases'] ?: 0);
        $maxMail = (int)($q['max_emails'] ?: 0);

        $phpDefault = (string)Config::get('php.default', '8.3');
        $installed  = self::installedPhp();

        $gauges = [
            ['label' => 'Disk',        'used' => $disk, 'max' => $maxDisk, 'text' => Stats::bytes($disk) . ' / ' . Stats::bytes($maxDisk)],
            ['label' => 'Bandwidth',   'used' => $bw,   'max' => $maxBw,   'text' => Stats::bytes($bw) . ' / ' . Stats::bytes($maxBw)],
            ['label' => 'Domains',     'used' => $dDom,'max' => $maxDom,  'text' => $dDom . ' / ' . $maxDom],
            ['label' => 'Databases',   'used' => $dDb, 'max' => $maxDb,   'text' => $dDb . ' / ' . $maxDb],
            ['label' => 'Email',       'used' => 0,   'max' => $maxMail, 'text' => '0 / ' . $maxMail],
        ];

        $alerts = [];
        if ($maxDisk > 0 && $disk / $maxDisk >= 0.9) {
            $alerts[] = 'Disk almost full (' . Stats::bytes($disk) . ' of ' . Stats::bytes($maxDisk) . ').';
        }
        if ($maxDom > 0 && $dDom >= $maxDom) {
            $alerts[] = 'Domain quota is full. Delete a domain or upgrade the plan.';
        }
        if (!in_array($phpDefault, $installed, true)) {
            $alerts[] = 'The plan default requires PHP ' . $phpDefault . ', which is not installed. Contact the admin.';
        }

        $alerts[] = 'Email is not active on this shared IP (port 25 is blocked).';

        // "Primary domain" for a user = a domain they own. For an admin who does not
        // own a domain (the panel serves itself through a separate vhost, so admins
        // own none through the domains table), show the panel domain from base_url.
        // If base_url is not set yet (fresh install), use the request host
        // so the card is not empty or '-'.
        $primaryUser = Stats::primaryDomain($uid);
        $panelHost   = (string)(parse_url((string)Config::get('app.base_url', ''), PHP_URL_HOST) ?: '');
        if ($panelHost === '' && PHP_SAPI !== 'cli') {
            $panelHost = (string)preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        }
        $primaryIsUser = $primaryUser !== null;
        $primary = $primaryIsUser ? $primaryUser : $panelHost;

        return [
            'gauges'        => $gauges,
            'server'        => [
                'hostname'    => $srv['hostname'],
                'os'          => $srv['os'],
                'php'         => $srv['php'],
                'public_ip'   => Stats::publicIp(),
                'nginx_label' => preg_replace('#^nginx version: #', '', $srv['nginx']) ?: '-',
            ],
            'php_label'     => $phpDefault . ' (default)',
            'php_installed' => $installed,
            'primary_domain' => $primary,
            'primary_label'  => $primaryIsUser ? 'Domain utama' : 'Domain panel',
            'uptime'        => self::uptime(),
            'version'       => FPANEL_VERSION,
            'alerts'        => $alerts,
            'badges'        => ['/domains' => $dDom, '/databases' => $dDb],
            'quota'         => $q,
        ];
    }

    /** PHP versions actually installed on the server. */
    public static function installedPhp(): array
    {
        // Used to fill the "PHP version" choices in the domain form, so
        // it must match the detection in Vhost: only versions whose FPM socket
        // really exists. Looking only at /etc/php/<v>/ would also offer versions
        // whose FPM is down, which then silently fall back to the default.
        $out = \Fpanel\Service\Vhost::installedPhpVersions();
        return $out === [] ? [PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION] : $out;
    }

    public static function uptime(): string
    {
        $u = @file_get_contents('/proc/uptime');
        if ($u === false) {
            return '-';
        }
        $s = (int)(float)explode(' ', $u)[0];
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        return ($d > 0 ? $d . "h " : '') . $h . 'j ' . $m . 'm';
    }
}
