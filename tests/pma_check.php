<?php
/**
 * Assertion phpMyAdmin per-database (level service).
 *
 * Dipanggil dari tests/run.sh lewat wrapper yang membaca 4 kolom:
 *   ok<TAB>nama
 *   no<TAB>nama<TAB>dapat<TAB>harap
 *
 * Yang diuji di sini adalah sisi PHP. Sisi HTTP (404 tanpa token, halaman
 * benar-benar terbuka) diuji di run.sh.
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

/** User non-admin milik test suite (dibuat run.sh sebelum section ini). */
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
    no_('create database uji', (string)$create['error'], 'berhasil');
    echo "ok\tlaunch: url berbentuk /pma/<32 hex>\n";
    exit(0);
}
ok_('create database uji');

$dbId = (int)Connection::value("SELECT id FROM databases WHERE name = 'pma_uji'");
$duId = (int)Connection::value("SELECT id FROM db_users WHERE name = 'pma_app'");
$physDb = MariaDb::physDb('admin', 'pma_uji');
$physUser = MariaDb::physUser('admin', 'pma_app');

// --------------------------------------------------------------- ketersediaan
is_('phpMyAdmin terpasang', 1, PhpMyAdmin::available() ? 1 : 0);
is_('dokumen front controller ada', 1, is_file('/usr/share/phpmyadmin/fpma-index.php') ? 1 : 0);

// ------------------------------------------------------------------- launch
$launch = PhpMyAdmin::launch('admin', $dbId, $duId);
is_('launch: ok', 1, $launch['ok'] ? 1 : 0);
$token = (string)($launch['token'] ?? '');
$url   = (string)($launch['url'] ?? '');

is_('token: 32 hex', 1, preg_match('/^[a-f0-9]{32}$/', $token) ? 1 : 0);
is_('url: diawali /pma/<token>/', 1, str_starts_with($url, '/pma/' . $token . '/') ? 1 : 0);
is_('url: mendarat di halaman database', 1, str_contains($url, 'route=/server/databases') ? 1 : 0);

$confFile = CONF_DIR_PMA . '/' . $token . '.php';
is_('file konfigurasi dibuat', 1, is_file($confFile) ? 1 : 0);
$conf = is_file($confFile) ? (string)file_get_contents($confFile) : '';

// Isi file konfigurasi
is_('konfigurasi: auth_type=config', 1, str_contains($conf, "'auth_type']      = 'config'") ? 1 : 0);
is_('konfigurasi: hanya_db = db milik user', 1, str_contains($conf, "'only_db']        = '{$physDb}'") ? 1 : 0);
is_('konfigurasi: user = user db milik user', 1, str_contains($conf, "'user']           = '{$physUser}'") ? 1 : 0);
// hide_db pernah dipakai dan itu justru bug: menyembunyikan database itu
// sendiri sehingga halaman database kosong. Jangan pernah dikembalikan.
is_('konfigurasi: TIDAK pakai hide_db', 1, str_contains($conf, 'hide_db') ? 0 : 1);
is_('konfigurasi: tidak menyimpan password user lain', 1,
    (str_contains($conf, "'controluser']    = null") && str_contains($conf, "'pmadb']          = null")) ? 1 : 0);
// Password harusnya ada (enkripsi), tapi jangan bocor lewat pesan lain.
is_('konfigurasi: terikat ke token yang sama', 1,
    (str_contains($conf, "preg_match('#/pma/([a-f0-9]{32})#'") && str_contains($conf, $token)) ? 1 : 0);

// Izin file: boleh dibaca www-data (kelompok), tapi TIDAK boleh untuk "other".
$mode = is_file($confFile) ? (fileperms($confFile) & 0777) : 0;
is_('file konfigurasi tidak world-readable', 0, $mode & 0007);
is_('file konfigurasi hanya root+www-data yang boleh baca', 1, (($mode & 0040) !== 0) ? 1 : 0);

// --------------------------------------------------------------------- IDOR
$other = otherUser();
if ($other === '') {
    no_('IDOR: user non-admin tersedia', 'tidak ada', 'uujirole');
} else {
    ok_('IDOR: user non-admin tersedia');
    is_('IDOR launch oleh user lain ditolak', 0, PhpMyAdmin::launch($other, $dbId, $duId)['ok'] ? 1 : 0);
    is_('launch dengan db id ngawur ditolak', 0, PhpMyAdmin::launch('admin', 999999, $duId)['ok'] ? 1 : 0);
    is_('launch dengan dbuser ngawur ditolak', 0, PhpMyAdmin::launch('admin', $dbId, 999999)['ok'] ? 1 : 0);
    is_('launch dengan user tidak dikenal ditolak', 0, PhpMyAdmin::launch('tidak-ada', $dbId, $duId)['ok'] ? 1 : 0);
}

// ------------------------------------------------------- token tidak menumpang
$second = PhpMyAdmin::launch('admin', $dbId, $duId);
$token2 = (string)($second['token'] ?? '');
is_('token kedua berbeda dari pertama', 1, ($token2 !== '' && $token2 !== $token) ? 1 : 0);
is_('kedua token punya file masing-masing', 1,
    (is_file(CONF_DIR_PMA . '/' . $token . '.php') && is_file(CONF_DIR_PMA . '/' . $token2 . '.php')) ? 1 : 0);

// ------------------------------------------------------------------- sweep
touch($confFile, time() - 7200);              // 2 jam lalu
touch(CONF_DIR_PMA . '/' . $token2 . '.php'); // masih baru
$gone = PhpMyAdmin::sweep();
is_('sweep: menghapus token kedaluwarsa', 1, $gone >= 1 ? 1 : 0);
is_('sweep: file token lama hilang', 0, is_file($confFile) ? 1 : 0);
is_('sweep: token baru tetap ada', 1, is_file(CONF_DIR_PMA . '/' . $token2 . '.php') ? 1 : 0);

// Token yang filenya hilang harus ditolak launch ulang? Tidak: launch membuat
// token baru. Yang penting file yang hilang tidak lagi dipakai.
$active = PhpMyAdmin::activeTokens();
is_('activeTokens = jumlah file token', $active, count(glob(CONF_DIR_PMA . '/*.php') ?: []));

// --------------------------------------------- database dihapus -> tidak bisa
MariaDb::delete('admin', $dbId);
is_('launch setelah database dihapus ditolak', 0,
    PhpMyAdmin::launch('admin', $dbId, $duId)['ok'] ? 1 : 0);

// ------------------------------------------------------------------ cleanup
sweepTokens();
is_('cleanup: tidak ada sisa file token', 0, PhpMyAdmin::activeTokens());
is_('cleanup: tidak ada sisa database uji', 0,
    Connection::value("SELECT COUNT(*) FROM databases WHERE name = 'pma_uji'"));
is_('cleanup: tidak ada sisa user db uji', 0,
    Connection::value("SELECT COUNT(*) FROM db_users WHERE name = 'pma_app'"));