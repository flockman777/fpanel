<?php
/* Mockup router: gzip + cache headers + correct content-type.
   Used as: php -S 0.0.0.0:8090 -t <docroot> _router.php  */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit('router is for cli-server only'); }

$root = realpath(__DIR__);
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = realpath($root . urldecode($uri));

// reject direct access to this router
if ($path === __FILE__) { http_response_code(404); exit; }

// prevent path traversal
if (!$path || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) {
    http_response_code(404);
    exit('Not found');
}

if (!is_file($path)) { return false; } // let the built-in server handle it (e.g. 404 / PHP)

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

// build assets = immutable (use ?v= when the file name changes), other assets cache for 1 hour
$immutable = str_starts_with($uri, '/assets/') && isset($_GET['v']);
header('Cache-Control: ' . ($immutable ? 'public, max-age=31536000, immutable' : 'no-cache'));

/* PHP: gzip output, then include so the router stays the single entry point */
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
