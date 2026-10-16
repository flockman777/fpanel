<?php
declare(strict_types=1);
namespace Fpanel\Service;

use Fpanel\Database\Connection;
use Fpanel\Support\Audit;

/**
 * Domain management: quota validation -> docroot -> nginx vhost -> reload.
 * Order matters: DB LAST, so a failed system step never leaves an orphan row.
 */
final class DomainManager
{
    /**
     * Valid domain types.
     *
     * Only two: static and php. Node.js and Python are NOT domain types --
     * they are runtime applications attached to a domain, and their status
     * is stored in the runtime_apps table via the domain_id column. A domain is
     * never "of type node"; a domain always has a docroot, and optionally
     * has an application served by nginx through a proxy.
     *
     * Legacy values may still exist in databases that have not been migrated;
     * db:migrate-domain-type cleans them up.
     */
    public const TYPES = ['static', 'php'];

    /** Legacy values mapped to php during migration. */
    public const LEGACY_TYPES = ['node', 'python', 'binary'];

    /** @return array{ok:bool,error?:string,domain?:array} */
    public static function create(int $userId, string $username, string $name, string $type, ?string $php, bool $issueTls = false): array
    {
        $name = strtolower(trim($name));
        $v = Validator::domain($name);
        if ($v !== null) {
            return ['ok' => false, 'error' => $v];
        }
        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => 'Unknown domain type: ' . $type];
        }

        $installed = Panel::installedPhp();
        if ($type === 'php') {
            if ($php === null || $php === '') {
                $php = (string)Config::get('php.default', '8.3');
            }
            if (!in_array($php, $installed, true)) {
                return ['ok' => false, 'error' => "PHP {$php} is not installed on the server."];
            }
        } else {
            $php = null;
        }

        $quota = (int)Connection::value(
            'SELECT COALESCE(p.max_domains, 0) FROM users u
               LEFT JOIN packages p ON p.id = u.package_id WHERE u.id = :u',
            ['u' => $userId]
        );
        $used  = Stats::usedDomains($userId);
        if ($quota > 0 && $used >= $quota) {
            return ['ok' => false, 'error' => "Domain quota is full ({$used}/{$quota})."];
        }

        $exists = Connection::value('SELECT id FROM domains WHERE name = :n', ['n' => $name]);
        if ($exists !== null) {
            return ['ok' => false, 'error' => "Domain {$name} is already in use."];
        }

        try {
            Vhost::ensureUserRoot($username);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $docroot = Vhost::docroot($username, $name);
        $parent  = dirname($docroot);

        // 1. docroot
        if (!is_dir($parent) && !@mkdir($parent, 0750, true) && !is_dir($parent)) {
            return ['ok' => false, 'error' => "Failed to create {$parent}."];
        }
        if (!is_dir($docroot) && !@mkdir($docroot, 0755, true) && !is_dir($docroot)) {
            return ['ok' => false, 'error' => "Failed to create docroot {$docroot}."];
        }
        @chown($parent, 'www-data');
        @chown($docroot, 'www-data');
        @chmod($parent, 0750);

        // 2. placeholder so the domain responds right away. The static type
        // must NOT get a .php file at all: a static docroot has no PHP
        // handler in the vhost, so such a file is just junk that confuses users
        // who think their static site can run PHP.
        if ($type === 'php') {
            if (!is_file($docroot . '/index.php')) {
                $phpinfo = "<?php\n// Automatic start page. Delete this file to start building your site.\n"
                    . "header('Content-Type: text/plain; charset=utf-8');\n"
                    . "echo \"FPanel: domain {$name} is active. \\n\";\n"
                    . "echo 'PHP: ' . PHP_VERSION . \"\\n\";\n"
                    . "echo 'Docroot: ' . __DIR__ . \"\\n\";\n";
                @file_put_contents($docroot . '/index.php', $phpinfo, 0644);
                @chown($docroot . '/index.php', 'www-data');
            }
        } else {
            if (!is_file($docroot . '/index.html')) {
                $esc = htmlspecialchars($name, ENT_QUOTES);
                $html = "<!doctype html>\n<html lang=\"en\">\n<head>\n"
                    . "<meta charset=\"utf-8\">\n"
                    . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
                    . "<title>{$esc}</title>\n"
                    . "<style>body{font-family:system-ui,-apple-system,sans-serif;margin:0;"
                    . "display:grid;place-items:center;min-height:100vh;background:#f8fafc;color:#0f172a}\n"
                    . ".b{text-align:center;padding:2rem}\n"
                    . "h1{font-size:1.25rem;margin:0 0 .5rem}\n"
                    . "p{margin:0;color:#64748b;font-size:.875rem}</style>\n"
                    . "</head>\n<body><div class=\"b\">\n"
                    . "<h1>{$esc} is active</h1>\n"
                    . "<p>Upload files through the File Manager to get started.</p>\n"
                    . "</div></body>\n</html>\n";
                @file_put_contents($docroot . '/index.html', $html, 0644);
                @chown($docroot . '/index.html', 'www-data');
            }
        }
        if ($type === 'php' && $php !== null) {
            $ini = Vhost::iniDir($name, $php);
            if (!is_dir($ini) && !@mkdir($ini, 0755, true) && !is_dir($ini)) {
                return ['ok' => false, 'error' => "Failed to create the extension dir {$ini}."];
            }
        }

        // 3. vhost + nginx -t + reload
        try {
            $fresh = Connection::one('SELECT * FROM domains WHERE name = :n', ['n' => $name]);
            if (is_array($fresh)) {
                self::deployWithDerived($name, $username, $fresh);
            } else {
                Vhost::deploy($name, $username, $docroot, $type, $php);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        // 4. DB (last)
        try {
            $id = Connection::insert('domains', [
                'user_id'     => $userId,
                'name'        => $name,
                'type'        => $type,
                'is_subdomain'=> 0,
                'parent_domain'=> null,
                'docroot'     => $docroot,
                'php_version' => $php,
                'status'      => 'active',
            ]);
        } catch (\Throwable $e) {
            Vhost::remove($name);
            return ['ok' => false, 'error' => 'Failed to save the domain: ' . $e->getMessage()];
        }

        Audit::log('domain.create', $userId, $username, 'domain', $id, '', 'ok', ['name' => $name, 'type' => $type, 'php' => $php]);

        // HTTPS is optional. A certificate failure must NOT abort domain
        // creation: the user still has HTTP and can click "Issue SSL" again.
        $ssl = null;
        if ($issueTls === true) {
            $ssl = self::issueCertificate($id, $name, $username, $userId);
        }

        $row = Connection::one('SELECT * FROM domains WHERE id = :i', ['i' => $id]);
        return [
            'ok'     => true,
            'domain' => is_array($row) ? $row : [],
            'ssl'    => $ssl,
        ];
    }

    /**
     * Issue a certificate for one domain and then install its 443 block.
     * Returns ['ok'=>bool,'message'=>string]; does not throw an exception.
     */
    public static function issueCertificate(int $domainId, string $name, string $username, int $userId): array
    {
        Connection::update('domains', ['ssl_status' => 'pending', 'ssl_error' => null], 'id = :i', ['i' => $domainId]);

        $res = LetsEncrypt::issue($name);
        if (($res['ok'] ?? false) !== true) {
            $msg = mb_substr((string)($res['error'] ?? 'Failed to issue SSL.'), 0, 500);
            Connection::update('domains', ['ssl_status' => 'error', 'ssl_error' => $msg], 'id = :i', ['i' => $domainId]);
            Audit::log('domain.ssl.error', $userId, $username, 'domain', $domainId, '', 'error', ['name' => $name, 'error' => $msg]);
            return ['ok' => false, 'message' => $msg];
        }

        // The certificate already exists, but the vhost still serves HTTP only.
        // Rebuild it so the 443 block is included; without this SSL is installed but unused.
        try {
            $d = Connection::one('SELECT * FROM domains WHERE id = :i', ['i' => $domainId]);
            if (is_array($d)) {
                self::deployWithDerived((string)$d['name'], $username, $d);
            }
        } catch (\Throwable $e) {
            $msg = mb_substr('Certificate issued but the vhost failed: ' . $e->getMessage(), 0, 500);
            Connection::update('domains', ['ssl_status' => 'error', 'ssl_error' => $msg], 'id = :i', ['i' => $domainId]);
            return ['ok' => false, 'message' => $msg];
        }

        $days = LetsEncrypt::daysLeft($name);
        Connection::update(
            'domains',
            ['ssl_status' => 'active', 'ssl_issued_at' => gmdate('Y-m-d H:i:s'), 'ssl_error' => null],
            'id = :i',
            ['i' => $domainId]
        );
        Audit::log('domain.ssl.issue', $userId, $username, 'domain', $domainId, '', 'ok', ['name' => $name, 'days' => $days]);
        return ['ok' => true, 'message' => 'Certificate issued' . ($days !== null ? ', valid for ' . $days . ' days.' : '.')];
    }

    /** Revoke the domain certificate. true = clean, false = something left behind. */
    public static function revokeCertificate(int $domainId, string $name): bool
    {
        $ok = LetsEncrypt::revoke($name);
        Connection::update(
            'domains',
            $ok
                ? ['ssl_status' => 'none', 'ssl_issued_at' => null, 'ssl_error' => null]
                : ['ssl_status' => 'error', 'ssl_error' => 'The certificate could not be revoked. Delete it manually: certbot delete --cert-name ' . $name],
            'id = :i',
            ['i' => $domainId]
        );
        return $ok;
    }


    /**
     * Deploy a vhost with all the derived info it needs.
     *
     * Vhost::deploy() needs two things that are NOT in the domains row:
     *   - per-domain php.ini override (php_ini column)
     *   - runtime app port, if the domain is of type node/python
     * Both are passed through one helper so no call site forgets them,
     * and a node-type domain is never deployed without a proxy to its app.
     */
    /**
     * Rebuild the vhost from an already-fetched domain row.
     *
     * Used from outside (e.g. after a runtime app is created) so a
     * node/python type domain is proxied to the new app port too.
     */
    public static function rebuildFor(string $username, array $d): void
    {
        self::deployWithDerived((string)$d['name'], $username, $d);
    }

    private static function deployWithDerived(string $name, string $username, array $d): void
    {
        $phpIni = [];
        $raw    = (string)($d['php_ini'] ?? '');
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $phpIni = $decoded;
            }
        }
        // The port comes from runtime_apps, not from domains.type. Even a
        // php-type domain may have a Node app on its subdomain, so there is
        // only one source of truth: the app that actually exists.
        $port = AppRuntime::portForDomain((int)$d['id']);
        Vhost::deploy(
            $name,
            $username,
            (string)$d['docroot'],
            (string)$d['type'],
            $d['php_version'] === null ? null : (string)$d['php_version'],
            $phpIni,
            $port
        );
    }

    public static function delete(int $userId, string $username, int $domainId): array
    {
        $d = Connection::one(
            'SELECT * FROM domains WHERE id = :i AND user_id = :u',
            ['i' => $domainId, 'u' => $userId]
        );
        if ($d === null) {
            return ['ok' => false, 'error' => 'Domain not found.'];
        }
        $name    = (string)$d['name'];
        $docroot = (string)$d['docroot'];

        Vhost::remove($name);
        if ($d['php_version'] !== null) {
            $ini = Vhost::iniDir($name, (string)$d['php_version']);
            if (is_dir($ini)) {
                array_map('unlink', glob($ini . '/*') ?: []);
                @rmdir($ini);
            }
        }
        // Remove the docroot contents. Root-owned files (e.g. leftover uploads)
        // cannot be unlinked by www-data, and '@' used to swallow that error
        // so the delete was reported as successful while data was left behind.
        $parent  = dirname($docroot);
        $leftover = [];
        if (str_starts_with($docroot, Vhost::WWW_ROOT . '/' . $username . '/') && is_dir($parent)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($parent, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $ok = $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                if (!$ok && file_exists($f->getPathname())) {
                    $leftover[] = $f->getPathname();
                }
            }
            if (!@rmdir($parent) && is_dir($parent)) {
                $leftover[] = $parent;
            }
        }

        // Revoke the certificate first. If certbot delete fails (e.g. it is
        // already gone), that is no reason to abort the domain deletion.
        if (LetsEncrypt::revoke($name) === false) {
            $leftover[] = 'TLS certificate ' . $name;
        }

        // nginx logs are removed as well. Otherwise this domain's access/error log
        // files linger on disk until the next housekeeping run, even though
        // nothing owns them anymore.
        $logsRemoved = Housekeeping::make()->purgeDomainLogs($name);

        Connection::run('DELETE FROM domains WHERE id = :i', ['i' => $domainId]);

        $note = $leftover === []
            ? ['name' => $name, 'logs_removed' => $logsRemoved]
            : ['name' => $name, 'leftover' => array_slice($leftover, 0, 10), 'leftover_total' => count($leftover)];
        Audit::log(
            'domain.delete',
            $userId,
            $username,
            'domain',
            $domainId,
            '',
            $leftover === [] ? 'ok' : 'partial',
            $note
        );

        if ($leftover !== []) {
            return [
                'ok'    => true,
                'error' => 'Domain deleted, but ' . count($leftover)
                    . ' files could not be deleted (usually owned by root). '
                    . 'Delete them manually: ' . implode(', ', array_slice($leftover, 0, 3)),
            ];
        }
        return ['ok' => true];
    }
}
