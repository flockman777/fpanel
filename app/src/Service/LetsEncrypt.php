<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Pembuatan sertifikat Let's Encrypt per-domain lewat certbot webroot.
 *
 * certbot dijalankan lewat sudo karena menulis ke /etc/letsencrypt. Aturan
 * sudoers dibuat per-argv0 supaya www-data tidak mendapat shell root: yang
 * boleh hanya binary certbot itu sendiri. Semua argumen tetap coming from
 * server (nama domain sudah divalidasi lebih dulu), bukan dari user.
 *
 * rate limit Let's Encrypt: 5 sertifikat duplikat per minggu. Renewal harian
 * otomatis karena itu harus dihindari; renew di sini hanya jalan kalau
 * sertifikat benar-benar mendekati kedaluwarsa atau memang diminta user.
 */
final class LetsEncrypt
{
    public const LETSENCRYPT_DIR = '/etc/letsencrypt';
    public const LIVE_DIR        = '/etc/letsencrypt/live';
    public const WEBROOT         = '/var/www/acme/challenges';

    /**
     * Email pendaftaran akun Let's Encrypt.
     *
     * Urutan: config `letsencrypt.email` (bisa lewat env
     * FPANEL_LETSENCRYPT_EMAIL) → host `app.base_url` → domain yang sedang
     * diminta sertifikatnya. Jadi instalasi baru tetap jalan tanpa diatur.
     */
    public static function registerEmail(string $forDomain = ''): string
    {
        $email = trim((string)\Fpanel\Support\Config::get('letsencrypt.email', ''));
        if ($email !== '') {
            return $email;
        }
        $host = (string)(parse_url((string)\Fpanel\Support\Config::get('app.base_url', ''), PHP_URL_HOST) ?: '');
        if ($host === '') {
            $host = $forDomain;
        }
        return $host !== '' ? 'admin@' . $host : 'admin@localhost';
    }

    /** Hari sebelum kedaluwarsa saat certbot diizinkan renew. */
    private const RENEW_WINDOW_DAYS = 30;

    public static function certName(string $domain): string
    {
        return $domain;
    }

    public static function fullchain(string $domain): string
    {
        return self::LIVE_DIR . '/' . self::certName($domain) . '/fullchain.pem';
    }

    public static function privkey(string $domain): string
    {
        return self::LIVE_DIR . '/' . self::certName($domain) . '/privkey.pem';
    }

    /**
     * Apakah sertifikat untuk domain ini sudah ada?
     *
     * /etc/letsencrypt/live dimiliki root dengan mode 0700, jadi panel yang
     * jalan sebagai www-data tidak bisa is_file() ke sana. Karena itu status
     * dibaca lewat CLI certbot, bukan lewat filesystem.
     */
    public static function hasCert(string $domain): bool
    {
        return self::certInfo($domain) !== null;
    }

    /** Nama host + masa berlaku dari certbot. Null kalau belum ada cert. */
    public static function certInfo(string $domain): ?array
    {
        $name = self::certName($domain);
        if (!isset(self::$infoCache)) {
            self::$infoCache = self::readAll();
        }
        return self::$infoCache[$name] ?? null;
    }

    private static ?array $infoCache = null;

    /** @return array<string,array{hosts:array,days:?int}> */
    private static function readAll(): array
    {
        $out = [];
        [$rc, $txt] = self::run(['certificates']);
        if ($rc !== 0) {
            return $out;
        }
        $cur = null;
        foreach (explode("\n", $txt) as $line) {
            $t = trim($line);
            if (str_starts_with($t, 'Certificate Name:')) {
                $cur = trim(substr($t, 18));
                $out[$cur] = ['hosts' => [], 'days' => null];
                continue;
            }
            if ($cur === null) {
                continue;
            }
            if (str_starts_with($t, 'Domains:')) {
                $out[$cur]['hosts'] = array_values(array_filter(array_map('trim', explode(' ', trim(substr($t, 9))))));
            }
            if (str_starts_with($t, 'Expiry Date:')) {
                // certbot sudah menghitung sendiri: "Expiry Date: 2026-12-27
                // 16:11:49+00:00 (VALID: 89 days)". Pakai angka itu, jangan
                // parse ulang tanggalnya (formatnya berubah antar versi).
                if (preg_match('/\(VALID:\s*(\d+)\s*days?\)/i', $t, $m)) {
                    $out[$cur]['days'] = (int)$m[1];
                } else {
                    $ts = strtotime(trim(substr($t, 12)));
                    if ($ts !== false) {
                        $out[$cur]['days'] = (int)floor(($ts - time()) / 86400);
                    }
                }
            }
        }
        return $out;
    }

    /** Hari tersisa sebelum kedaluwarsa; null kalau tidak ada cert. */
    public static function daysLeft(string $domain): ?int
    {
        $i = self::certInfo($domain);
        return $i === null ? null : $i['days'];
    }

    /**
     * Nama host yang akan dimasukkan ke sertifikat: domain + www bila ada DNS.
     * Host/www yang tidak resolve sengaja dilewati supaya issuance tidak
     * gagal total gara-gara subdomain yang memang tidak diarahkan.
     */
    public static function hostsFor(string $domain): array
    {
        // www hanya ikut kalau punya DNS sendiri. Kalau tidak, issuance gagal
        // total karena satu hostname yang tidak bisa di verifikasi.
        $hosts = [$domain];
        if (self::resolves('www.' . $domain)) {
            $hosts[] = 'www.' . $domain;
        }
        return $hosts;
    }

    private static function resolves(string $host): bool
    {
        $ip = @gethostbyname($host);
        return $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Terbitkan (atau renew) sertifikat.
     *
     * @return array{ok:bool,error:?string,msg:?string,days:?int}
     */
    public static function issue(string $domain, bool $force = false): array
    {
        if (self::hasCert($domain)) {
            $days = self::daysLeft($domain);
            if (!$force && $days !== null && $days > self::RENEW_WINDOW_DAYS) {
                return [
                    'ok'   => true,
                    'error'=> null,
                    'msg'  => 'Sertifikat masih valid ' . $days . ' hari lagi.',
                    'days' => $days,
                ];
            }
        }

        $hosts = self::hostsFor($domain);
        $args  = [
            'certonly',
            '--webroot', '--webroot-path', self::WEBROOT,
            '--cert-name', self::certName($domain),
            '--non-interactive', '--agree-tos',
            '-m', self::registerEmail($domain),
            '--keep-until-expiring',
        ];
        foreach ($hosts as $h) {
            $args[] = '-d';
            $args[] = $h;
        }
        if ($force) {
            $args[] = '--force-renewal';
        }

        [$rc, $out] = self::run($args);

        self::$infoCache = null;
        if ($rc === 0 && self::hasCert($domain)) {
            $days = self::daysLeft($domain);
            return [
                'ok'   => true,
                'error'=> null,
                'msg'  => 'Sertifikat terbit untuk ' . implode(', ', $hosts)
                    . ($days !== null ? ' (valid ' . $days . ' hari)' : ''),
                'days' => $days,
            ];
        }

        return [
            'ok'    => false,
            'error' => self::explain($out),
            'msg'   => null,
            'days'  => null,
        ];
    }

    public static function remove(string $domain): array
    {
        // Jangan pakai is_dir() di /etc/letsencrypt/live: direktori itu mode
        // 0700 milik root, jadi www-data selalu melihatnya tidak ada dan cert
        // tidak pernah terhapus. Status dibaca lewat certbot listing.
        if (self::certInfo($domain) === null) {
            return ['ok' => true, 'error' => null];
        }
        [$rc, $out] = self::run([
            'delete', '--cert-name', self::certName($domain), '--non-interactive',
        ]);
        return [
            'ok'    => $rc === 0,
            'error' => $rc === 0 ? null : 'certbot delete gagal: ' . self::tail($out),
        ];
    }

    /**
     * Cabut + hapus sertifikat domain.
     * true berarti tidak ada sisa di /etc/letsencrypt untuk domain ini.
     */
    public static function revoke(string $domain): bool
    {
        $r = self::remove($domain);
        self::$infoCache = null; // status berubah, jangan pakai cache lama
        return ($r['ok'] ?? false) === true && self::certInfo($domain) === null;
    }

    public static function issued(): array
    {
        $out = [];
        foreach (self::readAll() as $name => $i) {
            $out[] = ['name' => $name, 'days' => $i['days'], 'hosts' => $i['hosts']];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }


    /**
     * Jalankan certbot lewat sudo dan kembalikan [exitCode, output].
     */
    private static function run(array $args): array
    {
        $cmd = ['sudo', '-n', '/usr/bin/certbot'];
        foreach ($args as $a) {
            // Nama host sudah divalidasi sebagai label DNS sebelum sampai
            // ke sini, tapi tetap quoted per-argumen supaya tidak ada
            // opsi yang bisa menabrak nama file lain.
            $cmd[] = $a;
        }
        $line = implode(' ', array_map(
            static fn (string $a): string => escapeshellarg($a),
            $cmd
        ));
        $out   = [];
        $rc    = 0;
        exec($line . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    /** Terjemahkan output certbot jadi pesan yang bisa dibaca user. */
    private static function explain(string $out): string
    {
        if (str_contains($out, 'rate limit') || str_contains($out, 'too many certificates')) {
            return "Batas rate Let's Encrypt tercapai untuk domain ini. Coba lagi dalam beberapa jam.";
        }
        if (str_contains($out, 'NXDOMAIN') || str_contains($out, 'Could not resolve')) {
            return 'DNS domain ini belum resolve ke server. Arahkan A record dulu, lalu coba lagi.';
        }
        if (str_contains($out, 'Timeout') || str_contains($out, 'timed out')) {
            return "Server tidak menjawab Let's Encrypt. Pastikan port 80 terbuka ke internet.";
        }
        if (str_contains($out, 'unauthorized') || str_contains($out, 'Invalid response')) {
            return "Domain ditolak challenge Let's Encrypt. Pastikan .well-known/acme-challenge tidak diblokir.";
        }
        if (str_contains($out, 'sudo')) {
            return 'Panel tidak punya hak menjalankan certbot. Periksa /etc/sudoers.d/fpanel.';
        }
        return 'Gagal terbit: ' . self::tail($out);
    }

    private static function tail(string $out, int $lines = 2): string
    {
        $l = array_values(array_filter(array_map('trim', explode("\n", $out))));
        return $l === [] ? 'tidak ada output' : implode(' / ', array_slice($l, -$lines));
    }
}
