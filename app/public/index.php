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

// CSRF token for forms/AJAX. It always issues a fresh token so it
// never expires even when GET /login has already been redirected.
$router->get('/api/csrf', function () use ($csrfToken): Response {
    return Response::json(['csrf' => $csrfToken()]);
});

// ---------- auth ----------
// Without this, /
// replies 404. The root must always have a destination: to the dashboard
// when signed in, to login when not.
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
            'error'  => 'Invalid CSRF token. Reload the page and try again.',
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
    // Correct password but 2FA active: login is not finished. attempt() has already
    // created the intermediate session, so the user goes to the code page, not the dashboard.
    if (!empty($r['need_totp'])) {
        return Response::redirect('/login/2fa');
    }
    // A device token is only issued after 2FA passes too. If it were issued
    // at the password step, anyone who knows the password could get remember-me
    // without ever going through the 2FA code.
    if ($remember && !empty($r['user_id'])) {
        RememberDevice::issue((int)$r['user_id'], $req->ip(), $req->userAgent());
    }
    return Response::redirect('/dashboard');
});

// One-time login link: /login/link/<token>
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
    // This page carries the token in the URL, so never let other pages
    // link back here through the Referer.
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
        return Response::text('419 invalid CSRF', 419);
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
        return Response::text('419 invalid CSRF', 419);
    }
    $auth->logout();
    return Response::redirect('/login');
});

/**
 * Extend the session from the "remember this device" cookie.
 *
 * Called by the login page when the device cookie is present but the session
 * is already gone. The new session is only short-lived (e.g. 7 days), not 90
 * days like the device cookie: the remember-me cookie is a revocable
 * credential, so sessions created from it need not live long as well.
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
        // Device revoked or account suspended: clear its cookie so it does
        // not keep retrying.
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

// ---------- global search ----------
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

// ---------- panel pages ----------
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

// --- Domains: real data + real create/delete forms ---
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
    // Days left come from certbot, not from a DB column, so the UI stays
    // correct even when certbot.timer has already renewed outside the app.
    foreach ($rows as &$r) {
        $r['ssl_days'] = LetsEncrypt::daysLeft((string)$r['name']);
        $r['ssl_live'] = LetsEncrypt::hasCert((string)$r['name']);
    }
    unset($r);
    return $page('section', [
        'active' => '/domains', 'title' => 'Domains', 'lead' => 'Manage your hosting domains and subdomains.',
        'icon' => 'globe', 'panel' => 'form-domain', 'noTable' => true,
        'rows' => $rows, 'empty' => 'No domains yet.',
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
        return Response::text('419 invalid CSRF', 419);
    }
    $name = (string)$req->post_('name', '');
    $type = (string)$req->post_('type', 'php');
    $php  = (string)$req->post_('php_version', '');
    // Only the value "1" counts as yes. A HTML checkbox sends "1" when
    // checked, and sends nothing when not; testing "!== ''" would
    // enable SSL if someone sends issue_tls=0.
    $wantTls = $req->post_('issue_tls', '') === '1';
    $r = DomainManager::create(
        (int)$me['id'], (string)$me['username'], $name, $type,
        $php === '' ? null : $php, $wantTls
    );
    if (!$r['ok']) {
        return $domainView($req, (string)$r['error']);
    }
    $ok = 'Domain ' . $r['domain']['name'] . ' created. The nginx vhost is already active.';
    // SSL failure does not cancel the domain, so it is reported as a warning.
    if (is_array($r['ssl'] ?? null)) {
        $ok .= $r['ssl']['ok']
            ? ' The SSL certificate is active as well.'
            : ' SSL failed: ' . $r['ssl']['message'];
    }
    return $domainView($req, null, $ok);
});

$router->post('/domains/ssl', function (Request $req) use ($domainView, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
    }
    $id   = (int)$req->post_('id', '0');
    $act  = (string)$req->post_('action', 'issue');
    $d = Connection::one(
        'SELECT id, name FROM domains WHERE id = :i AND user_id = :u',
        ['i' => $id, 'u' => (int)$me['id']]
    );
    if ($d === null) {
        return $domainView($req, 'Domain not found.');
    }

    if ($act === 'revoke') {
        $ok  = DomainManager::revokeCertificate((int)$d['id'], (string)$d['name']);
        $msg = $ok
            ? 'Certificate for ' . $d['name'] . ' revoked. The domain still serves over HTTP.'
            : 'The certificate could not be revoked. Remove it manually on the server.';
        Audit::log('domain.ssl.revoke', (int)$me['id'], (string)$me['username'], 'domain', $id, '', $ok ? 'ok' : 'error', ['name' => $d['name']]);
        // The vhost must be rebuilt so the 443 block goes away.
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
        return Response::text('419 invalid CSRF', 419);
    }
    $r = DomainManager::delete((int)$me['id'], (string)$me['username'], (int)$req->post_('id', '0'));
    // DomainManager may return ok=true while including an error, e.g. when
    // leftover files exist. Swallowing that error makes the user think it is clean.
    $warn = isset($r['error']) ? (string)$r['error'] : null;
    return $domainView(
        $req,
        $r['ok'] ? $warn : $warn,
        $r['ok'] ? 'Domain deleted.' : null
    );
});

// --- Databases: real CRUD through MariaDb (root helper) ---
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
    // Error message from a redirect (e.g. failing to create the phpMyAdmin token).
    $err = trim((string)$req->query('err', ''));
    return $dbView($req, $err === '' ? null : $err);
});

// Every DB action goes through one wrapper: auth + CSRF, then re-render the page
// with the result message (new credentials are passed on to the view too).
$dbAction = function (string $route, callable $fn) use ($auth, $dbView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $dbView, $fn): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 invalid CSRF', 419);
        }
        $r = $fn($me, $req);
        return $dbView($req, $r['error'], $r['ok'] ? ($r['msg'] ?? null) : null,
            $r['newCred'] ?? null);
    });
};

$dbAction('/databases/create', function (array $me, Request $req): array {
    $r = MariaDb::create((string)$me['username'],
        (string)$req->post_('db', ''), (string)$req->post_('user', ''),
        (string)$req->post_('priv', 'STANDARD'));
    Audit::log('db.create', (int)$me['id'], (string)$me['username'], 'database',
        (string)$req->post_('db', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => null, 'msg' => 'Database ' . $r['db'] . ' created.',
            'newCred' => ['db' => $r['db'], 'user' => $r['user'], 'password' => $r['password']]];
});

$dbAction('/databases/delete', function (array $me, Request $req): array {
    $id  = (int)$req->post_('db', 0);
    $r   = MariaDb::delete((string)$me['username'], $id);
    Audit::log('db.delete', (int)$me['id'], (string)$me['username'], 'database',
        (string)$id, $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? 'Database deleted.' : null];
});

$dbAction('/databases/user-add', function (array $me, Request $req): array {
    $r = MariaDb::addUser((string)$me['username'], (int)$req->post_('db', 0),
        (string)$req->post_('user', ''), (string)$req->post_('priv', 'STANDARD'));
    Audit::log('db.user_add', (int)$me['id'], (string)$me['username'], 'dbuser',
        (string)$req->post_('user', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => null, 'msg' => 'User ' . $r['user'] . ' added.',
            'newCred' => ['db' => (string)$req->post_('db', ''), 'user' => $r['user'], 'password' => $r['password']]];
});

$dbAction('/databases/user-delete', function (array $me, Request $req): array {
    $r = MariaDb::deleteUser((string)$me['username'], (int)$req->post_('db', 0),
        (int)$req->post_('dbuser', 0));
    Audit::log('db.user_delete', (int)$me['id'], (string)$me['username'], 'dbuser',
        (string)$req->post_('dbuser', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? 'Database user deleted.' : null];
});

// phpMyAdmin: one-time token, then redirect to /pma/<token>/.
// Deliberately POST (not GET) so the token does not leak from browser
// prefetch/links or from the access log of the database list page.
$router->post('/databases/phpmyadmin', function (Request $req) use ($auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
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

// --- Backup: list, create, verify, delete ------------------------------
// Restore is DELIBERATELY not here: restore overwrites the live database,
// so it can only be run from the shell by an operator.
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

// This page is admin-only: the archive list, sizes, and cron log leak
// information about the whole server. Regular users must not reach
// the view, so gate here (not only on each POST action).
$router->get('/backups', function (Request $req) use ($auth, $backupView) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 admins only', 403);
    }
    return $backupView($req);
});

/**
 * Backup actions, admin-only.
 *
 * A backup contains all panel data (SQLite + user files + databases), so
 * non-admin users must not see the list or trigger creation.
 */
$backupAction = function (string $route, callable $fn) use ($auth, $backupView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $backupView, $fn): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 invalid CSRF', 419);
        }
        if (($me['role'] ?? '') !== 'admin') {
            return Response::text('403 admins only', 403);
        }
        $r = $fn($me, $req);
        $back = '/backups';
        if (!$r['ok']) {
            return Response::redirect($back . '?err=' . rawurlencode((string)$r['error']));
        }
        $target = $back . '?ok=' . rawurlencode((string)($r['message'] ?? 'Done.'));
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
    // The report is shown whatever the outcome; the outcome is success so the
    // operator still reads the full report even when verification fails.
    return ['ok' => true, 'error' => null,
            'message' => $r['ok'] ? 'Verification of ' . $name . ' succeeded.'
                                   : 'Verification of ' . $name . ' FAILED.',
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
 * POST wrapper for app actions: auth + CSRF, then redirect with a message.
 * Delete deliberately uses its own wrapper because the UI needs a confirm.
 */
$appsAction = function (string $route, callable $fn) use ($auth, $appsView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $appsView, $fn): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 invalid CSRF', 419);
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
    // Folder relative to the docroot, not a free-form path from the user.
    $rel = trim((string)$req->post_('root', ''), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return ['ok' => false, 'error' => 'The app folder is not valid.'];
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
    // The vhost must be rebuilt so the domain proxies to the app port.
    $d = Connection::one('SELECT * FROM domains WHERE name = :n AND user_id = :u',
        ['n' => $domain, 'u' => (int)$me['id']]);
    if (is_array($d)) {
        \Fpanel\Service\DomainManager::rebuildFor((string)$me['username'], $d);
    }
    return ['ok' => true, 'error' => null,
            'message' => 'App ' . $r['name'] . ' created and running on port ' . $r['port'] . '.'];
});

$appsAction('/apps/action', function (array $me, Request $req): array {
    $id  = (int)$req->post_('app', 0);
    $act = (string)$req->post_('act', '');
    $u   = (string)$me['username'];
    $r = match ($act) {
        'start'   => AppRuntime::start($u, $id),
        'stop'    => AppRuntime::stop($u, $id),
        'restart' => AppRuntime::restart($u, $id),
        default   => ['ok' => false, 'error' => 'Unknown action.'],
    };
    Audit::log('app.' . ($act !== '' ? $act : 'unknown'), (int)$me['id'], $u, 'app',
        (string)$id, $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => $r['error']];
    }
    return ['ok' => true, 'error' => null, 'message' => 'App ' . $act . ' succeeded.'];
});

$router->post('/apps/logs', function (Request $req) use ($auth, $appsView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
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
    return ['ok' => true, 'error' => null, 'message' => 'App removed from the panel.'];
});

$appsAction('/apps/redeploy', function (array $me, Request $req): array {
    $domain = (string)$req->post_('domain', '');
    $d = Connection::one('SELECT * FROM domains WHERE name = :n AND user_id = :u',
        ['n' => $domain, 'u' => (int)$me['id']]);
    if (!is_array($d)) {
        return ['ok' => false, 'error' => 'Domain not found.'];
    }
    \Fpanel\Service\DomainManager::rebuildFor((string)$me['username'], $d);
    return ['ok' => true, 'error' => null, 'message' => 'Domain vhost rebuilt.'];
});

// --- PHP Extensions: toggle per PHP version ----------------------------------
$extView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth) {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $ver = (string)$req->query('ver', '');
    $u   = (string)$me['username'];
    $uid = (int)$me['id'];

    // In cPanel, Select PHP Version works at the ACCOUNT level: extensions and
    // php.ini apply to all of the user's domains, not one domain. That is why
    // there is no domain picker on this page; per-domain versions live in
    // MultiPHP Manager (/multiphp).
    $domains = Connection::all(
        'SELECT id, name, type FROM domains WHERE user_id = :u ORDER BY name',
        ['u' => $uid]
    );

    // php.ini values are shown once for the whole account. Overrides are merged
    // in order (last domain wins), then $mixed flags whether domains really
    // differ so the view can say so honestly.
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
        'lead'       => 'PHP versions, extensions, and PHP Settings for all of your domains.',
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
    // Toggle results are passed via the query string because the action POSTs
    // then redirects. Without this, the flash message would never show.
    $err = trim((string)$req->query('err', ''));
    $ok  = trim((string)$req->query('ok', ''));
    return $extView($req, $err === '' ? null : $err, $ok === '' ? null : $ok);
});

// ACCOUNT-level PHP version: applies to all domains whose php_version is empty
// (that is, the ones following the account version). Domains with an explicit
// version set by MultiPHP Manager are not touched.
$router->post('/php-extensions/version', function (Request $req) use ($auth, $extView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 admins only', 403);
    }
    $u   = (string)$me['username'];
    $ver = (string)$req->post_('version', '');
    if (!in_array($ver, Vhost::installedPhpVersions(), true)) {
        return Response::redirect('/php-extensions?err=' . rawurlencode('Unknown PHP version.'));
    }

    // Stored in the settings table so other views can use it as a reference.
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
        . '&ok=' . rawurlencode("PHP $ver applied to $changed domain(s)."));
});

// Apply all extensions for one version at once (phpSelector style:
// a single Apply button, not one request per extension).
$router->post('/php-extensions/apply', function (Request $req) use ($auth, $extView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 admins only', 403);
    }
    $ver = (string)$req->post_('version', '');
    $cur = (array)$req->post_('cur', []);
    $on  = (array)$req->post_('on', []);

    // Only extension names that really exist in this version are processed,
    // so a crafted request cannot touch other files.
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
            continue; // unchanged, do not restart the pool needlessly
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
        ? 'No extension changes.'
        : count($changed) . ' extensions updated for PHP ' . $ver . '.';
    return Response::redirect($back . '&ok=' . rawurlencode($msg));
});

// --- MultiPHP Manager: PHP version per domain -------------------------------
$multiphpView = function (Request $req, ?string $err = null, ?string $ok = null) use ($page, $auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    return $page('multiphp', [
        'active'    => '/multiphp',
        'title'     => 'MultiPHP Manager',
        'lead'      => 'Choose the PHP version for each domain.',
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
        return Response::text('419 invalid CSRF', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 admins only', 403);
    }
    $u       = (string)$me['username'];
    $picked  = array_map('intval', array_keys((array)$req->post_('dom', [])));
    $vers    = (array)$req->post_('ver', []);
    $allowed = Vhost::installedPhpVersions();

    // Only domains owned by this user may be changed.
    // type <> 'static': static domains do not run PHP, so they have no
    // version. Either way, their version select is not shown in the view.
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
        return Response::redirect($back . '?ok=' . rawurlencode('No versions changed.'));
    }
    return Response::redirect($back . '?ok=' . rawurlencode("PHP version updated for $changed domain(s)."));
});

$router->post('/php-extensions/toggle', function (Request $req) use ($auth, $extView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
    }
    $me = $auth->user();
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 admins only', 403);
    }
    $ver  = (string)$req->post_('version', '');
    $ext  = (string)$req->post_('ext', '');
    $on   = (string)$req->post_('state', '') === '1';
    $r    = PhpExtensions::toggle($ver, $ext, $on);
    Audit::log('php.extension', (int)$me['id'], (string)$me['username'], 'extension',
        $ext, $req->ip(), $r['ok'] ? 'ok' : 'error', ['version' => $ver, 'enabled' => $on]);
    $back = '/php-extensions?ver=' . rawurlencode($ver);
    // The extension checkbox form also sends the selected domain so the
    // redirect returns to the same settings panel, not to the first domain.
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
        return Response::text('419 invalid CSRF', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 admins only', 403);
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
        return Response::text('419 invalid CSRF', 419);
    }
    $id = (int)$req->post_('domain', 0);
    $u  = (string)$me['username'];
    $action = (string)$req->post_('act', 'save');

    // All fields prefixed with "ini_" are picked up at once; do not read them by
    // name so new directives are saved automatically too. The mapping from field
    // names to directives (including dotted ones like opcache.enable) lives in
    // PhpSettings::fromForm().
    $input = PhpSettings::fromForm($_POST);

    // scope=all: from the Select PHP Version page, php.ini applies to every
    // domain the user owns (not one domain), so each domain is saved with the
    // same values and the pool is reloaded once at the end.
    $scopeAll = (string)$req->post_('scope', '') === 'all';
    if ($scopeAll) {
        $ids = array_map('intval', array_column(Connection::all(
            'SELECT id FROM domains WHERE user_id = :u', ['u' => (int)$me['id']]
        ), 'id'));
        if ($ids === []) {
            return Response::redirect('/php-extensions?err=' . rawurlencode('No domains yet.'));
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
        // Reload the pool per used version, once for each version.
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
            ? 'PHP Settings reset to the defaults for all domains.'
            : 'PHP Settings saved for ' . count($ids) . ' domain(s).';
        return Response::redirect($back2 . $sep2 . 'ok=' . rawurlencode($msg2));
    }

    $r = $action === 'reset'
        ? PhpSettings::reset($u, $id)
        : PhpSettings::save($u, $id, $input);

    Audit::log('domain.php_settings', (int)$me['id'], $u, 'domain', (string)$id,
        $req->ip(), $r['ok'] ? 'ok' : 'error', ['action' => $action]);

    // If the form was submitted from the PHP Extensions page, go back there; if
    // from the Domains page, stay on the Domains page as before.
    $back = '/domains';
    $to   = trim((string)$req->post_('back', ''));
    if (str_starts_with($to, '/php-extensions')) {
        $back = '/php-extensions?ver=' . rawurlencode(trim((string)$req->post_('ver', '')));
    }
    // The separator must follow $back: if a query string already exists, use "&".
    $sep = str_contains($back, '?') ? '&' : '?';
    if (!$r['ok']) {
        return Response::redirect($back . $sep . 'err=' . rawurlencode((string)$r['error']));
    }
    $msg = $action === 'reset' ? 'PHP settings restored to defaults.' : 'PHP settings saved.';
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
    // Deliberately does not show the password in the URL: this is POST, so it does not reach history.
    return ['ok' => true, 'error' => null,
            'newCred' => ['db' => '', 'user' => '', 'password' => $r['password']]];
});

// --- Files: File Manager (browse, upload, edit, rename, delete) ---
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

// Recalculate now (admin). Normal collection runs hourly from cron.
$router->post('/usage/collect', function (Request $req) use ($auth, $usageView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
    }
    if (($me['role'] ?? '') !== 'admin') {
        return Response::text('403 admins only', 403);
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
        // Only PHP domains can be install targets.
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
        return Response::text('419 invalid CSRF', 419);
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
        'title'  => 'Manage ' . (string)$ov['data']['domain'],
        'ov'     => $ov['data'],
    ]);
});

$router->post('/wordpress/update', function (Request $req) use ($auth, $wpView): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
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
        return Response::text('419 invalid CSRF', 419);
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
        return Response::text('419 invalid CSRF', 419);
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
            'Every minute'      => '* * * * *',
            'Every 5 minutes'   => '*/5 * * * *',
            'Every hour'        => '0 * * * *',
            'Every day at 3'    => '0 3 * * *',
            'Every Sunday'      => '0 3 * * 0',
            'Day 1 each month'  => '0 4 1 * *',
        ],
        'error'   => $err,
        'ok'      => $ok,
    ]);
};

$router->get('/cron', fn (Request $req) => $cronView($req));

// Every cron action goes through one wrapper: CSRF + audit + redirect with a flash.
$cronAction = function (string $route, callable $fn, string $auditName) use ($auth, $cronView, $router): void {
    $router->post($route, function (Request $req) use ($auth, $cronView, $fn, $auditName): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 invalid CSRF', 419);
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
        return ['ok' => false, 'error' => 'Cron job not found.', 'message' => null];
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
            return Response::text('419 invalid CSRF', 419);
        }
        $r = $fn($me, $req, $req->ip());
        return $fileView($req, $r['error'], $r['ok'] ? ($r['msg'] ?? null) : null);
    });
};

// copy/move:
//   path    = SOURCE folder (same as all other actions, from the main form)
//   name    = name of the item being moved
//   to      = DESTINATION folder ('' = source folder)
//   newname = new name when moved (optional)
$fileAction('/files/copy', function (array $me, Request $req): array {
    $from = (string)$req->post_('path', '');
    $to   = (string)$req->post_('to', '');
    $r = FileManager::copy((string)$me['username'], $from, (string)$req->post_('name', ''),
        $to, (string)$req->post_('newname', ''));
    Audit::log('files.copy', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['to' => $to]);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? ('Copied to ' . ($to === '' ? 'this folder' : $to) . '.') : null];
});

$fileAction('/files/move', function (array $me, Request $req): array {
    $from = (string)$req->post_('path', '');
    $to   = (string)$req->post_('to', '');
    $r = FileManager::move((string)$me['username'], $from, (string)$req->post_('name', ''),
        $to, (string)$req->post_('newname', ''));
    Audit::log('files.move', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['to' => $to]);
    return ['ok' => $r['ok'], 'error' => $r['error'],
            'msg' => $r['ok'] ? ('Moved to ' . ($to === '' ? 'this folder' : $to) . '.') : null];
});

$fileAction('/files/zip', function (array $me, Request $req): array {
    $r = FileManager::zip((string)$me['username'], (string)$req->post_('path', ''), (string)$req->post_('name', ''));
    Audit::log('files.zip', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => $r['ok'] ? ('Archive ' . $r['zip'] . ' created.') : null];
});

$fileAction('/files/unzip', function (array $me, Request $req): array {
    $r = FileManager::unzip((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('name', ''), (string)$req->post_('to', ''));
    Audit::log('files.unzip', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['files' => $r['files'] ?? 0]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => $r['ok'] ? ($r['files'] . ' item(s) extracted to ' . $r['dest'] . '.') : null];
});

$fileAction('/files/chmod', function (array $me, Request $req): array {
    // The form sends "644" or "0644" as TEXT. It must be read as
    // octal (base 8): (int)'644' = decimal 644, while permission 644
    // octal = decimal 420. A wrong parse would reject actually valid modes.
    $raw = trim((string)$req->post_('mode', ''));
    if (!preg_match('/^0?[0-7]{3,4}$/', $raw)) {
        return ['ok' => false, 'error' => 'Mode must be octal 000-777.', 'msg' => null];
    }
    $mode = (int)octdec($raw);
    $r = FileManager::chmod((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('name', ''), $mode);
    Audit::log('files.chmod', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['mode' => $r['mode'] ?? null]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => $r['ok'] ? ('Permissions changed to ' . $r['mode'] . '.') : null];
});

// Download via GET: $fileAction cannot be used because it is POST + CSRF.
// CSRF does not apply here because there are no side effects (read only).
$router->get('/files/download', function (Request $req) use ($auth): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    $d = FileManager::download((string)$me['username'],
        (string)$req->query('path', ''), (string)$req->query('name', ''));
    if ($d === null) {
        return Response::text('File not found or not downloadable.', 404);
    }
    return Response::download($d['path'], $d['name']);
});

$fileAction('/files/mkdir', function (array $me, Request $req): array {
    $r = FileManager::mkdir((string)$me['username'], (string)$req->post_('path', ''), (string)$req->post_('name', ''));
    Audit::log('files.mkdir', (int)$me['id'], (string)$me['username'], 'dir',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['path' => $req->post_('path')]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'Folder created.'];
});

$fileAction('/files/newfile', function (array $me, Request $req): array {
    $r = FileManager::newFile((string)$me['username'], (string)$req->post_('path', ''), (string)$req->post_('name', ''));
    Audit::log('files.newfile', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'File created.'];
});

$fileAction('/files/rename', function (array $me, Request $req): array {
    $r = FileManager::rename((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('from', ''), (string)$req->post_('to', ''));
    Audit::log('files.rename', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('from', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', ['to' => $req->post_('to')]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'Name changed.'];
});

$fileAction('/files/save', function (array $me, Request $req): array {
    $r = FileManager::writeText((string)$me['username'], (string)$req->post_('path', ''),
        (string)$req->post_('name', ''), (string)$req->post_('body', ''));
    Audit::log('files.save', (int)$me['id'], (string)$me['username'], 'file',
        (string)$req->post_('name', ''), $req->ip(), $r['ok'] ? 'ok' : 'error', []);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => 'File saved.'];
});

$fileAction('/files/delete', function (array $me, Request $req): array {
    $names = $req->post_('names');
    $names = is_array($names) ? array_map('strval', $names) : [(string)$req->post_('name', '')];
    $r = FileManager::delete((string)$me['username'], (string)$req->post_('path', ''), $names);
    Audit::log('files.delete', (int)$me['id'], (string)$me['username'], 'file', null, $req->ip(),
        $r['ok'] ? 'ok' : 'error', ['count' => count($names)]);
    return ['ok' => $r['ok'], 'error' => $r['error'], 'msg' => ($r['deleted'] ?? 0) . ' items deleted.'];
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
                $errs[] = $name . ' (too large)';
                continue;
            }
            if ($e !== UPLOAD_ERR_OK) {
                $errs[] = $name . ' (upload failed)';
                continue;
            }
            if ($sz > FileManager::MAX_UPLOAD) {
                $errs[] = $name . ' (maks 64 MB)';
                continue;
            }
            $safe = FileManager::validName(basename($name));
            if ($safe === null) {
                $errs[] = $name . ' (invalid name)';
                continue;
            }
            if (FileManager::blockedExt($safe)) {
                $errs[] = $safe . ' (.php type blocked)';
                continue;
            }
            if ($base === null || !is_dir($base)) {
                $errs[] = $safe . ' (destination folder does not exist)';
                continue;
            }
            $dest = $base . '/' . $safe;
            if (is_uploaded_file($tmp) && @move_uploaded_file($tmp, $dest)) {
                @chown($dest, 'www-data');
                @chmod($dest, 0644);
                $okN++;
            } else {
                $errs[] = $safe . ' (failed to save)';
            }
        }
    }
    Audit::log('files.upload', (int)$me['id'], (string)$me['username'], 'file', null, $req->ip(),
        $okN > 0 ? 'ok' : 'error', ['count' => $okN, 'path' => $rel]);
    $msg = $okN > 0 ? $okN . ' file(s) uploaded.' : null;
    if ($errs !== []) {
        $msg = ($msg ? $msg . ' ' : '') . 'Skipped: ' . implode('; ', array_slice($errs, 0, 3));
    }
    return [
        'ok'    => $okN > 0 || $errs === [],
        'error' => ($okN === 0 && $errs !== []) ? 'No files were uploaded.' : null,
        'msg'   => $msg,
    ];
});

// --- Software: real PHP & Node ---
$router->get('/software', function (Request $req) use ($page, $auth): Response {
    $rows = [];
    foreach (Panel::installedPhp() as $v) {
        $rows[] = ['PHP-FPM ' . $v, 'installed', $v === PHP_VERSION ? 'active (FPM default)' : 'available'];
    }
    $rows[] = ['Node.js', 'not yet', 'Node 22 / 24 — planned for a future release'];
    $rows[] = ['WP-CLI', trim((string)(shell_exec('wp --version --allow-root 2>/dev/null') ?: 'not yet')), 'app manager'];
    return $page('section', [
        'active' => '/software', 'title' => 'Software & Runtime',
        'lead' => 'Choose the runtime version per domain. Changes use a separate PHP-FPM pool.',
        'icon' => 'package', 'panel' => 'runtimes',
        'cols' => ['Runtime', 'Status', 'Keterangan'],
        'rows' => $rows, 'empty' => 'No runtimes yet.',
    ]);
});

// --- Security: real SSL (no certificate yet) ---
// Security page data is shared by the GET and the POST actions so the
// recovery codes (which exist only once) are not lost between renders.
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
        'lead'         => 'SSL certificates and two-factor authentication for your account.',
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

// The QR is rendered on the server via the qrencode binary: the TOTP secret never leaves the server.
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
        return Response::text('The QR code cannot be rendered on this server', 500);
    }
    return Response::html($svg)->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
        ->withHeader('Cache-Control', 'no-store, private')
        ->withHeader('X-Content-Type-Options', 'nosniff');
});

/**
 * 2FA actions. When an action produces recovery codes, the page is rendered
 * directly so the codes are visible once and then gone (the DB stores hashes only).
 */
$totpAction = static function (string $route, callable $fn, string $audit)
    use ($auth, $page, $router, $securityView): void {
    $router->post($route, static function (Request $req) use ($auth, $page, $fn, $audit, $securityView): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 invalid CSRF', 419);
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
 * "Remember this device" actions: revoke one device or all of them.
 * All devices are also revoked when the password or 2FA changes.
 */
$deviceAction = static function (string $route, callable $fn, string $audit)
    use ($auth, $page, $router, $securityView): void {
    $router->post($route, static function (Request $req) use ($auth, $page, $fn, $audit, $securityView): Response {
        $me = $auth->user();
        if ($me === null) {
            return Response::redirect('/login');
        }
        if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
            return Response::text('419 invalid CSRF', 419);
        }
        $r = $fn($me, $req);
        Audit::log($audit, (int)$me['id'], (string)$me['username'], 'user',
            (int)$me['id'], $req->ip(), $r['ok'] ? 'ok' : 'error', []);
        if (!$r['ok']) {
            return Response::redirect('/security?err=' . rawurlencode((string)$r['error']));
        }
        // If the action produces show-once data (e.g. a login link), the page is
        // rendered directly. Otherwise that data would be lost after the redirect.
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
    // The ID is checked against this user's own list, so it is not possible to
    // revoke someone else's device just by guessing a number.
    if ($id < 1 || !in_array($id, array_column($own, 'id'), true)) {
        return ['ok' => false, 'error' => 'Device not found.'];
    }
    RememberDevice::revoke($id);
    return ['ok' => true, 'error' => null, 'message' => 'Device revoked.'];
}, 'device.revoke');

$deviceAction('/security/devices/revoke-all', static function (array $me, Request $req): array {
    $n = RememberDevice::revokeAll((int)$me['id']);
    return ['ok' => true, 'error' => null,
        'message' => $n . ' devices revoked from all devices.'];
}, 'device.revoke_all');

// Issue a one-time login link through the UI, then display the link.
// Any user may create a link for their own account, so there is no need to
// restrict it to admin: the issuance uses $me, not a username from the
// request, so a link cannot be created for someone else's account.
$deviceAction('/security/login-link', static function (array $me, Request $req): array {
    $r = LoginLink::issue((string)$me['username'], (string)$req->ip());
    if (empty($r['ok'])) {
        return ['ok' => false, 'error' => (string)$r['error']];
    }
    // The link is shown once, only in this response. It is not stored in the
    // HTML of other pages and not carried in the redirect query string, so it
    // does not leak through history or access logs. The DB stores only its hash.
    return ['ok' => true, 'error' => null,
        'message' => 'Link created. Valid for ' . LoginLink::TTL_MIN
            . ' minutes and expires after use.',
        'once'    => ['loginLink' => (string)$r['token'],
                      'loginLinkPath' => '/login/link/' . (string)$r['token']]];
}, 'login_link.issue');

$totpAction('/security/2fa/setup', static function (string $u, Request $req): array {
    $r = Totp::startSetup($u);
    if (!$r['ok']) {
        return $r + ['message' => null];
    }
    // The secret never goes through the query string: it is stored in the DB and read back.
    return ['ok' => true, 'error' => null, 'recovery' => [],
        'message' => 'Scan the QR code, then enter the code from your app to enable it.'];
}, 'totp.setup');

$totpAction('/security/2fa/activate', static function (string $u, Request $req): array {
    $r = Totp::activate($u, (string)$req->post_('code', ''));
    return $r + ['message' => $r['ok']
        ? '2FA is active. Save the recovery codes below.'
        : null];
}, 'totp.activate');

$totpAction('/security/2fa/recovery', static function (string $u, Request $req): array {
    if (!Totp::isEnabled($u)) {
        return ['ok' => false, 'error' => '2FA is not active yet.', 'message' => null, 'recovery' => []];
    }
    return ['ok' => true, 'error' => null, 'recovery' => Totp::regenerateRecovery($u),
        'message' => 'New recovery codes were generated. Save them now.'];
}, 'totp.recovery');

$totpAction('/security/2fa/disable', static function (string $u, Request $req): array {
    $row = Connection::one('SELECT password_hash FROM users WHERE username = :u', ['u' => $u]);
    if ($row === null
        || !PasswordHasher::verify((string)$req->post_('password', ''), (string)$row['password_hash'])) {
        return ['ok' => false, 'error' => 'Wrong password.', 'message' => null, 'recovery' => []];
    }
    return Totp::disable($u) + ['message' => '2FA disabled.', 'recovery' => []];
}, 'totp.disable');

// --- Email, Backups, Account ---
$router->get('/email', fn (Request $r) => $page('section', [
    'active' => '/email', 'title' => 'Email', 'lead' => 'Email hosting is waiting for a dedicated IP (port 25 is blocked on the shared IP).',
    'icon' => 'mail', 'panel' => '', 'cols' => [], 'rows' => [],
    'empty' => 'The email feature is not active on this server yet.',
]));

$router->get('/backups', function (Request $req) use ($page, $auth): Response {
    $rows = Connection::all(
        'SELECT b.kind, b.status, b.size_bytes, b.created_at FROM backups b
           WHERE b.user_id = :u ORDER BY b.id DESC',
        ['u' => (int)($auth->user()['id'] ?? 0)]
    );
    return $page('section', [
        'active' => '/backups', 'title' => 'Backup', 'lead' => 'Snapshots and scheduled backups for your domains.',
        'icon' => 'archive', 'panel' => '', 'cols' => ['Type', 'Status', 'Size', 'Created'],
        'rows' => array_map(static fn (array $r): array => [
            $r['kind'], $r['status'], \Fpanel\Service\Stats::bytes((int)$r['size_bytes']), $r['created_at'],
        ], $rows),
        'empty' => 'No backups yet.',
    ]);
});

$router->get('/account', fn (Request $req) => $page('section', [
    'active' => '/account', 'title' => 'Profile & Password', 'lead' => 'Manage your account and login security.',
    'icon' => 'user-round', 'panel' => 'form-account', 'err' => $req->query('error'), 'ok' => $req->query('ok'), 'cols' => [], 'rows' => [],
    'empty' => '',
]));

$router->post('/account/password', function (Request $req) use ($auth, $csrfToken): Response {
    $me = $auth->user();
    if ($me === null) {
        return Response::redirect('/login');
    }
    if (!Csrf::check($req->cookie(Csrf::COOKIE), $req->post_('_csrf'))) {
        return Response::text('419 invalid CSRF', 419);
    }
    $cur = (string)$req->post_('current_password', '');
    $new = (string)$req->post_('new_password', '');
    $cfm = (string)$req->post_('confirm_password', '');

    $hash = Connection::value('SELECT password_hash FROM users WHERE id = :u', ['u' => (int)$me['id']]);
    if (!is_string($hash) || !password_verify($cur, $hash)) {
        return Response::redirect('/account?error=' . rawurlencode('The current password is wrong.'));
    }
    if ($new !== $cfm) {
        return Response::redirect('/account?error=' . rawurlencode('The password confirmation does not match.'));
    }
    $v = Validator::password($new);           // null = passed, string = error message
    if ($v !== null) {
        return Response::redirect('/account?error=' . rawurlencode($v));
    }
    Connection::run('UPDATE users SET password_hash = :h, updated_at = datetime(\'now\') WHERE id = :u', [
        'h' => password_hash($new, PASSWORD_ARGON2ID), 'u' => (int)$me['id'],
    ]);
    Audit::log('auth.password_change', (int)$me['id'], (string)$me['username'], 'user', (int)$me['id'], $req->ip());
    return Response::redirect('/account?ok=' . rawurlencode('Password changed successfully.'));
});

$res = $router->dispatch($request)
    ?? Response::html('<h1>404 Not Found</h1>', 404);
$res->withHeader('X-Frame-Options', 'SAMEORIGIN')
    ->withHeader('X-Content-Type-Options', 'nosniff')
    ->withHeader('Referrer-Policy', 'same-origin')
    ->send();
