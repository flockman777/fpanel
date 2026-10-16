<?php
declare(strict_types=1);
return [
    'app' => [
        'name'     => 'fpanel',
        'debug'    => (getenv('FPANEL_APP_DEBUG') ?: '0') === '1',
        'timezone' => 'Asia/Jakarta',
        // Primary panel domain, used for absolute links and redirects.
        // Empty = use the host from the request (web) / none (CLI).
        // The installer fills it in via config/local.php based on --domain.
        // Port 8090 is only a fallback while the panel has no certificate.
        'base_url' => getenv('FPANEL_BASE_URL') ?: '',
    ],
    'db' => [
        'file' => FPANEL_ROOT . '/var/db/fpanel.sqlite',
    ],
    'security' => [
        'session_cookie' => 'fp_session',
        'csrf_cookie'    => 'fp_csrf',
        'login_max_fails' => 5,
    ],
    'paths' => [
        'webroot'    => '/var/www',
        'log'        => FPANEL_ROOT . '/var/log',
        'snapshots'  => FPANEL_ROOT . '/var/snapshots',
    ],
    'nginx' => [
        'bin'       => '/usr/sbin/nginx',
        'conf_dir'  => '/etc/nginx',
        'vhost_dir' => '/etc/nginx/conf.d/fpanel',
        'snippet'   => '/etc/nginx/snippets/fpanel',
    ],
    'php' => [
        // Versions allowed for pools. Sorted from the oldest.
        'versions'  => ['7.4', '8.1', '8.2', '8.3', '8.4'],
        'default'   => '8.3',
        'pool_dir'  => '/etc/php',
        // extensions that can be disabled via PHP_INI_SCAN_DIR per domain
        'togglable' => [
            'imagick', 'redis', 'memcached', 'apcu', 'bcmath', 'bz2',
            'calendar', 'ctype', 'curl', 'dom', 'exif', 'fileinfo',
            'gd', 'iconv', 'intl', 'mbstring', 'mysqli', 'opcache',
            'pdo_mysql', 'pdo_sqlite', 'soap', 'sodium', 'sqlite3',
            'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib',
        ],
        'opcache' => [
            'memory_consumption' => 64,
            'max_accelerated_files' => 10000,
            'validate_timestamps' => 0,
        ],
    ],
    'node' => [
        'versions' => ['22', '24'],
        'default'  => '22',
        'port_from' => 21000,
        'port_to'   => 21999,
    ],
    'mail' => [
        // Ports 25/465/587 are blocked by FPanel -> third-party relay only.
        'relay' => [
            'host' => getenv('FPANEL_MAIL_RELAY_HOST') ?: '',
            'port' => (int)(getenv('FPANEL_MAIL_RELAY_PORT') ?: 587),
            'user' => getenv('FPANEL_MAIL_RELAY_USER') ?: '',
            'pass' => getenv('FPANEL_MAIL_RELAY_PASS') ?: '',
            'from' => getenv('FPANEL_MAIL_FROM') ?: '',
        ],
    ],
    'cloudflare' => [
        'api_token' => getenv('FPANEL_CF_API_TOKEN') ?: '',
        'zone_id'   => getenv('FPANEL_CF_ZONE_ID') ?: '',
    ],
    // Let's Encrypt account registration. Empty = use admin@<host base_url>,
    // so a fresh installation automatically uses its own domain.
    'letsencrypt' => [
        'email' => '',
    ],
];
