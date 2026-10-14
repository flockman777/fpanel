<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Support\Audit;

/**
 * File Manager: operasi filesystem di dalam docroot user.
 *
 * Setiap path dari request diperlakukan sebagai TIDAK TERPERCAYA. Semua
 * operasi dilewatkan resolve() yang menolak keluar dari home user, termasuk
 * lewat symlink (path traversal via symlink adalah cara klasik keluar dari
 * docroot meski tidak ada '..' di input).
 */
final class FileManager
{
    public const MAX_UPLOAD = 64 * 1024 * 1024;   // 64 MB per file

    /** Nama yang tidak boleh dipakai user (bisa merusak aplikasi). */
    private const RESERVED = ['.htaccess', '.user.ini', '.ssh', '..', '.'];

    /** Ekstensi yang tidak boleh di-upload/dibuat lewat UI. */
    private const BLOCKED_EXT = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml'];

    public static function home(string $username): string
    {
        return Vhost::WWW_ROOT . '/' . $username;
    }

    /**
     * Ubah path relatif dari request menjadi path absolut yang aman.
     * Kembalikan null kalau keluar dari home user.
     */
    public static function resolve(string $username, string $rel): ?string
    {
        $home = self::home($username);
        if ($rel === '' || $rel === '/') {
            $rel = '.';
        }

        // Normalisasi tanpa menyentuh filesystem dulu, supaya path yang tidak
        // ada pun tetap bisa diceol.
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $rel)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }

        $abs = $home . ($parts === [] ? '' : '/' . implode('/', $parts));

        // Pemeriksaan akhir: path harus tetap di dalam home, setelah symlink
        // ikut diresolve. realpath() hanya jalan kalau path sudah ada, jadi
        // untuk path baru kita andalkan normalisasi di atas.
        $real = realpath($abs);
        if ($real !== false) {
            $realHome = realpath($home);
            if ($realHome === false || !str_starts_with($real, $realHome . '/') && $real !== $realHome) {
                return null;   // keluar lewat symlink
            }
            return $real;
        }

        $realHome = realpath($home);
        return $realHome === false ? null : $abs;
    }

    /** Validasi nama file/folder satu segmen. */
    public static function validName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            return null;
        }
        if (in_array($name, self::RESERVED, true)) {
            return null;
        }
        if (strlen($name) > 255) {
            return null;
        }
        return $name;
    }

    public static function blockedExt(string $name): bool
    {
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::BLOCKED_EXT, true);
    }

    /** Daftar isi folder: folder dulu, lalu file. */
    public static function listing(string $username, string $rel): array
    {
        $path = self::resolve($username, $rel);
        if ($path === null || !is_dir($path)) {
            return ['ok' => false, 'error' => 'Folder tidak ditemukan.'];
        }
        if (!is_readable($path)) {
            return ['ok' => false, 'error' => 'Tidak punya izin baca folder ini.'];
        }

        $entries = @scandir($path) ?: [];
        $dirs = [];
        $files = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $full = $path . '/' . $e;
            $isLink = is_link($full);
            $isDir  = is_dir($full);
            $row = [
                'name'     => $e,
                'is_dir'   => $isDir,
                'is_link'  => $isLink,
                'size'     => $isDir ? 0 : (int)(@filesize($full) ?: 0),
                'mtime'    => (int)(@filemtime($full) ?: 0),
                'mode'     => (string)@sprintf('%04o', @fileperms($full) & 0777),
                'readable' => is_readable($full),
                'writable' => is_writable($full),
            ];
            // Symlink ke luar docroot disembunyikan supaya user tidak bisa
            // melihat isi server lewat link yang dibuat sebelumnya.
            if ($isLink) {
                $t = realpath($full);
                if ($t === false || !str_starts_with($t, (string)realpath(self::home($username)))) {
                    continue;
                }
            }
            if ($isDir) {
                $dirs[] = $row;
            } else {
                $files[] = $row;
            }
        }

        $cmp = static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']);
        usort($dirs, $cmp);
        usort($files, $cmp);

        // Breadcrumb dari path relatif.
        $crumbs = [];
        $acc = '';
        if ($rel !== '' && $rel !== '.' && $rel !== '/') {
            $crumbs[] = ['label' => 'Home', 'path' => ''];
            foreach (array_filter(explode('/', trim($rel, '/'))) as $seg) {
                $acc = $acc === '' ? $seg : $acc . '/' . $seg;
                $crumbs[] = ['label' => $seg, 'path' => $acc];
            }
        } else {
            $crumbs[] = ['label' => 'Home', 'path' => ''];
        }

        return [
            'ok'        => true,
            'path'      => $rel === '' ? '.' : $rel,
            'dirs'      => $dirs,
            'files'     => $files,
            'crumbs'    => $crumbs,
            'parent'    => $rel === '' || $rel === '.' || $rel === '/' ? null : self::parent($rel),
        ];
    }

    private static function parent(string $rel): ?string
    {
        $rel = trim($rel, '/');
        if ($rel === '') {
            return null;
        }
        $p = dirname($rel);
        return ($p === '.' || $p === '/') ? '' : $p;
    }

    public static function mkdir(string $username, string $rel, string $name): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama folder tidak valid.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'Folder tujuan tidak ada.'];
        }
        $target = $base . '/' . $name;
        if (file_exists($target)) {
            return ['ok' => false, 'error' => 'Sudah ada nama tersebut.'];
        }
        if (!@mkdir($target, 0755)) {
            return ['ok' => false, 'error' => 'Gagal membuat folder.'];
        }
        @chown($target, 'www-data');
        return ['ok' => true, 'error' => null, 'name' => $name];
    }

    public static function rename(string $username, string $rel, string $from, string $to): array
    {
        $from = self::validName($from);
        $to   = self::validName($to);
        if ($from === null || $to === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $src = $base . '/' . $from;
        $dst = $base . '/' . $to;
        if (!file_exists($src)) {
            return ['ok' => false, 'error' => 'Sumber tidak ada.'];
        }
        if (file_exists($dst)) {
            return ['ok' => false, 'error' => 'Target sudah ada.'];
        }
        if (!@rename($src, $dst)) {
            return ['ok' => false, 'error' => 'Gagal mengubah nama.'];
        }
        return ['ok' => true, 'error' => null];
    }

    public static function delete(string $username, string $rel, array $names): array
    {
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $deleted = 0;
        $failed  = [];
        foreach ($names as $n) {
            $n = self::validName((string)$n);
            if ($n === null) {
                continue;
            }
            $target = $base . '/' . $n;
            if (!file_exists($target)) {
                continue;
            }
            $ok = is_dir($target) && !is_link($target)
                ? self::rmTree($target)
                : @unlink($target);
            $ok ? $deleted++ : $failed[] = $n;
        }
        return [
            'ok'      => $failed === [],
            'error'   => $failed === []
                ? null
                : 'Gagal menghapus: ' . implode(', ', array_slice($failed, 0, 5)),
            'deleted' => $deleted,
        ];
    }

    private static function rmTree(string $dir): bool
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        $ok = true;
        foreach ($it as $f) {
            $r = $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            if (!$r && file_exists($f->getPathname())) {
                $ok = false;
            }
        }
        return @rmdir($dir) && $ok;
    }

    /**
     * Ubah permission. $mode adalah OCTAL seperti 0644 (nilai PHP), bukan
     * angka desimal "644" dari form. Nilai 0644 di PHP = 420 desimal, jadi
     * batas validnya 0777 = 511, bukan 777.
     */
    public static function chmod(string $username, string $rel, string $name, int $mode): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        if ($mode < 0 || $mode > 0777) {
            return ['ok' => false, 'error' => 'Mode tidak valid.'];
        }
        // setuid/setgid/sticky tidak boleh lewat dari UI user.
        $mode &= 0777;
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $target = $base . '/' . $name;
        if (!file_exists($target)) {
            return ['ok' => false, 'error' => 'File tidak ada.'];
        }
        if (!@chmod($target, $mode)) {
            return ['ok' => false, 'error' => 'Gagal mengubah permission.'];
        }
        return ['ok' => true, 'error' => null, 'mode' => sprintf('%04o', $mode)];
    }

    /** Ambil isi file teks untuk diedit di panel. */
    public static function readText(string $username, string $rel, string $name, int $maxBytes = 1048576): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $target = $base . '/' . $name;
        if (!is_file($target)) {
            return ['ok' => false, 'error' => 'File bukan file biasa.'];
        }
        $size = (int)(@filesize($target) ?: 0);
        if ($size > $maxBytes) {
            return ['ok' => false, 'error' => 'File terlalu besar untuk diedit (maks 1 MB).'];
        }
        if (self::blockedExt($name)) {
            return ['ok' => false, 'error' => 'File .php tidak bisa diedit dari panel.'];
        }
        $body = @file_get_contents($target);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Gagal membaca file.'];
        }
        return ['ok' => true, 'error' => null, 'name' => $name, 'body' => $body, 'size' => $size];
    }

    public static function writeText(string $username, string $rel, string $name, string $body): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        if (self::blockedExt($name)) {
            return ['ok' => false, 'error' => 'File .php tidak bisa dibuat dari panel.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $target = $base . '/' . $name;
        if (@file_put_contents($target, $body) === false) {
            return ['ok' => false, 'error' => 'Gagal menyimpan file.'];
        }
        @chown($target, 'www-data');
        @chmod($target, 0644);
        return ['ok' => true, 'error' => null];
    }

    public static function newFile(string $username, string $rel, string $name): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama file tidak valid.'];
        }
        if (self::blockedExt($name)) {
            return ['ok' => false, 'error' => 'File .php tidak bisa dibuat dari panel.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $target = $base . '/' . $name;
        if (file_exists($target)) {
            return ['ok' => false, 'error' => 'File sudah ada.'];
        }
        if (@file_put_contents($target, '') === false) {
            return ['ok' => false, 'error' => 'Gagal membuat file.'];
        }
        @chown($target, 'www-data');
        @chmod($target, 0644);
        return ['ok' => true, 'error' => null];
    }

    /** Total ukuran home user dalam bytes. */
    public static function usedBytes(string $username): int
    {
        $home = self::home($username);
        if (!is_dir($home)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $total += $f->getSize();
            }
        }
        return $total;
    }

    /**
     * Salin file atau folder ke dalam $relTo.
     *
     * Folder disalin seluruh isinya (rekursif), bukan symlink-nya. Kalau
     * symlink ikut disalin apa adanya, user bisa menyalin symlink yang
     * menunjuk ke /etc lalu membacanya lewat File Manager.
     */
    public static function copy(string $username, string $relFrom, string $name, string $relTo, ?string $newName = null): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        $from = self::resolve($username, $relFrom);
        // Tujuan kosong berarti "folder yang sama". Tanpa ini resolve('')
        // mengembalikan home, jadi menyalin tanpa pilih folder diam-diam
        // menaruh berkas di root - jauh dari yang=user kira.
        $to   = $relTo === '' ? $from : self::resolve($username, $relTo);
        if ($from === null || $to === null || !is_dir($from) || !is_dir($to)) {
            return ['ok' => false, 'error' => 'Folder tujuan tidak ada.'];
        }
        $src = $from . '/' . $name;
        if (!file_exists($src) || is_link($src)) {
            return ['ok' => false, 'error' => 'File atau folder tidak ada.'];
        }

        $destName = $newName === null || $newName === '' ? $name : self::validName($newName);
        if ($destName === null) {
            return ['ok' => false, 'error' => 'Nama tujuan tidak valid.'];
        }
        $dst = $to . '/' . $destName;

        // Folder tidak boleh dipindah/Disalin ke dalam dirinya sendiri.
        if (is_dir($src) && (str_starts_with($dst . '/', rtrim($src, '/') . '/'))) {
            return ['ok' => false, 'error' => 'Tidak bisa menyalin folder ke dalam dirinya sendiri.'];
        }
        if (file_exists($dst)) {
            return ['ok' => false, 'error' => 'Sudah ada berkas bernama itu di tujuan.'];
        }
        if (!self::copyTree($src, $dst)) {
            return ['ok' => false, 'error' => 'Gagal menyalin.'];
        }
        @chown($dst, 'www-data');
        return ['ok' => true, 'error' => null];
    }

    /** Pindahkan file/folder ke folder lain (rename dalam satu folder = copy+delete). */
    public static function move(string $username, string $relFrom, string $name, string $relTo, ?string $newName = null): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        $from = self::resolve($username, $relFrom);
        // Tujuan kosong berarti "folder yang sama". Tanpa ini resolve('')
        // mengembalikan home, jadi menyalin tanpa pilih folder diam-diam
        // menaruh berkas di root - jauh dari yang=user kira.
        $to   = $relTo === '' ? $from : self::resolve($username, $relTo);
        if ($from === null || $to === null || !is_dir($from) || !is_dir($to)) {
            return ['ok' => false, 'error' => 'Folder tujuan tidak ada.'];
        }
        $src = $from . '/' . $name;
        if (!file_exists($src) || is_link($src)) {
            return ['ok' => false, 'error' => 'File atau folder tidak ada.'];
        }
        $destName = $newName === null || $newName === '' ? $name : self::validName($newName);
        if ($destName === null) {
            return ['ok' => false, 'error' => 'Nama tujuan tidak valid.'];
        }
        $dst = $to . '/' . $destName;
        if (is_dir($src) && str_starts_with(rtrim($dst, '/') . '/', rtrim($src, '/') . '/')) {
            return ['ok' => false, 'error' => 'Tidak bisa memindahkan folder ke dalam dirinya sendiri.'];
        }
        if (file_exists($dst)) {
            return ['ok' => false, 'error' => 'Sudah ada berkas bernama itu di tujuan.'];
        }
        // rename() atomik dan hanya jalan dalam satu filesystem.
        if (!@rename($src, $dst)) {
            return ['ok' => false, 'error' => 'Gagal memindahkan.'];
        }
        @chown($dst, 'www-data');
        return ['ok' => true, 'error' => null];
    }

    /** Salin rekursif, melewati symlink agar tidak bocor keluar home. */
    private static function copyTree(string $src, string $dst): bool
    {
        if (is_link($src)) {
            return false;   // jangan pernah tiru symlink
        }
        if (is_file($src)) {
            return @copy($src, $dst);
        }
        if (!is_dir($src)) {
            return false;
        }
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return false;
        }
        @chmod($dst, 0755);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $ok = true;
        foreach ($it as $f) {
            $target = $dst . '/' . substr($f->getPathname(), strlen($src) + 1);
            if ($f->isLink()) {
                continue;   // lewati symlink, jangan ikut
            }
            if ($f->isDir()) {
                $ok = @mkdir($target, 0755, true) && $ok;
                @chmod($target, 0755);
            } elseif ($f->isFile()) {
                $ok = @copy($f->getPathname(), $target) && $ok;
            }
        }
        return $ok;
    }

    /** Arsipkan folder/file jadi .zip, lalu hapus asalnya. */
    public static function zip(string $username, string $rel, string $name): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $src = $base . '/' . $name;
        if (!file_exists($src) || is_link($src)) {
            return ['ok' => false, 'error' => 'File atau folder tidak ada.'];
        }

        $zipPath = $base . '/' . $name . '.zip';
        if (file_exists($zipPath)) {
            return ['ok' => false, 'error' => 'Arsip dengan nama itu sudah ada.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Gagal membuat arsip zip.'];
        }
        $root = realpath($src);
        if ($root === false) {
            $zip->close();
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Target tidak bisa dibaca.'];
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $files = 0;
        foreach ($it as $f) {
            if ($f->isLink()) {
                continue;
            }
            $rel = $name . '/' . substr($f->getPathname(), strlen($root) + 1);
            if ($f->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($f->getPathname(), $rel);
                $files++;
            }
        }
        $zip->close();
        if ($files === 0) {
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'Folder kosong tidak bisa diarsipkan.'];
        }
        @chown($zipPath, 'www-data');
        @chmod($zipPath, 0644);

        // Asli SENGAJA TIDAK dihapus. Menghapus sumber dari satu klik
        // "Kompres" destroying data tanpa konfirmasi. Kalau user memang
        // ingin menghapus, ada tombol Hapus yang terpisah.
        return ['ok' => true, 'error' => null, 'zip' => $name . '.zip', 'source_kept' => true];
    }

    /** Ekstrak .zip ke folder saat ini. Tolak nama entri yang tries keluar folder. */
    public static function unzip(string $username, string $rel, string $name, string $destName = ''): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Nama tidak valid.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'Folder tidak ada.'];
        }
        $zipPath = $base . '/' . $name;
        if (!is_file($zipPath) || strtolower((string)pathinfo($zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            return ['ok' => false, 'error' => 'Berkas zip tidak ditemukan.'];
        }

        // Destinasi darf baru, supaya ekstraksi tidak menimpa folder sumber.
        $dest = $destName === '' ? $base : $base . '/' . self::validName($destName);
        if ($dest === null || !@mkdir($dest, 0755, true) && !is_dir($dest)) {
            return ['ok' => false, 'error' => 'Folder tujuan tidak bisa dibuat.'];
        }
        $realDest = realpath($dest);
        if ($realDest === false) {
            return ['ok' => false, 'error' => 'Folder tujuan tidak bisa dibaca.'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'Arsip zip rusak atau tidak bisa dibaca.'];
        }

        // Arsip yang dibuat FPanel sendiri (zip()) menyimpan isi di dalam satu
        // folder teratas, misalnya salin/a.txt. Kalau diekstrak apa adanya,
        // hasilnya jadi hasil/salin/a.txt - satu tingkat terlalu dalam dari
        // yang=user expect. Prefix itu dilepas supaya isi langsung masuk ke
        // folder tujuan. Arsip luar yang tidak punya satu akar tunggal tidak
        // disentuh sama sekali.
        // Arsip yang dibuat FPanel sendiri (zip()) menyimpan isi di dalam satu
        // folder teratas, misalnya salin/a.txt. Kalau diekstrak apa adanya,
        // hasilnya jadi hasil/salin/a.txt - satu tingkat terlalu dalam dari
        // yang diharapkan user. Prefix itu dilepas supaya isi langsung masuk
        // ke folder tujuan. Arsip luar yang tidak punya satu akar tunggal
        // tidak disentuh sama sekali.
        $firstSeg = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $e = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            $seg = explode('/', ltrim($e, '/'))[0] ?? '';
            if ($seg !== '') {
                $firstSeg[$seg] = true;
            }
        }
        $strip = count($firstSeg) === 1 ? (string)array_key_first($firstSeg) : null;

        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = str_replace('\\', '/', (string)$zip->getNameIndex($i));

            // --- validasi zip slip -------------------------------------------
            // Normalisasi manual. realpath() HANYA bekerja untuk path yang
            // sudah ada, sehingga untuk entri baru (../../etc/passwd) dia
            // mengembalikan false dan guard tidak akan pernah menyala.
            if (str_contains($entry, "\0") || str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry)) {
                $zip->close();
                @rmdir($dest);
                return ['ok' => false, 'error' => 'Arsip ditolak: berisi path absolut.'];
            }
            $stack = [];
            foreach (explode('/', $entry) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    // Naik satu level. Kalau tidak ada yang bisa dinai, entri
                    // ini benar-benar mencoba keluar dari folder tujuan.
                    if ($stack === []) {
                        $zip->close();
                        @rmdir($dest);
                        return ['ok' => false, 'error' => 'Arsip ditolak: mencoba menulis di luar folder tujuan (zip slip).'];
                    }
                    array_pop($stack);
                    continue;
                }
                $stack[] = $seg;
            }
            if ($stack === []) {
                continue;   // entri "." atau folder teratas yang di-strip
            }

            // Lepas satu folder teratas kalau arsip punya satu akar tunggal.
            $relative = $strip !== null && $stack[0] === $strip
                ? array_slice($stack, 1)
                : $stack;
            if ($relative === []) {
                continue;
            }
            $relPath = implode('/', $relative);

            // --- tulis eksplisit, bukan extractTo($dest, $entry) -------------
            // extractTo() SELALU menulis ke $dest/<entry>, sehingga prefix
            // yang sudah dihapus di atas tidak bisa dipakai. File ditulis
            // manual ke path relatif yang sudah diaudit.
            $isDirEntry = str_ends_with($entry, '/') || !str_contains(basename($entry), '.');
            $outPath = $realDest . '/' . $relPath;
            // Sabuk pengaman terakhir: hasil normalisasi wajib di dalam tujuan.
            if (!str_starts_with($outPath, $realDest . '/')) {
                $zip->close();
                @rmdir($dest);
                return ['ok' => false, 'error' => 'Arsip ditolak: path entri keluar dari folder tujuan.'];
            }
            if ($isDirEntry) {
                @mkdir($outPath, 0755, true);
                $n++;
                continue;
            }
            $parentDir = dirname($outPath);
            if (!is_dir($parentDir) && !@mkdir($parentDir, 0755, true)) {
                continue;
            }
            $stream = $zip->getStream($entry);
            if ($stream === false) {
                continue;
            }
            $fh = @fopen($outPath, 'wb');
            if ($fh === false) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $fh);
            fclose($stream);
            fclose($fh);
            $n++;
        }
        $zip->close();
        if ($n === 0) {
            return ['ok' => false, 'error' => 'Tidak ada berkas yang berhasil diekstrak.'];
        }
        return ['ok' => true, 'error' => null, 'files' => $n, 'dest' => basename($realDest)];
    }

    /**
     * Siapkan unduhan: kembalikan path absolut + nama, atau null kalau nama/
     * path tidak aman. Return type ?:array karena null itu hasil yang valid.
     */
    public static function download(string $username, string $rel, string $name): ?array
    {
        $name = self::validName($name);
        if ($name === null) {
            return null;
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return null;
        }
        $path = $base . '/' . $name;
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $real = realpath($path);
        $realHome = realpath(self::home($username));
        if ($real === false || $realHome === false || !str_starts_with($real, $realHome . '/')) {
            return null;
        }
        return ['path' => $real, 'name' => $name, 'size' => filesize($real) ?: 0];
    }

    public static function humanSize(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < 4) {
            $b /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)$b : number_format($b, 1)) . ' ' . $u[$i];
    }
}
