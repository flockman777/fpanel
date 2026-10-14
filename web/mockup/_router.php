<?php
/* Router mockup: gzip + cache header + content-type benar.
   Dipakai: php -S 0.0.0.0:8090 -t <docroot> _router.php  */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit('router hanya untuk cli-server'); }

$root = realpath(__DIR__);
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = realpath($root . urldecode($uri));

// tolak akses langsung ke router ini
if ($path === __FILE__) { http_response_code(404); exit; }

// cegah path traversal
if (!$path || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
    http_response_code(404);
    exit('Not found');
}

if (!is_file($path)) { return false; } // biarkan server bawaan tangani (mis. 404 / PHP)

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = [
  'html' => 'text/html; charset=utf-8',
  'php'  => 'text/html; charset=utf-8',
  'css'  => 'text/css; charset=utf-8',
  'js'   => 'text/javascript; charset=utf-8',
  'mjs'  => 'text/javascript; charset=utf-8',
  'json' => 'application/json; charset=utf-8',
  'svg'  => 'image/svg+xml',
  'png'  => 'image/png',
  'jpg'  => 'image/jpeg',
  'webp' => 'image/webp',
  'ico'  => 'image/x-icon',
  'woff2'=> 'font/woff2',
  'map'  => 'application/json; charset=utf-8',
  'txt'  => 'text/plain; charset=utf-8',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Vary: Accept-Encoding');

// aset build = immutable (pakai ?v= saat nama file berubah), assets lain cukup 1 jam
$immutable = str_starts_with($uri, '/assets/') && isset($_GET['v']);
header('Cache-Control: ' . ($immutable ? 'public, max-age=31536000, immutable' : 'no-cache'));

/* PHP: gzip output, lalu include supaya router tetap satu pintu */
if ($ext === 'php') {
    if (client_accepts_gzip()) { ob_start('ob_gzhandler'); }
    require $path;
    return true;
}

if (client_accepts_gzip() && is_gzippable($path)) { ob_start('ob_gzhandler'); }
readfile($path);
return true;

function client_accepts_gzip(): bool {
    return str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');
}
function is_gzippable(string $p): bool {
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'ico', 'woff2', 'zip', 'gz'], true)) return false;
    return filesize($p) > 1024;
}
