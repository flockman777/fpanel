<?php
require '/srv/fpanel/app/bootstrap.php';
use Fpanel\Database\Connection;
$r = Connection::one("SELECT p.max_databases FROM users u JOIN packages p ON p.id = u.package_id WHERE u.username = 'admin'");
echo (int)($r['max_databases'] ?? 0);
