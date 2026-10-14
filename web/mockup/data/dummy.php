<?php /* Data dummy untuk mockup (tidak menyentuh sistem, tidak memanggil bin/f-*) */

function dummy_domains(): array {
  return [
    ['domain' => 'tokoku.com',   'root' => 'public_html', 'ip' => '185.205.222.170', 'status' => 'on', 'ssl' => 'active',  'created' => '2026-03-12'],
    ['domain' => 'app.tokoku.com','root' => 'app',        'ip' => '185.205.222.170', 'status' => 'on', 'ssl' => 'active',  'created' => '2026-05-02'],
    ['domain' => 'toko-keripik.my.id','root' => 'public_html','ip' => '-',         'status' => 'off','ssl' => 'none',    'created' => '2026-08-21'],
  ];
}

function dummy_emails(): array {
  return [
    ['addr' => 'admin@tokoku.com',     'pw' => '••••••••', 'quota' => 1024, 'used' => 212, 'forward' => '-', 'status' => 'active'],
    ['addr' => 'cs@tokoku.com',        'pw' => '••••••••', 'quota' => 1024, 'used' => 58,  'forward' => '-', 'status' => 'active'],
    ['addr' => 'info@app.tokoku.com',  'pw' => '••••••••', 'quota' => 256,  'used' => 0,   'forward' => 'admin@tokoku.com', 'status' => 'active'],
    ['addr' => 'no-reply@tokoku.com',  'pw' => '••••••••', 'quota' => 256,  'used' => 9,   'forward' => '-', 'status' => 'suspended'],
  ];
}

function dummy_databases(): array {
  return [
    ['name' => 'demo_toko',   'users' => 2, 'size' => '48.2 MB', 'tables' => 14, 'created' => '2026-04-02'],
    ['name' => 'demo_blog',   'users' => 1, 'size' => '12.7 MB', 'tables' => 7,  'created' => '2026-06-18'],
  ];
}

function dummy_db_users(): array {
  return [
    ['name' => 'demo_toko_app', 'host' => 'localhost', 'dbs' => ['demo_toko'], 'perm' => 'all'],
    ['name' => 'demo_blog_app', 'host' => 'localhost', 'dbs' => ['demo_blog'], 'perm' => 'read'],
  ];
}

function dummy_services(): array {
  return [
    ['key' => 'admin.serviceApache',  'up' => true],
    ['key' => 'admin.serviceMaria',   'up' => true],
    ['key' => 'admin.servicePhpFpm',  'up' => true],
    ['key' => 'admin.serviceBind',    'up' => true],
    ['key' => 'admin.serviceExim',    'up' => false],
    ['key' => 'admin.serviceDovecot', 'up' => true],
  ];
}
