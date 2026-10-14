<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Generator vhost nginx.
 *
 * Kontrak:
 *  - listen 80 (provider masih menutup 80 dari luar; lokal tetap bisa dites via Host header)
 *  - nilai php.ini per-domain lewat fastcgi_param PHP_VALUE
 *    (PHP-FPM mengabaikan PHP_INI_SCAN_DIR dari fastcgi_param, jadi file
 *     per-domain tidak akan pernah terbaca)
 *  - reload selalu divalidasi `nginx -t` dulu, rollback otomatis kalau gagal
 */
final class Vhost
{
    public const AVAIL_DIR = '/etc/nginx/sites-available/fpanel-domains';
    public const ENABLED_DIR = '/etc/nginx/sites-enabled';
    public const LOG_DIR     = '/var/log/nginx/fpanel';
    public const WWW_ROOT    = '/var/www';
    // Fallback saja. Versi sebenarnya diambil per-domain lewat fpmSock(),
    // supaya domain yang memilih PHP 8.1 benar-benar routed ke php8.1-fpm.
    public const FPM_SOCK    = '/run/php/php8.3-fpm.sock';

    public static function confPath(string $domain): string
    {
        return self::AVAIL_DIR . '/' . self::slug($domain) . '.conf';
    }

    /** Versi PHP yang terpasang di sistem (deteksi dari /etc/php/<v>/fpm). */
    public static function phpVersions(): array
    {
        $base = '/etc/php';
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (glob($base . '/*/fpm', GLOB_ONLYDIR) ?: [] as $dir) {
            // Adanya /etc/php/<v>/fpm/ berarti versi itu terpasang.
            // Sengaja TIDAK memakai conf.d/domains sebagai patokan: direktori itu
            // baru dibuat setelah ada domain yang memakai versinya, sehingga versi
            // yang baru dipasang tidak akan pernah terdeteksi.
            if (preg_match('#/etc/php/([0-9]+\.[0-9]+)/#', $dir, $m)) {
                $out[] = $m[1];
            }
        }
        // usort, bukan sort: sort() argumen kedua adalah flag, bukan comparator.
        usort($out, 'version_compare');
        return $out;
    }

    /**
     * Socket FPM untuk satu versi PHP.
     *
     * Versi divalidasi ketat karena nilainya masuk ke file konfigurasi nginx.
     * Tanpa validasi, input seperti "8.3; return 404" bisa menyuntik baris
     * konfigurasi nginx lewat nama socket.
     */
    public static function fpmSock(string $php): string
    {
        if (!preg_match('/^[0-9]+\\.[0-9]+$/', $php)) {
            return self::FPM_SOCK;
        }
        $sock = '/run/php/php' . $php . '-fpm.sock';
        // file_exists(), bukan is_file(): socket unix bukan regular file, jadi
        // is_file() selalu false dan setiap domain akan jatuh ke default.
        // Kalau socket tidak ada, fallback ke default supaya tidak menghasilkan
        // konfigurasi yang pasti gagal.
        return file_exists($sock) ? $sock : self::FPM_SOCK;
    }

    /** Versi PHP yang terpasang DAN punya socket FPM aktif. */
    public static function installedPhpVersions(): array
    {
        $out = [];
        foreach (self::phpVersions() as $v) {
            if (file_exists('/run/php/php' . $v . '-fpm.sock')) {
                $out[] = $v;
            }
        }
        usort($out, 'version_compare');
        return $out;
    }

    public static function iniDir(string $domain, string $php): string
    {
        return '/etc/php/' . $php . '/fpm/conf.d/domains/' . self::slug($domain);
    }

    /**
     * Pastikan web root per-user ada dan milik www-data.
     * /var/www sendiri di-setgid www-data supaya direktori baru mewarisi grup.
     */
    public static function ensureUserRoot(string $username): string
    {
        $root = self::WWW_ROOT . '/' . self::slug($username);
        if (!is_dir(self::WWW_ROOT)) {
            @mkdir(self::WWW_ROOT, 0755, true);
        }
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            throw new \RuntimeException('Gagal membuat web root user: ' . $root);
        }
        @chgrp($root, 'www-data');
        @chown($root, 'www-data');
        @chmod($root, 0750);
        return $root;
    }

    public static function docroot(string $username, string $domain): string
    {
        return self::WWW_ROOT . '/' . $username . '/' . self::slug($domain) . '/public';
    }

    /** Nama file aman dari nama domain (anti path traversal). */
    public static function slug(string $domain): string
    {
        $s = strtolower(trim($domain));
        $s = preg_replace('/[^a-z0-9.-]+/', '-', $s) ?? '';
        return trim($s, '-.') ?: 'invalid';
    }

    public static function render(string $domain, string $username, string $docroot, string $type, ?string $php, array $phpIni = [], ?int $appPort = null): string
    {
        $slug  = self::slug($domain);
        $names = $domain . ' www.' . $domain;
        $isPhp = $type === 'php';
        $access = self::LOG_DIR . '/' . $slug . '.access.log';
        $error  = self::LOG_DIR . '/' . $slug . '.error.log';

        // ACME harus selalu dijawab di port 80, walaupun sisanya sudah HTTPS.
        // Kalau location ini ikut di-redirect ke 443, renewal certbot gagal
        // karena Let's Encrypt selalu menghubungi port 80.
        $acme = "    # Let's Encrypt HTTP-01. SELALU di port 80, jangan di-redirect.\n"
            . "    location ^~ /.well-known/acme-challenge/ {\n"
            . "        root " . LetsEncrypt::WEBROOT . ";\n"
            . "        default_type \"text/plain\";\n"
            . "        access_log off;\n"
            . "    }\n\n";

        $common = '';
        $common .= "    root {$docroot};\n";
        $common .= "    index index.php index.html;\n\n";
        $common .= "    access_log {$access};\n";
        $common .= "    error_log  {$error};\n";
        $common .= "    client_max_body_size 128M;\n";
        $common .= "    server_tokens off;\n\n";
        $common .= "    # sembunyikan dotfile, kecuali .well-known (dipakai ACME)\n";
        $common .= "    location ~ /\.(?!well-known) {\n        deny all;\n        access_log off;\n    }\n\n";

        // Urutan cabang penting: aplikasi runtime yang menempel pada domain
        // SELALU menang atas PHP-FPM. App adalah front controller-nya, jadi
        // domain bertipe php pun harus di-proxy kalau ada app hidup.
        // Sebelumnya PHP dicek lebih dulu; begitu domain Node di-backfill
        // jadi php, app-nya berhenti dilayani sama sekali.
        if ($appPort !== null) {
            $ws = "127.0.0.1:" . $appPort;
            $common .= "    # app runtime aktif, dilayani via {$ws}\n";
            $common .= "    location / {\n";
            $common .= "        proxy_pass http://{$ws};\n";
            $common .= "        proxy_http_version 1.1;\n";
            // WebSocket (umum dipakai Node) harus lewat Upgrade/Connection.
            $common .= "        proxy_set_header Upgrade \$http_upgrade;\n";
            $common .= "        proxy_set_header Connection \$fp_connection_upgrade;\n";
            $common .= "        proxy_set_header Host \$host;\n";
            $common .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
            $common .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
            $common .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
            $common .= "        proxy_read_timeout 300s;\n";
            $common .= "        proxy_connect_timeout 10s;\n";
            $common .= "    }\n\n";
            // map di http level memberi nilai Connection yang benar (upgrade
            // atau close). Tanpa ini, header Connection selalu "upgrade" dan
            // request biasa bisa menggantung.
            $common .= "    location ~* ^/(assets|static|public|favicon\\.ico|robots\\.txt) {\n";
            $common .= "        root {$docroot};\n";
            $common .= "        try_files \$uri @app;\n";
            $common .= "    }\n\n";
            $common .= "    location @app {\n";
            $common .= "        proxy_pass http://{$ws};\n";
            $common .= "        proxy_http_version 1.1;\n";
            $common .= "        proxy_set_header Upgrade \$http_upgrade;\n";
            $common .= "        proxy_set_header Connection \$fp_connection_upgrade;\n";
            $common .= "        proxy_set_header Host \$host;\n";
            $common .= "        proxy_read_timeout 300s;\n";
            $common .= "    }\n";
        } elseif ($isPhp && $php !== null) {
            $common .= "    # PHP-FPM {$php}\n";
            $common .= "    location ~ \.[pP][hH][pP]\$ {\n";
            $common .= "        include fastcgi_params;\n";
            $common .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
            $common .= "        fastcgi_param HTTP_PROXY \"\";\n";
            // Nilai php.ini per-domain lewat PHP_VALUE, BUKAN PHP_INI_SCAN_DIR.
            // PHP-FPM mengabaikan PHP_INI_SCAN_DIR yang datang lewat
            // fastcgi_param (ia memakai scan dir dari pool config), sehingga
            // file per-domain tidak akan pernah terbaca. PHP_VALUE terbukti
            // bekerja dan terisolasi per domain.
            if ($phpIni !== []) {
                $pairs = [];
                foreach ($phpIni as $k => $v) {
                    $pairs[] = $k . ' = ' . $v;
                }
                // Dipisah newline; di nginx ditulis sebagai \n di dalam string.
                $common .= "        fastcgi_param PHP_VALUE \"" . implode('\\n', $pairs) . "\";\n";
            }
            $common .= "        fastcgi_read_timeout 300;\n";
            $common .= "        fastcgi_pass unix:" . self::fpmSock($php) . ";\n";
            $common .= "    }\n\n";
            $common .= "    # cegah kebocoran source PHP lewat ekstensi lain\n";
            $common .= "    location ~ \.(phtml|phar|php[0-9]|inc|shtml|htaccess)\$ {\n        deny all;\n        access_log off;\n    }\n\n";
            $common .= "    location / {\n        try_files \$uri \$uri/ /index.php?\$query_string;\n    }\n";
        } else {
            // Tanpa PHP dan tanpa app hidup: layani berkas statis supaya
            // halaman tidak kosong total.
            $common .= "    # domain {$type} tanpa app hidup: hanya statis\n";
            $common .= "    location / {\n        try_files \$uri \$uri/ /index.html =404;\n    }\n";
        }

        $head  = "# FPanel :: {$domain} (user: {$username})\n";
        $head .= "# Dihasilkan otomatis oleh FPanel. Jangan diedit manual.\n";

        if (!LetsEncrypt::hasCert($domain)) {
            // Belum ada sertifikat: satu server block HTTP saja.
            $out  = $head . "server {\n";
            $out .= "    listen 80;\n";
            $out .= "    listen [::]:80;\n";
            $out .= "    server_name {$names};\n\n";
            $out .= $acme;
            $out .= $common;
            $out .= "}\n";
            return $out;
        }

        // Sudah ada sertifikat: HTTP-answer hanya ACME lalu arahkan sisanya
        // ke HTTPS, dan seluruh situs dilayani di 443.
        $out  = $head . "server {\n";
        $out .= "    listen 80;\n";
        $out .= "    listen [::]:80;\n";
        $out .= "    server_name {$names};\n\n";
        $out .= $acme;
        $out .= "    location / {\n        return 301 https://\$host\$request_uri;\n    }\n";
        $out .= "}\n\n";

        $out .= "server {\n";
        $out .= "    listen 443 ssl http2;\n";
        $out .= "    listen [::]:443 ssl http2;\n";
        $out .= "    server_name {$names};\n\n";
        $out .= "    ssl_certificate     " . LetsEncrypt::fullchain($domain) . ";\n";
        $out .= "    ssl_certificate_key " . LetsEncrypt::privkey($domain) . ";\n";
        $out .= "    include /etc/nginx/snippets/fpanel-ssl.conf;\n\n";
        $out .= "    add_header Strict-Transport-Security \"max-age=31536000\" always;\n";
        $out .= "    add_header X-Content-Type-Options \"nosniff\" always;\n";
        $out .= "    add_header X-Frame-Options \"SAMEORIGIN\" always;\n";
        $out .= "    add_header Referrer-Policy \"strict-origin-when-cross-origin\" always;\n\n";
        $out .= $common;
        $out .= "}\n";
        return $out;
    }

    /**
     * Tunggu sampai vhost benar-benar melayani (atau sudah hilang).
     * $expect = true  -> host harus menjawab dengan status HTTP
     * $expect = false -> host harus berhenti menjawab (sudah dihapus)
     *
     * @return bool true bila kondisi tercapai dalam batas waktu
     */
    private static function waitForVhost(string $domain, bool $expect, float $timeout = 3.0): bool
    {
        // Host header WAJIB satu hostname. Mengirim daftar server_name
        // ("d.test www.d.test") membuat nginx membalas 400.
        $deadline = microtime(true) + $timeout;
        do {
            $status = self::probe($domain);
            if ($expect ? $status > 0 : $status === 0) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        return false;
    }

    /** Kirim HEAD ke vhost, kembalikan kode status (0 = tanpa respons / koneksi ditutup). */
    private static function probe(string $host): int
    {
        $fp = @stream_socket_client('tcp://127.0.0.1:80', $errno, $errstr, 0.4);
        if ($fp === false) {
            return 0;
        }
        stream_set_timeout($fp, 1);
        // Request line harus rapi. Versi sebelumnya "HEAD / .fpanel-probe"
        // bikin nginx balas 400 SEBELUM memilih server block, sehingga probe
        // tidak bisa membedakan vhost hidup vs sudah dihapus.
        fwrite($fp, "HEAD /fpanel-probe HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $line = (string)fgets($fp);
        fclose($fp);
        return preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) ? (int)$m[1] : 0;
    }

    /**
     * Jalankan perintah privileged. Panel jalan sebagai www-data, jadi
     * operasi nginx lewat sudo (aturan sempit di /etc/sudoers.d/fpanel).
     * Kalau prosesnya root (mis. CLI), jalankan langsung.
     */
    private static function priv(string $bin, string ...$args): array
    {
        $parts = [escapeshellarg($bin)];
        foreach ($args as $a) {
            $parts[] = escapeshellarg($a);
        }
        $inner = implode(' ', $parts);
        $root  = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $out   = [];
        exec(($root ? '' : 'sudo -n ') . $inner . ' 2>&1', $out, $rc);
        return [$rc, implode(' ', $out)];
    }

    /** Tulis + enable + validasi + reload. Throw dengan pesan bila gagal. */
    public static function deploy(string $domain, string $username, string $docroot, string $type, ?string $php, array $phpIni = [], ?int $appPort = null): void
    {
        $conf = self::confPath($domain);
        $bak  = null;

        foreach ([self::AVAIL_DIR, self::LOG_DIR, dirname($docroot)] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
                throw new \RuntimeException("Gagal membuat direktori: {$d}");
            }
        }
        if (is_file($conf)) {
            $bak = $conf . '.bak';
            copy($conf, $bak);
        }
        if (file_put_contents($conf, self::render($domain, $username, $docroot, $type, $php, $phpIni, $appPort)) === false) {
            throw new \RuntimeException("Gagal menulis vhost: {$conf}");
        }
        [$rc, $out] = self::priv('/usr/sbin/nginx', '-t');
        if ($rc !== 0) {
            if ($bak !== null) {
                copy($bak, $conf);
            } else {
                @unlink($conf);
            }
            throw new \RuntimeException('nginx -t gagal, vhost dibatalkan: ' . $out);
        }
        [$rc2, $out2] = self::priv('/bin/systemctl', 'reload', 'nginx');
        if ($rc2 !== 0) {
            throw new \RuntimeException('Reload nginx gagal: ' . $out2);
        }

        // `systemctl reload` kembali SEBELUM worker baru siap. Dan
        // socket listener tidak pernah ditutup saat graceful reload, jadi
        // cek koneksikan (fsockopen) selalu lolos seketika. Harus
        // benar-benar mengirim HTTP dan melihat apakah vhost baru dilayani.
        self::waitForVhost($domain, true);
    }

    public static function remove(string $domain): void
    {
        @unlink(self::confPath($domain));
        @unlink(self::confPath($domain) . '.bak');

        // Override PHP per-domain ikut dibersihkan. Kalau tertinggal, PHP-FPM
        // tetap memindai direktori itu untuk vhost yang sudah tidak ada.
        // scanSemuaVersi() karena remove() tidak menerima php_version.
        foreach (self::phpVersions() as $ver) {
            $ini = self::iniDir($domain, $ver);
            if (is_dir($ini)) {
                foreach (glob($ini . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($ini);
            }
        }

        [$rc] = self::priv('/usr/sbin/nginx', '-t');
        if ($rc === 0) {
            self::priv('/bin/systemctl', 'reload', 'nginx');

            // Worker lama masih melayani vhost yang baru saja dihapus dan
            // membalas 404 (docroot sudah hilang) selama masa shutdown.
            // Tunggu sampai host-nya benar-benar jatuh ke catch-all 444.
            self::waitForVhost($domain, false);
        }
    }
}
