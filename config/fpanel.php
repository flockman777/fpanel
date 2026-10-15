<?php
declare(strict_types=1);
return [
    'app' => [
        'name'     => 'fpanel',
        'debug'    => (getenv('FPANEL_APP_DEBUG') ?: '0') === '1',
        'timezone' => 'Asia/Jakarta',
        // Domain utama panel, dipakai untuk link absolut dan redirect.
        // Kosong = pakai host dari permintaan (web) / tidak ada (CLI).
        // Installer mengisinya lewat config/local.php sesuai --domain.
        // Port 8090 hanya fallback saat panel belum punya sertifikat.
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
        // Versi yang diizinkan untuk pool. Urut dari yang paling lama.
        'versions'  => ['7.4', '8.1', '8.2', '8.3', '8.4'],
        'default'   => '8.3',
        'pool_dir'  => '/etc/php',
        // extension yang bisa dimatikan lewat PHP_INI_SCAN_DIR per-domain
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
        // Port 25/465/587 diblokir FPanel -> hanya relay pihak ketiga.
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
    // Pendaftaran akun Let's Encrypt. Kosong = pakai admin@<host base_url>,
    // sehingga instalasi baru otomatis memakai domainnya sendiri.
    'letsencrypt' => [
        'email' => '',
    ],
];
