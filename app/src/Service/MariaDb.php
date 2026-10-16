<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * MySQL/MariaDB database & user management.
 *
 * PHP never talks to MariaDB directly as root. All operations go through
 * the root helper /usr/local/sbin/fpanel-dbadmin (see sudoers), which
 * validates its own arguments. That way the MariaDB root credentials cannot
 * leak through a config file, the source, or a SQLite dump.
 *
 * Tenant isolation: physical names always start with the username, so a user
 * can never collide with or read another user's database.
 */
final class MariaDb
{
    private const HELPER  = '/usr/local/sbin/fpanel-dbadmin';
    private const HOST    = 'localhost';
    private const CHARSET = 'utf8mb4';
    private const COLLATION = 'utf8mb4_unicode_ci';
    private const KEYFILE = '/etc/fpanel/app-key';

    /** Default privileges for a database user (enough for WordPress etc.). */
    public const PRIVS = 'SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,ALTER,INDEX,'
                      . 'REFERENCES,CREATE TEMPORARY TABLES,LOCK TABLES';

    /** Options the user may pick in the form (ALL PRIVILEGES excluded). */
    public const PRIV_CHOICES = [
        'READ ONLY'  => 'SELECT',
        'READ WRITE' => 'SELECT,INSERT,UPDATE,DELETE',
        'STANDARD'   => self::PRIVS,
    ];

    // ---------------------------------------------------------------- helper

    /**
     * Run the root helper.
     *
     * Passwords go through STDIN, not argv: argv can be read by other
     * processes on this machine through `ps`.
     */
    private static function helper(array $args, ?string $stdin = null): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'The MariaDB helper is not installed on the server.'];
        }
        $cmd = array_merge(['sudo', '-n', self::HELPER], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc  = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Failed to run the MariaDB helper.'];
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
            // The helper prefixes messages meant for the user with "ERR: ".
            $msg = $err !== '' ? $err : 'The MariaDB operation failed.';
            $msg = preg_replace('/^ERR:\s*/', '', $msg) ?: $msg;
            // Drop driver noise so the message to the user stays concise.
            $msg = preg_replace('/^(ERROR \d+ \(\w+\):.*)$/m', '', $msg) ?: $msg;
            return ['ok' => false, 'error' => trim($msg) ?: 'The MariaDB operation failed.'];
        }
        return ['ok' => true, 'error' => null, 'out' => $out];
    }

    // ------------------------------------------------------------- encryption

    /**
     * Encryption key for stored database user passwords.
     *
     * A DB password cannot be stored as a hash: PHP/WordPress need the plain
     * text password to connect. So it is encrypted (sodium secretbox), not
     * hashed. Otherwise the user could never use their database.
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
        // The nonce must be random per encryption and stored with the ciphertext.
        // The same nonce with the same message leaks the contents.
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

    // ------------------------------------------------------------------ name

    /** Physical database name: <username>_<db>, cut to 32 chars (MySQL limit). */
    public static function physDb(string $username, string $name): string
    {
        $n = $username . '_' . $name;
        return strlen($n) > 32 ? substr($n, 0, 32) : $n;
    }

    /** Physical user name: <username>_<dbuser>, cut to 16 chars (MySQL limit). */
    public static function physUser(string $username, string $name): string
    {
        $n = $username . '_' . $name;
        return strlen($n) > 16 ? substr($n, 0, 16) : $n;
    }

    /** Logical name shown to the user (without the tenant prefix). */
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

    // ------------------------------------------------------------------ create

    /**
     * Create one database + one MySQL user in a single action.
     *
     * Order matters: the MariaDB objects are created FIRST, then recorded in
     * SQLite. If the recording fails, the created objects are dropped
     * (rollback) so no orphan database is left unseen by the panel.
     */
    public static function create(string $username, string $dbName, string $userName, string $privKey = 'STANDARD'): array
    {
        if (($e = Validator::username($username)) !== null) {
            return ['ok' => false, 'error' => $e];
        }
        // Deliberately NOT strtolower(): silently turning "Toko" into "toko"
        // makes the user think they created a different database. Better to
        // reject it with a clear message, consistent with the HTML form pattern.
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
            return ['ok' => false, 'error' => 'Unknown privilege level.'];
        }

        $q = self::quota($username);
        if ($q['user_id'] === 0) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        if ($q['max_db'] > 0 && $q['used_db'] >= $q['max_db']) {
            return ['ok' => false, 'error' => "Database quota is full ({$q['used_db']}/{$q['max_db']})."];
        }
        if ($q['max_users'] > 0 && $q['used_users'] >= $q['max_users']) {
            return ['ok' => false, 'error' => "Database user quota is full ({$q['used_users']}/{$q['max_users']})."];
        }

        // The logical name must be unique per user; the physical name is certainly
        // unique because it starts with the username.
        $dup = Connection::value(
            'SELECT COUNT(*) FROM databases WHERE user_id = :u AND name = :n',
            ['u' => $q['user_id'], 'n' => $dbName]);
        if ((int)$dup > 0) {
            return ['ok' => false, 'error' => "Database {$dbName} already exists."];
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
            self::helper(['drop-db', $physDb]);   // do not leave a database without a user
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
            // No app-key: do not store a password that can never be opened
            // again. Roll everything back.
            self::helper(['drop-user', $physUser, self::HOST]);
            self::helper(['drop-db', $physDb]);
            return ['ok' => false, 'error' => 'App key unavailable; the password cannot be stored safely.'];
        }

        try {
            $dbId = Connection::insert('databases', [
                'user_id'   => $q['user_id'],
                'name'      => $dbName,
                'quota_mb'  => 0,          // 0 = no explicit limit
                'used_mb'   => 0,
                'charset'   => self::CHARSET,
            ]);
            Connection::insert('db_users', [
                'database_id'   => $dbId,
                'name'          => $userName,
                'password_hash' => $enc,      // sodium ciphertext, not a hash
                'host'          => self::HOST,
                'privileges'    => $privs,
            ]);
        } catch (\Throwable $e) {
            self::helper(['drop-user', $physUser, self::HOST]);
            self::helper(['drop-db', $physDb]);
            return ['ok' => false, 'error' => 'Failed to save the database: ' . $e->getMessage()];
        }

        return ['ok' => true, 'error' => null, 'db_id' => $dbId,
                'db' => $dbName, 'user' => $userName, 'password' => $password];
    }

    // ------------------------------------------------------------------ delete

    /** Delete a database + every MySQL user attached to it. */
    public static function delete(string $username, int $dbId): array
    {
        $row = self::owned($username, $dbId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database not found.'];
        }
        $physDb   = self::physDb($username, (string)$row['name']);
        $dbUserId = (int)$row['id'];

        // MySQL users must be removed first: if the database is dropped while its
        // grants still exist, MariaDB leaves the grant orphaned.
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

    /** Add an extra MySQL user to an existing database. */
    public static function addUser(string $username, int $dbId, string $userName, string $privKey = 'STANDARD'): array
    {
        $row = self::owned($username, $dbId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database not found.'];
        }
        $userName = trim($userName);
        if (($e = self::validDbUserName($userName)) !== null) {
            return ['ok' => false, 'error' => $e];
        }
        $privs = self::PRIV_CHOICES[$privKey] ?? null;
        if ($privs === null) {
            return ['ok' => false, 'error' => 'Unknown privilege level.'];
        }
        $q = self::quota($username);
        if ($q['max_users'] > 0 && $q['used_users'] >= $q['max_users']) {
            return ['ok' => false, 'error' => "Database user quota is full ({$q['used_users']}/{$q['max_users']})."];
        }
        $dup = Connection::value('SELECT COUNT(*) FROM db_users WHERE database_id = :d AND name = :n',
            ['d' => $dbId, 'n' => $userName]);
        if ((int)$dup > 0) {
            return ['ok' => false, 'error' => "User {$userName} already exists on this database."];
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
            return ['ok' => false, 'error' => 'App key unavailable.'];
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
            return ['ok' => false, 'error' => 'Failed to save the user: ' . $e->getMessage()];
        }
        return ['ok' => true, 'error' => null, 'user' => $userName, 'password' => $password];
    }

    public static function deleteUser(string $username, int $dbId, int $dbUserId): array
    {
        $db = self::owned($username, $dbId);
        if ($db === null) {
            return ['ok' => false, 'error' => 'Database not found.'];
        }
        $row = Connection::one(
            'SELECT * FROM db_users WHERE id = :i AND database_id = :d',
            ['i' => $dbUserId, 'd' => (int)$db['id']]);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database user not found.'];
        }
        // The last user may not be deleted: otherwise the database could not be
        // used by any application at all.
        $total = (int)Connection::value('SELECT COUNT(*) FROM db_users WHERE database_id = :d',
            ['d' => (int)$db['id']]);
        if ($total <= 1) {
            return ['ok' => false, 'error' => 'The last user cannot be deleted. Delete the whole database instead.'];
        }
        $r = self::helper(['drop-user',
            self::physUser($username, (string)$row['name']), (string)($row['host'] ?: self::HOST)]);
        if (!$r['ok']) {
            return $r;
        }
        Connection::run('DELETE FROM db_users WHERE id = :i', ['i' => $dbUserId]);
        return ['ok' => true, 'error' => null];
    }

    /** Database user password, shown once right after creation. */
    public static function reveal(string $username, int $dbId, int $dbUserId): array
    {
        if (self::owned($username, $dbId) === null) {
            return ['ok' => false, 'error' => 'Database not found.'];
        }
        $row = Connection::one(
            'SELECT password_hash FROM db_users WHERE id = :i AND database_id = :d',
            ['i' => $dbUserId, 'd' => $dbId]);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database user not found.'];
        }
        $plain = self::decrypt((string)$row['password_hash']);
        if ($plain === null) {
            return ['ok' => false, 'error' => 'The password cannot be opened (app key changed?).'];
        }
        return ['ok' => true, 'error' => null, 'password' => $plain];
    }

    // ------------------------------------------------------------------ misc

    /** The user's own database row, or null. Prevents IDOR. */
    private static function owned(string $username, int $dbId): ?array
    {
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($uid === 0) {
            return null;
        }
        return Connection::one('SELECT * FROM databases WHERE id = :i AND user_id = :u',
            ['i' => $dbId, 'u' => $uid]);
    }

    /** Update used_mb from the real size in MariaDB. Called during listing. */
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
            return ['ok' => false, 'error' => 'User not found.'];
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
            : ['ok' => false, 'error' => 'MariaDB cannot be reached: ' . $r['error']];
    }

    // ------------------------------------------------------------- internal

    /**
     * Names of MariaDB system databases may not be used as logical names.
     *
     * The helper filters too, but only physical names: "mysql" becomes
     * "admin_mysql" and thus escapes the deny-list. Here the logical name is
     * rejected so a user never sees a database called "mysql" in their panel.
     */
    private static function reservedName(string $n): ?string
    {
        if (in_array(strtolower($n), ['mysql', 'information_schema', 'performance_schema', 'sys', 'test'], true)) {
            return "'{$n}' is a system database name and may not be used.";
        }
        return null;
    }

    /** Checked through the helper: does this object really exist on the MariaDB server? */
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
            return 'Database user name is required.';
        }
        // A 16-char limit so <username>_<n> always fits the MySQL limit.
        if (!preg_match('/^[a-z0-9_]{1,16}$/', $n)) {
            return 'Database user name: lowercase letters, digits, underscore, max 16 characters.';
        }
        return null;
    }

    /**
     * Random password for MySQL users.
     *
     * Avoids characters that need escaping in SQL and characters that are
     * often mistyped when pasted into wp-config.
     */
    private static function randomPassword(int $len = 20): string
    {
        $sets = ['abcdefghijkmnopqrstuvwxyz', 'ABCDEFGHJKLMNPQRSTUVWXYZ', '23456789', '!@#%*?-_'];
        $all  = implode('', $sets);
        $pw   = '';
        // Guarantee each character class appears at least once.
        foreach ($sets as $s) {
            $pw .= $s[random_int(0, strlen($s) - 1)];
        }
        for ($i = strlen($pw); $i < $len; $i++) {
            $pw .= $all[random_int(0, strlen($all) - 1)];
        }
        // Shuffle the order: a tidy "upper-lower-digit-symbol" pattern at the
        // start is ironically easy to guess.
        for ($i = strlen($pw) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$pw[$i], $pw[$j]] = [$pw[$j], $pw[$i]];
        }
        return $pw;
    }
}
