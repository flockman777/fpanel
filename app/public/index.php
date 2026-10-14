<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Fpanel\Auth\Auth;
use Fpanel\Auth\LoginLink;
use Fpanel\Auth\PasswordHasher;
use Fpanel\Auth\RememberDevice;
use Fpanel\Database\Connection;
use Fpanel\Http\Csrf;
use Fpanel\Http\Request;
use Fpanel\Http\Response;
use Fpanel\Http\Router;
use Fpanel\Http\Session;
use Fpanel\Service\AppRuntime;
use Fpanel\Service\Backup;
use Fpanel\Service\Catalog;
use Fpanel\Service\Cron;
use Fpanel\Service\DomainManager;
use Fpanel\Service\FileManager;
use Fpanel\Service\LetsEncrypt;
use Fpanel\Service\MariaDb;
use Fpanel\Service\Panel;
use Fpanel\Service\PhpExtensions;
use Fpanel\Service\PhpMyAdmin;
use Fpanel\Service\PhpSettings;
use Fpanel\Service\Search;
use Fpanel\Service\Totp;
use Fpanel\Service\Usage;
use Fpanel\Service\Validator;
use Fpanel\Service\Wp;
use Fpanel\Service\Vhost;
use Fpanel\Support\Audit;
use Fpanel\Support\Config;
use Fpanel\View\View;

$request = Request::capture();
$session = new Session($request);
$auth    = new Auth($session);
$router  = new Router();

$csrfToken = static function (): string {
    $t = bin2hex(random_bytes(32));
    Csrf::issue($t);
    return $t;
};

// ---------- health ----------
$router->get('/api/health', function (): Response {
    $checks = [];
    try {
        Connection::value('SELECT 1');
        $checks['db'] = 'ok';
    } catch (\Throwable $e) {
        $checks['db'] = 'fail: ' . $e->getMessage();
    }
    $checks['php']     = PHP_VERSION;
    $checks['version'] = FPANEL_VERSION;
    $checks['time']    = gmdate('c');
    return Response::json($checks, $checks['db'] === 'ok' ? 200 : 503);
});

// Token CSRF untuk form/AJAX. Selalu menerbitkan token baru sehingga
// tidak pernah kedaluwarsa walau GET /login sudah ter-redirect.
$router->get('/api/csrf', function () use ($csrfToken): Response {
    return Response::json(['csrf' => $csrfToken()]);
});

// ---------- auth ----------
// Tanpa ini, /
// balas 404. Akar harus selalu punya tujuan: ke dashboard bila sudah
// masuk, ke login bila belum.
$router->get('/', function () use ($auth): Response {
    return Response::redirect($auth->check() ? '/dashboard' : '/login');
});

$router->get('/login', function (Request $req) use ($auth, $csrfToken): Response {
    if ($auth->check()) {
        return Response::redirect('/dashboard');
    }
    return Response::html(View::render('login', [
        'csrf'     => $csrfToken(),
        'error'    => $req->query('error'),
        'notice'   => $req->query('notice'),
    ]));
});

$router->post('/login', function (Request $req) use ($auth, $csrfToken): Response {
    $cookie = $req->cookie(Csrf::COOKIE);
    $field  = $req->post_('_csrf');
    if (!Csrf::check($cookie, is_string($field) ? $field : null)) {
        return Response::html(View::render('login', [
            'csrf'   => $csrfToken(),
            'error'  => 'Token CSRF tidak valid. Muat ulang halaman lalu coba lagi.',
            'notice' => null,
        ]), 419);
    }
    $u = (string)$req->post_('username', '');
    $p = (string)$req->post_('password', '');
    $remember = $req->post_('remember') === '1';
    $r = $auth->attempt($u, $p, $req->ip());
    if (!$r['ok']) {
        return Response::html(View::render('login', [
            'csrf'     => $csrfToken(),
            'error'    => $r['error'],
            'username' => $u,
            'notice'   => null,
        ]), 401);
    }
    // Password benar tapi 2FA aktif: login belum selesai. attempt() sudah membuat
    // sesi antar-langkah, jadi user diarahkan ke halaman kode, bukan ke dashboard.
    if (!empty($r['need_totp'])) {
        return Response::redirect('/login/2fa');
    }
    // Token perangkat hanya diterbitkan setelah 2FA juga lolos. Kalau terbit
    // di langkah password, siapa pun yang tahu password bisa mendapat remember-me
    // tanpa pernah melewati kode 2FA.
    if ($remember && !empty($r['user_id'])) {
        RememberDevice::issue((int)$r['user_id'], $req->ip(), $req->userAgent());
    }
    return Response::redirect('/dashboard');
});

// Tautan login sekali pakai: /login/link/<token>
$router->get('/login/link/{token}', function (Request $req, array $params) use ($auth, $session, $csrfToken): Response {
    if ($auth->check()) {
        return Response::redirect('/dashboard');
    }
    $uid = LoginLink::redeem((string)($params['token'] ?? ''), $req->ip());
    if ($uid === null) {
        return Response::html(View::render('login-link-invalid'), 410);
    }
    $row = Connection::one(
        'SELECT id, username, status FROM users WHERE id = :i', ['i' => $uid]
    );
    if ($row === null || (string)$row['status'] !== 'active') {
        return Response::html(View::render('login-link-invalid'), 403);
    }
    $session->start((int)$row['id']);
    Connection::update('users', [
        'last_login_at' => gmdate('Y-m-d H:i:s'),
        'last_login_ip' => $req->ip(),
        'failed_logins' => 0,
        'updated_at'    => gmdate('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => (int)$row['id']]);
    Audit::log('auth.login', (int)$row['id'], (string)$row['username'], 'user',
        (int)$row['id'], $req->ip(), 'ok', ['method' => 'link']);
    // Halaman ini pakai token di URL, jadi jangan pernahIzinkan halaman lain
    // merujuk balik ke sini lewat Referer.
    return Response::html(View::render('login-link-ok', [
        'me' => $row, 'csrf' => $csrfToken(),
    ]))->withHeader('Referrer-Policy', 'no-referrer');
});

$router->get('/login/2fa', function (Request $req) use ($auth, $csrfToken, $session): Response {
    if ($auth->check()) {
        return Response::redirect('/dashboard');
    }
    $pending = $session->pendingUser('totp');
    if ($pending === null) {
        return Response::redirect('/login');
    }
    return Response::html(View::render('login-2fa', [
        'csrf'     => $csrfToken(),
        'username' => (string)$pending['username'],
        'error'    => null,
    ]));
});

$router->post('/login/2fa', function (Request $req) use ($auth, $csrfToken, $session): Response {
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $pending = $session->pendingUser('totp');
    if ($pending === null) {
        return Response::redirect('/login');
    }
    $r = $auth->confirmTotp((string)$req->post_('code', ''), $req->ip());
    if (!$r['ok']) {
        return Response::html(View::render('login-2fa', [
            'csrf'     => $csrfToken(),
            'username' => (string)$pending['username'],
            'error'    => (string)$r['error'],
        ]), 401);
    }
    return Response::redirect('/dashboard');
});

$router->post('/logout', function (Request $req) use ($auth, $csrfToken): Response {
    $cookie = $req->cookie(Csrf::COOKIE);
    $field  = $req->post_('_csrf');
    if (!Csrf::check($cookie, is_string($field) ? $field : null)) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $auth->logout();
    return Response::redirect('/login');
});

/**
 * Perpanjang sesi dari cookie "ingat perangkat ini".
 *
 * Dipanggil halaman login saat cookie perangkat ada tapi sesi sudah habis.
 * Sesi yang baru dibuat hanya berumur pendek (mis. 7 hari), bukan 90 hari
 * seperti cookie perangkat: cookie remember-me adalah credential yang bisa dicabut,
 * jadi sesi hasil penggunaannya tidak perlu ikut berumur panjang.
 */
$router->get('/login/remember', function (Request $req) use ($auth, $session): Response {
    if ($auth->check()) {
        return Response::redirect('/dashboard');
    }
    $devId = RememberDevice::deviceId((string)$req->cookie(RememberDevice::COOKIE));
    if ($devId < 1) {
        return Response::redirect('/login');
    }
    $row = Connection::one(
        'SELECT d.user_id, u.status FROM remember_devices d
           JOIN users u ON u.id = d.user_id
          WHERE d.id = :i AND d.revoked_at IS NULL',
        ['i' => $devId]
    );
    if ($row === null || (string)$row['status'] !== 'active') {
        // Perangkat dicabut atau akun ditangguhkan: bersihkan cookienya supaya
        // tidak terus mencoba.
        RememberDevice::clearCookie();
        return Response::redirect('/login');
    }
    RememberDevice::touch($devId);
    $session->start((int)$row['user_id'], 'auth', 7 * 86400);
    Connection::update('users', [
        'last_login_at' => gmdate('Y-m-d H:i:s'),
        'last_login_ip' => $req->ip(),
    ], 'id = :id', ['id' => (int)$row['user_id']]);
    Audit::log('auth.remember', (int)$row['user_id'], 'perangkat', 'user',
        (int)$row['user_id'], $req->ip(), 'ok', ['device' => $devId]);
    return Response::redirect('/dashboard');
});

$router->get('/api/me', function (Request $req) use ($auth): Response {
    $u = $auth->user();
    if ($u === null) {
        return Response::json(['authenticated' => false], 401);
    }
    return Response::json([
        'authenticated' => true,
        'id'            => (int)$u['id'],
        'username'      => $u['username'],
        'email'         => $u['email'],
        'role'          => $u['role'],
    ]);
});

// ---------- pencarian global ----------
$router->get('/api/search', function (Request $req) use ($auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::json(['results' => []], 401);
    }
    $quota = Panel::data($me)['quota'] ?? [];
    return Response::json([
        'results' => Search::query($me, is_array($quota) ? $quota : [], (string)$req->query('q', '')),
    ]);
});

// ---------- halaman panel ----------
$page = static function (string $view, array $extra = []) use ($auth, $csrfToken): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $csrf = $csrfToken();
    $s    = Panel::data($me);
    return Response::html(View::render($view, $extra + [
        'me' => $me, 'csrf' => $csrf, 's' => $s,
    ]));
};

$router->get('/dashboard', fn (Request $r) => $page('dashboard', [
    'active'  => '/dashboard',
    'catalog' => Catalog::forUser($s['quota'] ?? [], (string)($s['quota']['resources'] ?? '{}')),
]));

// --- Domains: data nyata + form create/delete nyata ---
$domainView = static function (Request $req, ?string $error = null, ?string $ok = null) use ($page, $auth): Response {
    $me   = $auth->user() ?? [];
    $rows = Connection::all(
        'SELECT id, name, type, docroot, status, COALESCE(php_version, node_version, "") AS rt,
                COALESCE(ssl_status, "none") AS ssl_status,
                COALESCE(ssl_issued_at, "") AS ssl_issued_at,
                COALESCE(ssl_error, "") AS ssl_error
           FROM domains WHERE user_id = :u ORDER BY is_subdomain, name',
        ['u' => (int)($me['id'] ?? 0)]
    );
    $qErr = trim((string)$req->query('err', ''));
    $qOk  = trim((string)$req->query('ok', ''));
    if ($qErr !== '') { $err = $qErr; }
    if ($qOk  !== '') { $ok  = $qOk; }
    // Sisa hari diambil dari certbot, bukan dari kolom DB, supaya UI tetap
    // benar walau certbot.timer sudah me-renew di luar aplikasi.
    foreach ($rows as &$r) {
        $r['ssl_days'] = LetsEncrypt::daysLeft((string)$r['name']);
        $r['ssl_live'] = LetsEncrypt::hasCert((string)$r['name']);
    }
    unset($r);
    return $page('section', [
        'active' => '/domains', 'title' => 'Domains', 'lead' => 'Kelola domain dan subdomain hosting Anda.',
        'icon' => 'globe', 'panel' => 'form-domain', 'noTable' => true,
        'rows' => $rows, 'empty' => 'Belum ada domain.',
        'phpInstalled' => Panel::installedPhp(),
        'phpDefaults'  => \Fpanel\Service\PhpSettings::defaults(),
        'phpDefault'   => (string)Config::get('php.default', '8.3'),
        'error' => $error ?? $req->query('error'),
        'ok'    => $ok    ?? $req->query('ok'),
    ]);
};
$router->get('/domains', fn (Request $req) => $domainView($req));

$router->post('/domains', function (Request $req) use ($domainView, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $name = (string)$req->post_('name', '');
    $type = (string)$req->post_('type', 'php');
    $php  = (string)$req->post_('php_version', '');
    // Hanya nilai "1" yang dianggap ya. Checkbox HTML mengirim "1" saat
    // dicentang, dan tidak mengirim apa-apa saat tidak; menguji "!== ''"
    // akan mengaktifkan SSL kalau ada yang mengirim issue_tls=0.
    $wantTls = $req->post_('issue_tls', '') === '1';
    $r = DomainManager::create(
        (int)$me['id'], (string)$me['username'], $name, $type,
        $php === '' ? null : $php, $wantTls
    );
    if (!$r['ok']) {
        return $domainView($req, (string)$r['error']);
    }
    $ok = 'Domain ' . $r['domain']['name'] . ' dibuat. Vhost nginx sudah aktif.';
    // SSL gagal tidak membatalkan domain, jadi dilaporkan sebagai peringatan.
    if (is_array($r['ssl'] ?? null)) {
        $ok .= $r['ssl']['ok']
            ? ' Sertifikat SSL juga aktif.'
            : ' SSL gagal: ' . $r['ssl']['message'];
    }
    return $domainView($req, null, $ok);
});

$router->post('/domains/ssl', function (Request $req) use ($domainView, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $id   = (int)$req->post_('id', '0');
    $act  = (string)$req->post_('action', 'issue');
    $d = Connection::one(
        'SELECT id, name FROM domains WHERE id = :i AND user_id = :u',
        ['i' => $id, 'u' => (int)$me['id']]
    );
    if ($d === null) {
        return $domainView($req, 'Domain tidak ditemukan.');
    }

    if ($act === 'revoke') {
        $ok  = DomainManager::revokeCertificate((int)$d['id'], (string)$d['name']);
        $msg = $ok
            ? 'Sertifikat ' . $d['name'] . ' dicabut. Domain tetap jalan via HTTP.'
            : 'Sertifikat tidak bisa dicabut. Hapus manual di server.';
        Audit::log('domain.ssl.revoke', (int)$me['id'], (string)$me['username'], 'domain', $id, '', $ok ? 'ok' : 'error', ['name' => $d['name']]);
        // Vhost perlu dibangun ulang supaya blok 443 hilang.
        $row = Connection::one('SELECT * FROM domains WHERE id = :i', ['i' => $id]);
        if ($ok && is_array($row)) {
            PhpSettings::apply((string)$row['name'], (string)$me['username'], $row);
        }
        return $domainView($req, $ok ? null : $msg, $ok ? $msg : null);
    }

    $r = DomainManager::issueCertificate((int)$d['id'], (string)$d['name'], (string)$me['username'], (int)$me['id']);
    return $r['ok'] ? $domainView($req, null, $r['message']) : $domainView($req, $r['message']);
});

$router->post('/domains/delete', function (Request $req) use ($domainView, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $r = DomainManager::delete((int)$me['id'], (string)$me['username'], (int)$req->post_('id', '0'));
    // DomainManager boleh return ok=true sambil menyertakan error, mis. saat
    // ada berkas sisa. Menelan error itu membuat user mengira bersih.
    $warn = isset($r['error']) ? (string)$r['error'] : null;
    return $domainView(
        $req,
        $r['ok'] ? $warn : $warn,
        $r['ok'] ? 'Domain dihapus.' : null
    );
});

// --- Databases: CRUD nyata lewat MariaDb (helper root) ---
$dbView = function (Request $req, ?string $err = null, ?string $ok = null, ?array $newCred = null) use ($page, $auth) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $u = (string)$me['username'];
    return $page('databases', [
        'active'  => '/databases',
        'data'    => MariaDb::listing($u),
        'health'  => MariaDb::health(),
        'error'   => $err,
        'ok'      => $ok,
        'newCred' => $newCred,
    ]);
};

$router->get('/databases', function (Request $req) use ($dbView): Response {
    // Pesan error dari redirect (mis. gagal membuat token phpMyAdmin).
    $err = trim((string)$req->query('err', ''));
    return $dbView($req, $err === '' ? null : $err);
});

// Semua aksi DB melewati satu wrapper: auth + CSRF, lalu render ulang halaman
// dengan pesan hasil (kredensial baru ikut diteruskan ke view).
$dbAction = function (string $route, callable $fn) use ($auth, $dbView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $dbView, $fn): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 CSRF tidak valid', 419);
        }
        $r = $fn($me, $req);
        return $dbView($req, $r['error'], $r['ok'] ? ($r['msg'] ?? null) : null,
            $r['newCred'] ?? null);
    });
};

$dbAction('/databases/create', function (array $me, Request $req): array {
    $r = MariaDb::create((string)$me['username'],
        (string)$req->post_('db', ''), (string)$req->post_('user', ''),
        (string)$req->post_('priv', 'STANDAR'));
    Audit::log('db.create', (int)$me['id'], (string)$me['username'], 'database',
        (string)$req->post_('db', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => null, 'msg' => 'Database ' . $r['db'] . ' dibuat.',
            'newCred' => ['db' => $r['db'], 'user' => $r['user'], 'password' => $r['password']]];
});

$dbAction('/databases/delete', function (array $me, Request $req): array {
    $id  = (int)$req->post_('db', 0);
    $r   = MariaDb::delete((string)$me['username'], $id);
    Audit::log('db.delete', (int)$me['id'], (string)$me['username'], 'database',
        (string)$id, $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? 'Database dihapus.' : null];
});

$dbAction('/databases/user-add', function (array $me, Request $req): array {
    $r = MariaDb::addUser((string)$me['username'], (int)$req->post_('db', 0),
        (string)$req->post_('user', ''), (string)$req->post_('priv', 'STANDAR'));
    Audit::log('db.user_add', (int)$me['id'], (string)$me['username'], 'dbuser',
        (string)$req->post_('user', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => null, 'msg' => 'User ' . $r['user'] . ' ditambahkan.',
            'newCred' => ['db' => (string)$req->post_('db', ''), 'user' => $r['user'], 'password' => $r['password']]];
});

$dbAction('/databases/user-delete', function (array $me, Request $req): array {
    $r = MariaDb::deleteUser((string)$me['username'], (int)$req->post_('db', 0),
        (int)$req->post_('dbuser', 0));
    Audit::log('db.user_delete', (int)$me['id'], (string)$me['username'], 'dbuser',
        (string)$req->post_('dbuser', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? 'User database dihapus.' : null];
});

// phpMyAdmin: token sekali pakai, lalu redirect ke /pma/<token>/.
// Sengaja POST (bukan GET) supaya token tidak bocor dari prefetch/link
// peramban maupun dari log akses halaman daftar database.
$router->post('/databases/phpmyadmin', function (Request $req) use ($auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $r = PhpMyAdmin::launch((string)$me['username'],
        (int)$req->post_('db', 0), (int)$req->post_('dbuser', 0));
    Audit::log('db.phpmyadmin', (int)$me['id'], (string)$me['username'], 'dbuser',
        (string)$req->post_('dbuser', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return Response::redirect('/databases?err=' . rawurlencode((string)$r['error']));
    }
    return Response::redirect((string)$r['url']);
});

// --- Backup: daftar, buat, verifikasi, hapus ------------------------------
// Restore SENGAJA tidak ada di sini: restore menimpa database yang sedang
// hidup, jadi hanya bisa dijalankan dari shell oleh operator.
$backupView = function (Request $req, ?string $err = null, ?string $ok = null,
                        ?string $report = null) use ($page, $auth) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $qErr = trim((string)$req->query('err', ''));
    $qOk  = trim((string)$req->query('ok', ''));
    $qRep = trim((string)$req->query('report', ''));
    if ($qErr !== '') { $err = $qErr; }
    if ($qOk  !== '') { $ok  = $qOk; }
    if ($qRep !== '') { $report = $qRep; }
    return $page('backups', [
        'active' => '/backups',
        'data'   => Backup::listing(),
        'ready'  => Backup::available(),
        'error'  => $err,
        'ok'     => $ok,
        'report' => $report,
    ]);
};

// Halaman ini hanya untuk admin: daftar arsip, ukuran, dan log cron
// membocarkan informasi seluruh server. User biasa tidak boleh sampai
// ke view, jadi gate di sini (bukan hanya di setiap aksi POST).
$router->get('/backups', function (Request $req) use ($auth, $backupView) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 Hanya admin', 403);
    }
    return $backupView($req);
});

/**
 * Aksi backup, khusus admin.
 *
 * Backup berisi seluruh data panel (SQLite + file user + database), jadi user
 * non-admin tidak boleh melihat daftar maupun memicu pembuatan.
 */
$backupAction = function (string $route, callable $fn) use ($auth, $backupView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $backupView, $fn): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 CSRF tidak valid', 419);
        }
        if (($me['role'] ?? '') !== 'admin') {
            return Response::text('403 Hanya admin', 403);
        }
        $r = $fn($me, $req);
        $back = '/backups';
        if (!$r['ok']) {
            return Response::redirect($back . '?err=' . rawurlencode((string)$r['error']));
        }
        $target = $back . '?ok=' . rawurlencode((string)($r['message'] ?? 'Selesai.'));
        if (($r['report'] ?? '') !== '') {
            $target .= '&report=' . rawurlencode((string)$r['report']);
        }
        return Response::redirect($target);
    });
};

$backupAction('/backups/create', function (array $me, Request $req): array {
    $r = Backup::create((int)$req->post_('keep', Backup::KEEP));
    Audit::log('backup.create', (int)$me['id'], (string)$me['username'], 'backup',
        'manual', $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'message' => $r['message'] ?? null];
});

$backupAction('/backups/verify', function (array $me, Request $req): array {
    $name = (string)$req->post_('name', '');
    $r    = Backup::verify($name);
    Audit::log('backup.verify', (int)$me['id'], (string)$me['username'], 'backup',
        $name, $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    // Laporan ditampilkan apa pun hasilnya; outcome sukses supaya operator
    // tetap membaca laporan lengkapnya walau verifikasi gagal.
    return ['ok' => true, 'error' => null,
            'message' => $r['ok'] ? 'Verifikasi ' . $name . ' berhasil.'
                                   : 'Verifikasi ' . $name . ' GAGAL.',
            'report'  => (string)$r['report']];
});

$backupAction('/backups/delete', function (array $me, Request $req): array {
    $name = (string)$req->post_('name', '');
    $r    = Backup::delete($name);
    Audit::log('backup.delete', (int)$me['id'], (string)$me['username'], 'backup',
        $name, $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'message' => $r['message'] ?? null];
});

$backupAction('/backups/prune', function (array $me, Request $req): array {
    $r = Backup::prune((int)$req->post_('keep', Backup::KEEP));
    Audit::log('backup.prune', (int)$me['id'], (string)$me['username'], 'backup',
        'retensi', $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'message' => $r['message'] ?? null];
});

// --- App runtime: Node.js / Python per domain -------------------------------
$appsView = function (Request $req, ?string $err = null, ?string $ok = null,
                      ?string $log = null, ?string $logFor = null) use ($page, $auth) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $qErr = trim((string)$req->query('err', ''));
    $qOk  = trim((string)$req->query('ok', ''));
    if ($qErr !== '') { $err = $qErr; }
    if ($qOk  !== '') { $ok  = $qOk; }
    return $page('apps', [
        'active'  => '/apps',
        'data'    => AppRuntime::listing((string)$me['username']),
        'ready'   => AppRuntime::managersReady(),
        'domains' => Connection::all(
            'SELECT id, name, type, docroot FROM domains WHERE user_id = :u ORDER BY name',
            ['u' => (int)$me['id']]),
        'error'   => $err,
        'ok'      => $ok,
        'log'     => $log,
        'logFor'  => $logFor,
    ]);
};

$router->get('/apps', fn (Request $req) => $appsView($req));

/**
 * Wrapper POST untuk aksi app: auth + CSRF, lalu redirect dengan pesan.
 * Delete sengaja memakai wrapper sendiri karena butuh confirm di UI.
 */
$appsAction = function (string $route, callable $fn) use ($auth, $appsView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $appsView, $fn): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 CSRF tidak valid', 419);
        }
        $r = $fn($me, $req);
        $back = '/apps';
        if (!$r['ok']) {
            return Response::redirect($back . '?err=' . rawurlencode((string)$r['error']));
        }
        return Response::redirect($back . '?ok=' . rawurlencode((string)$r['message']));
    });
};

$appsAction('/apps/create', function (array $me, Request $req): array {
    $domain = (string)$req->post_('domain', '');
    $docroot = (string)Connection::value(
        'SELECT docroot FROM domains WHERE name = :n AND user_id = :u',
        ['n' => $domain, 'u' => (int)$me['id']]);
    // Folder relatif terhadap docroot, bukan path bebas dari user.
    $rel = trim((string)$req->post_('root', ''), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return ['ok' => false, 'error' => 'Folder app tidak valid.'];
    }
    $root = rtrim($docroot, '/') . '/' . $rel;

    $type = (string)Connection::value(
        'SELECT type FROM domains WHERE name = :n AND user_id = :u',
        ['n' => $domain, 'u' => (int)$me['id']]);
    $kind = $type === 'python' ? 'python' : 'node';

    $r = AppRuntime::create((string)$me['username'], $kind,
        (string)$req->post_('name', ''), $domain, $root, (string)$req->post_('entry', ''));
    Audit::log('app.create', (int)$me['id'], (string)$me['username'], 'app',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error',
        ['domain' => $domain, 'kind' => $kind]);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    // Vhost harus dibangun ulang supaya domain-nya proxy ke port app.
    $d = Connection::one('SELECT * FROM domains WHERE name = :n AND user_id = :u',
        ['n' => $domain, 'u' => (int)$me['id']]);
    if (is_array($d)) {
        \Fpanel\Service\DomainManager::rebuildFor((string)$me['username'], $d);
    }
    return ['ok' => true, 'error' => null,
            'message' => 'App ' . $r['name'] . ' dibuat dan berjalan di port ' . $r['port'] . '.'];
});

$appsAction('/apps/action', function (array $me, Request $req): array {
    $id  = (int)$req->post_('app', 0);
    $act = (string)$req->post_('act', '');
    $u   = (string)$me['username'];
    $r = match ($act) {
        'start'   => AppRuntime::start($u, $id),
        'stop'    => AppRuntime::stop($u, $id),
        'restart' => AppRuntime::restart($u, $id),
        default   => ['ok' => false, 'error' => 'Aksi tidak dikenal.'],
    };
    Audit::log('app.' . ($act !== '' ? $act : 'unknown'), (int)$me['id'], $u, 'app',
        (string)$id, $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => null, 'message' => 'App ' . $act . ' berhasil.'];
});

$router->post('/apps/logs', function (Request $req) use ($auth, $appsView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $id = (int)$req->post_('app', 0);
    $r  = AppRuntime::logs((string)$me['username'], $id, 80);
    if (!$r['ok']) {
        return Response::redirect('/apps?err=' . rawurlencode((string)$r['error']));
    }
    $name = (string)Connection::value(
        'SELECT name FROM runtime_apps WHERE id = :i AND user_id = :u',
        ['i' => $id, 'u' => (int)$me['id']]);
    return $appsView($req, null, null, (string)$r['log'], $name);
});

$appsAction('/apps/delete', function (array $me, Request $req): array {
    $id = (int)$req->post_('app', 0);
    $u  = (string)$me['username'];
    $r  = AppRuntime::delete($u, $id);
    Audit::log('app.delete', (int)$me['id'], $u, 'app', (string)$id, $req->ip(),
        $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => null, 'message' => 'App dihapus dari panel.'];
});

$appsAction('/apps/redeploy', function (array $me, Request $req): array {
    $domain = (string)$req->post_('domain', '');
    $d = Connection::one('SELECT * FROM domains WHERE name = :n AND user_id = :u',
        ['n' => $domain, 'u' => (int)$me['id']]);
    if (!is_array($d)) {
        return ['ok' => false, 'error' => 'Domain tidak ditemukan.'];
    }
    \Fpanel\Service\DomainManager::rebuildFor((string)$me['username'], $d);
    return ['ok' => true, 'error' => null, 'message' => 'Vhost domain dibangun ulang.'];
});

// --- PHP Extensions: toggle per versi PHP ----------------------------------
$extView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $ver = (string)$req->query('ver', '');
    $u   = (string)$me['username'];
    $uid = (int)$me['id'];

    // Di cPanel, Select PHP Version bekerja pada level AKUN: extension dan
    // php.ini berlaku untuk semua domain user, bukan satu domain. Karena itu
    // tidak ada pemilih domain di halaman ini; versi per domain diatur di
    // MultiPHP Manager (/multiphp).
    $domains = Connection::all(
        'SELECT id, name, type FROM domains WHERE user_id = :u ORDER BY name',
        ['u' => $uid]
    );

    // Nilai php.ini ditampilkan sekali untuk seluruh akun. Override digabung
    // berurutan (domain terakhir menang), lalu $mixed menandai apakah antar
    // domain memang berbeda supaya view bisa jujur mengatakannya.
    $merged = [];
    $mixed  = false;
    foreach ($domains as $dd) {
        $ov = PhpSettings::forDomain($u, (int)$dd['id']);
        if ($ov === []) {
            continue;
        }
        foreach ($ov as $k2 => $v2) {
            if (isset($merged[$k2]) && $merged[$k2] !== $v2) {
                $mixed = true;
            }
            $merged[$k2] = $v2;
        }
    }

    return $page('php-extensions', [
        'active'     => '/php-extensions',
        'title'      => 'Select PHP Version',
        'lead'       => 'Versi PHP, extension, dan PHP Settings untuk semua domain Anda.',
        'icon'       => 'settings-2',
        'data'       => PhpExtensions::listing($ver),
        'domains'    => $domains,
        'nDomains'   => count($domains),
        'iniDefault' => PhpSettings::defaults(),
        'iniCurrent' => array_merge(PhpSettings::defaults(), $merged),
        'iniCustom'  => $merged !== [],
        'iniMixed'   => $mixed,
        'error'      => $err,
        'ok'         => $ok,
    ]);
};

$router->get('/php-extensions', function (Request $req) use ($extView): Response {
    // Pesan hasil toggle dikirim lewat query string karena aksinya POST lalu
    // redirect. Tanpa ini flash-nya tidak pernah tampil.
    $err = trim((string)$req->query('err', ''));
    $ok  = trim((string)$req->query('ok', ''));
    return $extView($req, $err === '' ? null : $err, $ok === '' ? null : $ok);
});

// Versi PHP tingkat AKUN: applies ke semua domain yang php_version-nya kosong
// (yaitu yang mengikuti versi akun). Domain dengan versi eksplisit hasil
// MultiPHP Manager tidak ikut disentuh.
$router->post('/php-extensions/version', function (Request $req) use ($auth, $extView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 Hanya admin', 403);
    }
    $u   = (string)$me['username'];
    $ver = (string)$req->post_('version', '');
    if (!in_array($ver, Vhost::installedPhpVersions(), true)) {
        return Response::redirect('/php-extensions?err=' . rawurlencode('Versi PHP tidak dikenal.'));
    }

    // Disimpan di tabel settings supaya bisa jadi rujukan tampilan lain.
    Connection::run(
        'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :t)
         ON CONFLICT(key) DO UPDATE SET value = :v2, updated_at = :t2',
        ['k' => 'php_version', 'v' => $ver, 't' => gmdate('c'), 'v2' => $ver, 't2' => gmdate('c')]
    );

    $targets = Connection::all(
        'SELECT * FROM domains
          WHERE user_id = :u AND type <> :static AND (php_version IS NULL OR php_version = :empty)',
        ['u' => (int)$me['id'], 'static' => 'static', 'empty' => '']
    );
    $changed = 0;
    foreach ($targets as $d) {
        Connection::run('UPDATE domains SET php_version = :v WHERE id = :i',
            ['v' => $ver, 'i' => (int)$d['id']]);
        DomainManager::rebuildFor($u, $d + ['php_version' => $ver]);
        $changed++;
    }
    Audit::log('php.version', (int)$me['id'], $u, 'account', 'php', $req->ip(), 'ok',
        ['version' => $ver, 'domains' => $changed]);
    return Response::redirect('/php-extensions?ver=' . rawurlencode($ver)
        . '&ok=' . rawurlencode("PHP $ver diterapkan ke $changed domain."));
});

// Apply sekaligus untuk seluruh extension pada satu versi (ala phpSelector:
// satu tombol Apply, bukan satu request per extension).
$router->post('/php-extensions/apply', function (Request $req) use ($auth, $extView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 Hanya admin', 403);
    }
    $ver = (string)$req->post_('version', '');
    $cur = (array)$req->post_('cur', []);
    $on  = (array)$req->post_('on', []);

    // Hanya nama extension yang benar-benar ada di versi ini yang diproses,
    // sehingga request crafted tidak bisa menyentuh file lain.
    $known = [];
    foreach ((array)(PhpExtensions::listing($ver)['extensions'] ?? []) as $x) {
        $known[(string)$x['name']] = true;
    }

    $changed = [];
    $errors  = [];
    foreach ($cur as $ext => $was) {
        $ext = (string)$ext;
        if (!isset($known[$ext])) {
            continue;
        }
        $want = isset($on[$ext]);
        if ($want === ((string)$was === '1')) {
            continue; // tidak berubah, jangan restart pool tanpa perlu
        }
        $r = PhpExtensions::toggle($ver, $ext, $want);
        if ($r['ok']) {
            $changed[] = $ext;
        } else {
            $errors[] = $ext . ': ' . (string)$r['error'];
        }
    }
    Audit::log('php.extensions.apply', (int)$me['id'], (string)$me['username'], 'extension',
        implode(',', $changed), $req->ip(), $errors === [] ? 'ok' : 'error',
        ['version' => $ver, 'changed' => $changed]);

    $back = '/php-extensions?ver=' . rawurlencode($ver);
    if ($errors !== []) {
        return Response::redirect($back . '&err=' . rawurlencode(implode('; ', $errors)));
    }
    $msg = $changed === []
        ? 'Tidak ada perubahan extension.'
        : count($changed) . ' extension diperbarui untuk PHP ' . $ver . '.';
    return Response::redirect($back . '&ok=' . rawurlencode($msg));
});

// --- MultiPHP Manager: versi PHP per domain -------------------------------
$multiphpView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    return $page('multiphp', [
        'active'    => '/multiphp',
        'title'     => 'MultiPHP Manager',
        'lead'      => 'Pilih versi PHP untuk setiap domain.',
        'icon'      => 'layers',
        'versions'  => Vhost::installedPhpVersions(),
        'rows'      => Connection::all(
            'SELECT id, name, type, COALESCE(php_version, "") AS php_version
               FROM domains WHERE user_id = :u ORDER BY name',
            ['u' => (int)$me['id']]
        ),
        'error'     => $err,
        'ok'        => $ok,
    ]);
};

$router->get('/multiphp', function (Request $req) use ($multiphpView): Response {
    $err = trim((string)$req->query('err', ''));
    $ok  = trim((string)$req->query('ok', ''));
    return $multiphpView($req, $err === '' ? null : $err, $ok === '' ? null : $ok);
});

$router->post('/multiphp/apply', function (Request $req) use ($auth, $multiphpView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 Hanya admin', 403);
    }
    $u       = (string)$me['username'];
    $picked  = array_map('intval', array_keys((array)$req->post_('dom', [])));
    $vers    = (array)$req->post_('ver', []);
    $allowed = Vhost::installedPhpVersions();

    // Hanya domain milik user ini yang boleh diubah.
    // type <> 'static': domain static tidak menjalankan PHP, jadi tidak punya
    // versi. alone atau tidak, select versinya tidak ditampilkan di view.
    $rows = $picked === [] ? [] : Connection::all(
        'SELECT * FROM domains WHERE user_id = :u AND type <> :static AND id IN (' .
        implode(',', array_fill(0, count($picked), '?')) . ')',
        array_merge([(int)$me['id'], 'static'], $picked)
    );

    $changed = 0;
    $bad     = [];
    foreach ($rows as $d) {
        $want = (string)($vers[(string)$d['id']] ?? $vers[(int)$d['id']] ?? '');
        if (!in_array($want, $allowed, true)) {
            continue;
        }
        if ((string)$d['php_version'] === $want) {
            continue;
        }
        Connection::run('UPDATE domains SET php_version = :v WHERE id = :i',
            ['v' => $want, 'i' => (int)$d['id']]);
        DomainManager::rebuildFor($u, $d + ['php_version' => $want]);
        $changed++;
    }
    Audit::log('php.multiphp', (int)$me['id'], $u, 'domain', implode(',', $picked), $req->ip(),
        $bad === [] ? 'ok' : 'error', ['changed' => $changed]);

    $back = '/multiphp';
    if ($changed === 0) {
        return Response::redirect($back . '?ok=' . rawurlencode('Tidak ada versi yang berubah.'));
    }
    return Response::redirect($back . '?ok=' . rawurlencode("$changed domain diperbarui versinya."));
});

$router->post('/php-extensions/toggle', function (Request $req) use ($auth, $extView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $me = $auth->user();
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 Hanya admin', 403);
    }
    $ver  = (string)$req->post_('version', '');
    $ext  = (string)$req->post_('ext', '');
    $on   = (string)$req->post_('state', '') === '1';
    $r    = PhpExtensions::toggle($ver, $ext, $on);
    Audit::log('php.extension', (int)$me['id'], (string)$me['username'], 'extension',
        $ext, $req->ip(), $r['ok'] ? 'ok' : 'error', ['version' => $ver, 'enabled' => $on]);
    $back = '/php-extensions?ver=' . rawurlencode($ver);
    // Form centang extension ikut mengirim domain terpilih supaya redirect
    // kembali ke panel settings yang sama, bukan ke domain pertama.
    $dom = trim((string)$req->post_('dom', ''));
    if ($dom !== '' && preg_match('/^[a-z0-9.-]+$/i', $dom)) {
        $back .= '&dom=' . rawurlencode($dom);
    }
    if (!$r['ok']) {
        return Response::redirect($back . '&err=' . rawurlencode((string)$r['error']));
    }
    return Response::redirect($back . '&ok=' . rawurlencode((string)$r['message']));
});

$router->post('/php-extensions/restore', function (Request $req) use ($auth, $extView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 Hanya admin', 403);
    }
    $ver = (string)$req->post_('version', '');
    $r   = PhpExtensions::restore($ver);
    Audit::log('php.extension_restore', (int)$me['id'], (string)$me['username'], 'extension',
        '*', $req->ip(), $r['ok'] ? 'ok' : 'error', ['version' => $ver]);
    $back = '/php-extensions?ver=' . rawurlencode($ver);
    return Response::redirect($back . ($r['ok'] ? '&ok=' . rawurlencode((string)$r['message'])
                                                : '&err=' . rawurlencode((string)$r['error'])));
});

// --- PHP Settings per-domain (PHP_VALUE) ------------------------------------
$router->post('/domains/php-settings', function (Request $req) use ($auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $id = (int)$req->post_('domain', 0);
    $u  = (string)$me['username'];
    $action = (string)$req->post_('act', 'save');

    // Semua field berawalan "ini_" diambil sekaligus; jangan ambil per nama
    // supaya directive baru otomatis ikut tersimpan. Pemetaan nama field ke
    // directive (termasuk yang bertitik seperti opcache.enable) ada di
    // PhpSettings::fromForm().
    $input = PhpSettings::fromForm($_POST);

    // scope=all: dari halaman Select PHP Version, php.ini berlaku untuk semua
    // domain milik user (bukan satu domain), jadi setiap domain disimpan dengan
    // nilai yang sama lalu pool dimuat ulang sekali di akhir.
    $scopeAll = (string)$req->post_('scope', '') === 'all';
    if ($scopeAll) {
        $ids = array_map('intval', array_column(Connection::all(
            'SELECT id FROM domains WHERE user_id = :u', ['u' => (int)$me['id']]
        ), 'id'));
        if ($ids === []) {
            return Response::redirect('/php-extensions?err=' . rawurlencode('Belum ada domain.'));
        }
        $okAll = true;
        $errAll = '';
        foreach ($ids as $did) {
            $rr = $action === 'reset'
                ? PhpSettings::reset($u, $did)
                : PhpSettings::save($u, $did, $input, false);
            if (!$rr['ok']) {
                $okAll  = false;
                $errAll = (string)$rr['error'];
                break;
            }
        }
        // Muat ulang pool per versi yang terpakai, satu kali per versi.
        foreach (array_unique(array_column(Connection::all(
            'SELECT DISTINCT COALESCE(php_version, "8.3") AS v FROM domains WHERE user_id = :u',
            ['u' => (int)$me['id']]
        ), 'v')) as $pv) {
            PhpSettings::reloadPool((string)$pv);
        }
        Audit::log('domain.php_settings', (int)$me['id'], $u, 'account', 'all',
            $req->ip(), $okAll ? 'ok' : 'error', ['action' => $action, 'scope' => 'all']);

        $back2 = '/php-extensions';
        $sep2  = '?';
        if (!$okAll) {
            return Response::redirect($back2 . $sep2 . 'err=' . rawurlencode($errAll));
        }
        $msg2 = $action === 'reset'
            ? 'PHP Settings dikembalikan ke default untuk semua domain.'
            : 'PHP Settings disimpan untuk ' . count($ids) . ' domain.';
        return Response::redirect($back2 . $sep2 . 'ok=' . rawurlencode($msg2));
    }

    $r = $action === 'reset'
        ? PhpSettings::reset($u, $id)
        : PhpSettings::save($u, $id, $input);

    Audit::log('domain.php_settings', (int)$me['id'], $u, 'domain', (string)$id,
        $req->ip(), $r['ok'] ? 'ok' : 'error', ['action' => $action]);

    // Kalau form dikirim dari halaman PHP Extensions, balik ke sana; kalau
    // dari halaman Domains, tetap di halaman Domains seperti sebelumnya.
    $back = '/domains';
    $to   = trim((string)$req->post_('back', ''));
    if (str_starts_with($to, '/php-extensions')) {
        $back = '/php-extensions?ver=' . rawurlencode(trim((string)$req->post_('ver', '')));
    }
    // Pemisah harus mengikuti $back: kalau sudah ada query string, pakai "&".
    $sep = str_contains($back, '?') ? '&' : '?';
    if (!$r['ok']) {
        return Response::redirect($back . $sep . 'err=' . rawurlencode((string)$r['error']));
    }
    $msg = $action === 'reset' ? 'Pengaturan PHP dikembalikan ke default.' : 'Pengaturan PHP disimpan.';
    return Response::redirect($back . $sep . 'ok=' . rawurlencode($msg));
});

$dbAction('/databases/reveal', function (array $me, Request $req): array {
    $r = MariaDb::reveal((string)$me['username'], (int)$req->post_('db', 0),
        (int)$req->post_('dbuser', 0));
    Audit::log('db.reveal', (int)$me['id'], (string)$me['username'], 'dbuser',
        (string)$req->post_('dbuser', ''), $req->ip(), 'ok', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    // Sengaja tidak displays password di URL: ini POST, jadi tidak masuk history.
    return ['ok' => true, 'error' => null,
            'newCred' => ['db' => '', 'user' => '', 'password' => $r['password']]];
});

// --- Files: File Manager (browse, upload, edit, rename, hapus) ---
// --- Cron Jobs ---------------------------------------------------------------
// --- WordPress installer ----------------------------------------------------
// --- Resource Usage (per domain) ---------------------------------------------
$usageView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $u = (string)$me['username'];
    return $page('usage', [
        'active'  => '/usage',
        'title'   => 'Resource Usage',
        'lead'    => 'Pemakaian disk, bandwidth, dan request per domain.',
        'icon'    => 'gauge',
        'rows'    => Usage::listing($u),
        'summary' => Usage::summary($u),
        'lastRun' => Usage::lastCollected(),
        'error'   => $err,
        'ok'      => $ok,
    ]);
};

$router->get('/usage', fn (Request $req) => $usageView($req));

// Hitung ulang sekarang (admin). Penghitungan normal jalan per jam dari cron.
$router->post('/usage/collect', function (Request $req) use ($auth, $usageView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 Hanya admin', 403);
    }
    $r = Usage::collect();
    Audit::log('usage.collect', (int)$me['id'], (string)$me['username'], 'system', 'all',
        $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return Response::redirect('/usage?err=' . rawurlencode((string)$r['error']));
    }
    return Response::redirect('/usage?ok=' . rawurlencode((string)$r['message']));
});

$wpView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $u = (string)$me['username'];
    $versions = Wp::versions();

    return $page('wordpress', [
        'active'   => '/wordpress',
        'sites'    => Wp::listing($u),
        'versions' => $versions,
        'latest'   => (string)($versions[0] ?? ''),
        // Hanya domain PHP yang bisa jadi sasaran instalasi.
        'domains'  => Connection::all(
            "SELECT name, type FROM domains WHERE user_id = :u AND type = 'php' ORDER BY name",
            ['u' => (int)$me['id']]
        ),
        'job'      => Wp::latestJob($u),
        'running'  => Wp::jobRunning($u),
        'error'    => $err,
        'ok'       => $ok,
    ]);
};

$router->get('/wordpress', fn (Request $req) => $wpView($req));

$router->post('/wordpress/install', function (Request $req) use ($auth, $wpView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $r = Wp::startInstall((string)$me['username'], (string)$req->post_('domain', ''), [
        'version'        => (string)$req->post_('version', ''),
        'title'          => (string)$req->post_('title', ''),
        'admin_user'     => (string)$req->post_('admin_user', ''),
        'admin_password' => (string)$req->post_('admin_password', ''),
        'admin_email'    => (string)$req->post_('admin_email', ''),
        'is_staging'     => (string)$req->post_('is_staging', '') === '1',
    ]);
    if (!$r['ok']) {
        return Response::redirect('/wordpress?err=' . rawurlencode((string)$r['error']));
    }
    return Response::redirect('/wordpress?ok=' . rawurlencode((string)$r['message']));
});

$router->get('/wordpress/{id}/manage', function (Request $req, array $params) use ($auth, $page): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $ov = Wp::overview((string)$me['username'], (int)($params['id'] ?? '0'));
    if (!$ov['ok']) {
        return Response::redirect('/wordpress?err=' . rawurlencode((string)$ov['error']));
    }
    return $page('wordpress-manage', [
        'active' => '/wordpress',
        'title'  => 'Kelola ' . (string)$ov['data']['domain'],
        'ov'     => $ov['data'],
    ]);
});

$router->post('/wordpress/update', function (Request $req) use ($auth, $wpView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $parts = '';
    foreach (['core', 'plugins', 'themes'] as $p) {
        if ($req->post_('part_' . $p) === '1') {
            $parts .= ($parts === '' ? '' : ',') . $p;
        }
    }
    $r = Wp::startUpdate((string)$me['username'], (int)$req->post_('id', '0'), $parts);
    Audit::log('wp.update', (int)$me['id'], (string)$me['username'], 'wordpress',
        (string)$req->post_('id', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['parts' => $parts]);
    return $wpView($req, $r['ok'] ? null : (string)$r['error'], $r['ok'] ? (string)$r['message'] : null);
});

$router->post('/wordpress/staging', function (Request $req) use ($auth, $wpView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $id  = (int)$req->post_('id', '0');
    $cur = Wp::own((string)$me['username'], $id);
    $r   = Wp::setStaging((string)$me['username'], $id, $cur === null || (int)$cur['is_staging'] !== 1);
    Audit::log('wp.staging', (int)$me['id'], (string)$me['username'], 'wordpress',
        (string)$id, $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return $wpView($req, $r['ok'] ? null : (string)$r['error'], $r['ok'] ? (string)$r['message'] : null);
});

$router->post('/wordpress/forget', function (Request $req) use ($auth, $wpView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $id = (int)$req->post_('id', 0);
    $r  = Wp::forget((string)$me['username'], $id);
    Audit::log('wp.forget', (int)$me['id'], (string)$me['username'], 'wp_site', (string)$id,
        $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return Response::redirect('/wordpress?err=' . rawurlencode((string)$r['error']));
    }
    return Response::redirect('/wordpress?ok=' . rawurlencode((string)$r['message']));
});

$cronView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $u      = (string)$me['username'];
    $editId = (int)$req->query('edit', 0);
    $logId  = (int)$req->query('log', 0);

    return $page('cron', [
        'active'  => '/cron',
        'jobs'    => Cron::listing($u),
        'edit'    => $editId > 0 ? Cron::find($u, $editId) : null,
        'logJobId' => $logId,
        'logText' => $logId > 0 ? Cron::logTail($u, $logId) : '',
        'presets' => [
            'Setiap menit'      => '* * * * *',
            'Setiap 5 menit'    => '*/5 * * * *',
            'Setiap jam'        => '0 * * * *',
            'Setiap hari jam 3' => '0 3 * * *',
            'Setiap Minggu'     => '0 3 * * 0',
            'Tanggal 1 tiap bulan' => '0 4 1 * *',
        ],
        'error'   => $err,
        'ok'      => $ok,
    ]);
};

$router->get('/cron', fn (Request $req) => $cronView($req));

// Semua aksi cron lewat satu wrapper: CSRF + audit + redirect dengan flash.
$cronAction = function (string $route, callable $fn, string $auditName) use ($auth, $cronView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $cronView, $fn, $auditName): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 CSRF tidak valid', 419);
        }
        $u      = (string)$me['username'];
        $id     = (int)$req->post_('id', 0);
        $r      = $fn($u, $req, $id);
        Audit::log($auditName, (int)$me['id'], $u, 'cron', (string)$id, $req->ip(),
            $r['ok'] ? 'ok' : 'error', []);
        if (!$r['ok']) {
            return Response::redirect('/cron?err=' . rawurlencode((string)$r['error']));
        }
        return Response::redirect('/cron?ok=' . rawurlencode((string)$r['message']));
    });
};

$cronAction('/cron/create', function (string $u, Request $req, int $id): array {
    return Cron::create($u, (string)$req->post_('name', ''), (string)$req->post_('command', ''),
        (string)$req->post_('schedule', ''));
}, 'cron.create');

$cronAction('/cron/update', function (string $u, Request $req, int $id): array {
    return Cron::update($u, $id, (string)$req->post_('name', ''), (string)$req->post_('command', ''),
        (string)$req->post_('schedule', ''), null);
}, 'cron.update');

$cronAction('/cron/toggle', function (string $u, Request $req, int $id): array {
    $job = Cron::find($u, $id);
    if ($job === null) {
        return ['ok' => false, 'error' => 'Cron job tidak ditemukan.', 'message' => null];
    }
    return Cron::update($u, $id, null, null, null, (int)$job['enabled'] !== 1);
}, 'cron.toggle');

$cronAction('/cron/delete', function (string $u, Request $req, int $id): array {
    return Cron::delete($u, $id);
}, 'cron.delete');

$cronAction('/cron/run', function (string $u, Request $req, int $id): array {
    return Cron::runNow($u, $id);
}, 'cron.run');

$fileView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $u     = (string)$me['username'];
    $rel   = (string)$req->query('path', '');
    $list  = FileManager::listing($u, $rel);
    $edit  = null;
    $eFile = (string)$req->query('edit', '');
    if ($eFile !== '' && !empty($list['ok'])) {
        $edit = FileManager::readText($u, $rel, $eFile);
        if (!$edit['ok']) {
            $err = $edit['error'];
            $edit = null;
        }
    }
    return $page('files', [
        'active' => '/files',
        'list'   => $list,
        'edit'   => $edit,
        'used'   => FileManager::humanSize(FileManager::usedBytes($u)),
        'error'  => $err,
        'ok'     => $ok,
    ]);
};

$router->get('/files', fn (Request $req) => $fileView($req));

$fileAction = function (string $route, callable $fn) use ($auth, $fileView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $fileView, $fn): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 CSRF tidak valid', 419);
        }
        $r = $fn($me, $req, $req->ip());
        return $fileView($req, $r['error'], $r['ok'] ? ($r['msg'] ?? null) : null);
    });
};

// copy/move:
//   path    = folder ASAL (sama seperti semua aksi lain, dari form utama)
//   name    = nama item yang dipindahkan
//   to      = folder TUJUAN ('' = folder asal)
//   newname = nama baru saat dipindah (opsional)
$fileAction('/files/copy', function (array $me, Request $req): array {
    $from = (string)$req->post_('path', '');
    $to   = (string)$req->post_('to', '');
    $r = FileManager::copy((string)$me['username'], $from, (string)$req->post_('name', ''),
        $to, (string)$req->post_('newname', ''));
    Audit::log('files.copy', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['to' => $to]);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? ('Disalin ke ' . ($to === '' ? 'folder ini' : $to) . '.') : null];
});

$fileAction('/files/move', function (array $me, Request $req): array {
    $from = (string)$req->post_('path', '');
    $to   = (string)$req->post_('to', '');
    $r = FileManager::move((string)$me['username'], $from, (string)$req->post_('name', ''),
        $to, (string)$req->post_('newname', ''));
    Audit::log('files.move', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['to' => $to]);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? ('Dipindahkan ke ' . ($to === '' ? 'folder ini' : $to) . '.') : null];
});

$fileAction('/files/zip', function (array $me, Request $req): array {
    $r = FileManager::zip((string)$me['username'], (string)$req->post_('path', ''), (string)$req->post_('name', ''));
    Audit::log('files.zip', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => $r['ok'] ? ('Arsip ' . $r['zip'] . ' dibuat.') : null];
});

$fileAction('/files/unzip', function (array $me, Request $req): array {
    $r = FileManager::unzip((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('name', ''), (string)$req->post_('to', ''));
    Audit::log('files.unzip', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['files' => $r['files'] ?? 0]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => $r['ok'] ? ($r['files'] . ' item diekstrak ke ' . $r['dest'] . '.') : null];
});

$fileAction('/files/chmod', function (array $me, Request $req): array {
    // Form mengirim "644" atau "0644" sebagai TEKS. Harus dibaca sebagai
    // oktal (base 8): (int)'644' = 644 desimal, sedangkan permission 644
    // oktal = 420 desimal. Salah parse akan menolak mode yang sebenarnya valid.
    $raw = trim((string)$req->post_('mode', ''));
    if (!preg_match('/^0?[0-7]{3,4}$/', $raw)) {
        return ['ok' => false, 'error' => 'Mode harus oktal 000-777.', 'msg' => null];
    }
    $mode = (int)octdec($raw);
    $r = FileManager::chmod((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('name', ''), $mode);
    Audit::log('files.chmod', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['mode' => $r['mode'] ?? null]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => $r['ok'] ? ('Permission diubah ke ' . $r['mode'] . '.') : null];
});

// Unduhan lewat GET: tidak bisa pakai $fileAction karena itu POST + CSRF.
// CSRF tidak berlaku di sini karena tidak ada efek samping (hanya baca).
$router->get('/files/download', function (Request $req) use ($auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $d = FileManager::download((string)$me['username'],
        (string)$req->query('path', ''), (string)$req->query('name', ''));
    if ($d === null) {
        return Response::text('Berkas tidak ditemukan atau tidak boleh diunduh.', 404);
    }
    return Response::download($d['path'], $d['name']);
});

$fileAction('/files/mkdir', function (array $me, Request $req): array {
    $r = FileManager::mkdir((string)$me['username'], (string)$req->post_('path', ''), (string)$req->post_('name', ''));
    Audit::log('files.mkdir', (int)$me['id'], (string)$me['username'], 'dir',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['path' => $req->post_('path')]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'Folder dibuat.'];
});

$fileAction('/files/newfile', function (array $me, Request $req): array {
    $r = FileManager::newFile((string)$me['username'], (string)$req->post_('path', ''), (string)$req->post_('name', ''));
    Audit::log('files.newfile', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'File dibuat.'];
});

$fileAction('/files/rename', function (array $me, Request $req): array {
    $r = FileManager::rename((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('from', ''), (string)$req->post_('to', ''));
    Audit::log('files.rename', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('from', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['to' => $req->post_('to')]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'Nama diubah.'];
});

$fileAction('/files/save', function (array $me, Request $req): array {
    $r = FileManager::writeText((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('name', ''), (string)$req->post_('body', ''));
    Audit::log('files.save', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'File disimpan.'];
});

$fileAction('/files/delete', function (array $me, Request $req): array {
    $names = $req->post_('names');
    $names = is_array($names) ? array_map('strval', $names) : [(string)$req->post_('name', '')];
    $r = FileManager::delete((string)$me['username'], (string)$req->post_('path', ''), $names);
    Audit::log('files.delete', (int)$me['id'], (string)$me['username'], 'file', null, $req->ip(),
        $r['ok'] ? 'ok' : 'error', ['count' => count($names)]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => ($r['deleted'] ?? 0) . ' item dihapus.'];
});

$fileAction('/files/upload', function (array $me, Request $req): array {
    $u    = (string)$me['username'];
    $rel  = (string)$req->post_('path', '');
    $base = FileManager::resolve($u, $rel);
    $okN  = 0;
    $errs = [];
    $f    = $_FILES['files'] ?? null;
    if (is_array($f) && isset($f['name'])) {
        $n = is_array($f['name']) ? count($f['name']) : 1;
        for ($i = 0; $i < $n; $i++) {
            $name = is_array($f['name']) ? (string)$f['name'][$i] : (string)$f['name'];
            $tmp  = is_array($f['tmp_name']) ? (string)$f['tmp_name'][$i] : (string)$f['tmp_name'];
            $e    = is_array($f['error']) ? (int)$f['error'][$i] : (int)$f['error'];
            $sz   = is_array($f['size']) ? (int)$f['size'][$i] : (int)$f['size'];

            if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) {
                $errs[] = $name . ' (terlalu besar)';
                continue;
            }
            if ($e !== UPLOAD_ERR_OK) {
                $errs[] = $name . ' (upload gagal)';
                continue;
            }
            if ($sz > FileManager::MAX_UPLOAD) {
                $errs[] = $name . ' (maks 64 MB)';
                continue;
            }
            $safe = FileManager::validName(basename($name));
            if ($safe === null) {
                $errs[] = $name . ' (nama tidak valid)';
                continue;
            }
            if (FileManager::blockedExt($safe)) {
                $errs[] = $safe . ' (tipe .php diblokir)';
                continue;
            }
            if ($base === null || !is_dir($base)) {
                $errs[] = $safe . ' (folder tujuan tidak ada)';
                continue;
            }
            $dest = $base . '/' . $safe;
            if (is_uploaded_file($tmp) && @move_uploaded_file($tmp, $dest)) {
                @chown($dest, 'www-data');
                @chmod($dest, 0644);
                $okN++;
            } else {
                $errs[] = $safe . ' (gagal disimpan)';
            }
        }
    }
    Audit::log('files.upload', (int)$me['id'], (string)$me['username'], 'file', null, $req->ip(),
        $okN > 0 ? 'ok' : 'error', ['count' => $okN, 'path' => $rel]);
    $msg = $okN > 0 ? $okN . ' file diunggah.' : null;
    if ($errs !== []) {
        $msg = ($msg ? $msg . ' ' : '') . 'Dilewati: ' . implode('; ', array_slice($errs, 0, 3));
    }
    return [
        'ok'    => $okN > 0 || $errs === [],
        'error' => ($okN === 0 && $errs !== []) ? 'Tidak ada file yang terunggah.' : null,
        'msg'   => $msg,
    ];
});

// --- Software: PHP & Node nyata ---
$router->get('/software', function (Request $req) use ($page, $auth): Response {
    $rows = [];
    foreach (Panel::installedPhp() as $v) {
        $rows[] = ['PHP-FPM ' . $v, 'terinstall', $v === PHP_VERSION ? 'aktif (default FPM)' : 'tersedia'];
    }
    $rows[] = ['Node.js', 'belum', 'Node 22 / 24 — implementasi berikutnya'];
    $rows[] = ['WP-CLI', trim((string)(shell_exec('wp --version --allow-root 2>/dev/null') ?: 'belum')), 'app manager'];
    return $page('section', [
        'active' => '/software', 'title' => 'Software & Runtime',
        'lead' => 'Pilih versi runtime per domain. Perubahan memakai pool PHP-FPM terpisah.',
        'icon' => 'package', 'panel' => 'runtimes',
        'cols' => ['Runtime', 'Status', 'Keterangan'],
        'rows' => $rows, 'empty' => 'Belum ada runtime.',
    ]);
});

// --- Security: SSL nyata (belum ada sertifikat) ---
// Data halaman Security dipakai bersama oleh GET dan aksi POST supaya
// kode pemulihan (yang hanya ada sekali) tidak hilang di antara render.
$securityView = static function (array $me, array $extra = []) use ($auth): array {
    $u     = (string)$me['username'];
    $certs = Connection::all(
        'SELECT d.name AS domain, c.provider, c.status, c.expires_at FROM ssl_certs c
           JOIN domains d ON d.id = c.domain_id WHERE d.user_id = :u ORDER BY c.expires_at',
        ['u' => (int)$me['id']]
    );
    $secret = Totp::pendingSecret($u);
    return $extra + [
        'active'       => '/security',
        'title'        => 'Security',
        'lead'         => 'Sertifikat SSL dan autentikasi dua faktor untuk akun Anda.',
        'icon'         => 'shield',
        'certs'        => $certs,
        'totpEnabled'  => Totp::isEnabled($u),
        'totpSecret'   => $secret,
        'recovery'     => [],
        'recoveryLeft' => Totp::recoveryLeft($u),
        'totpNotice'   => Totp::securityNotice(),
        'devices'      => RememberDevice::listing((int)$me['id']),
        'currentDevice'=> RememberDevice::deviceId(
            (string)($_COOKIE[RememberDevice::COOKIE] ?? '')
        ),
        'error'        => null,
        'ok'           => null,
    ];
};

$router->get('/security', function (Request $req) use ($page, $auth, $securityView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $err = trim((string)$req->query('err', ''));
    $ok  = trim((string)$req->query('ok', ''));
    return $page('security', $securityView($me, [
        'error' => $err === '' ? null : $err,
        'ok'    => $ok === '' ? null : $ok,
    ]));
});

// QR dirender di server lewat biner qrencode: rahasia TOTP tidak keluar dari server.
$router->get('/security/2fa/qr.svg', function (Request $req) use ($auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::text('401', 401);
    }
    $secret = Totp::pendingSecret((string)$me['username']);
    if ($secret === null || $secret === '') {
        return Response::text('404', 404);
    }
    $svg = Totp::qrSvg(Totp::provisioningUri($secret, (string)$me['username']));
    if ($svg === null) {
        return Response::text('QR tidak bisa dirender di server ini', 500);
    }
    return Response::html($svg)->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
        ->withHeader('Cache-Control', 'no-store, private')
        ->withHeader('X-Content-Type-Options', 'nosniff');
});

/**
 * Aksi 2FA. Kalau aksi menghasilkan kode pemulihan, halamannya dirender
 * langsung supaya kode itu terlihat sekali lalu hilang (DB hanya menyimpan hash).
 */
$totpAction = static function (string $route, callable $fn, string $audit)
    use ($auth, $page, $router, $securityView): void {
    $router->post($route, static function (Request $req) use ($auth, $page, $fn, $audit, $securityView): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 CSRF tidak valid', 419);
        }
        $r = $fn((string)$me['username'], $req);
        Audit::log($audit, (int)$me['id'], (string)$me['username'], 'user',
            (int)$me['id'], $req->ip(), $r['ok'] ? 'ok' : 'error', []);
        if (!$r['ok']) {
            return Response::redirect('/security?err=' . rawurlencode((string)$r['error']));
        }
        $recovery = (array)($r['recovery'] ?? []);
        if ($recovery !== []) {
            return $page('security', $securityView($me, [
                'recovery' => $recovery,
                'ok'       => (string)$r['message'],
            ]));
        }
        return Response::redirect('/security?ok=' . rawurlencode((string)$r['message']));
    });
};

/**
 * Aksi "ingat perangkat ini": cabut satu perangkat atau semuanya.
 * Semua perangkat ikut dicabut saat password atau 2FA berubah.
 */
$deviceAction = static function (string $route, callable $fn, string $audit)
    use ($auth, $page, $router, $securityView): void {
    $router->post($route, static function (Request $req) use ($auth, $page, $fn, $audit, $securityView): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 CSRF tidak valid', 419);
        }
        $r = $fn($me, $req);
        Audit::log($audit, (int)$me['id'], (string)$me['username'], 'user',
            (int)$me['id'], $req->ip(), $r['ok'] ? 'ok' : 'error', []);
        if (!$r['ok']) {
            return Response::redirect('/security?err=' . rawurlencode((string)$r['error']));
        }
        // Kalau aksi menghasilkan data sekali-tampil (mis. tautan login), halaman
        // dirender langsung. Kalau tidak, data itu akan hilang setelah redirect.
        $once = $r['once'] ?? null;
        if (is_array($once)) {
            return $page('security', $securityView($me, $once + [
                'ok' => (string)$r['message'],
            ]));
        }
        return Response::redirect('/security?ok=' . rawurlencode((string)$r['message']));
    });
};

$deviceAction('/security/devices/revoke', static function (array $me, Request $req): array {
    $id  = (int)$req->post_('id', '0');
    $own = RememberDevice::listing((int)$me['id']);
    // ID dicek terhadap daftar milik user ini, jadi tidak bisa mencabut
    // perangkat orang lain hanya dengan menebak angka.
    if ($id < 1 || !in_array($id, array_column($own, 'id'), true)) {
        return ['ok' => false, 'error' => 'Perangkat tidak ditemukan.'];
    }
    RememberDevice::revoke($id);
    return ['ok' => true, 'error' => null, 'message' => 'Perangkat dicabut.'];
}, 'device.revoke');

$deviceAction('/security/devices/revoke-all', static function (array $me, Request $req): array {
    $n = RememberDevice::revokeAll((int)$me['id']);
    return ['ok' => true, 'error' => null,
        'message' => $n . ' perangkat dicabut dari semua perangkat.'];
}, 'device.revoke_all');

// Terbitkan tautan login sekali pakai lewat UI, lalu tampilkan tautannya.
// Setiap user boleh membuat tautan untuk akunnya sendiri, jadi tidak perlu
// dibatasi admin: yang dipanggil adalah issuance milik $me, bukan username
// dari request, jadi tidak bisa dibuatkan untuk akun orang lain.
$deviceAction('/security/login-link', static function (array $me, Request $req): array {
    $r = LoginLink::issue((string)$me['username'], (string)$req->ip());
    if (empty($r['ok'])) {
        return ['ok' => false, 'error' => (string)$r['error']];
    }
    // Tautan ditampilkan sekali di respons ini saja. Tidak disimpan di HTML
    // halaman lain dan tidak ikut query string redirect, jadi tidak bocor lewat
    // history atau log akses. Yang tersimpan di database tetap hash-nya.
    return ['ok' => true, 'error' => null,
        'message' => 'Tautan dibuat. Berlaku ' . LoginLink::TTL_MIN
            . ' menit dan hangus setelah dipakai.',
        'once'    => ['loginLink' => (string)$r['token'],
                      'loginLinkPath' => '/login/link/' . (string)$r['token']]];
}, 'login_link.issue');

$totpAction('/security/2fa/setup', static function (string $u, Request $req): array {
    $r = Totp::startSetup($u);
    if (!$r['ok']) {
        return $r + ['message' => null];
    }
    // Secret tidak pernah lewat query string: disimpan di DB lalu dibaca lagi.
    return ['ok' => true, 'error' => null, 'recovery' => [],
        'message' => 'Pindai QR lalu masukkan kode dari aplikasi untuk mengaktifkan.'];
}, 'totp.setup');

$totpAction('/security/2fa/activate', static function (string $u, Request $req): array {
    $r = Totp::activate($u, (string)$req->post_('code', ''));
    return $r + ['message' => $r['ok']
        ? '2FA aktif. Simpan kode pemulihan di bawah.'
        : null];
}, 'totp.activate');

$totpAction('/security/2fa/recovery', static function (string $u, Request $req): array {
    if (!Totp::isEnabled($u)) {
        return ['ok' => false, 'error' => '2FA belum aktif.', 'message' => null, 'recovery' => []];
    }
    return ['ok' => true, 'error' => null, 'recovery' => Totp::regenerateRecovery($u),
        'message' => 'Kode pemulihan baru dibuat. Simpan sekarang.'];
}, 'totp.recovery');

$totpAction('/security/2fa/disable', static function (string $u, Request $req): array {
    $row = Connection::one('SELECT password_hash FROM users WHERE username = :u', ['u' => $u]);
    if ($row === null
        || !PasswordHasher::verify((string)$req->post_('password', ''), (string)$row['password_hash'])) {
        return ['ok' => false, 'error' => 'Password salah.', 'message' => null, 'recovery' => []];
    }
    return Totp::disable($u) + ['message' => '2FA dimatikan.', 'recovery' => []];
}, 'totp.disable');

// --- Email, Backups, Account ---
$router->get('/email', fn (Request $r) => $page('section', [
    'active' => '/email', 'title' => 'Email', 'lead' => 'Email hosting menunggu IP dedicated (port 25 diblokir di shared IP).',
    'icon' => 'mail', 'panel' => '', 'cols' => [], 'rows' => [],
    'empty' => 'Fitur email belum aktif di server ini.',
]));

$router->get('/backups', function (Request $req) use ($page, $auth): Response {
    $rows = Connection::all(
        'SELECT b.kind, b.status, b.size_bytes, b.created_at FROM backups b
           WHERE b.user_id = :u ORDER BY b.id DESC',
        ['u' => (int)($auth->user()['id'] ?? 0)]
    );
    return $page('section', [
        'active' => '/backups', 'title' => 'Backup', 'lead' => 'Snapshot dan backup terjadwal untuk domain Anda.',
        'icon' => 'archive', 'panel' => '', 'cols' => ['Jenis', 'Status', 'Ukuran', 'Dibuat'],
        'rows' => array_map(static fn (array $r): array => [
            $r['kind'], $r['status'], \Fpanel\Service\Stats::bytes((int)$r['size_bytes']), $r['created_at'],
        ], $rows),
        'empty' => 'Belum ada backup.',
    ]);
});

$router->get('/account', fn (Request $req) => $page('section', [
    'active' => '/account', 'title' => 'Profil & Password', 'lead' => 'Kelola akun dan keamanan login Anda.',
    'icon' => 'user-round', 'panel' => 'form-account', 'err' => $req->query('error'), 'ok' => $req->query('ok'), 'cols' => [], 'rows' => [],
    'empty' => '',
]));

$router->post('/account/password', function (Request $req) use ($auth, $csrfToken): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 CSRF tidak valid', 419);
    }
    $cur = (string)$req->post_('current_password', '');
    $new = (string)$req->post_('new_password', '');
    $cfm = (string)$req->post_('confirm_password', '');

    $hash = Connection::value('SELECT password_hash FROM users WHERE id = :u', ['u' => (int)$me['id']]);
    if (!is_string($hash) || !password_verify($cur, $hash)) {
        return Response::redirect('/account?error=' . rawurlencode('Password saat ini salah.'));
    }
    if ($new !== $cfm) {
        return Response::redirect('/account?error=' . rawurlencode('Konfirmasi password tidak cocok.'));
    }
    $v = Validator::password($new);           // null = lolos, string = pesan galat
    if ($v !== null) {
        return Response::redirect('/account?error=' . rawurlencode($v));
    }
    Connection::run('UPDATE users SET password_hash = :h, updated_at = datetime(\'now\') WHERE id = :u', [
        'h' => password_hash($new, PASSWORD_ARGON2ID), 'u' => (int)$me['id'],
    ]);
    Audit::log('auth.password_change', (int)$me['id'], (string)$me['username'], 'user', (int)$me['id'], $req->ip());
    return Response::redirect('/account?ok=' . rawurlencode('Password berhasil diganti.'));
});

$res = $router->dispatch($request)
    ?? Response::html('<h1>404 Not Found</h1>', 404);
$res->withHeader('X-Frame-Options', 'SAMEORIGIN')
    ->withHeader('X-Content-Type-Options', 'nosniff')
    ->withHeader('Referrer-Policy', 'same-origin')
    ->send();
