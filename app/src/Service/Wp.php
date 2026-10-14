<?php
declare(strict_types=1);

namespace Fpanel\Service;

use Fpanel\Database\Connection;
use Fpanel\Support\Audit;

/**
 * Installer WordPress satu-klik.
 *
 * Alur: unduh core dari wordpress.org, ekstrak ke docroot domain, buat
 * database + user lewat MariaDb, tulis wp-config.php (salt diacak sendiri di
 * server), lalu `wp core install` dengan WP-CLI.
 *
 * Kalau salah satu langkah gagal, semua yang sudah dibuat dibuang supaya tidak
 * ada instalasi setengah jadi atau database yatim.
 *
 * Yang perlu diketahui arsitektur: semua domain dilayani pool PHP-FPM milik
 * www-data, jadi file WordPress harus dibaca www-data. Karena itu WP-CLI
 * dijalankan sebagai user yang sama, tanpa sudo.
 */
final class Wp
{
    /** Cache versi di tabel settings; 6 jam cukup untuk satu hari. */
    private const CACHE_KEY    = 'wp_versions';
    private const CACHE_TTL    = 21600;
    private const API_VERSIONS = 'https://api.wordpress.org/core/version-check/1.7/';
    private const DOWNLOAD     = 'https://downloads.wordpress.org/release/wordpress-%s.zip';

    /** Batas unduhan: core WP zip sekitar 25-30 MB. */
    private const MAX_ZIP_BYTES = 90 * 1024 * 1024;

    // Unduhan core WP sekitar 37 MB. Kecepatan dari server ini bisa turun
    // ke ~150 KB/s (terukur 245 detik untuk 37 MB), jadi batas 5 menit terlalu
    // kecil. Installer jalan di latar belakang tanpa menunggu user, jadi
    // timeout longgar tidak mengganggu apa pun.
    private const TIMEOUT_SEC   = 1800;

    /** Status instalasi (JSON per job) supaya UI bisa menampilkan progres. */
    private const JOB_DIR       = '/var/lib/fpanel/wp';

    /** Batas kecepatan unduhan: di bawah 12 KB/s selama 45 detik = macet. */
    private const LOW_SPEED_LIMIT = 12288;
    private const LOW_SPEED_TIME  = 45;

    /** Berapa kali mencoba unduhan sebelum menyerah. */
    private const DL_ATTEMPTS = 3;

    /** Cache core zip per versi, supaya instalasi kedua tidak unduh ulang. */
    private const CACHE_DIR = '/var/cache/fpanel/wp';

    // ------------------------------------------------------------------ public

    /**
     * Daftar versi WP yang bisa dipilih, terbaru dulu.
     *
     * @return array<int, string>
     */
    public static function versions(): array
    {
        $cached = Connection::one('SELECT value, updated_at FROM settings WHERE key = :k', ['k' => self::CACHE_KEY]);
        if (is_array($cached) && trim((string)$cached['value']) !== '') {
            $age = time() - (int)strtotime((string)$cached['updated_at']);
            if ($age < self::CACHE_TTL) {
                $decoded = json_decode((string)$cached['value'], true);
                if (is_array($decoded) && $decoded !== []) {
                    return array_values(array_map('strval', $decoded));
                }
            }
        }

        $body   = self::http(self::API_VERSIONS);
        $out    = [];
        if ($body !== null) {
            $json = json_decode($body, true);
            if (is_array($json) && isset($json['offers']) && is_array($json['offers'])) {
                foreach ($json['offers'] as $offer) {
                    $v = is_array($offer) ? trim((string)($offer['version'] ?? '')) : '';
                    if ($v !== '' && !in_array($v, $out, true)) {
                        $out[] = $v;
                    }
                }
            }
        }
        // Tanpa akses ke API, pakai daftar bawaan supaya fitur tetap jalan.
        if ($out === []) {
            $out = ['7.1.2', '7.1.1', '7.1.0', '7.0.6', '6.9.9'];
        }
        $out = array_slice($out, 0, 12);

        Connection::run(
            'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = :v2, updated_at = :t2',
            [
                'k' => self::CACHE_KEY, 'v' => (string)json_encode($out),
                't' => gmdate('Y-m-d H:i:s'), 'v2' => (string)json_encode($out),
                't2' => gmdate('Y-m-d H:i:s'),
            ]
        );
        return $out;
    }

    /**
     * Daftar instalasi WP milik user, lengkap dengan nama domainnya.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listing(string $username): array
    {
        return Connection::all(
            'SELECT w.*, d.name AS domain_name, d.type AS domain_type
               FROM wp_sites w JOIN domains d ON d.id = w.domain_id
               JOIN users u ON u.id = w.user_id
              WHERE u.username = :u
              ORDER BY w.id DESC',
            ['u' => $username]
        );
    }

    /**
     * Mulai instalasi WordPress di latar belakang.
     *
     * Instalasi harus jalan di luar request HTTP: unduh core saja bisa 25-60
     * detik, sedangkan max_execution_time pool FPM 30 detik. Request ini hanya
     * memvalidasi lalu mencetak job; CLI (tanpa batas waktu PHP) yang
     * mengerjakan unduh-ekstrak-install.
     *
     * @param array{version?: string, title?: string, admin_user?: string,
     *              admin_password?: string, admin_email?: string,
     *              is_staging?: bool} $opts
     * @return array{ok: bool, error: string|null, message: string|null, job?: int}
     */
    public static function startInstall(string $username, string $domainName, array $opts = []): array
    {
        $version   = trim((string)($opts['version'] ?? ''));
        $title     = trim((string)($opts['title'] ?? ''));
        $adminUser = trim((string)($opts['admin_user'] ?? ''));
        $adminPass = (string)($opts['admin_password'] ?? '');
        $adminMail = trim((string)($opts['admin_email'] ?? ''));
        $isStaging = !empty($opts['is_staging']);

        $domain = self::domain($username, $domainName);
        if ($domain === null) {
            return self::err('Domain tidak ditemukan.');
        }
        if ((string)$domain['type'] !== 'php') {
            return self::err("Domain {$domainName} bertipe " . (string)$domain['type']
                . '. WordPress butuh domain tipe PHP.');
        }

        $docroot = (string)$domain['docroot'];
        if (!self::safeDocroot($docroot, $username)) {
            return self::err('Docroot domain tidak valid.');
        }

        // Kuota paket.
        $q = self::quota($username);
        $used = (int)Connection::value(
            'SELECT COUNT(*) FROM wp_sites w JOIN users u ON u.id = w.user_id WHERE u.username = :u',
            ['u' => $username]
        );
        if ($used >= $q['max_wp']) {
            return self::err("Kuota WordPress penuh ({$used}/{$q['max_wp']}).");
        }

        if ($adminUser === '' || preg_match('/^[A-Za-z0-9 ._@\-]{3,60}$/', $adminUser) !== 1) {
            return self::err('Nama admin WordPress tidak valid (3-60 karakter).');
        }
        if (strlen($adminPass) < 8 || strlen($adminPass) > 72) {
            return self::err('Password admin WordPress minimal 8 karakter.');
        }
        if ($adminMail === '' || filter_var($adminMail, FILTER_VALIDATE_EMAIL) === false) {
            return self::err('Email admin WordPress tidak valid.');
        }
        if ($title === '') {
            $title = (string)$domainName;
        }

        if (is_file($docroot . '/wp-load.php')) {
            return self::err('Domain ini sudah punya WordPress. Hapus lewat File Manager bila ingin memasang ulang.');
        }

        // Version: pakai yang diminta kalau ada di daftar, else yang terbaru.
        $known = self::versions();
        if ($version === '' || !in_array($version, $known, true)) {
            $version = $known[0] ?? 'latest';
        }

        $job = self::newJob($username, $domainName);
        self::writeJob($job, [
            'state'   => 'running',
            'step'    => 'menunggu',
            'version' => $version,
            'started' => gmdate('Y-m-d H:i:s'),
        ]);

        self::spawn([
            'username'       => $username,
            'domain'         => $domainName,
            'version'        => $version,
            'title'          => $title,
            'admin_user'     => $adminUser,
            'admin_password' => $adminPass,
            'admin_email'    => $adminMail,
            'is_staging'     => $isStaging,
            'job'            => $job,
        ]);

        Audit::log('wp.install.start', (int)$domain['user_id'], $username, 'domain', $domainName,
            '-', 'ok', ['version' => $version, 'job' => $job]);

        return [
            'ok'      => true,
            'error'   => null,
            'message' => "Instalasi WordPress ke $domainName dimulai di latar belakang. Halaman ini diperbarui otomatis.",
            'job'     => $job,
        ];
    }

    /**
     * Kerja berat instalasi. Dipanggil dari CLI, bukan dari request HTTP.
     *
     * @param array<string, mixed> $args
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function runInstall(array $args): array
    {
        $username   = (string)($args['username'] ?? '');
        $domainName = (string)($args['domain'] ?? '');
        $job        = (int)($args['job'] ?? 0);
        $version    = (string)($args['version'] ?? '');

        $domain = self::domain($username, $domainName);
        if ($domain === null) {
            self::writeJob($job, ['state' => 'error', 'step' => 'domain tidak ditemukan']);
            return self::err('Domain tidak ditemukan.');
        }
        $docroot = (string)$domain['docroot'];
        if (!self::safeDocroot($docroot, $username)) {
            self::writeJob($job, ['state' => 'error', 'step' => 'docroot tidak valid']);
            return self::err('Docroot tidak valid.');
        }

        self::writeJob($job, ['step' => 'membuat database']);
        $suffix  = bin2hex(random_bytes(5));
        $dbName  = 'wp_' . $suffix;
        $dbUser  = 'wpu_' . $suffix;
        $created = MariaDb::create($username, $dbName, $dbUser);
        if (empty($created['ok'])) {
            $msg = 'Gagal membuat database: ' . (string)($created['error'] ?? 'tanpa pesan');
            self::writeJob($job, ['state' => 'error', 'step' => $msg, 'finished' => gmdate('Y-m-d H:i:s')]);
            return self::err($msg);
        }
        $dbId = (int)($created['db_id'] ?? 0);
        // Password HARUS diambil dari hasil create(). MariaDb yang membuat dan
        // menyimpan passwordnya sendiri; password buatan kita sendiri tidak
        // pernah dipakai, sehingga wp-config.php tidak bisa konek.
        $dbPass = (string)($created['password'] ?? '');
        // MariaDb::create() mengembalikan NAMA PENDEK, sedangkan helper root
        // membuat user dengan prefiks user panel (mis. admin_wpu_abc123).
        // wp-config.php harus memakai nama fisik, kalau tidak MariaDB membalas
        // "Access denied for user 'wpu_abc123'@'localhost'".
        $dbUserReal = MariaDb::physUser($username, $dbUser);
        // Sama seperti user, nama database di MariaDB juga diberi prefiks user
        // panel, jadi wp-config.php harus memakai nama fisik.
        $dbNameReal = MariaDb::physDb($username, $dbName);
        if ($dbPass === '') {
            $msg = 'Password database tidak tersedia dari MariaDb.';
            self::writeJob($job, ['state' => 'error', 'step' => $msg, 'finished' => gmdate('Y-m-d H:i:s')]);
            return self::err($msg);
        }

        try {
            $r = self::installCore(
                $version, $docroot, $domainName,
                (string)($args['title'] ?? ''),
                (string)($args['admin_user'] ?? ''),
                (string)($args['admin_password'] ?? ''),
                (string)($args['admin_email'] ?? ''),
                $dbNameReal, $dbUserReal, $dbPass,
                static function (string $step) use ($job): void {
                    self::writeJob($job, ['step' => $step]);
                }
            );
            if (!$r['ok']) {
                self::cleanup($username, $dbId, $docroot);
                self::writeJob($job, [
                    'state' => 'error', 'step' => (string)$r['error'], 'finished' => gmdate('Y-m-d H:i:s'),
                ]);
                return self::err((string)$r['error']);
            }
        } catch (\Throwable $e) {
            self::cleanup($username, $dbId, $docroot);
            $msg = 'Instalasi gagal: ' . $e->getMessage();
            self::writeJob($job, ['state' => 'error', 'step' => $msg, 'finished' => gmdate('Y-m-d H:i:s')]);
            return self::err($msg);
        }

        $installed = (string)($r['installed_version'] ?? $version);
        Connection::run(
            'INSERT INTO wp_sites (user_id, domain_id, core_version, is_staging, created_at)
             VALUES (:u, :d, :v, :s, :t)',
            [
                'u' => (int)$domain['user_id'],
                'd' => (int)$domain['id'],
                'v' => $installed,
                's' => !empty($args['is_staging']) ? 1 : 0,
                't' => gmdate('Y-m-d H:i:s'),
            ]
        );

        Audit::log('wp.install', (int)$domain['user_id'], $username, 'domain', $domainName,
            '-', 'ok', ['version' => $installed]);

        self::writeJob($job, [
            'state'    => 'done',
            'step'     => "WordPress $installed terpasang",
            'finished' => gmdate('Y-m-d H:i:s'),
        ]);
        self::pruneJobs();

        return ['ok' => true, 'error' => null, 'message' => "WordPress $installed terpasang di $domainName."];
    }

    // ------------------------------------------------------------ status job

    /** Job terakhir milik user, untuk progres di UI. */
    public static function latestJob(string $username): ?array
    {
        self::ensureJobDir();
        $best = null;
        foreach (glob(self::JOB_DIR . '/*.json') ?: [] as $f) {
            $j = self::readJob((int)basename($f, '.json'));
            if ($j === null || (string)($j['username'] ?? '') !== $username) {
                continue;
            }
            if ($best === null || (int)$j['job'] > (int)$best['job']) {
                $best = $j;
            }
        }
        return $best;
    }

    public static function jobRunning(string $username): bool
    {
        $j = self::latestJob($username);
        return $j !== null && (string)($j['state'] ?? '') === 'running';
    }

    private static function newJob(string $username, string $domain): int
    {
        self::ensureJobDir();
        $id = 0;
        for ($i = 0; $i < 8; $i++) {
            $cand = (int)date('His') * 10 + random_int(0, 9);
            if (!is_file(self::JOB_DIR . '/' . $cand . '.json')) {
                $id = $cand;
                break;
            }
        }
        if ($id === 0) {
            $id = random_int(100000, 999999);
        }
        self::writeJob($id, ['username' => $username, 'domain' => $domain]);
        return $id;
    }

    private static function ensureJobDir(): void
    {
        if (!is_dir(self::JOB_DIR)) {
            @mkdir(self::JOB_DIR, 0755, true);
        }
    }

    /** @param array<string, mixed> $data */
    private static function writeJob(int $id, array $data): void
    {
        if ($id < 1) {
            return;
        }
        self::ensureJobDir();
        $path   = self::JOB_DIR . '/' . $id . '.json';
        $cur    = is_file($path) ? self::readJob($id) : null;
        $merged = array_merge(is_array($cur) ? $cur : [], $data, ['job' => $id]);
        @file_put_contents($path, (string)json_encode($merged), LOCK_EX);
    }

    /** @return array<string, mixed>|null */
    private static function readJob(int $id): ?array
    {
        $path = self::JOB_DIR . '/' . $id . '.json';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Buang job lama supaya folder tidak menumpuk. */
    private static function pruneJobs(): void
    {
        $cut = time() - (24 * 3600);
        foreach (glob(self::JOB_DIR . '/*.json') ?: [] as $f) {
            if ((int)filemtime($f) < $cut) {
                @unlink($f);
            }
        }
    }

    /**
     * Jalankan CLI installer di latar belakang, lepas dari sesi HTTP.
     *
     * setsid dipakai supaya proses tidak ikut mati saat worker FPM selesai.
     * Payload di-encode base64url sehingga tidak ada karakter shell di dalamnya.
     */
    /**
     * Decode payload base64url yang dikirim spawn().
     *
     * base64_decode() dengan strict=true menolak panjang yang tidak kelipatan
     * 4, sedangkan payload sengaja dipangkas padding-nya. Padding ditambahkan
     * dulu di sini.
     *
     * @return array<string, mixed>|null
     */
    public static function decodePayload(string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }
        $b64 = strtr($payload, '-_', '+/');
        $b64 .= str_repeat('=', (4 - (strlen($b64) % 4)) % 4);
        $raw  = base64_decode($b64, true);
        if ($raw === false) {
            return null;
        }
        $args = json_decode((string)$raw, true);
        return is_array($args) ? $args : null;
    }

    private static function spawn(array $args, string $action = 'install'): void
    {
        $payload = rtrim(strtr(base64_encode((string)json_encode($args)), '+/', '-_'), '=');
        $safe = preg_match('/^[a-z-]+$/', $action) === 1 ? $action : 'install';
        exec('setsid /usr/bin/php /srv/fpanel/bin/fpanel wp:' . $safe . ' ' . $payload . ' >/dev/null 2>&1 &');
    }

    // ------------------------------------------------------------------ kelola

    /**
     * Ringkasan satu instalasi untuk panel "Kelola".
     *
     * @return array{ok:bool,error?:string,data?:array<string,mixed>}
     */
    public static function overview(string $username, int $id): array
    {
        $w = self::own($username, $id);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Instalasi tidak ditemukan.'];
        }
        $docroot = (string)$w['docroot'];
        $core    = self::installedCore($docroot);
        $latest  = (string)(self::versions()[0] ?? '');

        [$rc, $out] = self::wpCli(['--path=' . $docroot, 'core', 'version', '--allow-root']);
        $running = $rc === 0 ? trim($out) : '';

        [$prc, $pout] = self::wpCli([
            '--path=' . $docroot, 'plugin', 'list', '--format=json',
            '--allow-root', '--skip-plugins', '--skip-themes',
        ]);
        $updates = ['plugins' => 0, 'themes' => 0, 'core' => 0];
        if ($prc === 0 && $pout !== '') {
            $list = json_decode($pout, true);
            if (is_array($list)) {
                foreach ($list as $p) {
                    if (isset($p['update']) && $p['update'] !== '') {
                        $updates['plugins']++;
                    }
                }
            }
        }
        [$trc, $tout] = self::wpCli([
            '--path=' . $docroot, 'theme', 'list', '--format=json',
            '--allow-root', '--skip-plugins', '--skip-themes',
        ]);
        if ($trc === 0 && $tout !== '') {
            $list = json_decode($tout, true);
            if (is_array($list)) {
                foreach ($list as $t) {
                    if (isset($t['update']) && $t['update'] !== '') {
                        $updates['themes']++;
                    }
                }
            }
        }
        if ($latest !== '' && $core !== null && version_compare($core, $latest, '<')) {
            $updates['core'] = 1;
        }

        return ['ok' => true, 'error' => null, 'data' => [
            'id'           => (int)$w['id'],
            'domain'       => (string)$w['domain_name'],
            'docroot'      => $docroot,
            'core'         => $core ?? '-',
            'running_core' => $running !== '' ? $running : '-',
            'latest'       => $latest,
            'is_staging'   => (int)$w['is_staging'],
            'created_at'   => (string)$w['created_at'],
            'updates'      => $updates,
        ]];
    }

    /**
     * Perbarui core WordPress instalasi tertentu.
     *
     * Dijalankan lewat spawn karena wp core update bisa mengunduh berkas dan
     * pool FPM hanya punya 30 detik. Job-nya sendiri yang dilaporkan lewat
     * latestJob(), jadi UI bisa menampilkan progres seperti saat instalasi.
     */
    public static function startUpdate(string $username, int $id, string $parts = 'core,plugins,themes'): array
    {
        $w = self::own($username, $id);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Instalasi tidak ditemukan.'];
        }
        $allowed = ['core', 'plugins', 'themes'];
        $pick    = array_values(array_intersect(explode(',', $parts), $allowed));
        if ($pick === []) {
            return ['ok' => false, 'error' => 'Tidak ada yang bisa diperbarui.'];
        }
        $job = self::newJob($username, (string)$w['domain_name']);
        self::writeJob($job, [
            'state'   => 'running',
            'step'    => 'Menyiapkan pembaruan ' . implode(', ', $pick) . '…',
            'started' => gmdate('Y-m-d H:i:s'),
            'site_id' => (int)$w['id'],
            'parts'   => $pick,
        ]);
        self::spawn([
            'username' => $username,
            'docroot'  => (string)$w['docroot'],
            'job'      => $job,
            'site_id'  => (int)$w['id'],
            'parts'    => implode(',', $pick),
        ], 'update');

        return ['ok' => true, 'error' => null, 'job' => $job,
                'message' => 'Pembaruan ' . implode(', ', $pick)
                    . ' dijadwalkan untuk ' . (string)$w['domain_name'] . '.'];
    }

    /**
     * Eksekusi pembaruan; dipanggil CLI, bukan dari HTTP.
     */
    public static function runUpdate(array $args): array
    {
        $job     = (int)($args['job'] ?? 0);
        $docroot = (string)($args['docroot'] ?? '');
        $parts   = array_map('trim', explode(',', (string)($args['parts'] ?? '')));
        if ($job < 1 || $docroot === '' || !is_dir($docroot)) {
            return ['ok' => false, 'error' => 'Argumen pembaruan tidak lengkap.'];
        }

        $labels = [
            'core'    => 'Memperbarui core WordPress',
            'plugins' => 'Memperbarui plugin',
            'themes'  => 'Memperbarui tema',
        ];
        $done = [];
        foreach ($parts as $p) {
            if (!isset($labels[$p])) {
                continue;
            }
            self::writeJob($job, ['state' => 'running', 'step' => $labels[$p] . '…']);
            [$rc, $out] = self::wpCli(['--path=' . $docroot, $p, 'update', '--all',
                                       '--allow-root', '--skip-plugins', '--skip-themes']);
            if ($rc !== 0) {
                self::writeJob($job, [
                    'state'    => 'error',
                    'step'     => $labels[$p] . ' GAGAL',
                    'finished' => gmdate('Y-m-d H:i:s'),
                    'error'    => substr($out, 0, 500),
                ]);
                return ['ok' => false, 'error' => $labels[$p] . ' gagal: ' . substr($out, 0, 300)];
            }
            $done[] = $p;
        }

        $core = self::installedCore($docroot) ?? '-';
        self::writeJob($job, [
            'state'    => 'done',
            'step'     => 'Pembaruan selesai. Core sekarang ' . $core . '.',
            'finished' => gmdate('Y-m-d H:i:s'),
        ]);
        if (isset($args['site_id'])) {
            Connection::update('wp_sites', ['core_version' => $core], 'id = :i',
                ['i' => (int)$args['site_id']]);
        }
        return ['ok' => true, 'error' => null, 'message' => 'Pembaruan selesai. Core ' . $core . '.'];
    }

    /**
     * Ubah penanda staging untuk satu instalasi.
     *
     * Hanya mengubah label di panel; tidak memindahkan berkas atau database.
     * Pemisahan produksi/staging yang sesungguhnya ada di domain dan database
     * masing-masing, bukan di flag ini.
     */
    public static function setStaging(string $username, int $id, bool $staging): array
    {
        $w = self::own($username, $id);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Instalasi tidak ditemukan.'];
        }
        Connection::update('wp_sites', ['is_staging' => $staging ? 1 : 0], 'id = :i',
            ['i' => (int)$w['id']]);
        return ['ok' => true, 'error' => null,
                'message' => ($staging ? 'Ditandai sebagai staging.' : 'Ditandai sebagai produksi.')];
    }

    /**
     * Hapus catatan instalasi (file dan database tidak dihapus).
     *
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function forget(string $username, int $id): array
    {
        $row = self::own($username, $id);
        if ($row === null) {
            return self::err('Instalasi tidak ditemukan.');
        }
        Connection::run('DELETE FROM wp_sites WHERE id = :i', ['i' => $id]);
        Audit::log('wp.forget', (int)$row['user_id'], $username, 'wp_site', (string)$id, '-', 'ok', []);
        return ['ok' => true, 'error' => null, 'message' => 'Instalasi dihapus dari daftar. File dan database tidak dihapus.'];
    }

    /** Core version yang benar-benar terpasang, dibaca dari file WP. */
    public static function installedCore(string $docroot): ?string
    {
        $f = $docroot . '/wp-includes/version.php';
        if (!is_file($f) || !is_readable($f)) {
            return null;
        }
        $src = (string)file_get_contents($f, false, null, 0, 8192);
        return preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $src, $m) === 1 ? (string)$m[1] : null;
    }

    // ------------------------------------------------------------------ install

    /**
     * @return array{ok: bool, error?: string, installed_version?: string}
     */
    private static function installCore(
        string $version,
        string $docroot,
        string $domainName,
        string $title,
        string $adminUser,
        string $adminPass,
        string $adminMail,
        string $dbName,
        string $dbUser,
        string $dbPass,
        ?callable $progress = null
    ): array {
        $say = static function (string $step) use ($progress): void {
            if (is_callable($progress)) {
                $progress($step);
            }
        };
        if (!is_dir($docroot)) {
            @mkdir($docroot, 0755, true);
        }
        if (!is_dir($docroot) || !is_writable($docroot)) {
            return ['ok' => false, 'error' => "Docroot tidak bisa ditulis: $docroot"];
        }

        $zipUrl = $version === 'latest'
            ? 'https://wordpress.org/latest.zip'
            : sprintf(self::DOWNLOAD, $version);

        $tmp = tempnam(sys_get_temp_dir(), 'wp');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'Tidak bisa membuat berkas sementara.'];
        }
        try {
                // Pakai cache kalau ada: unduhan 37 MB di server ini bisa memakan
            // 4-5 menit, dan biasanya versinya sama.
            $dl = ['code' => 0, 'error' => 'tidak mencoba'];
            $cached = self::cachedZip($version);
            if ($cached !== null) {
                $say('memakai core dari cache lokal');
                @copy($cached, $tmp);
                $dl = ['code' => is_file($tmp) ? 200 : 0, 'error' => ''];
            }

            // Koneksi ke CDN WordPress kadang STALL di tengah (terlihat macet
            // di 55%). Tanpa batas kecepatan, curl menunggu sampai timeout
            // penuh 30 menit. Jadi: abort kalau melambat, lalu coba lagi.
            for ($attempt = 1; $attempt <= self::DL_ATTEMPTS; $attempt++) {
                if ((int)($dl['code'] ?? 0) === 200) {
                    break;
                }
                $say('mengunduh core WordPress'
                    . ($attempt > 1 ? " (percobaan $attempt)" : ''));
                @unlink($tmp);
                    $dl = self::download($zipUrl, $tmp, $say);
                if ((int)$dl['code'] === 200) {
                    break;
                }
                $say('unduhan gagal, mencoba lagi…');
                sleep(3);
            }
            if ((int)$dl['code'] !== 200) {
                return ['ok' => false, 'error' => "Unduhan core WordPress gagal: " . (string)$dl['error']];
            }
            self::storeZip($version, $tmp);
            $size = (int)filesize($tmp);
            if ($size < 1024 * 1024) {
                return ['ok' => false, 'error' => 'Berkas zip terunduh terlalu kecil, mungkin bukan core WordPress.'];
            }
            if ($size > self::MAX_ZIP_BYTES) {
                return ['ok' => false, 'error' => 'Berkas zip terlalu besar.'];
            }

            $say('mengekstrak core');
            $extract = self::extract($tmp, $docroot);
            if (!$extract['ok']) {
                return ['ok' => false, 'error' => (string)$extract['error']];
            }

            $say('menulis wp-config.php');
            self::writeConfig($docroot, $dbName, $dbUser, $dbPass);

            $say('menjalankan wp core install');

            [$rc, $out] = self::wpCli([
                'core', 'install',
                '--path=' . $docroot,
                '--url=' . $domainName,
                '--title=' . $title,
                '--admin_user=' . $adminUser,
                '--admin_password=' . $adminPass,
                '--admin_email=' . $adminMail,
                '--skip-email',
            ]);
            if ($rc !== 0) {
                return ['ok' => false, 'error' => 'wp core install gagal: ' . self::firstLine($out)];
            }

            $installed = self::installedCore($docroot) ?? $version;
            return ['ok' => true, 'installed_version' => $installed];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Unduh core ke $target.
     *
     * @return array{code: int, error: string} kode HTTP dan pesan error curl
     */
    private static function download(string $url, string $target, ?callable $progress = null): array
    {
        if (!function_exists('curl_init')) {
            return 0;
        }
        // CURLOPT_FILE menuntut HANDLE, bukan string path. Kalau diberi path,
        // curl gagal dengan "supplied argument is not a valid File-Handle".
        $fh = @fopen($target, 'wb');
        if ($fh === false) {
            return ['code' => 0, 'error' => 'tidak bisa membuat berkas unduhan'];
        }

        $lastPct = -1;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT      => 'FPanel/1.0 (+wordpress installer)',
            CURLOPT_NOPROGRESS     => false,
            // Kecepatan di bawah ambang selama N detik = transfer dianggap
            // macet, lalu gagal supaya percobaan berikutnya jalan.
            CURLOPT_LOW_SPEED_LIMIT  => self::LOW_SPEED_LIMIT,
            CURLOPT_LOW_SPEED_TIME   => self::LOW_SPEED_TIME,
        ]);
        if (is_callable($progress)) {
            // Progress dipanggil sangat sering; hanya kirim kalau persennya
            // berubah supaya file status tidak ditulis ribuan kali.
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
                static function ($_ch, $dlTotal, $dlNow) use ($progress, &$lastPct): int {
                    if ($dlTotal > 0) {
                        $pct = (int)floor(($dlNow / $dlTotal) * 100);
                        if ($pct !== $lastPct && $pct % 5 === 0) {
                            $lastPct = $pct;
                            $progress("mengunduh core WordPress $pct%");
                        }
                    }
                    return 0;
                }
            );
        }

        $ok   = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_errno($ch);
        $emsg = (string)curl_error($ch);
        curl_close($ch);
        if (is_resource($fh)) {
            fclose($fh);
        }
        if ($ok === false || $err !== 0 || $code !== 200) {
            @unlink($target);
            if ($err !== 0) {
                // curl error 28 = timeout. Ini yang pernah terjadi karena batas
                // 5 menit terlalu kecil untuk 37 MB di kecepatan rendah.
                return ['code' => 0, 'error' => $emsg !== '' ? $emsg . ' (curl ' . $err . ')' : 'curl error ' . $err];
            }
            return ['code' => $code, 'error' => 'HTTP ' . $code];
        }
        return ['code' => 200, 'error' => ''];
    }

    /**
     * Ekstrak zip dan pindahkan isi folder wordpress/ ke docroot.
     *
     * @return array{ok: bool, error?: string}
     */
    private static function extract(string $zip, string $docroot): array
    {
        $tmpDir = sys_get_temp_dir() . '/wpx-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Folder sementara tidak bisa dibuat.'];
        }

        // Installer jalan di PHP CLI, dan ekstensi zip belum tentu aktif di
        // sana (di server ini zip hanya dimuat untuk FPM). Jadi biner unzip
        // dipakai lebih dulu, ZipArchive hanya cadangan.
        $unzip = self::which('unzip');
        if ($unzip !== null) {
            $cmd = [$unzip, '-q', '-o', $zip, '-d', $tmpDir];
            $rc  = self::runQuiet($cmd, $tmpDir);
            if ($rc !== 0) {
                self::rmTree($tmpDir);
                return ['ok' => false, 'error' => 'Ekstraksi core WordPress gagal (unzip).'];
            }
        } elseif (class_exists(\ZipArchive)) {
            $za = new \ZipArchive();
            if ($za->open($zip) !== true) {
                @rmdir($tmpDir);
                return ['ok' => false, 'error' => 'Berkas zip WordPress tidak bisa dibuka.'];
            }
            $ok = $za->extractTo($tmpDir);
            $za->close();
            if ($ok !== true) {
                self::rmTree($tmpDir);
                return ['ok' => false, 'error' => 'Ekstraksi core WordPress gagal.'];
            }
        } else {
            @rmdir($tmpDir);
            return ['ok' => false, 'error' => 'unzip dan ekstensi ZipArchive sama-sama tidak tersedia.'];
        }

        $src = $tmpDir . '/wordpress';
        if (!is_dir($src)) {
            // Beberapa zip tanpa folder induk; pakai isi direktori kerja.
            $src = $tmpDir;
        }
        if (!self::copyTree($src, $docroot)) {
            self::rmTree($tmpDir);
            return ['ok' => false, 'error' => 'Pemindahan file WordPress ke docroot gagal.'];
        }
        self::rmTree($tmpDir);
        return ['ok' => true];
    }

    /** Tulis wp-config.php dengan kredensial DB dan salt yang diacak di server. */
    private static function writeConfig(string $docroot, string $dbName, string $dbUser, string $dbPass): void
    {
        $keys = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
                 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
        $defs = '';
        foreach ($keys as $k) {
            $defs .= sprintf("define('%s', '%s');\n", $k, self::salt());
        }

        $config = "<?php\n"
            . "/**\n"
            . " * wp-config.php dibuat otomatis oleh FPanel. Jangan diedit manual.\n"
            . " *\n"
            . " * Kredensial database tersimpan di berkas ini, sama seperti panel\n"
            . " * hosting lain. Jangan masukkan file ini ke backup publik.\n"
            . " */\n"
            . "define('DB_NAME', '" . $dbName . "');\n"
            . "define('DB_USER', '" . $dbUser . "');\n"
            . "define('DB_PASSWORD', '" . $dbPass . "');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n\n"
            . $defs . "\n"
            // Tanpa $table_prefix, wp-settings.php gagal dengan
            // "Undefined variable $table_prefix" dan instalasi berhenti.
            . "\$table_prefix = 'wp_';\n\n"
            . "define('WP_DEBUG', false);\n"
            . "define('FS_METHOD', 'direct');\n"
            . "define('DISALLOW_FILE_EDIT', true);\n"
            . "define('AUTOMATIC_UPDATER_DISABLED', false);\n"
            . "define('WP_MEMORY_LIMIT', '256M');\n"
            . "define('WP_MAX_MEMORY_LIMIT', '512M');\n\n"
            . "if (!defined('ABSPATH')) {\n"
            . "    define('ABSPATH', __DIR__ . '/');\n"
            . "}\n"
            . "require_once ABSPATH . 'wp-settings.php';\n";

        file_put_contents($docroot . '/wp-config.php', $config, LOCK_EX);
        @chmod($docroot . '/wp-config.php', 0640);
    }

    /** Jalankan WP-CLI dengan argumen array (tanpa shell). */
    private static function wpCli(array $args): array
    {
        $cmd = array_merge(['/usr/local/bin/wp'], $args);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, '/var/www');
        if (!is_resource($proc)) {
            return [1, 'gagal menjalankan wp-cli'];
        }
        @fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        return [proc_close($proc), trim($out . "\n" . $err)];
    }

    /** Buang sisa instalasi yang gagal: file WP dan database. */
    private static function cleanup(string $username, int $dbId, string $docroot): void
    {
        if ($dbId > 0) {
            MariaDb::delete($username, $dbId);
        }
        // Hanya file inti WordPress yang dihapus; file milik user lain di
        // docroot tidak boleh ikut terhapus.
        foreach (['wp-admin', 'wp-includes', 'wp-login.php', 'wp-settings.php',
                  'wp-load.php', 'index.php', 'wp-config.php', 'wp-content',
                  'wp-blog-header.php', 'wp-comments-post.php', 'wp-cron.php',
                  'wp-links-opml.php', 'wp-mail.php', 'wp-signup.php',
                  'wp-trackback.php', 'xmlrpc.php', 'readme.html', 'license.txt'] as $f) {
            $p = $docroot . '/' . $f;
            if (is_dir($p)) {
                self::rmTree($p);
            } elseif (is_file($p)) {
                @unlink($p);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed>|null */
    private static function domain(string $username, string $name): ?array
    {
        return Connection::one(
            'SELECT d.* FROM domains d JOIN users u ON u.id = d.user_id
              WHERE u.username = :u AND d.name = :n',
            ['u' => $username, 'n' => $name]
        );
    }

    /** @return array<string, mixed>|null */
    public static function own(string $username, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return Connection::one(
            'SELECT w.* FROM wp_sites w JOIN users u ON u.id = w.user_id
              WHERE u.username = :u AND w.id = :i',
            ['u' => $username, 'i' => $id]
        );
    }

    /** Docroot harus di dalam /var/www/<user>/ supaya tidak keluar dariMilik user. */
    private static function safeDocroot(string $docroot, string $username): bool
    {
        $base = '/var/www/' . $username . '/';
        return str_starts_with($docroot, $base) && !str_contains($docroot, '..');
    }

    /** @return array{max_wp: int} */
    private static function quota(string $username): array
    {
        $v = (int)Connection::value(
            'SELECT COALESCE(p.max_wp_sites, 0) FROM users u LEFT JOIN packages p ON p.id = u.package_id
              WHERE u.username = :u',
            ['u' => $username]
        );
        return ['max_wp' => $v];
    }

    private static function randomPassword(int $len): string
    {
        $abc = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $abc[random_int(0, strlen($abc) - 1)];
        }
        return $out;
    }

    /** 64 karakter dari character set yang aman untuk konstanta PHP. */
    private static function salt(): string
    {
        $abc = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~`+=,.;:/?|';
        $out = '';
        for ($i = 0; $i < 64; $i++) {
            $out .= $abc[random_int(0, strlen($abc) - 1)];
        }
        return $out;
    }

    private static function copyTree(string $src, string $dst): bool
    {
        $ok = true;
        $items = @scandir($src);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $s = $src . '/' . $item;
            $d = $dst . '/' . $item;
            if (is_dir($s)) {
                if (!is_dir($d) && !@mkdir($d, 0755, true)) {
                    $ok = false;
                    continue;
                }
                $ok = self::copyTree($s, $d) && $ok;
                continue;
            }
            if (!@copy($s, $d)) {
                $ok = false;
            }
        }
        return $ok;
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p)) {
                self::rmTree($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($dir);
    }

    private static function http(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'FPanel/1.0 (+wordpress installer)',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : null;
    }

    private static function firstLine(string $text): string
    {
        $lines = preg_split('/\R/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return substr((string)($lines[0] ?? 'tanpa pesan'), 0, 200);
    }

    private static function cachedZip(string $version): ?string
    {
        $p = self::CACHE_DIR . '/wordpress-' . preg_replace('/[^0-9A-Za-z._-]/', '', $version) . '.zip';
        return is_file($p) && filesize($p) > 1024 * 1024 ? $p : null;
    }

    private static function storeZip(string $version, string $tmp): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }
        $p = self::CACHE_DIR . '/wordpress-' . preg_replace('/[^0-9A-Za-z._-]/', '', $version) . '.zip';
        if (!is_file($p)) {
            @copy($tmp, $p);
        }
    }

    /** Cari biner di PATH. */
    private static function which(string $bin): ?string
    {
        $path = (string)(getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        foreach (explode(':', $path) as $dir) {
            $p = rtrim($dir, '/') . '/' . $bin;
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return null;
    }

    /** Jalankan perintah tanpa output; kembalikan kode keluar. */
    private static function runQuiet(array $cmd, string $cwd): int
    {
        $desc = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, $cwd);
        if (!is_resource($proc)) {
            return 1;
        }
        return proc_close($proc);
    }

    /** @return array{ok: bool, error: string|null, message: string|null} */
    private static function err(string $msg): array
    {
        return ['ok' => false, 'error' => $msg, 'message' => null];
    }
}