<?php
/**
 * Front controller phpMyAdmin untuk FPanel.
 *
 * nginx hanya menulis ulang /pma/<token>/... menjadi /fpma/... dengan location
 * internal, jadi /fpma/ tidak bisa diakses langsung dari luar. Validasi token
 * dilakukan di sini, di PHP, karena hasilnya mudah dipastikan: tanpa file
 * konfigurasi yang cocok, request berakhir 404 dan phpMyAdmin tidak pernah
 * dijalankan sama sekali.
 *
 * Password user database tidak pernah melewati file ini: kredensial dibaca
 * oleh file token di /etc/phpmyadmin/conf.d/<token>.php yang sudah ada.
 */
declare(strict_types=1);

// Token dibaca dari URI ASLI (nginx menyimpannya di $request_uri walau
// request-nya sudah di-rewrite ke /fpma/...).
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (!preg_match('#/pma/([a-f0-9]{32})(?:/|\?|$)#', $uri, $m)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

$conf = '/etc/phpmyadmin/conf.d/' . $m[1] . '.php';
if (!is_file($conf) || !is_readable($conf)) {
    // Token tidak dikenal / sudah kedaluwarsa / sudah di-sweep.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

// phpMyAdmin membaca $cfg['Servers'] dari /etc/phpmyadmin/config.inc.php,
// yang sudah memuat conf.d/*.php. File token tadi membatasi himself ke token
// yang sama lewat pemeriksaan REQUEST_URI, jadi tetap aman.
//
// SCRIPT_NAME sengaja /index.php supaya phpMyAdmin membuat link RELATIF
// (index.php?route=...). Link relatif inilah yang membuat token di path tetap
// ikut terbawa saat user navigasi di dalam phpMyAdmin.
require '/usr/share/phpmyadmin/index.php';
