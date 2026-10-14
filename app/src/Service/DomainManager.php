<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;
use Fpanel\Support\Audit;

/**
 * Manajemen domain: validasi kuota -> docroot -> vhost nginx -> reload.
 * Urutan penting: DB LAST, supaya kalau langkah sistem gagal tidak ada baris yatim.
 */
final class DomainManager
{
    /**
     * Tipe domain yang valid.
     *
     * Hanya dua: static dan php. Node.js dan Python BUKAN tipe domain --
     * keduanya aplikasi runtime yang menempel pada domain, dan statusnya
     * disimpan di tabel runtime_apps lewat kolom domain_id. Domain tidak
     * pernah "berjenis node"; domain selalu punya docroot, lalu opsional
     * punya aplikasi yang dilayani nginx lewat proxy.
     *
     * Nilai lama masih mungkin ada di database yang belum dimigrasi;
     * db:migrate-domain-type yang membersihkannya.
     */
    public const TYPES = ['static', 'php'];

    /** Nilai historis yang dipetakan ke php saat migrasi. */
    public const LEGACY_TYPES = ['node', 'python', 'binary'];

    /** @return array{ok:bool,error?:string,domain?:array} */
    public static function create(int $userId, string $username, string $name, string $type, ?string $php, bool $issueTls = false): array
    {
        $name = strtolower(trim($name));
        $v = Validator::domain($name);
        if ($v !== null) {
            return ['ok' => false, 'error' => $v];
        }
        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => 'Tipe domain tidak dikenal: ' . $type];
        }

        $installed = Panel::installedPhp();
        if ($type === 'php') {
            if ($php === null || $php === '') {
                $php = (string)Config::get('php.default', '8.3');
            }
            if (!in_array($php, $installed, true)) {
                return ['ok' => false, 'error' => "PHP {$php} belum terinstall di server."];
            }
        } else {
            $php = null;
        }

        $quota = (int)Connection::value(
            'SELECT COALESCE(p.max_domains, 0) FROM users u
               LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = :u',
            ['u' => $userId]
        );
        $used  = Stats::usedDomains($userId);
        if ($quota > 0 && $used >= $quota) {
            return ['ok' => false, 'error' => "Kuota domain penuh ({$used}/{$quota})."];
        }

        $exists = Connection::value('SELECT id FROM domains WHERE name = :n', ['n' => $name]);
        if ($exists !== null) {
            return ['ok' => false, 'error' => "Domain {$name} sudah dipakai."];
        }

        try {
            Vhost::ensureUserRoot($username);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $docroot = Vhost::docroot($username, $name);
        $parent  = dirname($docroot);

        // 1. docroot
        if (!is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) {
            return ['ok' => false, 'error' => "Gagal membuat {$parent}."];
        }
        if (!is_dir($docroot) && !@mkdir($docroot, 0755, true) && !is_dir($docroot)) {
            return ['ok' => false, 'error' => "Gagal membuat docroot {$docroot}."];
        }
        @chown($parent, 'www-data');
        @chown($docroot, 'www-data');
        @chmod($parent, 0750);

        // 2. placeholder supaya domain langsung merespons. Tipe static TIDAK
        // boleh dapat .php sama sekali: docroot static tidak punya handler
        // PHP di vhost, jadi file itu cuma sampah yang membingungkan user
        // yang mengira situs statisnya bisa menjalankan PHP.
        if ($type === 'php') {
            if (!is_file($docroot . '/index.php')) {
                $phpinfo = "<?php\n// Halaman awal otomatis. Hapus untuk mulai membangun situs.\n"
                    . "header('Content-Type: text/plain; charset=utf-8');\n"
                    . "echo \"FPanel: domain {$name} aktif. \\n\";\n"
                    . "echo 'PHP: ' . PHP_VERSION . \"\\n\";\n"
                    . "echo 'Docroot: ' . __DIR__ . \"\\n\";\n";
                @file_put_contents($docroot . '/index.php', $phpinfo, 0644);
                @chown($docroot . '/index.php', 'www-data');
            }
        } else {
            if (!is_file($docroot . '/index.html')) {
                $esc = htmlspecialchars($name, ENT_QUOTES);
                $html = "<!doctype html>\n<html lang=\"id\">\n<head>\n"
                    . "<meta charset=\"utf-8\">\n"
                    . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
                    . "<title>{$esc}</title>\n"
                    . "<style>body{font-family:system-ui,-apple-system,sans-serif;margin:0;"
                    . "display:grid;place-items:center;min-height:100vh;background:#f8fafc;color:#0f172a}\n"
                    . ".b{text-align:center;padding:2rem}\n"
                    . "h1{font-size:1.25rem;margin:0 0 .5rem}\n"
                    . "p{margin:0;color:#64748b;font-size:.875rem}</style>\n"
                    . "</head>\n<body><div class=\"b\">\n"
                    . "<h1>{$esc} aktif</h1>\n"
                    . "<p>Upload berkas lewat File Manager untuk mulai.</p>\n"
                    . "</div></body>\n</html>\n";
                @file_put_contents($docroot . '/index.html', $html, 0644);
                @chown($docroot . '/index.html', 'www-data');
            }
        }
        if ($type === 'php' && $php !== null) {
            $ini = Vhost::iniDir($name, $php);
            if (!is_dir($ini) && !@mkdir($ini, 0755, true) && !is_dir($ini)) {
                return ['ok' => false, 'error' => "Gagal membuat dir ekstensi {$ini}."];
            }
        }

        // 3. vhost + nginx -t + reload
        try {
            $fresh = Connection::one('SELECT * FROM domains WHERE name = :n', ['n' => $name]);
            if (is_array($fresh)) {
                self::deployWithDerived($name, $username, $fresh);
            } else {
                Vhost::deploy($name, $username, $docroot, $type, $php);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // 4. DB (terakhir)
        try {
            $id = Connection::insert('domains', [
                'user_id'     => $userId,
                'name'        => $name,
                'type'        => $type,
                'is_subdomain'=> 0,
                'parent_domain'=> null,
                'docroot'     => $docroot,
                'php_version' => $php,
                'status'      => 'active',
            ]);
        } catch (\Throwable $e) {
            Vhost::remove($name);
            return ['ok' => false, 'error' => 'Gagal menyimpan domain: ' . $e->getMessage()];
        }

        Audit::log('domain.create', $userId, $username, 'domain', $id, '', 'ok', ['name' => $name, 'type' => $type, 'php' => $php]);

        // HTTPS opsional. Kegagalan sertifikat TIDAK boleh membatalkan pembuatan
        // domain: user tetap punya HTTP dan bisa menekan "Terbitkan SSL" lagi.
        $ssl = null;
        if ($issueTls === true) {
            $ssl = self::issueCertificate($id, $name, $username, $userId);
        }

        $row = Connection::one('SELECT * FROM domains WHERE id = :i', ['i' => $id]);
        return [
            'ok'     => true,
            'domain' => is_array($row) ? $row : [],
            'ssl'    => $ssl,
        ];
    }

    /**
     * Terbitkan sertifikat untuk satu domain lalu pasang blok 443-nya.
     * Kembalikan ['ok'=>bool,'message'=>string]; tidak melempar exception.
     */
    public static function issueCertificate(int $domainId, string $name, string $username, int $userId): array
    {
        Connection::update('domains', ['ssl_status' => 'pending', 'ssl_error' => null], 'id = :i', ['i' => $domainId]);

        $res = LetsEncrypt::issue($name);
        if (($res['ok'] ?? false) !== true) {
            $msg = mb_substr((string)($res['error'] ?? 'Gagal menerbitkan SSL.'), 0, 500);
            Connection::update('domains', ['ssl_status' => 'error', 'ssl_error' => $msg], 'id = :i', ['i' => $domainId]);
            Audit::log('domain.ssl.error', $userId, $username, 'domain', $domainId, '', 'error', ['name' => $name, 'error' => $msg]);
            return ['ok' => false, 'message' => $msg];
        }

        // Sertifikat sudah ada, tapi vhost masih serves HTTP saja. Bangun ulang
        // supaya blok 443 ikut, tanpa ini SSL terpasang tapi tidak terpakai.
        try {
            $d = Connection::one('SELECT * FROM domains WHERE id = :i', ['i' => $domainId]);
            if (is_array($d)) {
                self::deployWithDerived((string)$d['name'], $username, $d);
            }
        } catch (\Throwable $e) {
            $msg = mb_substr('Sertifikat terbit tapi vhost gagal: ' . $e->getMessage(), 0, 500);
            Connection::update('domains', ['ssl_status' => 'error', 'ssl_error' => $msg], 'id = :i', ['i' => $domainId]);
            return ['ok' => false, 'message' => $msg];
        }

        $days = LetsEncrypt::daysLeft($name);
        Connection::update(
            'domains',
            ['ssl_status' => 'active', 'ssl_issued_at' => gmdate('Y-m-d H:i:s'), 'ssl_error' => null],
            'id = :i',
            ['i' => $domainId]
        );
        Audit::log('domain.ssl.issue', $userId, $username, 'domain', $domainId, '', 'ok', ['name' => $name, 'days' => $days]);
        return ['ok' => true, 'message' => 'Sertifikat terbit' . ($days !== null ? ', berlaku ' . $days . ' hari.' : '.')];
    }

    /** Cabut sertifikat domain. true = bersih, false = masih tertinggal. */
    public static function revokeCertificate(int $domainId, string $name): bool
    {
        $ok = LetsEncrypt::revoke($name);
        Connection::update(
            'domains',
            $ok
                ? ['ssl_status' => 'none', 'ssl_issued_at' => null, 'ssl_error' => null]
                : ['ssl_status' => 'error', 'ssl_error' => 'Sertifikat tidak bisa dicabut. Hapus manual: certbot delete --cert-name ' . $name],
            'id = :i',
            ['i' => $domainId]
        );
        return $ok;
    }


    /**
     * Deploy vhost dengan semua info turunan yang dibutuhkan.
     *
     * Vhost::deploy() butuh dua hal yang TIDAK ada di baris domains:
     *   - override php.ini per domain (kolom php_ini)
     *   - port app runtime, kalau domain-nya bertipe node/python
     * Diteruskan lewat satu helper supaya tidak ada call site yang lupa,
     * dan domain type node tidak pernah ter-deploy tanpa proxy ke app-nya.
     */
    /**
     * Bangun ulang vhost dari baris domain yang sudah diambil.
     *
     * Dipakai dari luar (mis. setelah app runtime dibuat) supaya domain
     * type node/python ikut ter-proxy ke port app yang baru.
     */
    public static function rebuildFor(string $username, array $d): void
    {
        self::deployWithDerived((string)$d['name'], $username, $d);
    }

    private static function deployWithDerived(string $name, string $username, array $d): void
    {
        $phpIni = [];
        $raw    = (string)($d['php_ini'] ?? '');
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $phpIni = $decoded;
            }
        }
        // Port diambil dari runtime_apps, bukan dari domains.type. Domain
        // bertipe php pun boleh punya app Node di subdomainnya, jadi sumber
        // kebenaran hanya satu: app yang benar-benar ada.
        $port = AppRuntime::portForDomain((int)$d['id']);
        Vhost::deploy(
            $name,
            $username,
            (string)$d['docroot'],
            (string)$d['type'],
            $d['php_version'] === null ? null : (string)$d['php_version'],
            $phpIni,
            $port
        );
    }

    public static function delete(int $userId, string $username, int $domainId): array
    {
        $d = Connection::one(
            'SELECT * FROM domains WHERE id = :i AND user_id = :u',
            ['i' => $domainId, 'u' => $userId]
        );
        if ($d === null) {
            return ['ok' => false, 'error' => 'Domain tidak ditemukan.'];
        }
        $name    = (string)$d['name'];
        $docroot = (string)$d['docroot'];

        Vhost::remove($name);
        if ($d['php_version'] !== null) {
            $ini = Vhost::iniDir($name, (string)$d['php_version']);
            if (is_dir($ini)) {
                array_map('unlink', glob($ini . '/*') ?: []);
                @rmdir($ini);
            }
        }
        // Hapus isi docroot. File milik root (mis. hasil upload lama) tidak
        // bisa di-unlink oleh www-data, dan '@' dulu menelan error itu
        // sehingga delete dilaporkan sukses padahal data tertinggal.
        $parent  = dirname($docroot);
        $leftover = [];
        if (str_starts_with($docroot, Vhost::WWW_ROOT . '/' . $username . '/') && is_dir($parent)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($parent, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $ok = $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                if (!$ok && file_exists($f->getPathname())) {
                    $leftover[] = $f->getPathname();
                }
            }
            if (!@rmdir($parent) && is_dir($parent)) {
                $leftover[] = $parent;
            }
        }

        // Cabut sertifikat lebih dulu. Kalau certbot delete gagal (mis. sudah
        // tidak ada), itu bukan alasan membatalkan penghapusan domain.
        if (LetsEncrypt::revoke($name) === false) {
            $leftover[] = 'sertifikat TLS ' . $name;
        }

        // Log nginx ikut dibuang. Kalau tidak, file access/error log domain ini
        // menggantung di disk sampai housekeeping berikutnya, padahal tidak ada
        // lagi pemiliknya.
        $logsRemoved = Housekeeping::make()->purgeDomainLogs($name);

        Connection::run('DELETE FROM domains WHERE id = :i', ['i' => $domainId]);

        $note = $leftover === []
            ? ['name' => $name, 'logs_removed' => $logsRemoved]
            : ['name' => $name, 'leftover' => array_slice($leftover, 0, 10), 'leftover_total' => count($leftover)];
        Audit::log(
            'domain.delete',
            $userId,
            $username,
            'domain',
            $domainId,
            '',
            $leftover === [] ? 'ok' : 'partial',
            $note
        );

        if ($leftover !== []) {
            return [
                'ok'    => true,
                'error' => 'Domain dihapus, tapi ada ' . count($leftover)
                    . ' berkas yang tidak bisa dihapus (biasanya milik root). '
                    . 'Hapus manual: ' . implode(', ', array_slice($leftover, 0, 3)),
            ];
        }
        return ['ok' => true];
    }
}
