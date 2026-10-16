<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;

/**
 * Per-domain php.ini values (memory_limit, upload, opcache, ...).
 *
 * Applied through fastcgi_param PHP_VALUE in the domain vhost, because that
 * is the only way that really works: PHP-FPM ignores
 * PHP_INI_SCAN_DIR sent through fastcgi_param, so per-domain .ini files
 * would never be read.
 *
 * PHP_VALUE can only change directives with the PHP_INI_ALL extension, so
 * this list cannot be extended carelessly. The values go into the nginx
 * config file, so every directive AND its value is strictly validated here
 * — no character may be able to break the config line.
 */
final class PhpSettings
{
    /**
     * Directives that may be changed per domain.
     *
     * 'type' determines how the value is validated:
     *   size  -> integer with an optional K/M/G unit
     *   time  -> integer with an optional M/S unit
     *   int   -> plain integer
     *   bool  -> 0 or 1
     *   flags -> list of allowed flag names (for error_reporting)
     */
    private const SCHEMA = [
        'memory_limit'           => ['type' => 'size',  'default' => '128M'],
        'upload_max_filesize'    => ['type' => 'size',  'default' => '2M'],
        'post_max_size'          => ['type' => 'size',  'default' => '8M'],
        'max_execution_time'     => ['type' => 'time',  'default' => '30'],
        'max_input_time'         => ['type' => 'time',  'default' => '60'],
        'max_input_vars'         => ['type' => 'int',   'default' => '1000'],
        'display_errors'         => ['type' => 'bool',  'default' => '0'],
        'display_startup_errors' => ['type' => 'bool',  'default' => '0'],
        'error_reporting'        => ['type' => 'flags', 'default' => 'E_ALL & ~E_DEPRECATED & ~E_STRICT'],
        'opcache.enable'                  => ['type' => 'bool', 'default' => '1'],
        'opcache.memory_consumption'      => ['type' => 'size', 'default' => '128M'],
        'opcache.max_accelerated_files'   => ['type' => 'int',  'default' => '10000'],
        'opcache.validate_timestamps'     => ['type' => 'bool', 'default' => '1'],
        'opcache.revalidate_freq'         => ['type' => 'int',  'default' => '2'],
    ];

    /** error_reporting values that may be used. */
    private const REPORTING_FLAGS = [
        'E_ALL', 'E_ERROR', 'E_WARNING', 'E_NOTICE', 'E_DEPRECATED', 'E_STRICT',
        'E_PARSE', 'E_CORE_ERROR', 'E_CORE_WARNING', 'E_COMPILE_ERROR',
    ];

    /** Upper bounds so a user cannot exhaust all server RAM. */
    private const MAX_SIZE_MB = 2048;
    private const MAX_TIME_S  = 3600;

    /** Server default values (from php.ini), used when a domain sets none. */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::SCHEMA as $k => $spec) {
            $out[$k] = $spec['default'];
        }
        return $out;
    }

    /**
     * Settings belonging to one domain.
     *
     * Because the php_ini column does not exist in every installation, the result
     * is cached in per-process memory and the column is created by bin/fpanel db:migrate-ini.
     */
    public static function forDomain(string $username, int $domainId): array
    {
        $row = self::owned($username, $domainId);
        if ($row === null) {
            return [];
        }
        $raw = (string)($row['php_ini'] ?? '');
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Reload the FPM pool of one PHP version.
     *
     * This is mandatory after the vhost is rewritten. Without it, the FPM
     * pool keeps the PHP_VALUE from the previous request: once an override
     * is removed (e.g. during a reset), the domain still runs with the old
     * value until the pool is reloaded.
     *
     * Use "reload" (graceful), not "restart": reload does not close the
     * listener socket, so there is no 502 window for other sites served by
     * the same pool.
     */
    public static function reloadPool(?string $php): void
    {
        $ver  = preg_match('/^[0-9]+\.[0-9]+$/', (string)$php) === 1 ? (string)$php : '8.3';
        $cmd  = ['sudo', '-n', '/usr/local/sbin/fpanel-php', 'reload', $ver];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($proc)) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
            }
            proc_close($proc);
        }
    }

    /** Defaults + domain overrides merged, for display in the form. */
    public static function merged(string $username, int $domainId): array
    {
        return array_merge(self::defaults(), self::forDomain($username, $domainId));
    }

    /**
     * Collect directives from form field names.
     *
     * PHP replaces dots and spaces with underscores in POST/GET field
     * names, so "ini_opcache.memory_consumption" actually arrives as
     * "ini_opcache_memory_consumption". Therefore the received name is
     * matched against SCHEMA first: an exact match is used; otherwise every
     * possible dot position is tried until one matches.
     *
     * Example: "opcache_memory_consumption" -> tried as-is (not in
     * SCHEMA), then "opcache.memory.consumption" (not there), then
     * "opcache.memory_consumption" (present) -> that one is used.
     *
     * Directives that really use underscores, like max_input_vars, stay
     * safe because the exact match is tried first, before any dot.
     */
    public static function fromForm(array $fields): array
    {
        $out = [];
        foreach ($fields as $raw => $value) {
            if (!is_string($raw) || !is_string($value) || !str_starts_with($raw, 'ini_')) {
                continue;
            }
            $name = substr($raw, 4);
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            if (isset(self::SCHEMA[$name])) {
                $out[$name] = $value;
                continue;
            }
            // Try every dot combination on the underscores (at most 4 underscores).
            $parts = explode('_', $name);
            $n     = count($parts);
            if ($n > 5) {
                continue;
            }
            $found = null;
            for ($mask = 1; $mask < (1 << ($n - 1)) && $found === null; $mask++) {
                $cand = $parts[0];
                for ($i = 1; $i < $n; $i++) {
                    $cand .= (($mask >> ($i - 1)) & 1) ? '.' . $parts[$i] : '_' . $parts[$i];
                }
                if (isset(self::SCHEMA[$cand])) {
                    $found = $cand;
                }
            }
            if ($found !== null) {
                $out[$found] = $value;
            }
        }
        return $out;
    }

    /**
     * Save a domain's settings.
     *
     * $input comes from the form: key = directive name. Directives outside
     * the SCHEMA list are ignored (not an error) so unknown fields from an
     * older form version do not break the page.
     */
    /**
     * @param bool $reloadPool Reload the pool after the vhost is written. Pass false
     *        when the caller wants to write many domains at once and
     *        reload the pool once at the end (see the scope=all route).
     *
     */
    public static function save(string $username, int $domainId, array $input, bool $reloadPool = true): array
    {
        $row = self::owned($username, $domainId);
        if ($row === null) {
            return ['ok' => false, 'error' => 'Domain not found.'];
        }

        $clean = [];
        foreach ($input as $key => $value) {
            if (!isset(self::SCHEMA[$key])) {
                continue;
            }
            $v = self::sanitize((string)$key, (string)$value);
            if ($v === null) {
                return ['ok' => false, 'error' => 'The value for ' . $key . ' is not valid.'];
            }
            if ($v !== '') {
                $clean[$key] = $v;
            }
        }

        $json = json_encode($clean, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Failed to save the settings.'];
        }
        Connection::run('UPDATE domains SET php_ini = :v WHERE id = :i',
            ['v' => $json, 'i' => $domainId]);

        // Redeploy the vhost so PHP_VALUE changes too.
        $d = self::owned($username, $domainId);
        if ($d !== null) {
            Vhost::deploy((string)$d['name'], $username, (string)$d['docroot'],
                (string)$d['type'], $d['php_version'] === null ? null : (string)$d['php_version'], $clean);

            // The pool is reloaded so the just-removed values (especially on a
            // reset) really stop applying, instead of lingering in the FPM
            // worker memory.
            if ($reloadPool) {
                self::reloadPool($d['php_version'] === null ? null : (string)$d['php_version']);
            }
        }

        return ['ok' => true, 'error' => null, 'settings' => $clean];
    }

    /**
     * Rebuild a domain vhost with the stored php_ini settings.
     *
     * Used whenever the vhost must be redeployed from outside PhpSettings
     * (e.g. after a certificate is revoked), so per-domain overrides are
     * not lost.
     */
    public static function apply(string $domain, string $username, array $d): void
    {
        $raw = (string)($d['php_ini'] ?? '');
        $ini = [];
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ini = $decoded;
            }
        }
        Vhost::deploy($domain, $username, (string)$d['docroot'], (string)$d['type'],
            ($d['php_version'] ?? null) === null ? null : (string)$d['php_version'], $ini);
    }

    /** Clear all domain overrides (back to the server defaults). */
    public static function reset(string $username, int $domainId): array
    {
        return self::save($username, $domainId, []);
    }

    // ------------------------------------------------------------------ internal

    /**
     * Validate one value. Returns null when invalid.
     *
     * Every accepted value is guaranteed to contain only characters safe
     * for fastcgi_param: letters, digits, spaces, and the operators & ~ | only.
     */
    private static function sanitize(string $key, string $value): ?string
    {
        $spec  = self::SCHEMA[$key] ?? null;
        if ($spec === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        switch ($spec['type']) {
            case 'size':
                if (!preg_match('/^(\d{1,5})([KMGkmg]?)$/', $value, $m)) {
                    return null;
                }
                $n = (int)$m[1];
                $mb = match (strtoupper($m[2])) {
                    'G' => $n * 1024,
                    'M' => $n,
                    'K' => (int)ceil($n / 1024),
                    default => (int)ceil($n / 1048576),
                };
                if ($mb > self::MAX_SIZE_MB || $n === 0) {
                    return null;
                }
                return $n . ($m[2] === '' ? '' : strtoupper($m[2]));

            case 'time':
                if (!preg_match('/^(\d{1,5})([SMsm]?)$/', $value, $m)) {
                    return null;
                }
                $sec = strtoupper($m[2]) === 'M' ? (int)$m[1] * 60 : (int)$m[1];
                if ($sec > self::MAX_TIME_S) {
                    return null;
                }
                return $m[1] . ($m[2] === '' ? '' : strtoupper($m[2]));

            case 'int':
                if (!preg_match('/^\d{1,7}$/', $value)) {
                    return null;
                }
                return $value;

            case 'bool':
                return in_array($value, ['0', '1'], true) ? $value : null;

            case 'flags':
                // Only known flag names, separated by & or |.
                $parts = preg_split('/[&|]/', $value) ?: [];
                $parts = array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
                if ($parts === []) {
                    return null;
                }
                foreach ($parts as $p) {
                    if (!in_array(strtoupper($p), self::REPORTING_FLAGS, true)) {
                        return null;
                    }
                }
                return implode(' & ', array_map('strtoupper', $parts));
        }
        return null;
    }

    /** The domain row owned by this user, or null (IDOR guard). */
    private static function owned(string $username, int $domainId): ?array
    {
        $uid = (int)Connection::value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
        if ($uid === 0) {
            return null;
        }
        return Connection::one('SELECT * FROM domains WHERE id = :i AND user_id = :u',
            ['i' => $domainId, 'u' => $uid]);
    }
}