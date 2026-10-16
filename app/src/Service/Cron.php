<?php
declare(strict_types=1);

namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * User-owned cron jobs.
 *
 * Each job is stored in the cron_jobs table and then synced to the file
 * /etc/cron.d/fpanel-cron-<user> by the root helper (fpanel-cron sync).
 * Execution and logging are handled by the fpanel-cron run helper, so the panel
 * does not need to run its own cron daemon.
 *
 * Security note: user commands are executed as www-data, the same
 * as in other hosting panels. Only "sync" needs root, because it
 * writes the cron file, and the helper validates every line against a strict
 * pattern so no arbitrary command can reach the cron daemon.
 */
final class Cron
{
    private const HELPER = '/usr/local/sbin/fpanel-cron';

    private const MAX_COMMAND = 1000;
    private const MAX_NAME    = 100;
    public const MAX_JOBS    = 50;

    /** Log size limit displayed in the UI; the helper truncates the log at 256 KB. */
    public const LOG_TAIL_BYTES = 16 * 1024;

    // Field indexes of the crontab schedule.
    private const F_MIN   = 0;
    private const F_HOUR  = 1;
    private const F_DOM   = 2;
    private const F_MONTH = 3;
    private const F_DOW   = 4;

    // The ~ delimiter is used because the character class contains "/" (for
    // steps like */5). If the delimiter were "/", the regex would close itself early.
    private const FIELD_RE = '~^[0-9*/,\-]{1,60}$~';

    // ------------------------------------------------------------------ public

    /**
     * List the jobs owned by one user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listing(string $username): array
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return [];
        }
        return Connection::all(
            'SELECT * FROM cron_jobs WHERE user_id = :u ORDER BY id',
            ['u' => $uid]
        );
    }

    /**
     * Create a new job.
     *
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function create(string $username, string $name, string $command, string $schedule): array
    {
        $uid = self::userId($username);
        if ($uid < 1) {
            return self::err('User not found.');
        }
        $name   = trim($name);
        $cmd    = trim($command);
        $sched  = trim($schedule);

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            return self::err('The cron job name is required and must be at most 100 characters.');
        }
        $err = self::validateCommand($cmd);
        if ($err !== null) {
            return self::err($err);
        }
        if (!self::validSchedule($sched)) {
            return self::err('Invalid schedule. Example: */5 * * * *');
        }

        $existing = (int)Connection::value(
            'SELECT COUNT(*) FROM cron_jobs WHERE user_id = :u',
            ['u' => $uid]
        );
        if ($existing >= self::MAX_JOBS) {
            return self::err('The cron job quota is full (' . self::MAX_JOBS . ').');
        }

        Connection::run(
            'INSERT INTO cron_jobs (user_id, name, command, schedule, enabled, created_at)
             VALUES (:u, :n, :c, :s, 1, :t)',
            ['u' => $uid, 'n' => $name, 'c' => $cmd, 's' => $sched, 't' => gmdate('Y-m-d H:i:s')]
        );
        $id = (int)Connection::pdo()->lastInsertId();

        $r = self::sync($username);
        if (!$r['ok']) {
            // Do not leave behind a job that would never run.
            Connection::run('DELETE FROM cron_jobs WHERE id = :i', ['i' => $id]);
            return $r;
        }
        return ['ok' => true, 'error' => null, 'message' => 'Cron job created.'];
    }

    /**
     * Update a user's job. A null parameter means "leave unchanged".
     *
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function update(
        string $username,
        int $id,
        ?string $name,
        ?string $command,
        ?string $schedule,
        ?bool $enabled
    ): array {
        if (self::find($username, $id) === null) {
            return self::err('Cron job not found.');
        }
        $set = [];
        $par = ['i' => $id, 'u' => self::userId($username)];

        if ($name !== null) {
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
                return self::err('The cron job name is required and must be at most 100 characters.');
            }
            $set[] = 'name = :n';
            $par['n'] = $name;
        }
        if ($command !== null) {
            $command = trim($command);
            $err = self::validateCommand($command);
            if ($err !== null) {
                return self::err($err);
            }
            $set[] = 'command = :c';
            $par['c'] = $command;
        }
        if ($schedule !== null) {
            $schedule = trim($schedule);
            if (!self::validSchedule($schedule)) {
                return self::err('Invalid schedule. Example: */5 * * * *');
            }
            $set[] = 'schedule = :s';
            $par['s'] = $schedule;
        }
        if ($enabled !== null) {
            $set[] = 'enabled = :e';
            $par['e'] = $enabled ? 1 : 0;
        }
        if ($set === []) {
            return ['ok' => true, 'error' => null, 'message' => 'No changes.'];
        }

        Connection::run(
            'UPDATE cron_jobs SET ' . implode(', ', $set) . ' WHERE id = :i AND user_id = :u',
            $par
        );
        $r = self::sync($username);
        return $r['ok'] ? ['ok' => true, 'error' => null, 'message' => 'Cron job updated.'] : $r;
    }

    /**
     * Delete a user's job.
     *
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function delete(string $username, int $id): array
    {
        if (self::find($username, $id) === null) {
            return self::err('Cron job not found.');
        }
        Connection::run(
            'DELETE FROM cron_jobs WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => self::userId($username)]
        );
        $r = self::sync($username);
        return $r['ok'] ? ['ok' => true, 'error' => null, 'message' => 'Cron job deleted.'] : $r;
    }

    /**
     * Run one job right now, outside its schedule.
     *
     * The helper is called WITHOUT sudo: execution must indeed run as www-data,
     * and it must not have any sudo path at all.
     *
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function runNow(string $username, int $id): array
    {
        if (self::find($username, $id) === null) {
            return self::err('Cron job not found.');
        }
        [$rc, $out] = self::helper(['run', $username, (string)$id], withSudo: false);
        if ($rc !== 0) {
            return self::err('Failed to run the job: ' . ($out !== '' ? $out : 'no message from the helper'));
        }
        Connection::run(
            'UPDATE cron_jobs SET last_run = :t, last_exit = 0 WHERE id = :i',
            ['t' => gmdate('Y-m-d H:i:s'), 'i' => $id]
        );
        return ['ok' => true, 'error' => null, 'message' => $out !== '' ? $out : 'Job executed.'];
    }

    /** Tail of the job log, for display in the UI. */
    public static function logTail(string $username, int $id, int $bytes = self::LOG_TAIL_BYTES): string
    {
        if (self::find($username, $id) === null) {
            return '';
        }
        $log = self::logPath($username, $id);
        if (!is_file($log) || !is_readable($log)) {
            return '';
        }
        $size = (int)filesize($log);
        $fh   = @fopen($log, 'rb');
        if ($fh === false) {
            return '';
        }
        if ($size > $bytes) {
            fseek($fh, -$bytes, SEEK_END);
        }
        $out = (string)stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    public static function logPath(string $username, int $id): string
    {
        return '/var/log/fpanel-cron/' . $username . '-' . $id . '.log';
    }

    /**
     * Rewrite the user's /etc/cron.d file from the list of enabled jobs.
     *
     * @return array{ok: bool, error: string|null, message: string|null}
     */
    public static function sync(string $username): array
    {
        $lines = '';
        foreach (self::listing($username) as $job) {
            if ((int)$job['enabled'] !== 1) {
                continue;
            }
            $lines .= (string)$job['schedule'] . ' ' . $username . ' '
                . (string)$job['id'] . ' ' . base64_encode((string)$job['command']) . "\n";
        }

        [$rc, $out] = self::helper(['sync', $username], $lines);
        if ($rc !== 0) {
            return self::err('Failed to sync cron: ' . ($out !== '' ? $out : 'no message from the helper'));
        }
        return ['ok' => true, 'error' => null, 'message' => $out];
    }

    // ---------------------------------------------------------------- schedule

    /**
     * Validate a 5-field expression.
     *
     * Deliberately strict: only digits, *, /, , and -. Aliases like @daily and
     * day names (Mon) are rejected so our parser and the cron daemon never
     * interpret them differently.
     */
    public static function validSchedule(string $schedule): bool
    {
        $fields = preg_split('/\s+/', trim($schedule), -1, PREG_SPLIT_NO_EMPTY);
        if ($fields === false || count($fields) !== 5) {
            return false;
        }
        foreach ($fields as $i => $f) {
            if (preg_match(self::FIELD_RE, $f) !== 1) {
                return false;
            }
            if (self::fieldOutOfRange($f, $i)) {
                return false;
            }
        }
        return true;
    }

    /** Value outside the field's range, for example minute "70". */
    private static function fieldOutOfRange(string $field, int $index): bool
    {
        foreach (explode(',', $field) as $part) {
            $part = trim($part);
            if ($part === '') {
                return true;
            }
            $base = $part;
            if (str_contains($part, '/')) {
                [$base, $stepRaw] = explode('/', $part, 2);
                if (preg_match('/^[0-9]{1,3}$/', $stepRaw) !== 1 || (int)$stepRaw < 1) {
                    return true;
                }
                $base = $base === '*' ? '' : $base;
            }
            if ($base === '' || $base === '*') {
                continue;
            }
            // A range needs both ends checked, not one whole string.
            $ends = str_contains($base, '-') ? explode('-', $base, 2) : [$base];
            foreach ($ends as $end) {
                if (preg_match('/^[0-9]{1,2}$/', $end) !== 1) {
                    return true;
                }
                if ((int)$end > self::fieldMax($index)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function fieldMax(int $index): int
    {
        return match ($index) {
            self::F_MIN   => 59,
            self::F_HOUR  => 23,
            self::F_DOM   => 31,
            self::F_MONTH => 12,
            self::F_DOW   => 7,
            default       => 59,
        };
    }

    /**
     * Estimated next run time, in UTC.
     *
     * Good enough for display in the UI, so the implementation is brute force
     * with per-minute and per-hour jumps, not a full cron parser.
     */
    public static function nextRun(string $schedule, ?string $from = null): ?string
    {
        if (!self::validSchedule($schedule)) {
            return null;
        }
        $fields = preg_split('/\s+/', trim($schedule), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sets   = [];
        for ($i = 0; $i < 5; $i++) {
            $sets[$i] = self::expandField($fields[$i], $i);
        }

        $start = $from !== null ? strtotime($from . ' UTC') : time();
        if ($start === false) {
            $start = time();
        }
        $ts = $start - ($start % 60) + 60; // start of the next minute

        // 400-day limit so an impossible expression does not loop forever.
        $limit = $ts + (400 * 86400);
        while ($ts < $limit) {
            if (isset($sets[self::F_MIN][(int)date('i', $ts)])
                && isset($sets[self::F_HOUR][(int)date('G', $ts)])
                && isset($sets[self::F_MONTH][(int)date('n', $ts)])
                && self::matchesDomDow($sets, $ts)) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
            $ts += 60;
            $minute = (int)date('i', $ts);
            if (!isset($sets[self::F_MIN][$minute])) {
                $next = self::nextValue($sets[self::F_MIN], $minute, 59);
                if ($next === null) {
                    // No minute matches in this hour: jump to the next hour.
                    $ts = $ts - ($minute * 60) + 3600;
                    continue;
                }
                $ts += ($next - $minute) * 60;
            }
        }
        return null;
    }

    /**
     * Standard cron rule for days: if both day-of-month and day-of-week are
     * restricted (not "*"), the job runs when either one matches.
     *
     * @param array<int, array<int, bool>> $sets
     */
    private static function matchesDomDow(array $sets, int $ts): bool
    {
        $domSet = $sets[self::F_DOM];
        $dowSet = $sets[self::F_DOW];

        $domAll = count($domSet) >= 31;                  // all days 1-31 covered
        $dowAll = count($dowSet) >= 7;                  // all days 0-6 covered

        $domHit = isset($domSet[(int)date('j', $ts)]);
        $dow    = (int)date('w', $ts);
        $dowHit = isset($dowSet[$dow]) || ($dow === 0 && isset($dowSet[7]));

        if ($domAll && $dowAll) {
            return true;
        }
        if (!$domAll && !$dowAll) {
            return $domHit || $dowHit;
        }
        return $domAll ? $dowHit : $domHit;
    }

    /**
     * @param array<int, bool> $set
     */
    private static function nextValue(array $set, int $current, int $max): ?int
    {
        for ($i = $current + 1; $i <= $max; $i++) {
            if (isset($set[$i])) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Expand one schedule field into the set of allowed values.
     *
     * @return array<int, bool>
     */
    private static function expandField(string $field, int $index): array
    {
        $max = match ($index) {
            self::F_MIN   => 59,
            self::F_HOUR  => 23,
            self::F_DOM   => 31,
            self::F_MONTH => 12,
            self::F_DOW   => 6,   // 7 is normalized to 0 below
            default       => 59,
        };
        $out = [];
        foreach (explode(',', $field) as $part) {
            $step  = 1;
            $range = $part;
            if (str_contains($part, '/')) {
                [$range, $stepRaw] = explode('/', $part, 2);
                $step = max(1, (int)$stepRaw);
            }
            if ($range === '' || $range === '*') {
                for ($v = 0; $v <= $max; $v += $step) {
                    $out[$v] = true;
                }
                continue;
            }
            if (str_contains($range, '-')) {
                [$a, $b] = explode('-', $range, 2);
                if (preg_match('/^[0-9]{1,2}$/', $a) !== 1 || preg_match('/^[0-9]{1,2}$/', $b) !== 1) {
                    continue;
                }
                $lo = (int)$a;
                $hi = (int)$b;
                if ($lo > $hi) {
                    [$lo, $hi] = [$hi, $lo];
                }
                $hi = min($hi, $index === self::F_DOW ? 7 : $max);
                $lo = min($lo, $index === self::F_DOW ? 7 : $max);
                for ($v = $lo; $v <= $hi; $v += $step) {
                    $out[$v === 7 ? 0 : $v] = true;
                }
                continue;
            }
            if (preg_match('/^[0-9]{1,2}$/', $range) === 1) {
                $v = (int)$range;
                $v = min($v, $index === self::F_DOW ? 7 : $max);
                $out[$v === 7 ? 0 : $v] = true;
            }
        }
        return $out;
    }

    // -------------------------------------------------------------- validation

    /**
     * Reject commands that are clearly dangerous or nonsensical.
     *
     * Commands are still treated as a "user command" like in other hosting
     * panels. What is checked here are patterns that are almost always a typo
     * or clearly never needed on shared hosting.
     */
    private static function validateCommand(string $command): ?string
    {
        if ($command === '') {
            return 'Command is required.';
        }
        if (mb_strlen($command) > self::MAX_COMMAND) {
            return 'Command is too long (maximum ' . self::MAX_COMMAND . ' characters).';
        }
        if (str_contains($command, "\0") || preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $command) === 1) {
            return 'The command contains invalid control characters.';
        }
        $banned = ['shutdown', 'reboot', 'halt', 'poweroff', 'mkfs', 'init 0', 'init 6'];
        foreach ($banned as $word) {
            if (preg_match('/(^|[;&|]\s*|\s)' . preg_quote($word, '/') . '(\s|$)/', $command) === 1) {
                return 'This command is not allowed.';
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ internal

    /** @return array<string, mixed>|null */
    public static function find(string $username, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        return Connection::one(
            'SELECT * FROM cron_jobs WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => self::userId($username)]
        );
    }

    private static function userId(string $username): int
    {
        return (int)Connection::value(
            'SELECT id FROM users WHERE username = :u',
            ['u' => $username]
        );
    }

    /**
     * Run the helper.
     *
     * @return array{0: int, 1: string} exit code and combined output
     */
    private static function helper(array $args, string $stdin = '', bool $withSudo = true): array
    {
        $base = $withSudo ? ['sudo', '-n', self::HELPER] : [self::HELPER];
        $cmd  = array_merge($base, $args);
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return [1, 'the helper could not be run'];
        }
        if ($stdin !== '') {
            @fwrite($pipes[0], $stdin);
        }
        @fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        $rc = proc_close($proc);
        return [$rc, trim($out . "\n" . $err)];
    }

    /** @return array{ok: bool, error: string|null, message: string|null} */
    private static function err(string $msg): array
    {
        return ['ok' => false, 'error' => $msg, 'message' => null];
    }
}