<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Cleanup of data that keeps growing: sessions, audit log, login attempts, and
 * nginx access logs whose domains no longer exist.
 *
 * Every operation here deletes data, so each step has an explicit age limit.
 * If a bug ever sneaks in, what disappears is old logs, not accounts.
 */
final class Housekeeping
{
    /** Minimum days before an audit log entry may be deleted. */
    public const AUDIT_KEEP_DAYS = 90;

    /** Minimum days before a login attempt may be deleted. */
    public const ATTEMPT_KEEP_DAYS = 7;

    /** Minimum age of a log file, in days, before it may be deleted. */
    public const LOG_MIN_AGE_DAYS = 3;

    /**
     * Minimum age (hours) of an offsite upload temp file before it counts as orphaned.
     *
     * A normal upload finishes within minutes; a file still around
     * after several hours means its process died midway.
     */
    public const TEMP_UPLOAD_MIN_AGE_HOURS = 6;

    /** Prefix of temp files created by Offsite::encryptFile() through tempnam(). */
    public const TEMP_UPLOAD_PREFIX = 'fp-enc-';

    private function __construct(private readonly string $logDir) {}

    public static function make(?string $logDir = null): self
    {
        return new self($logDir ?? self::defaultLogDir());
    }

    /**
     * Location of the panel's nginx access log.
     *
     * Checked from the FPM pool because the CLI may have a different environment;
     * when it cannot be found, this job simply skips log cleanup.
     */
    public static function defaultLogDir(): string
    {
        $candidates = [
            '/var/log/nginx/fpanel',
            '/srv/fpanel/var/log/nginx',
        ];
        foreach ($candidates as $d) {
            if (is_dir($d)) {
                return $d;
            }
        }
        return $candidates[0];
    }

    /**
     * Run all cleanup steps.
     *
     * $opt is only used by the test suite to force small age limits. It stays
     * empty in production, so retention always follows the constants above.
     *
     * @param  array{auditDays?:int,attemptDays?:int} $opt
     * @return array{ok:bool,error?:string,counts?:array<string,int>,warnings?:string[]}
     */
    public function run(bool $dryRun = false, array $opt = []): array
    {
        $counts = [];
        try {
            $counts['sessions']   = $this->pruneSessions($dryRun);
            $counts['audit_log']  = $this->pruneAuditLog($dryRun, $opt);
            $counts['login']      = $this->pruneLoginAttempts($dryRun, $opt);
            // This table does not exist yet on servers that have not run
            // db:migrate-devices, so pruneDeviceTable checks for it first.
            $counts['login_links'] = $this->pruneDeviceTable('login_links', 'used_at', $dryRun);
            $counts['devices']     = $this->pruneDeviceTable('remember_devices', 'revoked_at', $dryRun);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Database cleanup failed: ' . $e->getMessage()];
        }

        $warnings = [];
        try {
            $counts['nginx_logs'] = $this->pruneOrphanLogs($dryRun);
        } catch (\Throwable $e) {
            // Logs are not critical: a failure here must not fail the
            // database cleanup that already succeeded.
            $warnings[] = 'nginx logs skipped: ' . $e->getMessage();
        }
        try {
            $counts['temp_uploads'] = $this->pruneTempUploads($dryRun);
        } catch (\Throwable $e) {
            $warnings[] = 'Temp files skipped: ' . $e->getMessage();
        }

        return ['ok' => true, 'counts' => $counts, 'warnings' => $warnings];
    }

    /**
     * Offsite upload encryption temp files (fp-enc-*) left behind by
     * a process that stopped midway.
     *
     * The normal path always deletes them through the finally block in
     * Offsite::upload(); what can remain are leftovers of processes killed by
     * force (reboot, OOM, network timeout). Freshly created files are left
     * alone so an upload in progress is not disturbed, and names outside the
     * panel's prefix are never deleted.
     */
    public function pruneTempUploads(bool $dryRun = false): int
    {
        // TMPDIR may end with a trailing slash; trim it so the path does not
        // become "//fp-enc-*" when joined.
        $dir = rtrim(sys_get_temp_dir(), '/');
        if ($dir === '') {
            $dir = '/';
        }
        if (!is_dir($dir)) {
            return 0;
        }
        $limit = time() - self::TEMP_UPLOAD_MIN_AGE_HOURS * 3600;
        $pattern = '/^' . preg_quote(self::TEMP_UPLOAD_PREFIX, '/') . '[A-Za-z0-9]+$/';
        $n = 0;
        foreach ((array)glob($dir . '/' . self::TEMP_UPLOAD_PREFIX . '*') as $f) {
            // Regular files only; symlinks and similarly named files are left alone.
            if (!is_file($f) || is_link($f)) {
                continue;
            }
            if (!preg_match($pattern, basename($f))) {
                continue;
            }
            if ((int)@filemtime($f) > $limit) {
                continue;
            }
            $n++;
            if (!$dryRun) {
                @unlink($f);
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ database

    /** Expired sessions, including abandoned 2FA step-up sessions. */
    private function pruneSessions(bool $dryRun): int
    {
        if ($dryRun) {
            return (int)Connection::value(
                "SELECT COUNT(*) FROM sessions WHERE expires_at <= datetime('now')"
            );
        }
        return Connection::run("DELETE FROM sessions WHERE expires_at <= datetime('now')")->rowCount();
    }

    /**
     * The audit log is trimmed, but failed rows ('denied'/'error') are kept
     * longer because they record security incidents.
     */
    private function pruneAuditLog(bool $dryRun, array $opt = []): int
    {
        $okDays  = (int)($opt['auditDays'] ?? self::AUDIT_KEEP_DAYS);
        $badDays = $okDays * 3;
        $sql = "DELETE FROM audit_log
                WHERE (result = 'ok'  AND created_at < datetime('now', :okWin))
                   OR (result <> 'ok' AND created_at < datetime('now', :badWin))";
        if ($dryRun) {
            return (int)Connection::value(
                "SELECT COUNT(*) FROM audit_log
                  WHERE (result = 'ok'  AND created_at < datetime('now', ?))
                     OR (result <> 'ok' AND created_at < datetime('now', ?))",
                ['-' . $okDays . ' days', '-' . $badDays . ' days']
            );
        }
        return Connection::run($sql, [
            'okWin'  => '-' . $okDays . ' days',
            'badWin' => '-' . $badDays . ' days',
        ])->rowCount();
    }

    private function pruneLoginAttempts(bool $dryRun, array $opt = []): int
    {
        $keep = '-' . (int)($opt['attemptDays'] ?? self::ATTEMPT_KEEP_DAYS) . ' days';
        if ($dryRun) {
            return (int)Connection::value(
                'SELECT COUNT(*) FROM login_attempts WHERE created_at < datetime("now", ?)',
                [$keep]
            );
        }
        return Connection::run(
            'DELETE FROM login_attempts WHERE created_at < datetime("now", ?)',
            [$keep]
        )->rowCount();
    }

    // ---------------------------------------------------------------------- log

    /**
     * Prune the device / login link tables.
     *
     * Used rows are marked with a timestamp column; when that column is
     * NULL (e.g. the token is still active), pruning is based on the expiry
     * date instead. The table is checked first so this job keeps working on
     * servers that have not run db:migrate-devices.
     */
    private function pruneDeviceTable(string $table, string $doneCol, bool $dryRun): int
    {
        $exists = (int)Connection::value(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t",
            ['t' => $table]
        );
        if ($exists < 1) {
            return 0;
        }
        $keep = '-' . self::ATTEMPT_KEEP_DAYS . ' days';
        if ($dryRun) {
            return (int)Connection::value(
                "SELECT COUNT(*) FROM $table
                  WHERE expires_at < datetime('now')
                     OR ($doneCol IS NOT NULL AND $doneCol < datetime('now', ?))",
                [$keep]
            );
        }
        return Connection::run(
            "DELETE FROM $table
              WHERE expires_at < datetime('now')
                 OR ($doneCol IS NOT NULL AND $doneCol < datetime('now', :win))",
            ['win' => $keep]
        )->rowCount();
    }

    /**
     * Delete log files whose domain is no longer registered.
     *
     * Two cases are handled:
     *   - the file name matches no domain at all (domain deleted without clearing logs)
     *   - the file name matches, but the log is older than LOG_MIN_AGE_DAYS
     *
     * The minimum age matters: a freshly created domain may have a similar-
     * looking name, and an active log file must not be deleted midway.
     */
    public function pruneOrphanLogs(bool $dryRun = false): int
    {
        if (!is_dir($this->logDir)) {
            return 0;
        }
        $known = [];
        foreach (Connection::all('SELECT name FROM domains') as $r) {
            $known[strtolower((string)$r['name'])] = true;
        }

        $removed = 0;
        $minMtime = time() - (self::LOG_MIN_AGE_DAYS * 86400);
        $entries  = @scandir($this->logDir);
        if ($entries === false) {
            return 0;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->logDir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $domain = self::domainFromLogName($name);
            if ($domain === null) {
                continue;
            }
            if (isset($known[$domain])) {
                continue;
            }
            $mtime = (int)@filemtime($path);
            if ($mtime > $minMtime) {
                // Too fresh to be sure it is orphaned; try again on the next run.
                continue;
            }
            if ($dryRun) {
                $removed++;
                continue;
            }
            if (@unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Extract the domain name from a log file name.
     *
     * Panel pattern: <domain>.access.log and <domain>.error.log. It is split
     * for rotated logs too ( access.log.1 ). Returns null when there is no
     * match, so unrelated files are not deleted.
     */
    public static function domainFromLogName(string $filename): ?string
    {
        $name = $filename;
        // Drop the rotation suffix: .1, .2, .gz
        $name = (string)preg_replace('/\.(gz|\d+)$/', '', $name);
        foreach (['.access.log', '.error.log', '.access', '.error'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                $domain = substr($name, 0, -strlen($suffix));
                return self::validDomain($domain);
            }
        }
        return null;
    }

    private static function validDomain(string $d): ?string
    {
        $d = strtolower(trim($d));
        if ($d === '' || strlen($d) > 253) {
            return null;
        }
        // Hostname, not a relative path: prevents "../../etc/passwd" from being
        // read as a domain name when an odd file shows up in the log directory.
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $d)) {
            return null;
        }
        return $d;
    }

    /** Log file names for a domain (also used when a domain is deleted). */
    public static function logFiles(string $domain): array
    {
        return [$domain . '.access.log', $domain . '.error.log'];
    }

    /**
     * Delete the logs of a domain that was just removed from the panel.
     *
     * Called from DomainManager::delete so logs do not linger until the next
     * housekeeping run. There is no age requirement here: the domain was just
     * deleted on purpose, so its contents are genuinely no longer needed.
     *
     * @return int number of files actually deleted
     */
    public function purgeDomainLogs(string $domain): int
    {
        $domain = self::validDomain($domain);
        if ($domain === null || !is_dir($this->logDir)) {
            return 0;
        }
        $removed = 0;
        foreach (self::logFiles($domain) as $name) {
            $path = $this->logDir . '/' . $name;
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        // Rotated logs (access.log.1) are dropped too.
        foreach ((array)@glob($this->logDir . '/' . $domain . '.{access,error}.log*') as $path) {
            if (is_string($path) && is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }
}
