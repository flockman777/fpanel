<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * Per-domain Let's Encrypt certificates through the certbot webroot.
 *
 * certbot runs through sudo because it writes to /etc/letsencrypt. The
 * sudoers rules are written per argv0 so www-data does not get a root shell:
 * only the certbot binary itself is allowed. Every argument still comes from
 * the server (the domain name is validated first), never from the user.
 *
 * Let's Encrypt rate limit: 5 duplicate certificates per week. Daily
 * automatic renewal must therefore be avoided; renewal here only runs when
 * the certificate is genuinely near expiry or the user asked for it.
 */
final class LetsEncrypt
{
    public const LETSENCRYPT_DIR = '/etc/letsencrypt';
    public const LIVE_DIR        = '/etc/letsencrypt/live';
    public const WEBROOT         = '/var/www/acme/challenges';

    /**
     * Registration email for the Let's Encrypt account.
     *
     * Order: config `letsencrypt.email` (can come from the env var
     * FPANEL_LETSENCRYPT_EMAIL) → host of `app.base_url` → the domain being
     * certified. That way a fresh install works without configuration.
     */
    public static function registerEmail(string $forDomain = ''): string
    {
        $email = trim((string)\Fpanel\Support\Config::get('letsencrypt.email', ''));
        if ($email !== '') {
            return $email;
        }
        $host = (string)(parse_url((string)\Fpanel\Support\Config::get('app.base_url', ''), PHP_URL_HOST) ?: '');
        if ($host === '') {
            $host = $forDomain;
        }
        return $host !== '' ? 'admin@' . $host : 'admin@localhost';
    }

    /** Days before expiry when certbot is allowed to renew. */
    private const RENEW_WINDOW_DAYS = 30;

    public static function certName(string $domain): string
    {
        return $domain;
    }

    public static function fullchain(string $domain): string
    {
        return self::LIVE_DIR . '/' . self::certName($domain) . '/fullchain.pem';
    }

    public static function privkey(string $domain): string
    {
        return self::LIVE_DIR . '/' . self::certName($domain) . '/privkey.pem';
    }

    /**
     * Does a certificate for this domain already exist?
     *
     * /etc/letsencrypt/live is owned by root with mode 0700, so the panel
     * running as www-data cannot is_file() into it. Status is therefore read
     * through the certbot CLI, not through the filesystem.
     */
    public static function hasCert(string $domain): bool
    {
        return self::certInfo($domain) !== null;
    }

    /** Host names + validity from certbot. Null when there is no certificate yet. */
    public static function certInfo(string $domain): ?array
    {
        $name = self::certName($domain);
        if (!isset(self::$infoCache)) {
            self::$infoCache = self::readAll();
        }
        return self::$infoCache[$name] ?? null;
    }

    private static ?array $infoCache = null;

    /** @return array<string,array{hosts:array,days:?int}> */
    private static function readAll(): array
    {
        $out = [];
        [$rc, $txt] = self::run(['certificates']);
        if ($rc !== 0) {
            return $out;
        }
        $cur = null;
        foreach (explode("\n", $txt) as $line) {
            $t = trim($line);
            if (str_starts_with($t, 'Certificate Name:')) {
                $cur = trim(substr($t, 18));
                $out[$cur] = ['hosts' => [], 'days' => null];
                continue;
            }
            if ($cur === null) {
                continue;
            }
            if (str_starts_with($t, 'Domains:')) {
                $out[$cur]['hosts'] = array_values(array_filter(array_map('trim', explode(' ', trim(substr($t, 9))))));
            }
            if (str_starts_with($t, 'Expiry Date:')) {
                // certbot already computes it: "Expiry Date: 2026-12-27
                // 16:11:49+00:00 (VALID: 89 days)". Use that number; do not
                // re-parse the date (the format changes between versions).
                if (preg_match('/\(VALID:\s*(\d+)\s*days?\)/i', $t, $m)) {
                    $out[$cur]['days'] = (int)$m[1];
                } else {
                    $ts = strtotime(trim(substr($t, 12)));
                    if ($ts !== false) {
                        $out[$cur]['days'] = (int)floor(($ts - time()) / 86400);
                    }
                }
            }
        }
        return $out;
    }

    /** Days left before expiry; null when there is no certificate. */
    public static function daysLeft(string $domain): ?int
    {
        $i = self::certInfo($domain);
        return $i === null ? null : $i['days'];
    }

    /**
     * Host names to include in the certificate: domain + www when DNS exists.
     * Hosts/www that do not resolve are skipped on purpose so issuance does not
     * fail entirely because of a subdomain that was never pointed here.
     */
    public static function hostsFor(string $domain): array
    {
        // www only joins when it has its own DNS. Otherwise issuance fails
        // entirely because one hostname cannot be verified.
        $hosts = [$domain];
        if (self::resolves('www.' . $domain)) {
            $hosts[] = 'www.' . $domain;
        }
        return $hosts;
    }

    private static function resolves(string $host): bool
    {
        $ip = @gethostbyname($host);
        return $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Issue (or renew) a certificate.
     *
     * @return array{ok:bool,error:?string,msg:?string,days:?int}
     */
    public static function issue(string $domain, bool $force = false): array
    {
        if (self::hasCert($domain)) {
            $days = self::daysLeft($domain);
            if (!$force && $days !== null && $days > self::RENEW_WINDOW_DAYS) {
                return [
                    'ok'   => true,
                    'error'=> null,
                    'msg'  => 'Certificate is still valid for ' . $days . ' more days.',
                    'days' => $days,
                ];
            }
        }

        $hosts = self::hostsFor($domain);
        $args  = [
            'certonly',
            '--webroot', '--webroot-path', self::WEBROOT,
            '--cert-name', self::certName($domain),
            '--non-interactive', '--agree-tos',
            '-m', self::registerEmail($domain),
            '--keep-until-expiring',
        ];
        foreach ($hosts as $h) {
            $args[] = '-d';
            $args[] = $h;
        }
        if ($force) {
            $args[] = '--force-renewal';
        }

        [$rc, $out] = self::run($args);

        self::$infoCache = null;
        if ($rc === 0 && self::hasCert($domain)) {
            $days = self::daysLeft($domain);
            return [
                'ok'   => true,
                'error'=> null,
                'msg'  => 'Certificate issued for ' . implode(', ', $hosts)
                    . ($days !== null ? ' (valid ' . $days . ' days)' : ''),
                'days' => $days,
            ];
        }

        return [
            'ok'    => false,
            'error' => self::explain($out),
            'msg'   => null,
            'days'  => null,
        ];
    }

    public static function remove(string $domain): array
    {
        // Do not use is_dir() on /etc/letsencrypt/live: that directory is mode
        // 0700 owned by root, so www-data always sees it as missing and the cert
        // would never be deleted. Status is read through the certbot listing.
        if (self::certInfo($domain) === null) {
            return ['ok' => true, 'error' => null];
        }
        [$rc, $out] = self::run([
            'delete', '--cert-name', self::certName($domain), '--non-interactive',
        ]);
        return [
            'ok'    => $rc === 0,
            'error' => $rc === 0 ? null : 'certbot delete failed: ' . self::tail($out),
        ];
    }

    /**
     * Revoke + delete a domain certificate.
     * true means nothing is left for this domain in /etc/letsencrypt.
     */
    public static function revoke(string $domain): bool
    {
        $r = self::remove($domain);
        self::$infoCache = null; // status changed, do not use the old cache
        return ($r['ok'] ?? false) === true && self::certInfo($domain) === null;
    }

    public static function issued(): array
    {
        $out = [];
        foreach (self::readAll() as $name => $i) {
            $out[] = ['name' => $name, 'days' => $i['days'], 'hosts' => $i['hosts']];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }


    /**
     * Run certbot through sudo and return [exitCode, output].
     */
    private static function run(array $args): array
    {
        $cmd = ['sudo', '-n', '/usr/bin/certbot'];
        foreach ($args as $a) {
            // The host name is already validated as a DNS label before it
            // gets here, but each argument is still quoted so no
            // option can collide with another file name.
            $cmd[] = $a;
        }
        $line = implode(' ', array_map(
            static fn (string $a): string => escapeshellarg($a),
            $cmd
        ));
        $out   = [];
        $rc    = 0;
        exec($line . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }

    /** Turn certbot output into a message the user can read. */
    private static function explain(string $out): string
    {
        if (str_contains($out, 'rate limit') || str_contains($out, 'too many certificates')) {
            return "Let's Encrypt rate limit reached for this domain. Try again in a few hours.";
        }
        if (str_contains($out, 'NXDOMAIN') || str_contains($out, 'Could not resolve')) {
            return 'This domain does not resolve to the server yet. Point the A record here first, then try again.';
        }
        if (str_contains($out, 'Timeout') || str_contains($out, 'timed out')) {
            return "The server did not answer Let's Encrypt. Make sure port 80 is open to the internet.";
        }
        if (str_contains($out, 'unauthorized') || str_contains($out, 'Invalid response')) {
            return "Let's Encrypt rejected the challenge for this domain. Make sure .well-known/acme-challenge is not blocked.";
        }
        if (str_contains($out, 'sudo')) {
            return 'The panel is not allowed to run certbot. Check /etc/sudoers.d/fpanel.';
        }
        return 'Issuance failed: ' . self::tail($out);
    }

    private static function tail(string $out, int $lines = 2): string
    {
        $l = array_values(array_filter(array_map('trim', explode("\n", $out))));
        return $l === [] ? 'no output' : implode(' / ', array_slice($l, -$lines));
    }
}
