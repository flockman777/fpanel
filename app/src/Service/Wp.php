<?php
declare(strict_types=1);

namespace Fpanel\Service;

use Fpanel\Database\Connection;
use Fpanel\Support\Audit;

/**
 * One-click WordPress installer.
 *
 * Flow: download core from wordpress.org, extract it into the domain docroot,
 * create the database + user through MariaDb, write wp-config.php (salts
 * generated on the server), then `wp core install` with WP-CLI.
 *
 * When any step fails, everything created so far is dropped so no half-
 * finished installation or orphan database is left behind.
 *
 * Architecture note: every domain is served by the www-data PHP-FPM pool, so
 * WordPress files must be readable by www-data. That is why WP-CLI runs as
 * the same user, without sudo.
 */
final class Wp
{
    /** Version cache in the settings table; 6 hours is enough for one day. */
    private const CACHE_KEY    = 'wp_versions';
    private const CACHE_TTL    = 21600;
    private const API_VERSIONS = 'https://api.wordpress.org/core/version-check/1.7/';
    private const DOWNLOAD     = 'https://downloads.wordpress.org/release/wordpress-%s.zip';

    /** Download limit: the WP core zip is about 25-30 MB. */
    private const MAX_ZIP_BYTES = 90 * 1024 * 1024;

    // The WP core download is about 37 MB. Speed from this server can drop to
    // ~150 KB/s (measured 245 seconds for 37 MB), so a 5-minute limit is too
    // small. The installer runs in the background without waiting for a user,
    // so a loose timeout disturbs nothing.
    private const TIMEOUT_SEC   = 1800;

    /** Installation status (JSON per job) so the UI can show progress. */
    private const JOB_DIR       = '/var/lib/fpanel/wp';

    /** Download speed limit: below 12 KB/s for 45 seconds = stalled. */
    private const LOW_SPEED_LIMIT = 12288;
    private const LOW_SPEED_TIME  = 45;

    /** How many download attempts before giving up. */
    private const DL_ATTEMPTS = 3;

    /** Core zip cache per version, so a second install does not re-download. */
    private const CACHE_DIR = '/var/cache/fpanel/wp';

    // ------------------------------------------------------------------ public

    /**
     * List of WP versions that can be chosen, newest first.
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
        // Without API access, use the built-in list so the feature still works.
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
     * All WP installations owned by the user, complete with their domain names.
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
     * Start a WordPress installation in the background.
     *
     * Installation must run outside the HTTP request: downloading core alone
     * can take 25-60 seconds, while the FPM pool allows 30. This request only
     * validates and then spawns the job; the CLI (with no PHP time limit)
     * does the download-extract-install work.
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
            return self::err('Domain not found.');
        }
        if ((string)$domain['type'] !== 'php') {
            return self::err("Domain {$domainName} is of type " . (string)$domain['type']
                . '. WordPress needs a PHP-type domain.');
        }

        $docroot = (string)$domain['docroot'];
        if (!self::safeDocroot($docroot, $username)) {
            return self::err('The domain docroot is not valid.');
        }

        // Package quota.
        $q = self::quota($username);
        $used = (int)Connection::value(
            'SELECT COUNT(*) FROM wp_sites w JOIN users u ON u.id = w.user_id WHERE u.username = :u',
            ['u' => $username]
        );
        if ($used >= $q['max_wp']) {
            return self::err("WordPress quota is full ({$used}/{$q['max_wp']}).");
        }

        if ($adminUser === '' || preg_match('/^[A-Za-z0-9 ._@\-]{3,60}$/', $adminUser) !== 1) {
            return self::err('Invalid WordPress admin name (3-60 characters).');
        }
        if (strlen($adminPass) < 8 || strlen($adminPass) > 72) {
            return self::err('The WordPress admin password must be at least 8 characters.');
        }
        if ($adminMail === '' || filter_var($adminMail, FILTER_VALIDATE_EMAIL) === false) {
            return self::err('Invalid WordPress admin email.');
        }
        if ($title === '') {
            $title = (string)$domainName;
        }

        if (is_file($docroot . '/wp-load.php')) {
            return self::err('This domain already has WordPress. Delete it through the File Manager to install again.');
        }

        // Version: use the requested one when listed, otherwise the newest.
        $known = self::versions();
        if ($version === '' || !in_array($version, $known, true)) {
            $version = $known[0] ?? 'latest';
        }

        $job = self::newJob($username, $domainName);
        self::writeJob($job, [
            'state'   => 'running',
            'step'    => 'waiting',
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
            'message' => "WordPress installation to $domainName started in the background. This page refreshes automatically.",
            'job'     => $job,
        ];
    }

    /**
     * The heavy installation work. Called from the CLI, not from an HTTP request.
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
            self::writeJob($job, ['state' => 'error', 'step' => 'domain not found']);
            return self::err('Domain not found.');
        }
        $docroot = (string)$domain['docroot'];
        if (!self::safeDocroot($docroot, $username)) {
            self::writeJob($job, ['state' => 'error', 'step' => 'docroot not valid']);
            return self::err('Invalid docroot.');
        }

        self::writeJob($job, ['step' => 'creating the database']);
        $suffix  = bin2hex(random_bytes(5));
        $dbName  = 'wp_' . $suffix;
        $dbUser  = 'wpu_' . $suffix;
        $created = MariaDb::create($username, $dbName, $dbUser);
        if (empty($created['ok'])) {
            $msg = 'Failed to create the database: ' . (string)($created['error'] ?? 'no message');
            self::writeJob($job, ['state' => 'error', 'step' => $msg, 'finished' => gmdate('Y-m-d H:i:s')]);
            return self::err($msg);
        }
        $dbId = (int)($created['db_id'] ?? 0);
        // The password MUST come from the create() result. MariaDb generates
        // and stores its own password; one made up here would never be used,
        // so wp-config.php could not connect.
        $dbPass = (string)($created['password'] ?? '');
        // MariaDb::create() returns the SHORT NAME, while the root helper
        // creates the user with the panel user prefix (e.g. admin_wpu_abc123).
        // wp-config.php must use the physical name, otherwise MariaDB replies
        // "Access denied for user 'wpu_abc123'@'localhost'".
        $dbUserReal = MariaDb::physUser($username, $dbUser);
        // Like users, the database name in MariaDB also gets the panel user
        // prefix, so wp-config.php must use the physical name.
        $dbNameReal = MariaDb::physDb($username, $dbName);
        if ($dbPass === '') {
            $msg = 'The database password is not available from MariaDb.';
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
            $msg = 'Installation failed: ' . $e->getMessage();
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
            'step'     => "WordPress $installed installed",
            'finished' => gmdate('Y-m-d H:i:s'),
        ]);
        self::pruneJobs();

        return ['ok' => true, 'error' => null, 'message' => "WordPress $installed installed on $domainName."];
    }

    // ------------------------------------------------------------- job status

    /** The last job of the user, for progress in the UI. */
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

    /** Drop old jobs so the folder does not pile up. */
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
     * Run the installer CLI in the background, detached from the HTTP session.
     *
     * setsid is used so the process does not die when the FPM worker finishes.
     * The payload is base64url-encoded so it contains no shell characters.
     */
    /**
     * Decode the base64url payload sent by spawn().
     *
     * base64_decode() with strict=true rejects lengths that are not multiples
     * of 4, while the payload deliberately trims its padding. The padding is
     * added back here first.
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

    // ------------------------------------------------------------------ manage

    /**
     * Summary of one installation for the "Manage" panel.
     *
     * @return array{ok:bool,error?:string,data?:array<string,mixed>}
     */
    public static function overview(string $username, int $id): array
    {
        $w = self::own($username, $id);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Installation not found.'];
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
     * Update the WordPress core of a given installation.
     *
     * Runs through spawn because wp core update can download files and the
     * FPM pool only allows 30 seconds. The job itself is reported through
     * latestJob(), so the UI can show progress like during an installation.
     */
    public static function startUpdate(string $username, int $id, string $parts = 'core,plugins,themes'): array
    {
        $w = self::own($username, $id);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Installation not found.'];
        }
        $allowed = ['core', 'plugins', 'themes'];
        $pick    = array_values(array_intersect(explode(',', $parts), $allowed));
        if ($pick === []) {
            return ['ok' => false, 'error' => 'Nothing to update.'];
        }
        $job = self::newJob($username, (string)$w['domain_name']);
        self::writeJob($job, [
            'state'   => 'running',
            'step'    => 'Preparing the update of ' . implode(', ', $pick) . '…',
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
                'message' => 'Update of ' . implode(', ', $pick)
                    . ' scheduled for ' . (string)$w['domain_name'] . '.'];
    }

    /**
     * Execute the update; called from the CLI, not from HTTP.
     */
    public static function runUpdate(array $args): array
    {
        $job     = (int)($args['job'] ?? 0);
        $docroot = (string)($args['docroot'] ?? '');
        $parts   = array_map('trim', explode(',', (string)($args['parts'] ?? '')));
        if ($job < 1 || $docroot === '' || !is_dir($docroot)) {
            return ['ok' => false, 'error' => 'Incomplete update arguments.'];
        }

        $labels = [
            'core'    => 'Updating WordPress core',
            'plugins' => 'Updating plugins',
            'themes'  => 'Updating themes',
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
                    'step'     => $labels[$p] . ' FAILED',
                    'finished' => gmdate('Y-m-d H:i:s'),
                    'error'    => substr($out, 0, 500),
                ]);
                return ['ok' => false, 'error' => $labels[$p] . ' failed: ' . substr($out, 0, 300)];
            }
            $done[] = $p;
        }

        $core = self::installedCore($docroot) ?? '-';
        self::writeJob($job, [
            'state'    => 'done',
            'step'     => 'Update finished. Core is now ' . $core . '.',
            'finished' => gmdate('Y-m-d H:i:s'),
        ]);
        if (isset($args['site_id'])) {
            Connection::update('wp_sites', ['core_version' => $core], 'id = :i',
                ['i' => (int)$args['site_id']]);
        }
        return ['ok' => true, 'error' => null, 'message' => 'Update finished. Core ' . $core . '.'];
    }

    /**
     * Change the staging marker for one installation.
     *
     * Only changes the label in the panel; it moves no files or databases.
     * Real production/staging separation lives in the separate domains and
     * databases, not in this flag.
     */
    public static function setStaging(string $username, int $id, bool $staging): array
    {
        $w = self::own($username, $id);
        if ($w === null) {
            return ['ok' => false, 'error' => 'Installation not found.'];
        }
        Connection::update('wp_sites', ['is_staging' => $staging ? 1 : 0], 'id = :i',
            ['i' => (int)$w['id']]);
        return ['ok' => true, 'error' => null,
                'message' => ($staging ? 'Marked as staging.' : 'Marked as production.')];
    }

    /**
     * Delete the installation record (files and database are not deleted).
     *
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function forget(string $username, int $id): array
    {
        $row = self::own($username, $id);
        if ($row === null) {
            return self::err('Installation not found.');
        }
        Connection::run('DELETE FROM wp_sites WHERE id = :i', ['i' => $id]);
        Audit::log('wp.forget', (int)$row['user_id'], $username, 'wp_site', (string)$id, '-', 'ok', []);
        return ['ok' => true, 'error' => null, 'message' => 'Installation removed from the list. Files and database were not deleted.'];
    }

    /** The core version really installed, read from the WP file. */
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
            return ['ok' => false, 'error' => "Docroot is not writable: $docroot"];
        }

        $zipUrl = $version === 'latest'
            ? 'https://wordpress.org/latest.zip'
            : sprintf(self::DOWNLOAD, $version);

        $tmp = tempnam(sys_get_temp_dir(), 'wp');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'Could not create a temporary file.'];
        }
        try {
                // Use the cache when present: a 37 MB download on this server can
            // take 4-5 minutes, and the version is usually the same.
            $dl = ['code' => 0, 'error' => 'not attempted'];
            $cached = self::cachedZip($version);
            if ($cached !== null) {
                $say('using core from the local cache');
                @copy($cached, $tmp);
                $dl = ['code' => is_file($tmp) ? 200 : 0, 'error' => ''];
            }

            // The connection to the WordPress CDN sometimes STALLS midway
            // (looks stuck at 55%). Without a speed limit, curl waits for the
            // full 30-minute timeout. So: abort when it slows down, then retry.
            for ($attempt = 1; $attempt <= self::DL_ATTEMPTS; $attempt++) {
                if ((int)($dl['code'] ?? 0) === 200) {
                    break;
                }
                $say('downloading WordPress core'
                    . ($attempt > 1 ? " (attempt $attempt)" : ''));
                @unlink($tmp);
                    $dl = self::download($zipUrl, $tmp, $say);
                if ((int)$dl['code'] === 200) {
                    break;
                }
                $say('download failed, trying again…');
                sleep(3);
            }
            if ((int)$dl['code'] !== 200) {
                return ['ok' => false, 'error' => "WordPress core download failed: " . (string)$dl['error']];
            }
            self::storeZip($version, $tmp);
            $size = (int)filesize($tmp);
            if ($size < 1024 * 1024) {
                return ['ok' => false, 'error' => 'The downloaded zip is too small; it may not be the WordPress core.'];
            }
            if ($size > self::MAX_ZIP_BYTES) {
                return ['ok' => false, 'error' => 'The zip file is too large.'];
            }

            $say('extracting core');
            $extract = self::extract($tmp, $docroot);
            if (!$extract['ok']) {
                return ['ok' => false, 'error' => (string)$extract['error']];
            }

            $say('writing wp-config.php');
            self::writeConfig($docroot, $dbName, $dbUser, $dbPass);

            $say('running wp core install');

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
                return ['ok' => false, 'error' => 'wp core install failed: ' . self::firstLine($out)];
            }

            $installed = self::installedCore($docroot) ?? $version;
            return ['ok' => true, 'installed_version' => $installed];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Download core to $target.
     *
     * @return array{code: int, error: string} HTTP code and curl error message
     */
    private static function download(string $url, string $target, ?callable $progress = null): array
    {
        if (!function_exists('curl_init')) {
            return 0;
        }
        // CURLOPT_FILE wants a HANDLE, not a string path. Given a path, curl
        // fails with "supplied argument is not a valid File-Handle".
        $fh = @fopen($target, 'wb');
        if ($fh === false) {
            return ['code' => 0, 'error' => 'could not create the download file'];
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
            // Speed below the threshold for N seconds = the transfer counts as
            // stalled and fails, so the next attempt can run.
            CURLOPT_LOW_SPEED_LIMIT  => self::LOW_SPEED_LIMIT,
            CURLOPT_LOW_SPEED_TIME   => self::LOW_SPEED_TIME,
        ]);
        if (is_callable($progress)) {
            // Progress is called very often; only send when the percentage
            // changes so the status file is not written thousands of times.
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
                static function ($_ch, $dlTotal, $dlNow) use ($progress, &$lastPct): int {
                    if ($dlTotal > 0) {
                        $pct = (int)floor(($dlNow / $dlTotal) * 100);
                        if ($pct !== $lastPct && $pct % 5 === 0) {
                            $lastPct = $pct;
                            $progress("downloading WordPress core $pct%");
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
                // curl error 28 = timeout. This happened because a 5-minute
                // limit is too small for 37 MB at low speed.
                return ['code' => 0, 'error' => $emsg !== '' ? $emsg . ' (curl ' . $err . ')' : 'curl error ' . $err];
            }
            return ['code' => $code, 'error' => 'HTTP ' . $code];
        }
        return ['code' => 200, 'error' => ''];
    }

    /**
     * Extract the zip and move the contents of the wordpress/ folder into the docroot.
     *
     * @return array{ok: bool, error?: string}
     */
    private static function extract(string $zip, string $docroot): array
    {
        $tmpDir = sys_get_temp_dir() . '/wpx-' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0755, true)) {
            return ['ok' => false, 'error' => 'The temporary folder could not be created.'];
        }

        // The installer runs in PHP CLI, and the zip extension is not necessarily
        // active there (on this server zip is only loaded for FPM). So the unzip
        // binary is used first, with ZipArchive only as a fallback.
        $unzip = self::which('unzip');
        if ($unzip !== null) {
            $cmd = [$unzip, '-q', '-o', $zip, '-d', $tmpDir];
            $rc  = self::runQuiet($cmd, $tmpDir);
            if ($rc !== 0) {
                self::rmTree($tmpDir);
                return ['ok' => false, 'error' => 'WordPress core extraction failed (unzip).'];
            }
        } elseif (class_exists(\ZipArchive)) {
            $za = new \ZipArchive();
            if ($za->open($zip) !== true) {
                @rmdir($tmpDir);
                return ['ok' => false, 'error' => 'The WordPress zip file cannot be opened.'];
            }
            $ok = $za->extractTo($tmpDir);
            $za->close();
            if ($ok !== true) {
                self::rmTree($tmpDir);
                return ['ok' => false, 'error' => 'WordPress core extraction failed.'];
            }
        } else {
            @rmdir($tmpDir);
            return ['ok' => false, 'error' => 'Neither unzip nor the ZipArchive extension is available.'];
        }

        $src = $tmpDir . '/wordpress';
        if (!is_dir($src)) {
            // Some zips have no top-level folder; use the working directory contents.
            $src = $tmpDir;
        }
        if (!self::copyTree($src, $docroot)) {
            self::rmTree($tmpDir);
            return ['ok' => false, 'error' => 'Moving the WordPress files into the docroot failed.'];
        }
        self::rmTree($tmpDir);
        return ['ok' => true];
    }

    /** Write wp-config.php with DB credentials and salts generated on the server. */
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
            . " * wp-config.php is generated automatically by FPanel. Do not edit manually.\n"
            . " *\n"
            . " * The database credentials are stored in this file, just like other\n"
            . " * hosting panels. Never put this file into a public backup.\n"
            . " */\n"
            . "define('DB_NAME', '" . $dbName . "');\n"
            . "define('DB_USER', '" . $dbUser . "');\n"
            . "define('DB_PASSWORD', '" . $dbPass . "');\n"
            . "define('DB_HOST', 'localhost');\n"
            . "define('DB_CHARSET', 'utf8mb4');\n"
            . "define('DB_COLLATE', '');\n\n"
            . $defs . "\n"
            // Without $table_prefix, wp-settings.php fails with
            // "Undefined variable $table_prefix" and the install stops.
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

    /** Run WP-CLI with an argument array (no shell). */
    private static function wpCli(array $args): array
    {
        $cmd = array_merge(['/usr/local/bin/wp'], $args);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes, '/var/www');
        if (!is_resource($proc)) {
            return [1, 'failed to run wp-cli'];
        }
        @fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        return [proc_close($proc), trim($out . "\n" . $err)];
    }

    /** Drop the leftovers of a failed installation: WP files and the database. */
    private static function cleanup(string $username, int $dbId, string $docroot): void
    {
        if ($dbId > 0) {
            MariaDb::delete($username, $dbId);
        }
        // Only WordPress core files are deleted; other users' files in the
        // docroot must not be touched.
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

    /** The docroot must be inside /var/www/<user>/ so it cannot escape into another user's files. */
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

    /** 64 characters from a character set safe for PHP constants. */
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
        return substr((string)($lines[0] ?? 'no message'), 0, 200);
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

    /** Find a binary in PATH. */
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

    /** Run a command without output; return the exit code. */
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