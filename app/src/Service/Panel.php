<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Support\Config;

/** Menyusun data sidebar & badge beranda dari sumber nyata. */
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
            $alerts[] = 'Disk hampir penuh (' . Stats::bytes($disk) . ' dari ' . Stats::bytes($maxDisk) . ').';
        }
        if ($maxDom > 0 && $dDom >= $maxDom) {
            $alerts[] = 'Kuota domain penuh. Hapus domain atau naikkan paket.';
        }
        if (!in_array($phpDefault, $installed, true)) {
            $alerts[] = 'Default paket demands PHP ' . $phpDefault . ' yang belum terinstall. Hubungi admin.';
        }

        $alerts[] = 'Email belum aktif di shared IP (port 25 diblokir).';

        // "Domain utama" untuk user = domain miliknya. Untuk admin yang tidak
        // punya domain (panel ini melayani fpanel.my.id lewat vhost terpisah,
        // bukan lewat tabel domains), tampilkan domain panel dari base_url
        // supaya kartu tidak kosong dan '-'.
        $primaryUser = Stats::primaryDomain($uid);
        $panelHost   = (string)(parse_url((string)Config::get('app.base_url', ''), PHP_URL_HOST) ?: '');
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

    /** Versi PHP yang benar-benar terinstall di server. */
    public static function installedPhp(): array
    {
        // Dipakai untuk mengisi pilihan "Versi PHP" di form domain, jadi
        // harus sama dengan deteksi di Vhost: hanya versi yang socket FPM-nya
        // benar-benar ada. Kalau hanya melihat direktori /etc/php/<v>/, versi
        // yang FPM-nya mati tetap ditawarkan, lalu diam-diam memakai default.
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
