<?php
$area = 'user'; $title = 'MariaDB Databases'; $bodyAttrs = 'x-data';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/topbar-user.php';
require_once __DIR__ . '/data/dummy.php';
$dbs = dummy_databases(); $users = dummy_db_users();
$crumbs = [['label' => 'Databases']];
?>
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
  <?php
  require __DIR__ . '/partials/breadcrumb.php';
  $titleText = 'db.title'; $descText = 'db.desc';
  $btnHref = '#'; $btnText = 'db.create'; $btnIcon = 'database-zap';
  require __DIR__ . '/partials/page-head.php';
  ?>
  <div class="grid gap-6 md:grid-cols-[minmax(0,1fr)_260px]">
    <div class="min-w-0 space-y-6">
      <section>
        <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
          <i data-lucide="database" class="h-4 w-4 text-brand-600"></i>
          <span data-i18n="db.title">MariaDB Databases</span>
        </h2>
        <?php
        $rows = $dbs; $emptyKey = 'db.empty'; $emptyText = 'Belum ada database.';
        $cols = [
          ['label' => 'Nama Database', 'render' => fn($r) => '<span class="font-mono text-sm font-medium text-slate-800 dark:text-slate-200">' . htmlspecialchars($r['name']) . '</span>'],
          ['label' => 'User', 'render' => fn($r) => '<span class="badge-info">' . $r['users'] . '</span>'],
          ['label' => 'Ukuran', 'render' => fn($r) => '<span class="text-xs text-slate-600 dark:text-slate-300">' . htmlspecialchars($r['size']) . '</span>'],
          ['label' => 'Tabel', 'render' => fn($r) => '<span class="text-xs text-slate-600 dark:text-slate-300">' . $r['tables'] . '</span>'],
          ['label' => 'Dibuat', 'render' => fn($r) => '<span class="text-xs text-slate-500">' . htmlspecialchars($r['created']) . '</span>'],
        ];
        require __DIR__ . '/partials/table-wrapper.php';
        ?>
      </section>

      <section>
        <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
          <i data-lucide="user-cog" class="h-4 w-4 text-brand-600"></i>
          <span data-i18n="dbUser.title">DB Users</span>
        </h2>
        <?php
        $rows = $users; $emptyKey = 'db.empty'; $emptyText = 'Belum ada user database.';
        $cols = [
          ['label' => 'Username', 'render' => fn($r) => '<span class="font-mono text-sm font-medium text-slate-800 dark:text-slate-200">' . htmlspecialchars($r['name']) . '</span>'],
          ['label' => 'Host', 'render' => fn($r) => '<span class="text-xs text-slate-500">' . htmlspecialchars($r['host']) . '</span>'],
          ['label' => 'Database', 'render' => fn($r) => '<span class="text-xs text-slate-600 dark:text-slate-300">' . htmlspecialchars(implode(', ', $r['dbs'])) . '</span>'],
          ['label' => 'Hak akses', 'render' => fn($r) => $r['perm'] === 'all'
              ? '<span class="badge-ok">ALL PRIVILEGES</span>' : '<span class="badge-warn">SELECT</span>'],
        ];
        require __DIR__ . '/partials/table-wrapper.php';
        ?>
      </section>
    </div>
    <?php require __DIR__ . '/partials/sidebar-user.php'; ?>
    </div>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
