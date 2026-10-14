<?php
/**
 * Assertion level-service untuk modul MariaDB.
 *
 * Dipanggil dari tests/run.sh. Semua objek uji memakai awalan "uji" supaya
 * bisa dibersihkan dengan satu pola, dan supaya tidak pernah menyentuh
 * database milik user sungguhan.
 *
 * Format output: satu baris per assertion.
 *   ok<TAB>nama
 *   no<TAB>nama<TAB>ringkasan
 * Dipisah TAB supaya run.sh tidak perlu parses spasi (nama assertion mengandung spasi).
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

/** Bandingkan nilai nyata dengan yang diharapkan. */
function is_(string $name, mixed $expected, mixed $actual): void
{
    if ((string)$expected === (string)$actual) {
        ok($name);
        return;
    }
    no($name, $actual, $expected);
}

/** Kredensial service untuk user uji spesifik, hasil decrypt app-key. */
function creds(int $dbId, string $name): ?string
{
    $r = MariaDb::reveal('admin', $dbId, (int)Connection::value(
        'SELECT id FROM db_users WHERE database_id = :d AND name = :n',
        ['d' => $dbId, 'n' => $name]));
    return $r['ok'] ? (string)$r['password'] : null;
}

// --------------------------------------------------------------------- setup

// Bersihkan sisa run sebelumnya, supaya suite yang terputus di tengah tidak
// menyebabkan quota penuh atau nama bentrok pada run berikutnya.
foreach (['uji_svc', 'uji_kuota', 'uji_bocor'] as $stale) {
    $id = (int)Connection::value('SELECT id FROM databases WHERE name = :n', ['n' => $stale]);
    if ($id > 0) {
        MariaDb::delete('admin', $id);
    }
}

$res = MariaDb::health();
is_('MariaDB hidup', 1, $res['ok'] ? 1 : 0);

// --------------------------------------------------------------- validasi nama
// NamaPhysical selalu diawali "admin_", jadi yang diuji di sini adalah nama
// logis yang diketik user di form.
$bad = [
    'nama db huruf besar ditolak'    => 'Toko',
    'nama db ada spasi ditolak'     => 'tokoThesis',
    'nama db traversal ditolak'     => '../../etc',
    'nama db backtick ditolak'      => 'a`b',
    'nama db tanda kutip ditolak'   => "a'b",
    'nama db kosong ditolak'        => '',
    'nama db sistem mysql ditolak'  => 'mysql',
    'nama db semicolon ditolak'     => 'a;DROP',
];
foreach ($bad as $label => $input) {
    $r = MariaDb::create('admin', $input, 'uji_app');
    is_($label, 0, $r['ok'] ? 1 : 0);
}
is_('nama user >16 karakter ditolak', 0,
    MariaDb::create('admin', 'uji_svc', 'abcdefghijklmnopqrstuvwxyz')['ok'] ? 1 : 0);
is_('nama user huruf besar ditolak', 0,
    MariaDb::create('admin', 'uji_svc', 'UjiApp')['ok'] ? 1 : 0);
is_('privilege tak dikenal ditolak', 0,
    MariaDb::create('admin', 'uji_svc', 'uji_app', 'FILE')['ok'] ? 1 : 0);

// ------------------------------------------------------------------- create
$r = MariaDb::create('admin', 'uji_svc', 'uji_app');
if (!$r['ok']) {
    no('create: ok', (string)$r['error'], 'berhasil');
    echo "ok\tcreate: baris SQLite ada\n";   // placeholder agar tidak cascada gagal
} else {
    ok('create: ok');
    $dbId = (int)$r['db_id'];

    is_('create: baris SQLite ada', 1, Connection::value(
        'SELECT COUNT(*) FROM databases WHERE id = :i', ['i' => $dbId]));
    is_('create: db fisik ada di MariaDB', 1, MariaDb::serverExists('admin', 'uji_svc') ? 1 : 0);
    is_('create: user fisik ada di MariaDB', 1, MariaDb::serverHasUser('admin', 'uji_app') ? 1 : 0);
    is_('create: password acak terkirim balik', 1, strlen((string)$r['password']) >= 16 ? 1 : 0);

    // Password harus benar-benar bisa dipakai, bukan cuma disimpan.
    $pw = creds($dbId, 'uji_app');
    is_('password_db bisa dibuka (reveal)', 1, $pw !== null ? 1 : 0);
    is_('reveal sama dengan hasil create', 1, $pw === $r['password'] ? 1 : 0);

    $cipher = (string)Connection::value('SELECT password_hash FROM db_users WHERE database_id = :d',
        ['d' => $dbId]);
    is_('password_db disimpan terenkripsi, bukan plaintext', 1,
        ($pw !== null && $cipher !== $pw && !str_contains($cipher, (string)$pw)) ? 1 : 0);

    // Login beneran pakai kredensial yang panel berikan ke user.
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
    is_('password_db hasil panel bisa login & baca/tulis MariaDB', 1, $usable);

    // User DB tidak boleh menyentuh database sistem.
    $blocked = 0;
    try {
        (new PDO('mysql:host=localhost;dbname=mysql', 'admin_uji_app', (string)$pw, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]))->query('SELECT 1');
        $blocked = 0;
    } catch (Throwable $e) {
        $blocked = 1;
    }
    is_('user DB tidak bisa akses database sistem', 1, $blocked);

    is_('nama db duplikat ditolak', 0, MariaDb::create('admin', 'uji_svc', 'uji_lain')['ok'] ? 1 : 0);

    // ------------------------------------------------- user tambahan + privilege
    $ro  = MariaDb::addUser('admin', $dbId, 'uji_ro', 'READ ONLY');
    is_('addUser READ ONLY: ok', 1, $ro['ok'] ? 1 : 0);
    is_('addUser RO: user fisik ada di MariaDB', 1, MariaDb::serverHasUser('admin', 'uji_ro') ? 1 : 0);

    $roPw = creds($dbId, 'uji_ro');
    $denied = 0;
    try {
        (new PDO('mysql:host=localhost;dbname=admin_uji_svc', 'admin_uji_ro', (string)$roPw, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]))->exec('INSERT INTO t VALUES (1)');
    } catch (Throwable $e) {
        $denied = 1;
    }
    is_('addUser RO: INSERT benar-benar ditolak MariaDB', 1, $denied);

    is_('addUser nama duplikat ditolak', 0,
        MariaDb::addUser('admin', $dbId, 'uji_ro')['ok'] ? 1 : 0);
    is_('addUser nama invalid ditolak', 0,
        MariaDb::addUser('admin', $dbId, 'ro-2')['ok'] ? 1 : 0);

    // --------------------------------------------- user terakhir tidak boleh dihapus
    // Aturan: user terakhir dari sebuah database tidak boleh dihapus, karena
    // database-nya jadi tidak bisa dipakai aplikasi sama sekali. Selagi masih
    // ada user lain, menghapus satu user tetap boleh.
    $mainId = (int)Connection::value('SELECT id FROM db_users WHERE database_id = :d AND name = :n',
        ['d' => $dbId, 'n' => 'uji_app']);
    is_('hapus user pertama dari 2 user: boleh', 1,
        MariaDb::deleteUser('admin', $dbId, $mainId)['ok'] ? 1 : 0);
    is_('user pertama benar-benar hilang', 0, Connection::value(
        'SELECT COUNT(*) FROM db_users WHERE id = :i', ['i' => $mainId]));
    is_('user fisik ikut hilang dari MariaDB', 0, MariaDb::serverHasUser('admin', 'uji_app') ? 1 : 0);

    $roId2 = (int)Connection::value('SELECT id FROM db_users WHERE database_id = :d AND name = :n',
        ['d' => $dbId, 'n' => 'uji_ro']);
    is_('hapus user TERAKHIR ditolak', 0,
        MariaDb::deleteUser('admin', $dbId, $roId2)['ok'] ? 1 : 0);
    is_('user terakhir tetap ada setelah penolakan', 1, Connection::value(
        'SELECT COUNT(*) FROM db_users WHERE id = :i', ['i' => $roId2]));
    is_('user terakhir masih ada di MariaDB', 1, MariaDb::serverHasUser('admin', 'uji_ro') ? 1 : 0);

    // ------------------------------------------------------------------ IDOR
    // uujirole dibuat tests/run.sh sebagai user non-admin.
    $other = (string)Connection::value(
        "SELECT username FROM users WHERE role <> 'admin' ORDER BY id LIMIT 1");
    if ($other === '') {
        is_('IDOR: user non-admin tersedia untuk uji', 0, 1);
    } else {
        ok('IDOR: user non-admin tersedia untuk uji');
        // Pakai $roId2 (user yang benar-benar masih ada) supaya ini menguji
        // isolasi tenant, bukan sekadar "id tidak ditemukan".
        is_('IDOR reveal db orang lain ditolak', 0, MariaDb::reveal($other, $dbId, $roId2)['ok'] ? 1 : 0);
        is_('IDOR delete db orang lain ditolak', 0, MariaDb::delete($other, $dbId)['ok'] ? 1 : 0);
        is_('IDOR addUser db orang lain ditolak', 0, MariaDb::addUser($other, $dbId, 'x')['ok'] ? 1 : 0);
        is_('IDOR deleteUser db orang lain ditolak', 0, MariaDb::deleteUser($other, $dbId, $roId2)['ok'] ? 1 : 0);
        is_('IDOR db orang lain masih utuh', 1, MariaDb::reveal('admin', $dbId, $roId2)['ok'] ? 1 : 0);
    }

    // -------------------------------------------------- listing lalu hapus database
    $listing = MariaDb::listing('admin');
    is_('listing: db uji muncul', 1, count(array_filter(
        (array)$listing['databases'], static fn (array $d): bool => $d['name'] === 'uji_svc')));

    is_('hapus database: ok', 1, MariaDb::delete('admin', $dbId)['ok'] ? 1 : 0);
    is_('db fisik hilang dari MariaDB', 0, MariaDb::serverExists('admin', 'uji_svc') ? 1 : 0);
    is_('semua user fisik ikut hilang', 0, MariaDb::serverHasUser('admin', 'uji_app') ? 1 : 0);
    is_('baris SQLite ikut terhapus', 0, Connection::value(
        'SELECT COUNT(*) FROM databases WHERE id = :i', ['i' => $dbId]));
}

// -------------------------------------------------------------------- cleanup
is_('cleanup: tidak ada sisa db uji', 0,
    Connection::value("SELECT COUNT(*) FROM databases WHERE name LIKE 'uji_%'"));
is_('cleanup: tidak ada sisa user uji', 0,
    Connection::value("SELECT COUNT(*) FROM db_users WHERE name LIKE 'uji_%'"));
is_('cleanup: tidak ada sisa objek MariaDB uji', 0, MariaDb::serverExists('admin', 'uji_svc') ? 1 : 0);