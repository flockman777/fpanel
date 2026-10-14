<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Support\Config;

/**
 * Manajemen extension PHP per versi.
 *
 * Extension PHP hidup sebagai file .ini di /etc/php/<v>/fpm/conf.d/ dan baru
 * berlaku setelah pool FPM versi itu direstart. Keduanya butuh root, jadi
 * lewat helper /usr/local/sbin/fpanel-php (lihat sudoers). PHP tidak pernah
 * memegang akses root.
 *
 * Batasan yang perlu diketahui user:
 *   - Status extension sifatnya PER VERSI PHP, bukan per domain. Kalau versi
 *     8.3 punya zip aktif, semua domain yang memakai 8.3 ikut aktif. Ini
 *     memang cara kerja phpMyAdmin/phpSelector juga.
 *   -_extension_ tidak bisa dimatikan per domain, karena dimuatnya extension
 *     terjadi sebelum request(domain) sampai ke PHP.
 *
 * Values per-domain (memory_limit, upload_max_filesize, dst) ditangani terpisah
 * lewat PhpSettings, memakai fastcgi_param PHP_VALUE.
 */
final class PhpExtensions
{
    private const HELPER = '/usr/local/sbin/fpanel-php';

    /**
     * Extension yang tidak disarankan dimatikan.
     *
     * Matikan yang ini dan panel/WordPress bisa ikut rusak, jadi UI menandainya
     * dan tetap mengizinkan (user boleh, tapi dengan peringatan).
     */
    private const SENSITIVE = ['mysqli', 'pdo_mysql', 'json', 'session', 'iconv', 'mbstring', 'curl', 'openssl'];

    /** Baris "nama<TAB>status" dari helper. */
    private static function helper(array $args): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'Helper extension PHP tidak terpasang di server.'];
        }
        $cmd = array_merge(['sudo', '-n', self::HELPER], $args);
        $pipes = [];
        $proc  = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Gagal menjalankan helper extension PHP.'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $out = (string)$out;
        $err = trim((string)$err);
        if ($code !== 0) {
            $msg = preg_replace('/^ERR:\s*/', '', $err) ?: 'Operasi extension PHP gagal.';
            return ['ok' => false, 'error' => trim($msg)];
        }
        return ['ok' => true, 'error' => null, 'out' => $out];
    }

    /** Versi PHP yang terpasang, lengkap dengan daftar extension-nya. */
    public static function listing(string $version): array
    {
        $versions = Vhost::installedPhpVersions();
        if ($version === '') {
            $version = self::defaultVersion($versions);
        }
        if (!in_array($version, $versions, true)) {
            return ['ok' => false, 'error' => 'Versi PHP ' . $version . ' tidak terpasang di server.'];
        }

        $r = self::helper(['list', $version]);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }

        $exts = [];
        foreach (explode("\n", trim($r['out'])) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, "\t")) {
                continue;
            }
            [$name, $state] = array_pad(explode("\t", $line, 2), 2, '');
            if (!preg_match('/^[a-z0-9_]+$/', $name)) {
                continue;   // Jangan pernah percaya nama dari output helper.
            }
            $exts[] = [
                'name'      => $name,
                'enabled'   => $state === 'aktif',
                'sensitive' => in_array($name, self::SENSITIVE, true),
            ];
        }
        usort($exts, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $on = count(array_filter($exts, static fn (array $e): bool => $e['enabled']));
        return ['ok' => true, 'error' => null, 'version' => $version, 'versions' => $versions,
                'extensions' => $exts, 'active' => $on, 'total' => count($exts)];
    }

    /**
     * Matriks seluruh versi: [versi => ['ext' => 'aktif'|'nonaktif'|null]].
     *
     * Nilai null berarti extension itu tidak ada untuk versi tersebut, jadi UI
     * bisa menandai "tidak tersedia" alih-alih menawarkan sakelar yang sia-sia.
     */
    public static function matrix(): array
    {
        $r = self::helper(['matrix']);
        if (!$r['ok']) {
            return [];
        }
        $out = [];
        foreach (explode("\n", trim($r['out'])) as $line) {
            $line = trim($line);
            if ($line === '' || substr_count($line, "\t") < 2) {
                continue;
            }
            [$ver, $ext, $state] = explode("\t", $line, 3);
            if (!preg_match('/^[0-9]+\.[0-9]+$/', $ver) || !preg_match('/^[a-z0-9_]+$/', $ext)) {
                continue;
            }
            $out[$ver][$ext] = $state === 'aktif';
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    /**
     * Aktifkan / nonaktifkan satu extension, lalu restart pool versinya.
     *
     * Restart wajib: tanpa itu perubahan hanya tersimpan di file dan baru
     * berlaku setelah pool berikutnya dimuat ulang.
     */
    public static function toggle(string $version, string $extension, bool $enable): array
    {
        if (!preg_match('/^[0-9]+\.[0-9]+$/', $version)) {
            return ['ok' => false, 'error' => 'Versi PHP tidak valid.'];
        }
        if (!preg_match('/^[a-z0-9_]+$/', $extension)) {
            return ['ok' => false, 'error' => 'Nama extension tidak valid.'];
        }
        if (!in_array($version, Vhost::installedPhpVersions(), true)) {
            return ['ok' => false, 'error' => 'Versi PHP ' . $version . ' tidak terpasang.'];
        }

        $r = self::helper([$enable ? 'enable' : 'disable', $version, $extension]);
        if (!$r['ok']) {
            return $r;
        }

        $rs = self::helper(['restart', $version]);
        if (!$rs['ok']) {
            // File .ini sudah diubah tapi pool gagal start: kembalikan supaya
            // tidak ada keadaan setengah jadi yang tidak terlihat user.
            self::helper([$enable ? 'disable' : 'enable', $version, $extension]);
            return ['ok' => false, 'error' => $rs['error'] . ' (perubahan extension dikembalikan)'];
        }

        // Restart dijadwalkan (bukan sinkron) supaya request yang memancingnya
        // tidak dibunuh oleh pool yang sedang direstart.
        return ['ok' => true, 'error' => null,
                'message' => trim($r['out']) . ' ' . trim($rs['out'])
                    . ' Muat ulang halaman dalam beberapa detik.' ];
    }

    /** Kembalikan semua extension yang sebelumnya dimatikan lewat panel. */
    public static function restore(string $version): array
    {
        if (!preg_match('/^[0-9]+\.[0-9]+$/', $version)) {
            return ['ok' => false, 'error' => 'Versi PHP tidak valid.'];
        }
        $r  = self::helper(['restore', $version]);
        $rs = self::helper(['restart', $version]);
        if (!$r['ok']) {
            return $r;
        }
        return ['ok' => $rs['ok'], 'error' => $rs['error'], 'message' => trim($r['out'])];
    }

    public static function defaultVersion(array $versions): string
    {
        $cfg = (string)Config::get('php.default', '8.3');
        if (in_array($cfg, $versions, true)) {
            return $cfg;
        }
        return $versions === [] ? $cfg : (string)end($versions);
    }
}