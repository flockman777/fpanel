<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Panel data backup & restore.
 *
 * Archives are created by the root helper /usr/local/sbin/fpanel-backup (see
 * sudoers), and contain a SQLite snapshot, /var/www, database dumps, the nginx
 * configuration, and certificates. The backup directory is 0700 root, so PHP
 * (www-data) cannot read the files directly — every operation goes via helper.
 *
 * Restore is NOT exposed through the UI. Restore overwrites the live database,
 * so it must be run from the shell (bin/fpanel or the helper) where the
 * operator can see the whole log and can abort.
 */
final class Backup
{
    private const HELPER = '/usr/local/sbin/fpanel-backup';

    /** Number of archives kept by the daily schedule. */
    public const KEEP = 7;

    /** Filename pattern; exactly the one used by the helper. */
    private const PAT_FILE = '/^fpanel-[0-9]{8}-[0-9]{6}\.tar\.gz$/';

    /** Path of the cron log, so the UI can show the latest run's result. */
    private const CRON_LOG = '/var/log/fpanel-backup.log';

    // ------------------------------------------------------------------ listing

    /**
     * List the available backups.
     *
     * Filenames are validated again here even though the helper validates them
     * too: helper output is input that ends up in HTML.
     */
    public static function listing(): array
    {
        $r = self::helper(['list']);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'items' => []];
        }

        $items = [];
        $lines = array_slice(explode("\n", trim($r['out'])), 1);   // skip the header
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 3) {
                continue;
            }
            [$name, $created, $size] = [$cols[0], $cols[1], (int)$cols[2]];
            if (!preg_match(self::PAT_FILE, $name)) {
                continue;
            }
            $items[] = [
                'name'       => $name,
                'created_utc'=> $created,
                'size'       => $size,
                'size_human' => self::human($size),
            ];
        }
        // Newest first.
        usort($items, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return ['ok' => true, 'error' => null, 'items' => $items,
                'count' => count($items), 'keep' => self::KEEP,
                'last_log' => self::lastLog()];
    }

    /**
     * Name of the newest available archive, or '' if there is no backup yet.
     *
     * Used by the offsite:upload CLI so the latest archive can be sent without
     * having to name it manually.
     */
    public static function latestName(): string
    {
        $r = self::listing();
        if (!$r['ok'] || $r['items'] === []) {
            return '';
        }
        return (string)$r['items'][0]['name'];
    }

    /** Create one backup now. */
    public static function create(int $keep = self::KEEP): array
    {
        if ($keep < 1 || $keep > 100) {
            return ['ok' => false, 'error' => 'Keep count is out of range.'];
        }
        $r = self::helper(['create', (string)$keep]);
        if (!$r['ok']) {
            return ['ok' => false, 'error' => $r['error']];
        }
        // The helper prints a summary, then the path on the last line.
        $lines  = array_values(array_filter(explode("\n", trim($r['out']))));
        $path   = (string)end($lines);
        $name   = basename($path);
        $message = 'Backup finished: ' . ($name !== '' ? $name : '(name unreadable)');
        // Include the helper's short message so the reason is visible in the UI.
        $summary = trim(implode(' ', array_slice($lines, 0, -1)));
        if ($summary !== '') {
            $message .= ' — ' . $summary;
        }
        return ['ok' => true, 'error' => null, 'message' => $message, 'name' => $name];
    }

    /** Check the integrity of one archive (per-part checksum + sqlite). */
    public static function verify(string $name): array
    {
        if (!preg_match(self::PAT_FILE, $name)) {
            return ['ok' => false, 'error' => 'Invalid backup name.', 'report' => ''];
        }
        $r = self::helper(['verify', $name]);
        return ['ok' => $r['ok'], 'error' => $r['ok'] ? null : 'Verification failed.',
                'report' => $r['out'] . ($r['err'] !== '' ? "\n" . $r['err'] : '')];
    }

    /** Delete one archive. */
    public static function delete(string $name): array
    {
        if (!preg_match(self::PAT_FILE, $name)) {
            return ['ok' => false, 'error' => 'Invalid backup name.'];
        }
        $r = self::helper(['delete', $name]);
        return ['ok' => $r['ok'], 'error' => $r['error'],
                'message' => $r['ok'] ? 'Backup ' . $name . ' deleted.' : null];
    }

    /** Run retention per policy (also runs automatically on every create). */
    public static function prune(int $keep = self::KEEP): array
    {
        if ($keep < 1 || $keep > 100) {
            return ['ok' => false, 'error' => 'Keep count is out of range.'];
        }
        $r = self::helper(['prune', (string)$keep]);
        return ['ok' => $r['ok'], 'error' => $r['error'],
                'message' => $r['ok'] ? 'Retention applied, keeping the newest ' . $keep . ' archives.' : null];
    }

    /** Is the helper available? */
    public static function available(): bool
    {
        return is_executable(self::HELPER);
    }

    /**
     * Contents of the latest cron log (for display in the UI).
     *
     * The log is read without sudo: the log file is created by root with group
     * adm and mode 0640. If it cannot be read, return a short message, not a fatal error.
     */
    public static function lastLog(int $lines = 25): string
    {
        if (!is_readable(self::CRON_LOG)) {
            return '(no log yet; backups run automatically every day at 03:17 UTC)';
        }
        $fh = @fopen(self::CRON_LOG, 'r');
        if ($fh === false) {
            return '(the log cannot be read by www-data)';
        }
        $buf = '';
        while (($line = fgets($fh)) !== false) {
            $buf = $line . $buf;
            if (substr_count($buf, "\n") >= $lines) {
                break;
            }
        }
        fclose($fh);
        $out = trim($buf);
        return $out === '' ? '(the log is empty)' : $out;
    }

    // ------------------------------------------------------------------ internal

    private static function helper(array $args): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'The backup helper is not installed on the server.',
                    'out' => '', 'err' => ''];
        }
        $pipes = [];
        // Argument array, no shell: there is no interpolation that could be injected.
        $proc = @proc_open(array_merge(['sudo', '-n', self::HELPER], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Failed to run the backup helper.', 'out' => '', 'err' => ''];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $out = (string)$out;
        $err = (string)$err;
        if ($code !== 0) {
            $msg = trim(preg_replace('/^ERR:\s*/', '', $err) ?: '') ?: trim($out) ?: 'The backup operation failed.';
            return ['ok' => false, 'error' => $msg, 'out' => $out, 'err' => $err];
        }
        return ['ok' => true, 'error' => null, 'out' => $out, 'err' => $err];
    }

    private static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float)$bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)$bytes : number_format($v, 1)) . ' ' . $units[$i];
    }
}