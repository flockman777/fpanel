<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Runtime app per domain: Node.js dan Python.
 *
 * Kedua runtime dijalankan sebagai proses sendiri di belakang nginx, bukan
 * lewat pool PHP: Node memakai `node entry`, Python memakai gunicorn. Satu
 * app = satu systemd unit (fpanel-app-<nama>.service), jadi start/stop/status
 * jelas dan otomatis hidup lagi setelah reboot.
 *
 * App tidak memakai port bebas sembarangan: port dialokasikan dari satu
 * range yang dicatat di tabel, supaya tidak bentrok dengan nginx/panel/MariaDB
 * dan supaya dua app tidak mendapat port yang sama.
 *
 * nginx untuk domain bertipe node/python mengarahkan ke port app tersebut
 * (Vhost::render), dengan dukungan WebSocket untuk Node.
 */
final class AppRuntime
{
    /** Range port untuk app. Jauh dari 80/443/8090/3306 dan tidak dipakai proses lain. */
    public const PORT_MIN = 30000;
    public const PORT_MAX = 30999;

    // ------------------------------------------------------------------ listing

    /** Semua app milik sebuah user. */
    public static function listing(string $username): array
    {
        $uid = self::userId($username);
        if ($uid === 0) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
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
     * Daftarkan app baru.
     *
     * Port dan nama proses dialokasikan di sini supaya tidak pernah sama.
     */
    public static function create(string $username, string $kind, string $name,
                                 string $domainName, string $root, string $entry): array
    {
        if (!in_array($kind, ['node', 'python'], true)) {
            return ['ok' => false, 'error' => 'Jenis runtime harus node atau python.'];
        }
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,30}$/', $name)) {
            return ['ok' => false, 'error' => 'Nama app: huruf kecil, angka, _ atau -, 2-31 karakter.'];
        }

        $uid = self::userId($username);
        if ($uid === 0) {
            return ['ok' => false, 'error' => 'User tidak ditemukan.'];
        }

        // Root harus berada di dalam home user, tidak boleh keluar lewat path.
        $root = '/' . trim($root, '/');
        $home = FileManager::home($username);
        if (!str_starts_with($root . '/', rtrim($home, '/') . '/')) {
            return ['ok' => false, 'error' => 'Folder app harus berada di dalam home user.'];
        }
        if (!is_dir($root)) {
            return ['ok' => false, 'error' => 'Folder app tidak ditemukan: ' . $root];
        }
        $entryFile = $root . '/' . ltrim($entry, '/');
        if (!self::safeRelative($entry) || !is_file($entryFile)) {
            return ['ok' => false, 'error' => 'File entry tidak ditemukan: ' . $entry];
        }

        $dup = Connection::value('SELECT COUNT(*) FROM runtime_apps WHERE user_id = :u AND name = :n',
            ['u' => $uid, 'n' => $name]);
        if ((int)$dup > 0) {
            return ['ok' => false, 'error' => 'App ' . $name . ' sudah ada.'];
        }

        // Kuota app dari paket user.
        // Hanya kuota yang SESUAI dengan jenis app yang dicek. Kalau keduanya
        // dicek tanpa filter, satu app Node akan membuat app Python ditolak
        // dengan pesan "Kuota app Node penuh".
        $q = self::quota($username);
        if ($kind === 'node') {
            if ($q['max_node'] > 0 && $q['used_node'] >= $q['max_node']) {
                return ['ok' => false, 'error' => "Kuota app Node penuh ({$q['used_node']}/{$q['max_node']})."];
            }
        } elseif ($q['max_python'] > 0 && $q['used_python'] >= $q['max_python']) {
            return ['ok' => false, 'error' => "Kuota app Python penuh ({$q['used_python']}/{$q['max_python']})."];
        }

        $port = self::allocatePort();
        if ($port === null) {
            return ['ok' => false, 'error' => 'Tidak ada port bebas untuk app.'];
        }

        $domainId = null;
        if ($domainName !== '') {
            $domainId = (int)Connection::value(
                'SELECT id FROM domains WHERE name = :d AND user_id = :u',
                ['d' => strtolower($domainName), 'u' => $uid]);
            if ($domainId === 0) {
                return ['ok' => false, 'error' => 'Domain tidak ditemukan di akun Anda.'];
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

        // Daftarkan juga ke process manager supaya app langsung hidup.
        $started = self::start($username, $id);
        if (!$started['ok']) {
            // Buang unit systemd yang sudah dibuat helper. Kalau tidak,
            // unit itu tetap jalan padahal tidak ada app di panel.
            self::helper(['remove', $name]);
            Connection::run('DELETE FROM runtime_apps WHERE id = :i', ['i' => $id]);
            return ['ok' => false, 'error' => 'App gagal dijalankan: ' . $started['error']];
        }
        return ['ok' => true, 'error' => null, 'id' => $id, 'port' => $port,
                'name' => $name, 'kind' => $kind];
    }

    // -------------------------------------------------------------- process mgmt

    /** Jalankan app (systemd unit). */
    public static function start(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App tidak ditemukan.'];
        }
        return self::startApp($app);
    }

    public static function stop(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App tidak ditemukan.'];
        }
        return self::actionApp($app, 'stop');
    }

    public static function restart(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App tidak ditemukan.'];
        }
        return self::actionApp($app, 'restart');
    }

    /** Log app (baris terakhir). */
    public static function logs(string $username, int $appId, int $lines = 60): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App tidak ditemukan.'];
        }
        $lines = max(5, min(500, $lines));
        $r = self::helper(['logs', (string)$app['name'], (string)$lines]);
        return ['ok' => true, 'error' => null, 'log' => $r['out'] ?: '(belum ada log)'];
    }

    public static function delete(string $username, int $appId): array
    {
        $app = self::owned($username, $appId);
        if ($app === null) {
            return ['ok' => false, 'error' => 'App tidak ditemukan.'];
        }
        self::helper(['remove', (string)$app['name']]);
        Connection::run('DELETE FROM runtime_apps WHERE id = :i', ['i' => $appId]);
        return ['ok' => true, 'error' => null];
    }

    // -------------------------------------------------------------- diagnostics

    /** Versi runtime yang terpasang, untuk ditampilkan di UI. */
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


    /** Kuota app milik user, dipisah per jenis runtime. */
    public static function quota(string $username): array
    {
        $uid = self::userId($username);
        $row = Connection::one(
            'SELECT p.max_node_apps FROM users u LEFT JOIN packages p ON p.id = u.package_id
              WHERE u.id = :u', ['u' => $uid]);
        $maxNode = (int)($row['max_node_apps'] ?? 0);
        $used = Connection::all('SELECT kind FROM runtime_apps WHERE user_id = :u', ['u' => $uid]);
        // Node dan Python punya kuota TERPISAH. Kalau keduanya memakai
        // max_node_apps yang sama, satu app Node akan memakan kuota Python
        // padahal paketnya tidak mengizinkan itu.
        $row2 = Connection::one(
            'SELECT p.max_python_apps FROM users u LEFT JOIN packages p ON p.id = u.package_id
              WHERE u.id = :u', ['u' => $uid]);
        $maxPython = (int)($row2['max_python_apps'] ?? 0);
        if ($maxPython === 0) {
            // Instalasi lama belum punya kolom ini: pakai kuota Node supaya
            // app Python tidak terkunci total tanpa sebab.
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

    /** Path relatif aman (tanpa '..', tanpa path absolut). */
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
        // Port yang benar-benar dipakai proses lain juga harus dihindari.
        $busy = self::listeningPorts();
        for ($p = self::PORT_MIN; $p <= self::PORT_MAX; $p++) {
            if (!in_array($p, $used, true) && !in_array($p, $busy, true)) {
                return $p;
            }
        }
        return null;
    }

    /** Port TCP yang sedang listening, dibaca dari /proc (tanpa depending ke tool). */
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
                // Kolom 3 = state; 0A = LISTEN
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
     * Supervisor memakai satu systemd unit per app (fpanel-app-<nama>.service).
     *
     * Semua operasi butuh root, jadi lewat helper. Argumen sudah divalidasi
     * di sisi PHP (nama, root, entry) dan diulang lagi di helper.
     */
    private static function helper(array $args): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'Helper app runtime tidak terpasang di server.'];
        }
        $r = self::exec(array_merge(['sudo', '-n', self::HELPER], $args));
        $out = trim($r['out']);
        $err = trim($r['err']);
        if ($r['code'] !== 0) {
            $msg = preg_replace('/^ERR:\s*/', '', $err) ?: ($out ?: 'Operasi app gagal.');
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

    /** Port app untuk sebuah domain, atau null kalau domain tidak punya app. */
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
     * Jalankan perintah tanpa shell.
     *
     * proc_open dengan array argumen: tidak ada interpolasi shell, jadi nilai
     * yang datang dari user tidak bisa disisipkan sebagai perintah lain.
     */
    private static function exec(array $cmd): array
    {
        $pipes = [];
        $proc  = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'gagal menjalankan perintah'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['code' => proc_close($proc), 'out' => (string)$out, 'err' => (string)$err];
    }

    // ---------------------------------------------------------------- status/url

    /** Status sebenarnya dari systemd, bukan kolom di DB. */
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