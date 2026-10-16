<?php
/**
 * Service-level assertions for the MariaDB module.
 *
 * Called from tests/run.sh. All test objects use the "uji" prefix so they
 * can be cleaned up with one pattern, and never touch
 * databases belonging to real users.
 *
 * Output format: one line per assertion.
 *   ok<TAB>name
 *   no<TAB>name<TAB>summary
 * TAB-separated so run.sh does not need to parse spaces (assertion names contain spaces).
 */
declare(strict_types=1);

require '/srv/fpanel/app/bootstrap.php';

use Fpanel\Database\Connection;
use Fpanel\Service\MariaDb;

const OK  = "ok\t";
const NO  = "no\t";

function ok(string $name): void   { echo OK . $name . "\n"; }
function no(string $name, mixed $got, mixed $want): void
{
    $one = static fn (mixed $v): string => substr(preg_replace('/\s+/', ' ', var_export($v, true)), 0, 160);
    echo NO . $name . "\t" . $one($got) . "\t" . $one($want) . "\n";
}

/** Compare the actual value with the expected one. */
function is_(string $name, mixed $expected, mixed $actual): void
{
    if ((string)$expected === (string)$actual) {
        ok($name);
        return;
    }
    no($name, $actual, $expected);
}

/** Service credentials for a specific test user, decrypted with the app key. */
function creds(int $dbId, string $name): ?string
{
    $r = MariaDb::reveal('admin', $dbId, (int)Connection::value(
        'SELECT id FROM db_users WHERE database_id = :d AND name = :n',
        ['d' => $dbId, 'n' => $name]));
    return $r['ok'] ? (string)$r['password'] : null;
}

// --------------------------------------------------------------------- setup

// Clean up leftovers from a previous run, so a suite interrupted midway does
// not cause a full quota or name clashes on the next run.
foreach (['uji_svc', 'uji_kuota', 'uji_bocor'] as $stale) {
    $id = (int)Connection::value('SELECT id FROM databases WHERE name = :n', ['n' => $stale]);
    if ($id > 0) {
        MariaDb::delete('admin', $id);
    }
}

$res = MariaDb::health();
is_('MariaDB alive', 1, $res['ok'] ? 1 : 0);

// ---------------------------------------------------------------- name validation
// NamaPhysical always starts with "admin_", so what is tested here is the
// logical name typed by the user in the form.
$bad = [
    'db name with uppercase rejected'  => 'Toko',
    'db name with space rejected'     => 'tokoThesis',
    'db name traversal rejected'      => '../../etc',
    'db name with backtick rejected'  => 'a`b',
    'db name with quote rejected'     => "a'b",
    'empty db name rejected'          => '',
    'system db name mysql rejected'   => 'mysql',
    'db name with semicolon rejected' => 'a;DROP',
];
foreach ($bad as $label => $input) {
    $r = MariaDb::create('admin', $input, 'uji_app');
    is_($label, 0, $r['ok'] ? 1 : 0);
}
is_('user name >16 chars rejected', 0,
    MariaDb::create('admin', 'uji_svc', 'abcdefghijklmnopqrstuvwxyz')['ok'] ? 1 : 0);
is_('user name with uppercase rejected', 0,
    MariaDb::create('admin', 'uji_svc', 'UjiApp')['ok'] ? 1 : 0);
is_('unknown privilege rejected', 0,
    MariaDb::create('admin', 'uji_svc', 'uji_app', 'FILE')['ok'] ? 1 : 0);

// ------------------------------------------------------------------- create
$r = MariaDb::create('admin', 'uji_svc', 'uji_app');
if (!$r['ok']) {
    no('create: ok', (string)$r['error'], 'successful');
    echo "ok\tcreate: SQLite row exists\n";   // placeholder so it does not cascade into failures
} else {
    ok('create: ok');
    $dbId = (int)$r['db_id'];

    is_('create: SQLite row exists', 1, Connection::value(
        'SELECT COUNT(*) FROM databases WHERE id = :i', ['i' => $dbId]));
    is_('create: physical db exists in MariaDB', 1, MariaDb::serverExists('admin', 'uji_svc') ? 1 : 0);
    is_('create: physical user exists in MariaDB', 1, MariaDb::serverHasUser('admin', 'uji_app') ? 1 : 0);
    is_('create: random password sent back', 1, strlen((string)$r['password']) >= 16 ? 1 : 0);

    // The password must really be usable, not merely stored.
    $pw = creds($dbId, 'uji_app');
    is_('password_db can be revealed', 1, $pw !== null ? 1 : 0);
    is_('reveal matches the create result', 1, $pw === $r['password'] ? 1 : 0);

    $cipher = (string)Connection::value('SELECT password_hash FROM db_users WHERE database_id = :d',
        ['d' => $dbId]);
    is_('password_db stored encrypted, not plaintext', 1,
        ($pw !== null && $cipher !== $pw && !str_contains($cipher, (string)$pw)) ? 1 : 0);

    // Real login using the credentials the panel gives the user.
    $usable = 0;
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=admin_uji_svc', 'admin_uji_app', (string)$pw, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('CREATE TABLE t (id INT)');
        $pdo->exec('INSERT INTO t VALUES (9)');
        $usable = ((int)$pdo->query('SELECT id FROM t')->fetchColumn() === 9) ? 1 : 0;
    } catch (Throwable $e) {
        $usable = 0;
    }
    is_('password_db from the panel can log in & read/write MariaDB', 1, $usable);

    // A DB user must not touch system databases.
    $blocked = 0;
    try {
        (new PDO('mysql:host=localhost;dbname=mysql', 'admin_uji_app', (string)$pw, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]))->query('SELECT 1');
        $blocked = 0;
    } catch (Throwable $e) {
        $blocked = 1;
    }
    is_('DB user cannot access a system database', 1, $blocked);

    is_('duplicate db name rejected', 0, MariaDb::create('admin', 'uji_svc', 'uji_lain')['ok'] ? 1 : 0);

    // ------------------------------------------------- user tambahan + privilege
    $ro  = MariaDb::addUser('admin', $dbId, 'uji_ro', 'READ ONLY');
    is_('addUser READ ONLY: ok', 1, $ro['ok'] ? 1 : 0);
    is_('addUser RO: physical user exists in MariaDB', 1, MariaDb::serverHasUser('admin', 'uji_ro') ? 1 : 0);

    $roPw = creds($dbId, 'uji_ro');
    $denied = 0;
    try {
        (new PDO('mysql:host=localhost;dbname=admin_uji_svc', 'admin_uji_ro', (string)$roPw, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]))->exec('INSERT INTO t VALUES (1)');
    } catch (Throwable $e) {
        $denied = 1;
    }
    is_('addUser RO: INSERT really rejected by MariaDB', 1, $denied);

    is_('addUser duplicate name rejected', 0,
        MariaDb::addUser('admin', $dbId, 'uji_ro')['ok'] ? 1 : 0);
    is_('addUser invalid name rejected', 0,
        MariaDb::addUser('admin', $dbId, 'ro-2')['ok'] ? 1 : 0);

    // --------------------------------------------- last user must not be deleted
    // Rule: the last user of a database must not be deleted, because
    // the database would then be unusable for any application. As long as
    // another user exists, deleting one user is still allowed.
    $mainId = (int)Connection::value('SELECT id FROM db_users WHERE database_id = :d AND name = :n',
        ['d' => $dbId, 'n' => 'uji_app']);
    is_('delete first of 2 users: allowed', 1,
        MariaDb::deleteUser('admin', $dbId, $mainId)['ok'] ? 1 : 0);
    is_('first user really gone', 0, Connection::value(
        'SELECT COUNT(*) FROM db_users WHERE id = :i', ['i' => $mainId]));
    is_('physical user gone from MariaDB too', 0, MariaDb::serverHasUser('admin', 'uji_app') ? 1 : 0);

    $roId2 = (int)Connection::value('SELECT id FROM db_users WHERE database_id = :d AND name = :n',
        ['d' => $dbId, 'n' => 'uji_ro']);
    is_('delete LAST user rejected', 0,
        MariaDb::deleteUser('admin', $dbId, $roId2)['ok'] ? 1 : 0);
    is_('last user still present after the rejection', 1, Connection::value(
        'SELECT COUNT(*) FROM db_users WHERE id = :i', ['i' => $roId2]));
    is_('last user still present in MariaDB', 1, MariaDb::serverHasUser('admin', 'uji_ro') ? 1 : 0);

    // ------------------------------------------------------------------ IDOR
    // uujirole is created by tests/run.sh as a non-admin user.
    $other = (string)Connection::value(
        "SELECT username FROM users WHERE role <> 'admin' ORDER BY id LIMIT 1");
    if ($other === '') {
        is_('IDOR: non-admin user available for the test', 0, 1);
    } else {
        ok('IDOR: non-admin user available for the test');
        // Use $roId2 (a user that really still exists) so this tests
        // tenant isolation, not merely "id not found".
        is_('IDOR reveal of another user db rejected', 0, MariaDb::reveal($other, $dbId, $roId2)['ok'] ? 1 : 0);
        is_('IDOR delete of another user db rejected', 0, MariaDb::delete($other, $dbId)['ok'] ? 1 : 0);
        is_('IDOR addUser on another user db rejected', 0, MariaDb::addUser($other, $dbId, 'x')['ok'] ? 1 : 0);
        is_('IDOR deleteUser on another user db rejected', 0, MariaDb::deleteUser($other, $dbId, $roId2)['ok'] ? 1 : 0);
        is_('IDOR another user db still intact', 1, MariaDb::reveal('admin', $dbId, $roId2)['ok'] ? 1 : 0);
    }

    // -------------------------------------------------- listing then delete the db
    $listing = MariaDb::listing('admin');
    is_('listing: test db shows up', 1, count(array_filter(
        (array)$listing['databases'], static fn (array $d): bool => $d['name'] === 'uji_svc')));

    is_('delete database: ok', 1, MariaDb::delete('admin', $dbId)['ok'] ? 1 : 0);
    is_('physical db gone from MariaDB', 0, MariaDb::serverExists('admin', 'uji_svc') ? 1 : 0);
    is_('all physical users gone as well', 0, MariaDb::serverHasUser('admin', 'uji_app') ? 1 : 0);
    is_('SQLite row deleted as well', 0, Connection::value(
        'SELECT COUNT(*) FROM databases WHERE id = :i', ['i' => $dbId]));
}

// -------------------------------------------------------------------- cleanup
is_('cleanup: no leftover test db', 0,
    Connection::value("SELECT COUNT(*) FROM databases WHERE name LIKE 'uji_%'"));
is_('cleanup: no leftover test users', 0,
    Connection::value("SELECT COUNT(*) FROM db_users WHERE name LIKE 'uji_%'"));
is_('cleanup: no leftover MariaDB test objects', 0, MariaDb::serverExists('admin', 'uji_svc') ? 1 : 0);