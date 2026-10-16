<?php
declare(strict_types=1);
namespace Fpanel\Service;

/**
 * nginx vhost generator.
 *
 * Contract:
 *  - listen 80 (the provider still blocks 80 from outside; local tests still work via the Host header)
 *  - per-domain php.ini values through fastcgi_param PHP_VALUE
 *    (PHP-FPM ignores PHP_INI_SCAN_DIR from fastcgi_param, so per-domain
 *     files would never be read)
 *  - reload is always validated with `nginx -t` first, rolling back on failure
 */
final class Vhost
{
    public const AVAIL_DIR = '/etc/nginx/sites-available/fpanel-domains';
    public const ENABLED_DIR = '/etc/nginx/sites-enabled';
    public const LOG_DIR     = '/var/log/nginx/fpanel';
    public const WWW_ROOT    = '/var/www';
    // Fallback only. The real version is taken per domain through fpmSock(),
    // so a domain that picks PHP 8.1 is really routed to php8.1-fpm.
    public const FPM_SOCK    = '/run/php/php8.3-fpm.sock';

    public static function confPath(string $domain): string
    {
        return self::AVAIL_DIR . '/' . self::slug($domain) . '.conf';
    }

    /** PHP versions installed on the system (detected from /etc/php/<v>/fpm). */
    public static function phpVersions(): array
    {
        $base = '/etc/php';
        if (!is_dir($base)) {
            return [];
        }
        $out = [];
        foreach (glob($base . '/*/fpm', GLOB_ONLYDIR) ?: [] as $dir) {
            // The presence of /etc/php/<v>/fpm/ means that version is installed.
            // conf.d/domains is deliberately NOT used as the marker: that directory
            // only appears after a domain uses the version, so a freshly installed
            // version would never be detected.
            if (preg_match('#/etc/php/([0-9]+\.[0-9]+)/#', $dir, $m)) {
                $out[] = $m[1];
            }
        }
        // usort, not sort: sort()'s second argument is a flag, not a comparator.
        usort($out, 'version_compare');
        return $out;
    }

    /**
     * FPM socket for one PHP version.
     *
     * The version is strictly validated because its value goes into an nginx
     * config file. Without validation, input like "8.3; return 404" could
     * inject an nginx config line through the socket name.
     */
    public static function fpmSock(string $php): string
    {
        if (!preg_match('/^[0-9]+\\.[0-9]+$/', $php)) {
            return self::FPM_SOCK;
        }
        $sock = '/run/php/php' . $php . '-fpm.sock';
        // file_exists(), not is_file(): a unix socket is not a regular file, so
        // is_file() is always false and every domain would fall back to the default.
        // When the socket is missing, fall back to the default so we never render
        // a config that is guaranteed to fail.
        return file_exists($sock) ? $sock : self::FPM_SOCK;
    }

    /** PHP versions that are installed AND have an active FPM socket. */
    public static function installedPhpVersions(): array
    {
        $out = [];
        foreach (self::phpVersions() as $v) {
            if (file_exists('/run/php/php' . $v . '-fpm.sock')) {
                $out[] = $v;
            }
        }
        usort($out, 'version_compare');
        return $out;
    }

    public static function iniDir(string $domain, string $php): string
    {
        return '/etc/php/' . $php . '/fpm/conf.d/domains/' . self::slug($domain);
    }

    /**
     * Make sure the per-user web root exists and is owned by www-data.
     * /var/www itself is setgid www-data so new directories inherit the group.
     */
    public static function ensureUserRoot(string $username): string
    {
        $root = self::WWW_ROOT . '/' . self::slug($username);
        if (!is_dir(self::WWW_ROOT)) {
            @mkdir(self::WWW_ROOT, 0755, true);
        }
        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            throw new \RuntimeException('Failed to create the user web root: ' . $root);
        }
        @chgrp($root, 'www-data');
        @chown($root, 'www-data');
        @chmod($root, 0750);
        return $root;
    }

    public static function docroot(string $username, string $domain): string
    {
        return self::WWW_ROOT . '/' . $username . '/' . self::slug($domain) . '/public';
    }

    /** A file name safely derived from a domain name (anti path traversal). */
    public static function slug(string $domain): string
    {
        $s = strtolower(trim($domain));
        $s = preg_replace('/[^a-z0-9.-]+/', '-', $s) ?? '';
        return trim($s, '-.') ?: 'invalid';
    }

    public static function render(string $domain, string $username, string $docroot, string $type, ?string $php, array $phpIni = [], ?int $appPort = null): string
    {
        $slug  = self::slug($domain);
        $names = $domain . ' www.' . $domain;
        $isPhp = $type === 'php';
        $access = self::LOG_DIR . '/' . $slug . '.access.log';
        $error  = self::LOG_DIR . '/' . $slug . '.error.log';

        // ACME must always be answered on port 80, even when the rest is HTTPS.
        // If this location were redirected to 443, certbot renewal would fail
        // because Let's Encrypt always contacts port 80.
        $acme = "    # Let's Encrypt HTTP-01. ALWAYS on port 80, never redirect.\n"
            . "    location ^~ /.well-known/acme-challenge/ {\n"
            . "        root " . LetsEncrypt::WEBROOT . ";\n"
            . "        default_type \"text/plain\";\n"
            . "        access_log off;\n"
            . "    }\n\n";

        $common = '';
        $common .= "    root {$docroot};\n";
        $common .= "    index index.php index.html;\n\n";
        $common .= "    access_log {$access};\n";
        $common .= "    error_log  {$error};\n";
        $common .= "    client_max_body_size 128M;\n";
        $common .= "    server_tokens off;\n\n";
        $common .= "    # hide dotfiles, except .well-known (used by ACME)\n";
        $common .= "    location ~ /\.(?!well-known) {\n        deny all;\n        access_log off;\n    }\n\n";

        // Branch order matters: a runtime app attached to the domain
        // ALWAYS wins over PHP-FPM. The app is the front controller, so even a
        // php-type domain must be proxied when a live app exists.
        // PHP used to be checked first; once a Node domain was backfilled
        // to php, its app stopped being served at all.
        if ($appPort !== null) {
            $ws = "127.0.0.1:" . $appPort;
            $common .= "    # app runtime active, served through {$ws}\n";
            $common .= "    location / {\n";
            $common .= "        proxy_pass http://{$ws};\n";
            $common .= "        proxy_http_version 1.1;\n";
            // WebSocket (common in Node) needs Upgrade/Connection.
            $common .= "        proxy_set_header Upgrade \$http_upgrade;\n";
            $common .= "        proxy_set_header Connection \$fp_connection_upgrade;\n";
            $common .= "        proxy_set_header Host \$host;\n";
            $common .= "        proxy_set_header X-Real-IP \$remote_addr;\n";
            $common .= "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;\n";
            $common .= "        proxy_set_header X-Forwarded-Proto \$scheme;\n";
            $common .= "        proxy_read_timeout 300s;\n";
            $common .= "        proxy_connect_timeout 10s;\n";
            $common .= "    }\n\n";
            // The map at http level gives the right Connection value (upgrade
            // or close). Without it, the Connection header is always "upgrade"
            // and ordinary requests can hang.
            $common .= "    location ~* ^/(assets|static|public|favicon\\.ico|robots\\.txt) {\n";
            $common .= "        root {$docroot};\n";
            $common .= "        try_files \$uri @app;\n";
            $common .= "    }\n\n";
            $common .= "    location @app {\n";
            $common .= "        proxy_pass http://{$ws};\n";
            $common .= "        proxy_http_version 1.1;\n";
            $common .= "        proxy_set_header Upgrade \$http_upgrade;\n";
            $common .= "        proxy_set_header Connection \$fp_connection_upgrade;\n";
            $common .= "        proxy_set_header Host \$host;\n";
            $common .= "        proxy_read_timeout 300s;\n";
            $common .= "    }\n";
        } elseif ($isPhp && $php !== null) {
            $common .= "    # PHP-FPM {$php}\n";
            $common .= "    location ~ \.[pP][hH][pP]\$ {\n";
            $common .= "        include fastcgi_params;\n";
            $common .= "        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n";
            $common .= "        fastcgi_param HTTP_PROXY \"\";\n";
            // Per-domain php.ini values through PHP_VALUE, NOT PHP_INI_SCAN_DIR.
            // PHP-FPM ignores PHP_INI_SCAN_DIR coming through
            // fastcgi_param (it uses the scan dir from the pool config), so
            // per-domain files would never be read. PHP_VALUE is proven
            // to work and is isolated per domain.
            if ($phpIni !== []) {
                $pairs = [];
                foreach ($phpIni as $k => $v) {
                    $pairs[] = $k . ' = ' . $v;
                }
                // Separated by newlines; written in nginx as \n inside the string.
                $common .= "        fastcgi_param PHP_VALUE \"" . implode('\\n', $pairs) . "\";\n";
            }
            $common .= "        fastcgi_read_timeout 300;\n";
            $common .= "        fastcgi_pass unix:" . self::fpmSock($php) . ";\n";
            $common .= "    }\n\n";
            $common .= "    # prevent PHP source leaks through other extensions\n";
            $common .= "    location ~ \.(phtml|phar|php[0-9]|inc|shtml|htaccess)\$ {\n        deny all;\n        access_log off;\n    }\n\n";
            $common .= "    location / {\n        try_files \$uri \$uri/ /index.php?\$query_string;\n    }\n";
        } else {
            // No PHP and no live app: serve static files so the page is not
            // completely blank.
            $common .= "    # {$type} domain with no live app: static only\n";
            $common .= "    location / {\n        try_files \$uri \$uri/ /index.html =404;\n    }\n";
        }

        $head  = "# FPanel :: {$domain} (user: {$username})\n";
        $head .= "# Generated automatically by FPanel. Do not edit manually.\n";

        if (!LetsEncrypt::hasCert($domain)) {
            // No certificate yet: a single HTTP server block.
            $out  = $head . "server {\n";
            $out .= "    listen 80;\n";
            $out .= "    listen [::]:80;\n";
            $out .= "    server_name {$names};\n\n";
            $out .= $acme;
            $out .= $common;
            $out .= "}\n";
            return $out;
        }

        // Certificate exists: the HTTP block answers ACME only and redirects
        // the rest to HTTPS, with the whole site served on 443.
        $out  = $head . "server {\n";
        $out .= "    listen 80;\n";
        $out .= "    listen [::]:80;\n";
        $out .= "    server_name {$names};\n\n";
        $out .= $acme;
        $out .= "    location / {\n        return 301 https://\$host\$request_uri;\n    }\n";
        $out .= "}\n\n";

        $out .= "server {\n";
        $out .= "    listen 443 ssl http2;\n";
        $out .= "    listen [::]:443 ssl http2;\n";
        $out .= "    server_name {$names};\n\n";
        $out .= "    ssl_certificate     " . LetsEncrypt::fullchain($domain) . ";\n";
        $out .= "    ssl_certificate_key " . LetsEncrypt::privkey($domain) . ";\n";
        $out .= "    include /etc/nginx/snippets/fpanel-ssl.conf;\n\n";
        $out .= "    add_header Strict-Transport-Security \"max-age=31536000\" always;\n";
        $out .= "    add_header X-Content-Type-Options \"nosniff\" always;\n";
        $out .= "    add_header X-Frame-Options \"SAMEORIGIN\" always;\n";
        $out .= "    add_header Referrer-Policy \"strict-origin-when-cross-origin\" always;\n\n";
        $out .= $common;
        $out .= "}\n";
        return $out;
    }

    /**
     * Wait until the vhost really serves (or is already gone).
     * $expect = true  -> the host must answer with an HTTP status
     * $expect = false -> the host must stop answering (already removed)
     *
     * @return bool true when the condition is met within the deadline
     */
    private static function waitForVhost(string $domain, bool $expect, float $timeout = 3.0): bool
    {
        // The Host header MUST be a single hostname. Sending the server_name
        // list ("d.test www.d.test") makes nginx answer 400.
        $deadline = microtime(true) + $timeout;
        do {
            $status = self::probe($domain);
            if ($expect ? $status > 0 : $status === 0) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        return false;
    }

    /** Send HEAD to the vhost, return the status code (0 = no response / connection closed). */
    private static function probe(string $host): int
    {
        $fp = @stream_socket_client('tcp://127.0.0.1:80', $errno, $errstr, 0.4);
        if ($fp === false) {
            return 0;
        }
        stream_set_timeout($fp, 1);
        // The request line must be tidy. An earlier version used "HEAD / .fpanel-probe"
        // and nginx answered 400 BEFORE picking a server block, so the probe
        // could not tell a live vhost from a deleted one.
        fwrite($fp, "HEAD /fpanel-probe HTTP/1.0\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $line = (string)fgets($fp);
        fclose($fp);
        return preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) ? (int)$m[1] : 0;
    }

    /**
     * Run a privileged command. The panel runs as www-data, so nginx
     * operations go through sudo (narrow rules in /etc/sudoers.d/fpanel).
     * When the process is root (e.g. the CLI), run it directly.
     */
    private static function priv(string $bin, string ...$args): array
    {
        $parts = [escapeshellarg($bin)];
        foreach ($args as $a) {
            $parts[] = escapeshellarg($a);
        }
        $inner = implode(' ', $parts);
        $root  = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $out   = [];
        exec(($root ? '' : 'sudo -n ') . $inner . ' 2>&1', $out, $rc);
        return [$rc, implode(' ', $out)];
    }

    /** Write + enable + validate + reload. Throws with a message on failure. */
    public static function deploy(string $domain, string $username, string $docroot, string $type, ?string $php, array $phpIni = [], ?int $appPort = null): void
    {
        $conf = self::confPath($domain);
        $bak  = null;

        foreach ([self::AVAIL_DIR, self::LOG_DIR, dirname($docroot)] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
                throw new \RuntimeException("Failed to create directory: {$d}");
            }
        }
        if (is_file($conf)) {
            $bak = $conf . '.bak';
            copy($conf, $bak);
        }
        if (file_put_contents($conf, self::render($domain, $username, $docroot, $type, $php, $phpIni, $appPort)) === false) {
            throw new \RuntimeException("Failed to write the vhost: {$conf}");
        }
        [$rc, $out] = self::priv('/usr/sbin/nginx', '-t');
        if ($rc !== 0) {
            if ($bak !== null) {
                copy($bak, $conf);
            } else {
                @unlink($conf);
            }
            throw new \RuntimeException('nginx -t failed, vhost reverted: ' . $out);
        }
        [$rc2, $out2] = self::priv('/bin/systemctl', 'reload', 'nginx');
        if ($rc2 !== 0) {
            throw new \RuntimeException('Failed to reload nginx: ' . $out2);
        }

        // `systemctl reload` returns BEFORE the new workers are ready. And the
        // listening socket is never closed during a graceful reload, so a
        // connect check (fsockopen) always passes instantly. We must really
        // send HTTP and see whether the new vhost is being served.
        self::waitForVhost($domain, true);
    }

    public static function remove(string $domain): void
    {
        @unlink(self::confPath($domain));
        @unlink(self::confPath($domain) . '.bak');

        // The per-domain PHP override is cleaned up too. If left behind,
        // PHP-FPM would keep scanning that directory for a vhost that no
        // longer exists. All versions are scanned because remove() gets no php_version.
        foreach (self::phpVersions() as $ver) {
            $ini = self::iniDir($domain, $ver);
            if (is_dir($ini)) {
                foreach (glob($ini . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($ini);
            }
        }

        [$rc] = self::priv('/usr/sbin/nginx', '-t');
        if ($rc === 0) {
            self::priv('/bin/systemctl', 'reload', 'nginx');

            // Old workers still serve the just-deleted vhost and answer 404
            // (docroot gone) during shutdown. Wait until the host really falls
            // through to the catch-all 444.
            self::waitForVhost($domain, false);
        }
    }
}
