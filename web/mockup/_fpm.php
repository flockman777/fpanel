<?php
/* Adapter mockup untuk php-fpm (router lama _router.php hanya untuk cli-server). */
declare(strict_types=1);

$root = realpath(__DIR__);
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri  = preg_replace('#^/mockup#', '', $uri) ?: '/';
$path = realpath($root . urldecode($uri));

if ($path === false || $path === __FILE__
    || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0
    || !is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = [
  'html'=>'text/html; charset=utf-8','php'=>'text/html; charset=utf-8',
  'css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8',
  'json'=>'application/json; charset=utf-8','svg'=>'image/svg+xml',
  'png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp','ico'=>'image/x-icon',
  'woff2'=>'font/woff2','txt'=>'text/plain; charset=utf-8',
][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Vary: Accept-Encoding');

if ($ext === 'php') {
    if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) { ob_start('ob_gzhandler'); }
    require $path;
    return;
}
if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')
    && !in_array($ext, ['png','jpg','jpeg','webp','ico','woff2','zip','gz'], true)
    && filesize($path) > 1024) {
    ob_start('ob_gzhandler');
}
readfile($path);
