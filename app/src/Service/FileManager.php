<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Support\Audit;

/**
 * File Manager: filesystem operations inside the user's docroot.
 *
 * Every path from a request is treated as UNTRUSTED. All
 * operations go through resolve(), which refuses to leave the user's home, including
 * via symlink (path traversal through a symlink is the classic way out of
 * the docroot even when there is no '..' in the input).
 */
final class FileManager
{
    public const MAX_UPLOAD = 64 * 1024 * 1024;   // 64 MB per file

    /** Names the user may not use (they can break the application). */
    private const RESERVED = ['.htaccess', '.user.ini', '.ssh', '..', '.'];

    /** Extensions that may not be uploaded/created through the UI. */
    private const BLOCKED_EXT = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml'];

    public static function home(string $username): string
    {
        return Vhost::WWW_ROOT . '/' . $username;
    }

    /**
     * Turn a relative path from a request into a safe absolute path.
     * Return null if it escapes the user's home.
     */
    public static function resolve(string $username, string $rel): ?string
    {
        $home = self::home($username);
        if ($rel === '' || $rel === '/') {
            $rel = '.';
        }

        // Normalize without touching the filesystem first, so a path that does not
        // exist yet can still be checked.
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

        // Final check: the path must stay inside the home after symlinks
        // are resolved too. realpath() only works if the path already exists, so
        // for new paths we rely on the normalization above.
        $real = realpath($abs);
        if ($real !== false) {
            $realHome = realpath($home);
            if ($realHome === false || !str_starts_with($real, $realHome . '/') && $real !== $realHome) {
                return null;   // escaped via symlink
            }
            return $real;
        }

        $realHome = realpath($home);
        return $realHome === false ? null : $abs;
    }

    /** Validate a single-segment file/folder name. */
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

    /** Folder contents: directories first, then files. */
    public static function listing(string $username, string $rel): array
    {
        $path = self::resolve($username, $rel);
        if ($path === null || !is_dir($path)) {
            return ['ok' => false, 'error' => 'Folder not found.'];
        }
        if (!is_readable($path)) {
            return ['ok' => false, 'error' => 'You do not have read permission for this folder.'];
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
            // Symlinks pointing outside the docroot are hidden so the user cannot
            // inspect the server through a link created earlier.
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

        // Breadcrumb from the relative path.
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
            return ['ok' => false, 'error' => 'Invalid folder name.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'The destination folder does not exist.'];
        }
        $target = $base . '/' . $name;
        if (file_exists($target)) {
            return ['ok' => false, 'error' => 'That name already exists.'];
        }
        if (!@mkdir($target, 0755)) {
            return ['ok' => false, 'error' => 'Failed to create the folder.'];
        }
        @chown($target, 'www-data');
        return ['ok' => true, 'error' => null, 'name' => $name];
    }

    public static function rename(string $username, string $rel, string $from, string $to): array
    {
        $from = self::validName($from);
        $to   = self::validName($to);
        if ($from === null || $to === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
        }
        $src = $base . '/' . $from;
        $dst = $base . '/' . $to;
        if (!file_exists($src)) {
            return ['ok' => false, 'error' => 'The source does not exist.'];
        }
        if (file_exists($dst)) {
            return ['ok' => false, 'error' => 'The target already exists.'];
        }
        if (!@rename($src, $dst)) {
            return ['ok' => false, 'error' => 'Failed to rename.'];
        }
        return ['ok' => true, 'error' => null];
    }

    public static function delete(string $username, string $rel, array $names): array
    {
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
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
                : 'Failed to delete: ' . implode(', ', array_slice($failed, 0, 5)),
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
     * Change permissions. $mode is OCTAL like 0644 (a PHP value), not the
     * decimal number "644" from a form. 0644 in PHP = 420 decimal, so the
     * valid limit is 0777 = 511, not 777.
     */
    public static function chmod(string $username, string $rel, string $name, int $mode): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        if ($mode < 0 || $mode > 0777) {
            return ['ok' => false, 'error' => 'Invalid mode.'];
        }
        // setuid/setgid/sticky must not pass through from the user UI.
        $mode &= 0777;
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
        }
        $target = $base . '/' . $name;
        if (!file_exists($target)) {
            return ['ok' => false, 'error' => 'The file does not exist.'];
        }
        if (!@chmod($target, $mode)) {
            return ['ok' => false, 'error' => 'Failed to change permissions.'];
        }
        return ['ok' => true, 'error' => null, 'mode' => sprintf('%04o', $mode)];
    }

    /** Fetch the contents of a text file for editing in the panel. */
    public static function readText(string $username, string $rel, string $name, int $maxBytes = 1048576): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
        }
        $target = $base . '/' . $name;
        if (!is_file($target)) {
            return ['ok' => false, 'error' => 'Not a regular file.'];
        }
        $size = (int)(@filesize($target) ?: 0);
        if ($size > $maxBytes) {
            return ['ok' => false, 'error' => 'The file is too large to edit (max 1 MB).'];
        }
        if (self::blockedExt($name)) {
            return ['ok' => false, 'error' => '.php files cannot be edited from the panel.'];
        }
        $body = @file_get_contents($target);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Failed to read the file.'];
        }
        return ['ok' => true, 'error' => null, 'name' => $name, 'body' => $body, 'size' => $size];
    }

    public static function writeText(string $username, string $rel, string $name, string $body): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        if (self::blockedExt($name)) {
            return ['ok' => false, 'error' => '.php files cannot be created from the panel.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
        }
        $target = $base . '/' . $name;
        if (@file_put_contents($target, $body) === false) {
            return ['ok' => false, 'error' => 'Failed to save the file.'];
        }
        @chown($target, 'www-data');
        @chmod($target, 0644);
        return ['ok' => true, 'error' => null];
    }

    public static function newFile(string $username, string $rel, string $name): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid file name.'];
        }
        if (self::blockedExt($name)) {
            return ['ok' => false, 'error' => '.php files cannot be created from the panel.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
        }
        $target = $base . '/' . $name;
        if (file_exists($target)) {
            return ['ok' => false, 'error' => 'The file already exists.'];
        }
        if (@file_put_contents($target, '') === false) {
            return ['ok' => false, 'error' => 'Failed to create the file.'];
        }
        @chown($target, 'www-data');
        @chmod($target, 0644);
        return ['ok' => true, 'error' => null];
    }

    /** Total size of the user's home in bytes. */
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
     * Copy a file or folder into $relTo.
     *
     * A folder has its whole contents copied (recursively), not its symlinks. If
     * symlinks were copied as-is, a user could copy a symlink that
     * points to /etc and then read it through the File Manager.
     */
    public static function copy(string $username, string $relFrom, string $name, string $relTo, ?string $newName = null): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        $from = self::resolve($username, $relFrom);
        // An empty destination means "the same folder". Without this, resolve('')
        // returns the home, so copying without picking a folder silently
        // drops the file in the root - far from what the user expects.
        $to   = $relTo === '' ? $from : self::resolve($username, $relTo);
        if ($from === null || $to === null || !is_dir($from) || !is_dir($to)) {
            return ['ok' => false, 'error' => 'The destination folder does not exist.'];
        }
        $src = $from . '/' . $name;
        if (!file_exists($src) || is_link($src)) {
            return ['ok' => false, 'error' => 'The file or folder does not exist.'];
        }

        $destName = $newName === null || $newName === '' ? $name : self::validName($newName);
        if ($destName === null) {
            return ['ok' => false, 'error' => 'Invalid destination name.'];
        }
        $dst = $to . '/' . $destName;

        // A folder must not be moved/copied into itself.
        if (is_dir($src) && (str_starts_with($dst . '/', rtrim($src, '/') . '/'))) {
            return ['ok' => false, 'error' => 'Cannot copy a folder into itself.'];
        }
        if (file_exists($dst)) {
            return ['ok' => false, 'error' => 'A file with that name already exists at the destination.'];
        }
        if (!self::copyTree($src, $dst)) {
            return ['ok' => false, 'error' => 'Failed to copy.'];
        }
        @chown($dst, 'www-data');
        return ['ok' => true, 'error' => null];
    }

    /** Move a file/folder to another folder (rename within one folder = copy+delete). */
    public static function move(string $username, string $relFrom, string $name, string $relTo, ?string $newName = null): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        $from = self::resolve($username, $relFrom);
        // An empty destination means "the same folder". Without this, resolve('')
        // returns the home, so copying without picking a folder silently
        // drops the file in the root - far from what the user expects.
        $to   = $relTo === '' ? $from : self::resolve($username, $relTo);
        if ($from === null || $to === null || !is_dir($from) || !is_dir($to)) {
            return ['ok' => false, 'error' => 'The destination folder does not exist.'];
        }
        $src = $from . '/' . $name;
        if (!file_exists($src) || is_link($src)) {
            return ['ok' => false, 'error' => 'The file or folder does not exist.'];
        }
        $destName = $newName === null || $newName === '' ? $name : self::validName($newName);
        if ($destName === null) {
            return ['ok' => false, 'error' => 'Invalid destination name.'];
        }
        $dst = $to . '/' . $destName;
        if (is_dir($src) && str_starts_with(rtrim($dst, '/') . '/', rtrim($src, '/') . '/')) {
            return ['ok' => false, 'error' => 'Cannot move a folder into itself.'];
        }
        if (file_exists($dst)) {
            return ['ok' => false, 'error' => 'A file with that name already exists at the destination.'];
        }
        // rename() is atomic and only works within one filesystem.
        if (!@rename($src, $dst)) {
            return ['ok' => false, 'error' => 'Failed to move.'];
        }
        @chown($dst, 'www-data');
        return ['ok' => true, 'error' => null];
    }

    /** Recursive copy that skips symlinks so nothing leaks out of the home. */
    private static function copyTree(string $src, string $dst): bool
    {
        if (is_link($src)) {
            return false;   // never replicate a symlink
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
                continue;   // skip symlinks, do not follow them
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

    /** Archive a folder/file into a .zip, then delete the source. */
    public static function zip(string $username, string $rel, string $name): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
        }
        $src = $base . '/' . $name;
        if (!file_exists($src) || is_link($src)) {
            return ['ok' => false, 'error' => 'The file or folder does not exist.'];
        }

        $zipPath = $base . '/' . $name . '.zip';
        if (file_exists($zipPath)) {
            return ['ok' => false, 'error' => 'An archive with that name already exists.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Failed to create the zip archive.'];
        }
        $root = realpath($src);
        if ($root === false) {
            $zip->close();
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'The target cannot be read.'];
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
            return ['ok' => false, 'error' => 'An empty folder cannot be archived.'];
        }
        @chown($zipPath, 'www-data');
        @chmod($zipPath, 0644);

        // The source is DELIBERATELY NOT deleted. Deleting the source with a single
        // click on "Compress" would destroy data without confirmation. If the user
        // really wants it deleted, there is a separate Delete button.
        return ['ok' => true, 'error' => null, 'zip' => $name . '.zip', 'source_kept' => true];
    }

    /** Extract a .zip into the current folder. Reject entry names that try to escape the folder. */
    public static function unzip(string $username, string $rel, string $name, string $destName = ''): array
    {
        $name = self::validName($name);
        if ($name === null) {
            return ['ok' => false, 'error' => 'Invalid name.'];
        }
        $base = self::resolve($username, $rel);
        if ($base === null || !is_dir($base)) {
            return ['ok' => false, 'error' => 'The folder does not exist.'];
        }
        $zipPath = $base . '/' . $name;
        if (!is_file($zipPath) || strtolower((string)pathinfo($zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            return ['ok' => false, 'error' => 'The zip file was not found.'];
        }

        // The destination must be new, so extraction does not overwrite the source folder.
        $dest = $destName === '' ? $base : $base . '/' . self::validName($destName);
        if ($dest === null || !@mkdir($dest, 0755, true) && !is_dir($dest)) {
            return ['ok' => false, 'error' => 'The destination folder cannot be created.'];
        }
        $realDest = realpath($dest);
        if ($realDest === false) {
            return ['ok' => false, 'error' => 'The destination folder cannot be read.'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'The zip archive is corrupt or unreadable.'];
        }

        // Archives created by FPanel itself (zip()) store their contents inside one
        // top-level folder, for example copy/a.txt. If extracted as-is,
        // the result is result/copy/a.txt - one level too deep compared to
        // what the user expects. That prefix is stripped so the contents go straight
        // into the destination folder. Outside archives that do not have a single root
        // are not touched at all.
        // Archives created by FPanel itself (zip()) store their contents inside one
        // top-level folder, for example copy/a.txt. If extracted as-is,
        // the result is result/copy/a.txt - one level too deep compared to
        // what the user expects. That prefix is stripped so the contents go straight
        // into the destination folder. Outside archives without a single root are
        // not touched at all.
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

            // --- zip slip validation -----------------------------------------
            // Manual normalization. realpath() ONLY works for paths that
            // already exist, so for a new entry (../../etc/passwd) it
            // returns false and the guard would never trigger.
            if (str_contains($entry, "\0") || str_starts_with($entry, '/') || preg_match('#^[A-Za-z]:#', $entry)) {
                $zip->close();
                @rmdir($dest);
                return ['ok' => false, 'error' => 'Archive rejected: it contains an absolute path.'];
            }
            $stack = [];
            foreach (explode('/', $entry) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    // Go up one level. If there is nothing left to go up from,
                    // this entry is really trying to escape the destination folder.
                    if ($stack === []) {
                        $zip->close();
                        @rmdir($dest);
                        return ['ok' => false, 'error' => 'Archive rejected: it tries to write outside the destination folder (zip slip).'];
                    }
                    array_pop($stack);
                    continue;
                }
                $stack[] = $seg;
            }
            if ($stack === []) {
                continue;   // "." entry or the stripped top-level folder
            }

            // Strip one top-level folder if the archive has a single root.
            $relative = $strip !== null && $stack[0] === $strip
                ? array_slice($stack, 1)
                : $stack;
            if ($relative === []) {
                continue;
            }
            $relPath = implode('/', $relative);

            // --- explicit write, not extractTo($dest, $entry) ----------------
            // extractTo() ALWAYS writes to $dest/<entry>, so the prefix
            // stripped above cannot be used. Files are written
            // manually to the already-audited relative path.
            $isDirEntry = str_ends_with($entry, '/') || !str_contains(basename($entry), '.');
            $outPath = $realDest . '/' . $relPath;
            // Last safety belt: the normalized result must stay inside the destination.
            if (!str_starts_with($outPath, $realDest . '/')) {
                $zip->close();
                @rmdir($dest);
                return ['ok' => false, 'error' => 'Archive rejected: an entry path escapes the destination folder.'];
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
            return ['ok' => false, 'error' => 'No files were extracted.'];
        }
        return ['ok' => true, 'error' => null, 'files' => $n, 'dest' => basename($realDest)];
    }

    /**
     * Prepare a download: return the absolute path + name, or null if the name/
     * path is unsafe. The return type is ?array because null is a valid result.
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
