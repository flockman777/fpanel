<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Manajemen database & user MySQL/MariaDB.
 *
 * PHP tidak pernah speak langsung ke MariaDB sebagai root. Semua operasi
 * lewat helper root /usr/local/sbin/fpanel-dbadmin (lihat sudoers), yang
 * memvalidasi argumennya sendiri. Jadi kebocoran kredensial root MariaDB
 * tidak mungkin lewat file config, source, atau dump SQLite.
 *
 * Isolasi tenant: nama fisik selalu diawali username, jadi user tidak
 * mungkin menabrak atau membaca database milik user lain.
 */
final class MariaDb
{
    private const HELPER  = '/usr/local/sbin/fpanel-dbadmin';
    private const HOST    = 'localhost';
    private const CHARSET = 'utf8mb4';
    private const COLLATION = 'utf8mb4_unicode_ci';
    private const KEYFILE = '/etc/fpanel/app-key';

    /** Privilege default untuk user database (cukup untuk WordPress/dsb). */
    public const PRIVS = 'SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX,'
                      . 'REFERENCES,CREATE TEMPORARY TABLES,LOCK TABLES';

    /** Opsi yang boleh dipilih user di form (tidak termasuk ALL PRIVILEGES). */
    public const PRIV_CHOICES = [
        'READ ONLY'  => 'SELECT',
        'READ WRITE' => 'SELECT,INSERT,UPDATE,DELETE',
        'STANDAR'    => self::PRIVS,
    ];

    // ---------------------------------------------------------------- helper

    /**
     * Jalankan helper root.
     *
     * Password dikirim lewat STDIN, bukan argv: argv bisa dibaca proses
     * lain di mesin ini lewat `ps`.
     */
    private static function helper(array $args, ?string $stdin = null): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'Helper MariaDB tidak terpasang di server.'];
        }
        $cmd = array_merge(['sudo', '-n', self::HELPER], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Gagal menjalankan helper MariaDB.'];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin . "\n");
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $out = trim((string)$out);
        $err = trim((string)$err);
        if ($code !== 0) {
            // Helper memberi prefix "ERR: " pada pesan yang memang untuk ditampilkan ke user.
            $msg = $err !== '' ? $err : 'Operasi MariaDB gagal.';
            $msg = preg_replace('/^ERR:\s*/', '', $msg) ?: $msg;
            // Buang noise driver supaya pesan ke user tetap ringkas.
            $msg = preg_replace('/^(ERROR \d+ \(\w+\):.*)$/m', '', $msg) ?: $msg;
            return ['ok' => false, 'error' => trim($msg) ?: 'Operasi MariaDB gagal.'];
        }
        return ['ok' => true, 'error' => null, 'out' => $out];
    }

    // ------------------------------------------------------------- encryption

    /**
     * Kunci enkripsi simpan password user database.
     *
     * Password DB tidak bisa disimpan sebagai hash: PHP/WordPress butuh
     * password plaintext untuk konek. Jadi dienkripsi (sodium secretbox),
     * bukan di-hash. Kalau tidak, user tidak bisa pernah memakai databasenya.
     */
    private static function key(): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache !== '' ? $cache : null;
        }
        if (!is_readable(self::KEYFILE)) {
            $cache = '';
            return null;
        }
        $raw = trim((string)@file_get_contents(self::KEYFILE));
        $key = $raw !== '' ? (string)base64_decode($raw, true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $cache = '';
            return null;
        }
        return $cache = $key;
    }

    private static function encrypt(string $plain): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        // Nonce harus random per_enkripsi dan disimpan bersama ciphertext.
        // Nonce yang sama dengan pesan yang sama akan membocorkan isi.
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    private static function decrypt(string $blob): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $raw = base64_decode($blob, true);
        $min = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if ($raw === false || strlen($raw) < $min) {
            return null;
        }
        $nonce   = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain   = sodium_crypto_secretbox_open($payload, $nonce, $key);
        return $plain === false ? null : $plain;
    }

    // ------------------------------------------------------------------ nama

    /** Nama fisik database: <username>_<db>, dipotong ke 32 char (batas MySQL). */
    public static function physDb(string $username, string $name): string
    {
        $n = $username . '_' . $name;
        return strlen($n) > 32 ? substr($n, 0, 32) : $n;
    }

    /** Nama fisik user: <username>_<dbuser>, dipotong ke 16 char (batas MySQL). */
    public static function physUser(string $username, string $name): string
    {
        $n = $username . '_' . $name;
        return strlen($n) > 16 ? substr($n, 0, 16) : $n;
    }

    /** Nama logis yang ditampilkan ke user (tanpa prefix tenant). */
    public static function shortName(string $phys, string $username): string
    {
        $prefix = $username . '_';
        return str_starts_with($phys, $prefix) ? substr($phys, strlen($prefix)) : $phys;
    }

    // ----------------------------------------------------------------- kuota

    public static function quota(string $username): array
    {
        $row = Connection::one(
            'SELECT p.max_databases, p.max_db_users FROM users u
               LEFT JOIN packages p ON p.id = u.package_id WHERE u.username = :u',
            ['u' => $username]
        );
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        return [
            'user_id' => $uid,
            'max_db'    => (int)($row['max_databases'] ?? 0),
            'max_users' => (int)($row['max_db_users']  ?? 0),
            'used_db'   => (int)Connection::value(
                'SELECT COUNT(*) FROM databases WHERE user_id = :u', ['u' => $uid]),
            'used_users' => (int)Connection::value(
                'SELECT COUNT(*) FROM db_users WHERE database_id IN
                   (SELECT id FROM databases WHERE user_id = :u)', ['u' => $uid]),
        ];
    }

    // ------------------------------------------------------------------ buat

    /**
     * Buat satu database + satu user MySQL-nya dalam satu aksi.
     *
     * Urutannya penting: objek MariaDB dibuat DULU, baru dicatat di SQLite.
     * Kalau pencatatan gagal, objek yang sudah dibuat dibuang (rollback) supaya
     * tidak ada database yatim di server yang tidak terlihat panel.
     */
    public static function create(string $username, string $dbName, string $userName, string $privKey = 'STANDAR'): array
    {
        if (($e = Validator::username($username)) !== null) {
            return ['ok' => false, 'error' => $e];
        }
        // Sengaja TIDAK strtolower(): diam-diam mengubah "Toko" menjadi "toko"
        // membuat user mengira dia membuat database yang berbeda. Lebih baik
        // ditolak dengan pesan jelas, dan konsisten dengan pattern di form HTML.
        $dbName = trim($dbName);
        if (($e = Validator::dbName($dbName)) !== null) {
            return ['ok' => false, 'error' => $e];
        }
        if (($e = self::reservedName($dbName)) !== null) {
            return ['ok' => false, 'error' => $e];
        }
        $userName = trim($userName);
        if (($e = self::validDbUserName($userName)) !== null) {
            return ['ok' => false, 'error' => $e];
        }
        $privs = self::PRIV_CHOICES[$privKey] ?? null;
        if ($privs === null) {
            return ['ok' => false, 'error' => 'Level privilege tidak dikenal.'];
        }

        $q = self::quota($username);
        if ($q['user_id'] === 0) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
        }
        if ($q['max_db'] > 0 && $q['used_db'] >= $q['max_db']) {
            return ['ok' => false, 'error' => "Kuota database penuh ({$q['used_db']}/{$q['max_db']})."];
        }
        if ($q['max_users'] > 0 && $q['used_users'] >= $q['max_users']) {
            return ['ok' => false, 'error' => "Kuota user database penuh ({$q['used_users']}/{$q['max_users']})."];
        }

        // Nama logis harus unik per user; nama fisiknya sudah pasti unik
        // karena diawali username.
        $dup = Connection::value(
            'SELECT COUNT(*) FROM databases WHERE user_id = :u AND name = :n',
            ['u' => $q['user_id'], 'n' => $dbName]);
        if ((int)$dup > 0) {
            return ['ok' => false, 'error' => "Database {$dbName} sudah ada."];
        }

        $physDb   = self::physDb($username, $dbName);
        $physUser = self::physUser($username, $userName);
        $password = self::randomPassword();

        $r = self::helper(['create-db', $physDb, self::CHARSET, self::COLLATION]);
        if (!$r['ok']) {
            return $r;
        }
        $r = self::helper(['create-user', $physUser, self::HOST], $password);
        if (!$r['ok']) {
            self::helper(['drop-db', $physDb]);   // jangan tinggalkan db tanpa user
            return $r;
        }
        $r = self::helper(['grant', $physUser, self::HOST, $physDb, $privs]);
        if (!$r['ok']) {
            self::helper(['drop-user', $physUser, self::HOST]);
            self::helper(['drop-db', $physDb]);
            return $r;
        }

        $enc = self::encrypt($password);
        if ($enc === null) {
            // Tidak ada app-key: jangan simpan password yang tidak bisa
            // dibuka lagi. Undur semuanya.
            self::helper(['drop-user', $physUser, self::HOST]);
            self::helper(['drop-db', $physDb]);
            return ['ok' => false, 'error' => 'App key tidak tersedia, password tidak bisa disimpan aman.'];
        }

        try {
            $dbId = Connection::insert('databases', [
                'user_id'   => $q['user_id'],
                'name'      => $dbName,
                'quota_mb'  => 0,          // 0 = tanpa batas eksplisit
                'used_mb'   => 0,
                'charset'   => self::CHARSET,
            ]);
            Connection::insert('db_users', [
                'database_id'   => $dbId,
                'name'          => $userName,
                'password_hash' => $enc,      // ciphertext sodium, bukan hash
                'host'          => self::HOST,
                'privileges'    => $privs,
            ]);
        } catch (\Throwable $e) {
            self::helper(['drop-user', $physUser, self::HOST]);
            self::helper(['drop-db', $physDb]);
            return ['ok' => false, 'error' => 'Gagal menyimpan database: ' . $e->getMessage()];
        }

        return ['ok' => true, 'error' => null, 'db_id' => $dbId,
                'db' => $dbName, 'user' => $userName, 'password' => $password];
    }

    // ------------------------------------------------------------------ hapus

    /** Hapus database + semua user MySQL yang menempel padanya. */
    public static function delete(string $username, int $dbId): array
    {
        $row = self::owned($username, $dbId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database tidak ditemukan.'];
        }
        $physDb   = self::physDb($username, (string)$row['name']);
        $dbUserId = (int)$row['id'];

        // User MySQL harus dilepas dulu: kalau database dihapus sementara
        // grant-nya masih ada, MariaDB membiarkan grant yatim menggantung.
        $users = Connection::all('SELECT name, host FROM db_users WHERE database_id = :d',
            ['d' => $dbUserId]);
        foreach ($users as $u) {
            self::helper(['drop-user', self::physUser($username, (string)$u['name']),
                (string)($u['host'] ?: self::HOST)]);
        }

        $r = self::helper(['drop-db', $physDb]);
        if (!$r['ok']) {
            return $r;
        }
        Connection::run('DELETE FROM db_users WHERE database_id = :d', ['d' => $dbUserId]);
        Connection::run('DELETE FROM databases WHERE id = :i', ['i' => $dbUserId]);
        return ['ok' => true, 'error' => null];
    }

    // ------------------------------------------------------------------ user

    /** Tambah user MySQL tambahan untuk database yang sudah ada. */
    public static function addUser(string $username, int $dbId, string $userName, string $privKey = 'STANDAR'): array
    {
        $row = self::owned($username, $dbId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database tidak ditemukan.'];
        }
        $userName = trim($userName);
        if (($e = self::validDbUserName($userName)) !== null) {
            return ['ok' => false, 'error' => $e];
        }
        $privs = self::PRIV_CHOICES[$privKey] ?? null;
        if ($privs === null) {
            return ['ok' => false, 'error' => 'Level privilege tidak dikenal.'];
        }
        $q = self::quota($username);
        if ($q['max_users'] > 0 && $q['used_users'] >= $q['max_users']) {
            return ['ok' => false, 'error' => "Kuota user database penuh ({$q['used_users']}/{$q['max_users']})."];
        }
        $dup = Connection::value('SELECT COUNT(*) FROM db_users WHERE database_id = :d AND name = :n',
            ['d' => $dbId, 'n' => $userName]);
        if ((int)$dup > 0) {
            return ['ok' => false, 'error' => "User {$userName} sudah ada di database ini."];
        }

        $physDb   = self::physDb($username, (string)$row['name']);
        $physUser = self::physUser($username, $userName);
        $password = self::randomPassword();

        $r = self::helper(['create-user', $physUser, self::HOST], $password);
        if (!$r['ok']) {
            return $r;
        }
        $r = self::helper(['grant', $physUser, self::HOST, $physDb, $privs]);
        if (!$r['ok']) {
            self::helper(['drop-user', $physUser, self::HOST]);
            return $r;
        }
        $enc = self::encrypt($password);
        if ($enc === null) {
            self::helper(['drop-user', $physUser, self::HOST]);
            return ['ok' => false, 'error' => 'App key tidak tersedia.'];
        }
        try {
            Connection::insert('db_users', [
                'database_id'   => (int)$row['id'],
                'name'          => $userName,
                'password_hash' => $enc,
                'host'          => self::HOST,
                'privileges'    => $privs,
            ]);
        } catch (\Throwable $e) {
            self::helper(['drop-user', $physUser, self::HOST]);
            return ['ok' => false, 'error' => 'Gagal menyimpan user: ' . $e->getMessage()];
        }
        return ['ok' => true, 'error' => null, 'user' => $userName, 'password' => $password];
    }

    public static function deleteUser(string $username, int $dbId, int $dbUserId): array
    {
        $db = self::owned($username, $dbId);
        if ($db === null) {
            return ['ok' => false, 'error' => 'Database tidak ditemukan.'];
        }
        $row = Connection::one(
            'SELECT * FROM db_users WHERE id = :i AND database_id = :d',
            ['i' => $dbUserId, 'd' => (int)$db['id']]);
        if ($row === null) {
            return ['ok' => false, 'error' => 'User database tidak ditemukan.'];
        }
        // User terakhir tidak boleh dihapus: database-nya jadi tidak bisa
        // dipakai aplikasi sama sekali.
        $total = (int)Connection::value('SELECT COUNT(*) FROM db_users WHERE database_id = :d',
            ['d' => (int)$db['id']]);
        if ($total <= 1) {
            return ['ok' => false, 'error' => 'User terakhir tidak bisa dihapus. Hapus databasenya saja.'];
        }
        $r = self::helper(['drop-user',
            self::physUser($username, (string)$row['name']), (string)($row['host'] ?: self::HOST)]);
        if (!$r['ok']) {
            return $r;
        }
        Connection::run('DELETE FROM db_users WHERE id = :i', ['i' => $dbUserId]);
        return ['ok' => true, 'error' => null];
    }

    /** Password user database untuk ditampilkan sekali setelah dibuat. */
    public static function reveal(string $username, int $dbId, int $dbUserId): array
    {
        if (self::owned($username, $dbId) === null) {
            return ['ok' => false, 'error' => 'Database tidak ditemukan.'];
        }
        $row = Connection::one(
            'SELECT password_hash FROM db_users WHERE id = :i AND database_id = :d',
            ['i' => $dbUserId, 'd' => $dbId]);
        if ($row === null) {
            return ['ok' => false, 'error' => 'User database tidak ditemukan.'];
        }
        $plain = self::decrypt((string)$row['password_hash']);
        if ($plain === null) {
            return ['ok' => false, 'error' => 'Password tidak bisa dibuka (app key berubah?).'];
        }
        return ['ok' => true, 'error' => null, 'password' => $plain];
    }

    // ------------------------------------------------------------------ misc

    /** Baris database milik user tersebut, atau null. Mencegah IDOR. */
    private static function owned(string $username, int $dbId): ?array
    {
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($uid === 0) {
            return null;
        }
        return Connection::one('SELECT * FROM databases WHERE id = :i AND user_id = :u',
            ['i' => $dbId, 'u' => $uid]);
    }

    /** Update used_mb dari ukuran nyata di MariaDB. Dipanggil saat listing. */
    public static function refreshSize(string $username, array $databases): array
    {
        foreach ($databases as $d) {
            $phys = self::physDb($username, (string)$d['name']);
            $r = self::helper(['size', $phys]);
            if (!$r['ok']) {
                continue;
            }
            $bytes = (int)$r['out'];
            $mb    = (int)ceil($bytes / 1048576);
            if ($mb !== (int)$d['used_mb']) {
                Connection::run('UPDATE databases SET used_mb = :m WHERE id = :i',
                    ['m' => $mb, 'i' => (int)$d['id']]);
                $d['used_mb'] = $mb;
            }
        }
        return $databases;
    }

    public static function listing(string $username): array
    {
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($uid === 0) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
        }
        $dbs = Connection::all(
            'SELECT * FROM databases WHERE user_id = :u ORDER BY name', ['u' => $uid]);
        $dbs = self::refreshSize($username, $dbs);
        foreach ($dbs as $i => $d) {
            $dbs[$i]['users'] = Connection::all(
                'SELECT id, name, host, privileges FROM db_users WHERE database_id = :d ORDER BY name',
                ['d' => (int)$d['id']]);
            $dbs[$i]['server'] = self::HOST;
        }
        return ['ok' => true, 'error' => null, 'databases' => $dbs, 'quota' => self::quota($username)];
    }

    public static function health(): array
    {
        $r = self::helper(['ping']);
        return $r['ok']
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'MariaDB tidak bisa dijangkau: ' . $r['error']];
    }

    // ------------------------------------------------------------- internal

    /**
     * Nama database milik sistem MariaDB tidak boleh dipakai sebagai nama logis.
     *
     * Helper juga menyaring, tapi hanya nama fisik: "mysql" menjadi "admin_mysql"
     * sehingga lolos deny-list. Di sini nama logisnya yang ditolak, supaya user
     * tidak pernah melihat database bernama "mysql" di panelnya.
     */
    private static function reservedName(string $n): ?string
    {
        if (in_array(strtolower($n), ['mysql', 'information_schema', 'performance_schema', 'sys', 'test'], true)) {
            return "'{$n}' adalah nama database sistem, tidak boleh dipakai.";
        }
        return null;
    }

    /** Dicek lewat helper: apakah objek ini benar-benar ada di MariaDB server? */
    public static function serverExists(string $username, string $name): bool
    {
        $r = self::helper(['exists', self::physDb($username, $name)]);
        return $r['ok'] && trim($r['out']) === '1';
    }

    public static function serverHasUser(string $username, string $name): bool
    {
        $r = self::helper(['has-user', self::physUser($username, $name), self::HOST]);
        return $r['ok'] && trim($r['out']) === '1';
    }

    private static function validDbUserName(string $n): ?string
    {
        if ($n === '') {
            return 'Nama user database wajib diisi.';
        }
        // Batas 16 supaya <username>_<n> selalu muat di batas MySQL.
        if (!preg_match('/^[a-z0-9_]{1,16}$/', $n)) {
            return 'Nama user database: huruf kecil, angka, underscore, maks 16 karakter.';
        }
        return null;
    }

    /**
     * Password acak untuk user MySQL.
     *
     * Menghindari karakter yang perlu di-escape di SQL dan karakter yang
     * sering bikin salah salin saat dipaste ke wp-config.
     */
    private static function randomPassword(int $len = 20): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#%*?-_'];
        $all  = implode('', $sets);
        $pw   = '';
        // Jamin tiap kelas karakter muncul minimal sekali.
        foreach ($sets as $s) {
            $pw .= $s[random_int(0, strlen($s) - 1)];
        }
        for ($i = strlen($pw); $i < $len; $i++) {
            $pw .= $all[random_int(0, strlen($all) - 1)];
        }
        // Acak urutannya: pola "huruf besar-kecil-angka-simbol" yang rapi di
        // awal justru mudah ditebak.
        for ($i = strlen($pw) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$pw[$i], $pw[$j]] = [$pw[$j], $pw[$i]];
        }
        return $pw;
    }
}
