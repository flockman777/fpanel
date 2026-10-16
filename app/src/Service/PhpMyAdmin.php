<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Per-database phpMyAdmin with one-time tokens.
 *
 * How it works:
 *   1. The user clicks "phpMyAdmin" on one of their database users.
 *   2. The service generates a random token, then writes a phpMyAdmin config
 *      file to /etc/phpmyadmin/conf.d/<token>.php holding that DB user's credentials.
 *   3. The user is redirected to /pma/<token>/index.php.
 *   4. The config file is deleted automatically after the TTL.
 *
 * Two layers of protection, both required:
 *   - nginx only forwards requests whose token has a config file.
 *     Without that file the answer is 404, even with a valid token.
 *   - the config file only applies when REQUEST_URI really contains its token
 *     (checked inside the file itself). So if a file is somehow left behind,
 *     it does not grant access to any other request.
 *
 * The password here belongs to that user's own database user, one they
 * created themselves in the panel. There is no privilege escalation:
 * access is limited to a single database, and only_db is locked so other
 * databases on the same server cannot be seen.
 */
final class PhpMyAdmin
{
    /** phpMyAdmin loads every *.php file in this directory through glob. */
    private const CONF_DIR = '/etc/phpmyadmin/conf.d';

    public const URL_PREFIX = '/pma';

    /** How long a token lasts. Enough for one working session. */
    public const TTL_MINUTES = 30;

    /** phpMyAdmin server index in use. Forced to 1 so it cannot shift. */
    private const SERVER_INDEX = 1;

    /**
     * Create a token + config file, return the target URL.
     */
    public static function launch(string $username, int $dbId, int $dbUserId): array
    {
        $row = self::owned($username, $dbId, $dbUserId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Database or database user not found.'];
        }

        $cred = MariaDb::reveal($username, $dbId, $dbUserId);
        if (!$cred['ok']) {
            return ['ok' => false, 'error' => $cred['error']];
        }

        $dir = self::CONF_DIR;
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'error' => 'The phpMyAdmin config directory is not available.'];
        }

        // Clear expired tokens first so the directory does not pile up.
        self::sweep();

        $token = bin2hex(random_bytes(16));          // 128 bit, impossible to guess
        $file  = $dir . '/' . $token . '.php';

        $conf = self::renderConfig($token, (string)$row['phys_user'],
            (string)$cred['password'], (string)$row['phys_db']);
        if (@file_put_contents($file, $conf, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Failed to write the phpMyAdmin config.'];
        }
        @chmod($file, 0640);

        // Land on the database list page. ?server=1 without a route
        // produces a blank page in phpMyAdmin 5.x.
        $url = self::URL_PREFIX . '/' . $token
             . '/index.php?route=/server/databases&server=' . self::SERVER_INDEX;
        return ['ok' => true, 'error' => null, 'url' => $url, 'token' => $token,
                'expires_in' => self::TTL_MINUTES];
    }

    /**
     * Delete token files that are past their TTL.
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

    /** Number of live tokens (for display/diagnostics). */
    public static function activeTokens(): int
    {
        $files = glob(self::CONF_DIR . '/*.php') ?: [];
        return count($files);
    }

    // ------------------------------------------------------------------ internal

    /**
     * The database + user rows owned by this user, with physical names
     * included. Null when they do not own them (IDOR).
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
     * Contents of the phpMyAdmin config file.
     *
     * Note the opening section: this file only applies to requests whose
     * path contains the same token. Without it, a leftover file would hand
     * those credentials to everyone.
     */
    private static function renderConfig(string $token, string $user, string $pass, string $db): string
    {
        $i  = self::SERVER_INDEX;
        $tk = addslashes($token);
        $pw = addslashes($pass);

        $php = "<?php\n";
        $php .= "// FPanel: one-time phpMyAdmin session, token {$tk}.\n";
        $php .= "// This file is deleted automatically by PhpMyAdmin::sweep().\n";
        $php .= "// Only applies to requests whose path contains this token.\n";
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
        // This is the only thing limiting the view: the DB user itself has no
        // rights to other databases, and only_db keeps the shown list to just
        // one entry.
        $php .= "\$cfg['Servers'][{$i}]['only_db']        = '{$db}';\n";
        $php .= "\$cfg['Servers'][{$i}]['AllowNoPassword'] = false;\n";
        // Debian's default config storage is not used: the pma__* tables are not
        // this user's, and using them would mean the panel modifies them too.
        $php .= "\$cfg['Servers'][{$i}]['pmadb']          = null;\n";
        $php .= "\$cfg['Servers'][{$i}]['controluser']    = null;\n";
        $php .= "\$cfg['Servers'][{$i}]['controlpass']    = null;\n";
        return $php;
    }
}