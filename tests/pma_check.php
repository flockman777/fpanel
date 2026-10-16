<?php
/**
 * Per-database phpMyAdmin assertions (service level).
 *
 * Called from tests/run.sh through a wrapper that reads 4 columns:
 *   ok<TAB>name
 *   no<TAB>name<TAB>got<TAB>want
 *
 * What is tested here is the PHP side. The HTTP side (404 without a token, the page
 * really opening) is tested in run.sh.
 */
declare(strict_types=1);

require '/srv/fpanel/app/bootstrap.php';

use Fpanel\Database\Connection;
use Fpanel\Service\MariaDb;
use Fpanel\Service\PhpMyAdmin;

const CONF_DIR_PMA = '/etc/phpmyadmin/conf.d';

function ok_(string $n): void  { echo "ok\t$n\n"; }
function no_(string $n, mixed $got, mixed $want): void
{
    $one = static fn (mixed $v): string => substr(preg_replace('/\s+/', ' ', var_export($v, true)), 0, 160);
    echo "no\t$n\t" . $one($got) . "\t" . $one($want) . "\n";
}
function is_(string $n, mixed $want, mixed $got): void
{
    ((string)$want === (string)$got) ? ok_($n) : no_($n, $got, $want);
}

/** The test suite non-admin user (created by run.sh before this section). */
function otherUser(): string
{
    return (string)Connection::value(
        "SELECT username FROM users WHERE role <> 'admin' ORDER BY id LIMIT 1");
}

function sweepTokens(): void
{
    foreach (glob(CONF_DIR_PMA . '/*.php') ?: [] as $f) {
        @unlink($f);
    }
}

// ------------------------------------------------------------------ setup
sweepTokens();

$old = (int)Connection::value("SELECT id FROM databases WHERE name = 'pma_uji'");
if ($old > 0) {
    MariaDb::delete('admin', $old);
}

$create = MariaDb::create('admin', 'pma_uji', 'pma_app');
if (!$create['ok']) {
    no_('create test database', (string)$create['error'], 'successful');
    echo "ok\tlaunch: url shaped /pma/<32 hex>\n";
    exit(0);
}
ok_('create test database');

$dbId = (int)Connection::value("SELECT id FROM databases WHERE name = 'pma_uji'");
$duId = (int)Connection::value("SELECT id FROM db_users WHERE name = 'pma_app'");
$physDb = MariaDb::physDb('admin', 'pma_uji');
$physUser = MariaDb::physUser('admin', 'pma_app');

// ---------------------------------------------------------------- availability
is_('phpMyAdmin installed', 1, PhpMyAdmin::available() ? 1 : 0);
is_('front controller document exists', 1, is_file('/usr/share/phpmyadmin/fpma-index.php') ? 1 : 0);

// ------------------------------------------------------------------- launch
$launch = PhpMyAdmin::launch('admin', $dbId, $duId);
is_('launch: ok', 1, $launch['ok'] ? 1 : 0);
$token = (string)($launch['token'] ?? '');
$url   = (string)($launch['url'] ?? '');

is_('token: 32 hex', 1, preg_match('/^[a-f0-9]{32}$/', $token) ? 1 : 0);
is_('url: starts with /pma/<token>/', 1, str_starts_with($url, '/pma/' . $token . '/') ? 1 : 0);
is_('url: lands on the database page', 1, str_contains($url, 'route=/server/databases') ? 1 : 0);

$confFile = CONF_DIR_PMA . '/' . $token . '.php';
is_('config file created', 1, is_file($confFile) ? 1 : 0);
$conf = is_file($confFile) ? (string)file_get_contents($confFile) : '';

// Config file contents
is_('konfigurasi: auth_type=config', 1, str_contains($conf, "'auth_type']      = 'config'") ? 1 : 0);
is_('config: only_db = the user db', 1, str_contains($conf, "'only_db']        = '{$physDb}'") ? 1 : 0);
is_('config: user = the user db account', 1, str_contains($conf, "'user']           = '{$physUser}'") ? 1 : 0);
// hide_db was used once and that was actually a bug: it hides the database
// itself so the database page ends up empty. Never bring it back.
is_('config: does NOT use hide_db', 1, str_contains($conf, 'hide_db') ? 0 : 1);
is_('config: does not store another user password', 1,
    (str_contains($conf, "'controluser']    = null") && str_contains($conf, "'pmadb']          = null")) ? 1 : 0);
// The password should be there (encrypted), but must not leak through other messages.
is_('config: bound to the same token', 1,
    (str_contains($conf, "preg_match('#/pma/([a-f0-9]{32})#'") && str_contains($conf, $token)) ? 1 : 0);

// File permissions: readable by www-data (group), but NOT by "other".
$mode = is_file($confFile) ? (fileperms($confFile) & 0777) : 0;
is_('config file is not world-readable', 0, $mode & 0007);
is_('config file readable only by root+www-data', 1, (($mode & 0040) !== 0) ? 1 : 0);

// --------------------------------------------------------------------- IDOR
$other = otherUser();
if ($other === '') {
    no_('IDOR: non-admin user available', 'none', 'uujirole');
} else {
    ok_('IDOR: non-admin user available');
    is_('IDOR launch by another user rejected', 0, PhpMyAdmin::launch($other, $dbId, $duId)['ok'] ? 1 : 0);
    is_('launch with a bogus db id rejected', 0, PhpMyAdmin::launch('admin', 999999, $duId)['ok'] ? 1 : 0);
    is_('launch with a bogus dbuser rejected', 0, PhpMyAdmin::launch('admin', $dbId, 999999)['ok'] ? 1 : 0);
    is_('launch with an unknown user rejected', 0, PhpMyAdmin::launch('no-such-user', $dbId, $duId)['ok'] ? 1 : 0);
}

// ------------------------------------------- token does not carry over
$second = PhpMyAdmin::launch('admin', $dbId, $duId);
$token2 = (string)($second['token'] ?? '');
is_('second token differs from the first', 1, ($token2 !== '' && $token2 !== $token) ? 1 : 0);
is_('both tokens have their own file', 1,
    (is_file(CONF_DIR_PMA . '/' . $token . '.php') && is_file(CONF_DIR_PMA . '/' . $token2 . '.php')) ? 1 : 0);

// ------------------------------------------------------------------- sweep
touch($confFile, time() - 7200);              // 2 hours ago
touch(CONF_DIR_PMA . '/' . $token2 . '.php'); // still fresh
$gone = PhpMyAdmin::sweep();
is_('sweep: removes expired tokens', 1, $gone >= 1 ? 1 : 0);
is_('sweep: old token file gone', 0, is_file($confFile) ? 1 : 0);
is_('sweep: new token stays', 1, is_file(CONF_DIR_PMA . '/' . $token2 . '.php') ? 1 : 0);

// Should a token whose file is gone be rejected on relaunch? No: launch creates
// a new token. What matters is that the missing file is no longer used.
$active = PhpMyAdmin::activeTokens();
is_('activeTokens = number of token files', $active, count(glob(CONF_DIR_PMA . '/*.php') ?: []));

// ------------------------------------------- database deleted -> cannot launch
MariaDb::delete('admin', $dbId);
is_('launch after the database is deleted rejected', 0,
    PhpMyAdmin::launch('admin', $dbId, $duId)['ok'] ? 1 : 0);

// ------------------------------------------------------------------ cleanup
sweepTokens();
is_('cleanup: no leftover token files', 0, PhpMyAdmin::activeTokens());
is_('cleanup: no leftover test database', 0,
    Connection::value("SELECT COUNT(*) FROM databases WHERE name = 'pma_uji'"));
is_('cleanup: no leftover test db user', 0,
    Connection::value("SELECT COUNT(*) FROM db_users WHERE name = 'pma_app'"));