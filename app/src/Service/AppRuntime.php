<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * App runtime per domain: Node.js and Python.
 *
 * Both runtimes run as their own process behind nginx, rather than
 * through a PHP pool: Node uses `node entry`, Python uses gunicorn. One
 * app = one systemd unit (fpanel-app-<name>.service), so start/stop/status
 * are unambiguous and the app comes back up automatically after a reboot.
 *
 * Apps do not grab arbitrary ports: ports are allocated from a single
 * range recorded in the table, so they never clash with nginx/panel/MariaDB
 * and two apps never get the same port.
 *
 * nginx for a node/python domain points at that app's port
 * (Vhost::render), with WebSocket support for Node.
 */
final class AppRuntime
{
    /** Port range for apps. Far from 80/443/8090/3306 and not used by any other process. */
    public const PORT_MIN = 30000;
    public const PORT_MAX = 30999;

    // ------------------------------------------------------------------ listing

    /** All apps owned by one user. */
    public static function listing(string $username): array
    {
        $uid = self::userId($username);
        if ($uid === 0) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        $apps = Connection::all(
            'SELECT a.*, COALESCE(d.name, "") AS domain_name
               FROM runtime_apps a LEFT JOIN domains d ON d.id = a.domain_id
              WHERE a.user_id = :u ORDER BY a.kind, a.name', ['u' => $uid]);

        foreach ($apps as $i => $a) {
            $apps[$i]['status'] = self::liveStatus($a);
            $apps[$i]['url']     = self::publicUrl($a);
        }
        return ['ok' => true, 'error' => null, 'apps' => $apps,
                'node' => self::nodeVersion(), 'python' => self::pythonVersion()];
    }

    // ------------------------------------------------------------------- create

    /**
     * Register a new app.
     *
     * Port and process name are allocated here so they never collide.
     */
    public static function create(string $username, string $kind, string $name,
                                 string $domainName, string $root, string $entry): array
    {
        if (!in_array($kind, ['node', 'python'], true)) {
            return ['ok' => false, 'error' => 'Runtime kind must be node or python.'];
        }
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,30}$/', $name)) {
            return ['ok' => false, 'error' => 'App name: lowercase letters, digits, _ or -, 2-31 characters.'];
        }

        $uid = self::userId($username);
        if ($uid === 0) {
            return ['ok' => false, 'error' => 'User not found.'];
        }

        // Root must be inside the user's home; it may not escape via the path.
        $root = '/' . trim($root, '/');
        $home = FileManager::home($username);
        if (!str_starts_with($root . '/', rtrim($home, '/') . '/')) {
            return ['ok' => false, 'error' => 'The app folder must live inside the user home directory.'];
        }
        if (!is_dir($root)) {
            return ['ok' => false, 'error' => 'App folder not found: ' . $root];
        }
        $entryFile = $root . '/' . ltrim($entry, '/');
        if (!self::safeRelative($entry) || !is_file($entryFile)) {
            return ['ok' => false, 'error' => 'Entry file not found: ' . $entry];
        }

        $dup = Connection::value('SELECT COUNT(*) FROM runtime_apps WHERE user_id = :u AND name = :n',
            ['u' => $uid, 'n' => $name]);
        if ((int)$dup > 0) {
            return ['ok' => false, 'error' => 'App ' . $name . ' already exists.'];
        }

        // App quota from the user's package.
        // Only the quota MATCHING the app kind is checked. If both were
        // checked without filtering, one Node app would get Python apps
        // rejected with the message "Node app quota is full".
        $q = self::quota($username);
        if ($kind === 'node') {
            if ($q['max_node'] > 0 && $q['used_node'] >= $q['max_node']) {
                return ['ok' => false, 'error' => "Node app quota is full ({$q['used_node']}/{$q['max_node']})."];
            }
        } elseif ($q['max_python'] > 0 && $q['used_python'] >= $q['max_python']) {
            return ['ok' => false, 'error' => "Python app quota is full ({$q['used_python']}/{$q['max_python']})."];
        }

        $port = self::allocatePort();
        if ($port === null) {
            return ['ok' => false, 'error' => 'No free port available for the app.'];
        }

        $domainId = null;
        if ($domainName !== '') {
            $domainId = (int)Connection::value(
                'SELECT id FROM domains WHERE name = :d AND user_id = :u',
                ['d' => strtolower($domainName), 'u' => $uid]);
            if ($domainId === 0) {
                return ['ok' => false, 'error' => 'Domain not found in your account.'];
            }
        }

        $id = Connection::insert('runtime_apps', [
            'user_id'   => $uid,
            'username'  => $username,
            'domain_id' => $domainId,
            'name'      => $name,
            'kind'      => $kind,
            'root'      => $root,
            'entry'     => ltrim($entry, '/'),
            'node_version' => $kind === 'node' ? self::nodeVersion() : null,
            'port'      => $port,
            'status'    => 'stopped',
            'created_at' => gmdate('c'),
        ]);

        // Also register it with the process manager so the app starts right away.
        $started = self::start($username, $id);
        if (!$started['ok']) {
            // Drop the systemd unit the helper already created. Otherwise
            // that unit keeps running even though there is no app in the panel.
            self::helper(['remove', $name]);
            Connection::run('DELETE FROM runtime_apps WHERE id = :i', ['i' => $id]);
            return ['ok' => false, 'error' => 'The app failed to start: ' . $started['error']];
        }
        return ['ok' => true, 'error' => null, 'id' => $id, 'port' => $port,
                'name' => $name, 'kind' => $kind];
    }

    // -------------------------------------------------------------- process mgmt

    /** Start the app (systemd unit). */
    public static function start(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App not found.'];
        }
        return self::startApp($app);
    }

    public static function stop(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App not found.'];
        }
        return self::actionApp($app, 'stop');
    }

    public static function restart(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App not found.'];
        }
        return self::actionApp($app, 'restart');
    }

    /** App log (last lines). */
    public static function logs(string $username, int $appId, int $lines = 60): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App not found.'];
        }
        $lines = max(5, min(500, $lines));
        $r = self::helper(['logs', (string)$app['name'], (string)$lines]);
        return ['ok' => true, 'error' => null, 'log' => $r['out'] ?: '(no logs yet)'];
    }

    public static function delete(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App not found.'];
        }
        self::helper(['remove', (string)$app['name']]);
        Connection::run('DELETE FROM runtime_apps WHERE id = :i', ['i' => $appId]);
        return ['ok' => true, 'error' => null];
    }

    // -------------------------------------------------------------- diagnostics

    /** Installed runtime versions, for display in the UI. */
    public static function nodeVersion(): string
    {
        $v = trim((string)self::exec(['node', '--version'])['out']);
        return $v !== '' ? ltrim($v, 'v') : '';
    }

    public static function pythonVersion(): string
    {
        $v = trim((string)self::exec(['python3', '--version'])['out']);
        return $v !== '' ? preg_replace('/^Python\s*/', '', $v) : '';
    }

    public static function managersReady(): array
    {
        $h = self::helper(['ping']);
        return [
            'helper' => $h['ok'],
            'node'   => self::nodeVersion() !== '',
            'python' => self::pythonVersion() !== '',
        ];
    }

    // ------------------------------------------------------------------ internal

    private static function userId(string $username): int
    {
        return (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
    }


    /** The user's app quota, split per runtime kind. */
    public static function quota(string $username): array
    {
        $uid = self::userId($username);
        $row = Connection::one(
            'SELECT p.max_node_apps FROM users u LEFT JOIN packages p ON p.id = u.package_id
              WHERE u.id = :u', ['u' => $uid]);
        $maxNode = (int)($row['max_node_apps'] ?? 0);
        $used = Connection::all('SELECT kind FROM runtime_apps WHERE user_id = :u', ['u' => $uid]);
        // Node and Python have SEPARATE quotas. If both shared the same
        // max_node_apps, one Node app would eat into the Python quota
        // even though the package does not allow that.
        $row2 = Connection::one(
            'SELECT p.max_python_apps FROM users u LEFT JOIN packages p ON p.id = u.package_id
              WHERE u.id = :u', ['u' => $uid]);
        $maxPython = (int)($row2['max_python_apps'] ?? 0);
        if ($maxPython === 0) {
            // Older installs don't have this column yet: fall back to the Node
            // quota so Python apps are not locked out entirely for no reason.
            $maxPython = $maxNode;
        }
        return [
            'max_node'     => $maxNode,
            'used_node'    => count(array_filter($used, static fn (array $a): bool => $a['kind'] === 'node')),
            'max_python'   => $maxPython,
            'used_python'  => count(array_filter($used, static fn (array $a): bool => $a['kind'] === 'python')),
        ];
    }

    private static function owned(string $username, int $appId): ?array
    {
        $uid = self::userId($username);
        if ($uid === 0) {
            return null;
        }
        return Connection::one('SELECT * FROM runtime_apps WHERE id = :i AND user_id = :u',
            ['i' => $appId, 'u' => $uid]);
    }

    /** Safe relative path (no '..', no absolute path). */
    private static function safeRelative(string $p): bool
    {
        return $p !== ''
            && !str_starts_with($p, '/')
            && !str_contains($p, "\0")
            && !in_array('..', explode('/', $p), true);
    }

    private static function allocatePort(): ?int
    {
        $used = array_map('intval', array_column(
            Connection::all('SELECT port FROM runtime_apps'), 'port'));
        // Ports actually in use by other processes must be avoided as well.
        $busy = self::listeningPorts();
        for ($p = self::PORT_MIN; $p <= self::PORT_MAX; $p++) {
            if (!in_array($p, $used, true) && !in_array($p, $busy, true)) {
                return $p;
            }
        }
        return null;
    }

    /** TCP ports currently listening, read from /proc (without depending on a tool). */
    private static function listeningPorts(): array
    {
        $out = [];
        foreach (glob('/proc/net/tcp*') ?: [] as $file) {
            $fh = @fopen($file, 'r');
            if ($fh === false) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $parts = preg_split('/\s+/', trim($line)) ?: [];
                // Column 3 = state; 0A = LISTEN
                if (($parts[3] ?? '') !== '0A') {
                    continue;
                }
                $local = explode(':', $parts[1] ?? '');
                if (count($local) === 2) {
                    $out[] = hexdec($local[1]);
                }
            }
            fclose($fh);
        }
        return array_map('intval', $out);
    }

    // ------------------------------------------------------------ process runner

    private const HELPER = '/usr/local/sbin/fpanel-app';

    /**
     * Supervisor uses one systemd unit per app (fpanel-app-<name>.service).
     *
     * Every operation needs root, so it goes through the helper. Arguments are
     * already validated on the PHP side (name, root, entry) and again in the helper.
     */
    private static function helper(array $args): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'The app runtime helper is not installed on the server.'];
        }
        $r = self::exec(array_merge(['sudo', '-n', self::HELPER], $args));
        $out = trim($r['out']);
        $err = trim($r['err']);
        if ($r['code'] !== 0) {
            $msg = preg_replace('/^ERR:\s*/', '', $err) ?: ($out ?: 'The app operation failed.');
            return ['ok' => false, 'error' => trim($msg)];
        }
        return ['ok' => true, 'error' => null, 'out' => $out];
    }

    private static function startApp(array $app): array
    {
        return self::helper(['start', (string)$app['name'], (string)$app['root'],
            (string)$app['entry'], (string)$app['port'], (string)$app['kind'],
            FileManager::home((string)$app['username'])]);
    }

    private static function actionApp(array $app, string $action): array
    {
        return self::helper([$action, (string)$app['name']]);
    }

    /** The app port for a domain, or null if the domain has no app. */
    public static function portForDomain(int $domainId): ?int
    {
        if ($domainId <= 0) {
            return null;
        }
        $port = (int)Connection::value(
            'SELECT port FROM runtime_apps WHERE domain_id = :d ORDER BY id LIMIT 1',
            ['d' => $domainId]);
        return $port > 0 ? $port : null;
    }

    /**
     * Run a command without a shell.
     *
     * proc_open with an argument array: there is no shell interpolation, so a
     * value coming from the user cannot be injected as another command.
     */
    private static function exec(array $cmd): array
    {
        $pipes = [];
        $proc  = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'failed to run the command'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($proc), 'out' => (string)$out, 'err' => (string)$err];
    }

    // ---------------------------------------------------------------- status/url

    /** Actual status from systemd, not the column in the DB. */
    private static function liveStatus(array $app): string
    {
        $r = self::helper(['status', (string)$app['name']]);
        return $r['ok'] && trim($r['out']) === 'running' ? 'running' : 'stopped';
    }

    private static function publicUrl(array $app): string
    {
        $domain = (string)($app['domain_name'] ?? '');
        return $domain !== '' ? 'https://' . $domain . '/' : '';
    }
}