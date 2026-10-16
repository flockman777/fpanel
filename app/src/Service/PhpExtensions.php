<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Support\Config;

/**
 * PHP extension management per version.
 *
 * PHP extensions live as .ini files in /etc/php/<v>/fpm/conf.d/ and only
 * take effect after that version's FPM pool is restarted. Both need root,
 * so they go through the helper /usr/local/sbin/fpanel-php (see sudoers).
 * PHP itself never holds root access.
 *
 * Limits the user should know about:
 *   - Extension status is PER PHP VERSION, not per domain. When version 8.3
 *     has zip enabled, every domain using 8.3 gets it too. This is how
 *     phpMyAdmin/phpSelector work as well.
 *   - Extensions cannot be disabled per domain, because extension loading
 *     happens before a domain's request reaches PHP.
 *
 * Per-domain values (memory_limit, upload_max_filesize, etc.) are handled
 * separately by PhpSettings through fastcgi_param PHP_VALUE.
 */
final class PhpExtensions
{
    private const HELPER = '/usr/local/sbin/fpanel-php';

    /**
     * Extensions that are not recommended for disabling.
     *
     * Disabling these can break the panel/WordPress too, so the UI flags
     * them but still allows it (the user may, with a warning).
     */
    private const SENSITIVE = ['mysqli', 'pdo_mysql', 'json', 'session', 'iconv', 'mbstring', 'curl', 'openssl'];

    /** "name<TAB>status" lines from the helper. */
    private static function helper(array $args): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok' => false, 'error' => 'The PHP extension helper is not installed on the server.'];
        }
        $cmd = array_merge(['sudo', '-n', self::HELPER], $args);
        $pipes = [];
        $proc  = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['ok' => false, 'error' => 'Failed to run the PHP extension helper.'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $out = (string)$out;
        $err = trim((string)$err);
        if ($code !== 0) {
            $msg = preg_replace('/^ERR:\s*/', '', $err) ?: 'The PHP extension operation failed.';
            return ['ok' => false, 'error' => trim($msg)];
        }
        return ['ok' => true, 'error' => null, 'out' => $out];
    }

    /** Installed PHP versions, complete with their extension list. */
    public static function listing(string $version): array
    {
        $versions = Vhost::installedPhpVersions();
        if ($version === '') {
            $version = self::defaultVersion($versions);
        }
        if (!in_array($version, $versions, true)) {
            return ['ok' => false, 'error' => 'PHP version ' . $version . ' is not installed on the server.'];
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
                continue;   // Never trust a name coming from helper output.
            }
            $exts[] = [
                'name'      => $name,
                'enabled'   => $state === 'enabled',
                'sensitive' => in_array($name, self::SENSITIVE, true),
            ];
        }
        usort($exts, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $on = count(array_filter($exts, static fn (array $e): bool => $e['enabled']));
        return ['ok' => true, 'error' => null, 'version' => $version, 'versions' => $versions,
                'extensions' => $exts, 'active' => $on, 'total' => count($exts)];
    }

    /**
     * Matrix of all versions: [version => ['ext' => 'enabled'|'disabled'|null]].
     *
     * A null value means the extension does not exist for that version, so the
     * UI can mark it "not available" instead of offering a useless switch.
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
            $out[$ver][$ext] = $state === 'enabled';
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    /**
     * Enable / disable a single extension, then restart its version's pool.
     *
     * A restart is mandatory: otherwise the change only lands in the file and
     * takes effect the next time the pool is reloaded.
     */
    public static function toggle(string $version, string $extension, bool $enable): array
    {
        if (!preg_match('/^[0-9]+\.[0-9]+$/', $version)) {
            return ['ok' => false, 'error' => 'Invalid PHP version.'];
        }
        if (!preg_match('/^[a-z0-9_]+$/', $extension)) {
            return ['ok' => false, 'error' => 'Invalid extension name.'];
        }
        if (!in_array($version, Vhost::installedPhpVersions(), true)) {
            return ['ok' => false, 'error' => 'Versi PHP ' . $version . ' is not installed.'];
        }

        $r = self::helper([$enable ? 'enable' : 'disable', $version, $extension]);
        if (!$r['ok']) {
            return $r;
        }

        $rs = self::helper(['restart', $version]);
        if (!$rs['ok']) {
            // The .ini file was changed but the pool failed to start: revert it so
            // no half-finished state is left invisible to the user.
            self::helper([$enable ? 'disable' : 'enable', $version, $extension]);
            return ['ok' => false, 'error' => $rs['error'] . ' (extension change rolled back)'];
        }

        // The restart is scheduled (not synchronous) so the request that triggered
        // it is not killed by the pool being restarted.
        return ['ok' => true, 'error' => null,
                'message' => trim($r['out']) . ' ' . trim($rs['out'])
                    . ' Reload the page in a few seconds.' ];
    }

    /** Restore every extension previously disabled through the panel. */
    public static function restore(string $version): array
    {
        if (!preg_match('/^[0-9]+\.[0-9]+$/', $version)) {
            return ['ok' => false, 'error' => 'Invalid PHP version.'];
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