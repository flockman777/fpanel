<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * phpMyAdmin per-database dengan token sekali pakai.
 *
 * Cara kerjanya:
 *   1. User klik "phpMyAdmin" pada salah satu user database.
 *   2. Service membuat token acak, lalu menulis file konfigurasi phpMyAdmin
 *      ke /etc/phpmyadmin/conf.d/<token>.php yang berisi kredensial DB user itu.
 *   3. User diarahkan ke /pma/<token>/index.php.
 *   4. File konfigurasi dihapus otomatis setelah TTL.
 *
 * Dua lapis pengaman, keduanya wajib:
 *   - nginx hanya meneruskan request yang tokennya punya file konfigurasi.
 *     Tanpa file itu, jawabannya 404 walau tokennya benar.
 *   - file konfigurasi hanya berlaku bila REQUEST_URI memang memuat tokennya
 *     (dicek di dalam file itu sendiri). Jadi kalau file somehow tertinggal,
 *     file tersebut tidak memberi akses ke request lain.
 *
 * Password di sini adalah password user database milik user tersebut, yang
 * sudah dia sendiri yang buat di panel. Tidak ada privilege escalation:
 * hak akses ke satu database, dan hanya_db dikunci agar tidak bisa melihat
 * database lain di server yang sama.
 */
final class PhpMyAdmin
{
    /** phpMyAdmin memuat semua file *.php di direktori ini lewat glob. */
    private const CONF_DIR = '/etc/phpmyadmin/conf.d';

    public const URL_PREFIX = '/pma';

    /** Lama token berlaku. Cukup untuk satu sesi kerja. */
    public const TTL_MINUTES = 30;

    /** Server index phpMyAdmin yang dipakai. Dipaksa 1 supaya tidak bisa pindah. */
    private const SERVER_INDEX = 1;

    /**
     * Buat token + file konfigurasi, kembalikan URL tujuan.
     */
    public static function launch(string $username, int $dbId, int $dbUserId): array
    {
        $row = self::owned($username, $dbId, $dbUserId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database atau user database tidak ditemukan.'];
        }

        $cred = MariaDb::reveal($username, $dbId, $dbUserId);
        if (!$cred['ok']) {
            return ['ok' => false, 'error' => $cred['error']];
        }

        $dir = self::CONF_DIR;
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'error' => 'Direktori konfigurasi phpMyAdmin tidak tersedia.'];
        }

        // Bersihkan token kedaluwarsa dulu, supaya direktori tidak menumpuk.
        self::sweep();

        $token = bin2hex(random_bytes(16));          // 128 bit, tebak tidak mungkin
        $file  = $dir . '/' . $token . '.php';

        $conf = self::renderConfig($token, (string)$row['phys_user'],
            (string)$cred['password'], (string)$row['phys_db']);
        if (@file_put_contents($file, $conf, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Gagal menulis konfigurasi phpMyAdmin.'];
        }
        @chmod($file, 0640);

        // Landung di halaman daftar database. ?server=1 tanpa route
        // menghasilkan halaman kosong di phpMyAdmin 5.x.
        $url = self::URL_PREFIX . '/' . $token
             . '/index.php?route=/server/databases&server=' . self::SERVER_INDEX;
        return ['ok' => true, 'error' => null, 'url' => $url, 'token' => $token,
                'expires_in' => self::TTL_MINUTES];
    }

    /**
     * Hapus file token yang sudah lewat TTL.
     */
    public static function sweep(?int $ttlMinutes = null): int
    {
        $ttl  = ($ttlMinutes ?? self::TTL_MINUTES) * 60;
        $dir  = self::CONF_DIR;
        $gone = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $age = time() - (int)@filemtime($file);
            if ($age > $ttl) {
                if (@unlink($file)) {
                    $gone++;
                }
            }
        }
        return $gone;
    }

    public static function available(): bool
    {
        return is_dir(self::CONF_DIR) && is_readable(self::CONF_DIR)
            && is_dir('/usr/share/phpmyadmin');
    }

    /** Jumlah token yang masih hidup (untuk display/diagnostic). */
    public static function activeTokens(): int
    {
        $files = glob(self::CONF_DIR . '/*.php') ?: [];
        return count($files);
    }

    // ------------------------------------------------------------------ internal

    /**
     * Baris database + user yang dimiliki user tersebut, dengan nama fisik
     * ikut diambil. Null kalau bukan miliknya (IDOR).
     */
    private static function owned(string $username, int $dbId, int $dbUserId): ?array
    {
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($uid === 0) {
            return null;
        }
        $db = Connection::one(
            'SELECT id, name FROM databases WHERE id = :i AND user_id = :u',
            ['i' => $dbId, 'u' => $uid]);
        if ($db === null) {
            return null;
        }
        $du = Connection::one(
            'SELECT id, name FROM db_users WHERE id = :i AND database_id = :d',
            ['i' => $dbUserId, 'd' => (int)$db['id']]);
        if ($du === null) {
            return null;
        }
        return [
            'phys_db'   => MariaDb::physDb($username, (string)$db['name']),
            'phys_user' => MariaDb::physUser($username, (string)$du['name']),
        ];
    }

    /**
     * Isi file konfigurasi phpMyAdmin.
     *
     * Perhatikan bagian awal: file ini hanya berlaku untuk request yang
     * path-nya memuat token yang sama. Tanpa itu, file yang tertinggal
     * akan tetap memberi kredensial ke semua orang.
     */
    private static function renderConfig(string $token, string $user, string $pass, string $db): string
    {
        $i  = self::SERVER_INDEX;
        $tk = addslashes($token);
        $pw = addslashes($pass);

        $php = "<?php\n";
        $php .= "// FPanel: sesi phpMyAdmin sekali pakai, token {$tk}.\n";
        $php .= "// Berkas ini dihapus otomatis oleh PhpMyAdmin::sweep().\n";
        $php .= "// Hanya berlaku untuk request yang path-nya memuat token ini.\n";
        $php .= "\$__fpUri = (string) (\$_SERVER['REQUEST_URI'] ?? '');\n";
        $php .= "if (!preg_match('#/pma/([a-f0-9]{32})#', \$__fpUri, \$__fpM)\n";
        $php .= "    || !hash_equals('{$tk}', \$__fpM[1])) {\n";
        $php .= "    return;\n";
        $php .= "}\n";
        $php .= "\n";
        $php .= "\$cfg['Servers'][{$i}]['auth_type']      = 'config';\n";
        $php .= "\$cfg['Servers'][{$i}]['host']           = 'localhost';\n";
        $php .= "\$cfg['Servers'][{$i}]['user']           = '{$user}';\n";
        $php .= "\$cfg['Servers'][{$i}]['password']       = '{$pw}';\n";
        // Hanya ini yang membatasi tampilan: user DB-nya sendiri sudah tidak
        // punya hak atas database lain, dan only_db memastikan daftar yang
        // ditampilkan juga cuma satu.
        $php .= "\$cfg['Servers'][{$i}]['only_db']        = '{$db}';\n";
        $php .= "\$cfg['Servers'][{$i}]['AllowNoPassword'] = false;\n";
        // Config storage bawaan Debian tidak dipakai: tabel pma__* bukan
        // milik user ini, dan memakainya berarti panel ikut mengubahnya.
        $php .= "\$cfg['Servers'][{$i}]['pmadb']          = null;\n";
        $php .= "\$cfg['Servers'][{$i}]['controluser']    = null;\n";
        $php .= "\$cfg['Servers'][{$i}]['controlpass']    = null;\n";
        return $php;
    }
}