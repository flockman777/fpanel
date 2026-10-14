<?php
$area = 'user'; $title = 'Email Accounts'; $bodyAttrs = 'x-data';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/topbar-user.php';
require_once __DIR__ . '/data/dummy.php';
$rows = dummy_emails();
$crumbs = [['label' => 'Email', 'href' => 'email.php'], ['label' => 'Email Accounts']];
?>
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
  <?php
  require __DIR__ . '/partials/breadcrumb.php';
  $titleText = 'email.title'; $descText = 'email.desc';
  $btnHref = '#'; $btnText = 'email.create'; $btnIcon = 'mail-plus';
  require __DIR__ . '/partials/page-head.php';
  ?>
  <div class="grid gap-6 md:grid-cols-[minmax(0,1fr)_260px]">
    <div class="min-w-0">
      <?php
      $emptyKey = 'email.empty'; $emptyText = 'Belum ada akun email.';
      $cols = [
        ['label' => 'Alamat', 'render' => fn($r) => '<span class="font-medium text-slate-800 dark:text-slate-200">' . htmlspecialchars($r['addr']) . '</span>'],
        ['label' => 'Domain', 'render' => fn($r) => '<span class="text-xs text-slate-500">' . htmlspecialchars(explode('@', $r['addr'])[1]) . '</span>'],
        ['label' => 'Password', 'render' => fn($r) => '<span class="font-mono text-xs text-slate-500">' . htmlspecialchars($r['pw']) . '</span>'],
        ['label' => 'Forward To', 'render' => fn($r) => '<span class="text-xs text-slate-600 dark:text-slate-300">' . htmlspecialchars($r['forward']) . '</span>'],
        ['label' => 'Penggunaan', 'render' => function ($r) {
            $pct = $r['quota'] ? min(100, round($r['used'] / $r['quota'] * 100)) : 0;
            $tone = $pct >= 80 ? 'bar-high' : ($pct >= 60 ? 'bar-warn' : 'bar-ok');
            return '<div class="w-32"><div class="progress"><span class="' . $tone . '" style="width:' . $pct . '%"></span></div>'
                 . '<p class="mt-1 text-[11px] text-slate-500">' . $r['used'] . ' / ' . $r['quota'] . ' MB</p></div>';
        }],
        ['label' => 'Status', 'render' => fn($r) => $r['status'] === 'active'
            ? '<span class="badge-ok"><i data-lucide="check" class="h-3 w-3"></i><span data-i18n="email.statusActive">Aktif</span></span>'
            : '<span class="badge-off"><i data-lucide="ban" class="h-3 w-3"></i><span data-i18n="email.statusSuspended">Ditangguhkan</span></span>'],
      ];
      require __DIR__ . '/partials/table-wrapper.php';
      ?>
    </div>
    <?php require __DIR__ . '/partials/sidebar-user.php'; ?>
    </div>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
