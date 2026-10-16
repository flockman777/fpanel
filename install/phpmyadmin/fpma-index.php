<?php
/**
 * phpMyAdmin front controller for FPanel.
 *
 * nginx only rewrites /pma/<token>/... into /fpma/... with an internal
 * location, so /fpma/ cannot be accessed directly from outside. Token
 * validation happens here, in PHP, because the result is easy to pin down:
 * without a matching config file the request ends in 404 and phpMyAdmin is
 * never started at all.
 *
 * Database user passwords never pass through this file: the credentials are read
 * by the token file in /etc/phpmyadmin/conf.d/<token>.php that already exists.
 */
declare(strict_types=1);

// The token is read from the ORIGINAL URI (nginx keeps it in $request_uri even
// though the request has been rewritten to /fpma/...).
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (!preg_match('#/pma/([a-f0-9]{32})(?:/|\?|$)#', $uri, $m)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

$conf = '/etc/phpmyadmin/conf.d/' . $m[1] . '.php';
if (!is_file($conf) || !is_readable($conf)) {
    // Unknown / already expired / already swept token.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    exit;
}

// phpMyAdmin reads $cfg['Servers'] from /etc/phpmyadmin/config.inc.php,
// which already loads conf.d/*.php. That token file limits itself to the same
// token through a REQUEST_URI check, so it stays safe.
//
// SCRIPT_NAME is deliberately /index.php so phpMyAdmin generates RELATIVE
// links (index.php?route=...). These relative links are what keep the token in
// the path carried along as the user navigates inside phpMyAdmin.
require '/usr/share/phpmyadmin/index.php';
