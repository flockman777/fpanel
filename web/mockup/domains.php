<?php
$area = 'user'; $title = 'Domains'; $bodyAttrs = 'x-data';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/topbar-user.php';
require_once __DIR__ . '/data/dummy.php';
$rows = dummy_domains();
$crumbs = [['label' => 'Domains']];
?>
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
  <?php
  require __DIR__ . '/partials/breadcrumb.php';
  $titleText = 'domains.title'; $descText = 'domains.desc';
  $btnHref = 'domain-create.php'; $btnText = 'domains.create'; $btnIcon = 'plus';
  require __DIR__ . '/partials/page-head.php';
  ?>
  <div class="grid gap-6 md:grid-cols-[minmax(0,1fr)_260px]">
    <div class="min-w-0 space-y-3">
      <div class="flex items-center gap-2 text-sm text-slate-500">
        <span class="badge-info" x-text="$store.i18n.t('domains.count', { n: <?= count($rows) ?> })">3 domain</span>
        <span class="ml-auto"></span>
        <div class="relative w-56">
          <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
          <input x-model="q" type="search" class="input py-1.5 pl-8 text-sm" :placeholder="$store.i18n.t('common.search')">
        </div>
      </div>
      <?php
      $emptyKey = 'domains.empty'; $emptyText = 'Belum ada domain.';
      $cols = [
        ['label' => 'Domain', 'render' => fn($r) => '<a href="#" class="font-medium text-brand-600 hover:underline dark:text-brand-400">' . htmlspecialchars($r['domain']) . '</a>'],
        ['label' => 'Document Root', 'render' => fn($r) => '<span class="font-mono text-xs text-slate-600 dark:text-slate-300">' . htmlspecialchars($r['root']) . '</span>'],
        ['label' => 'IP', 'render' => fn($r) => '<span class="font-mono text-xs text-slate-500">' . htmlspecialchars($r['ip']) . '</span>'],
        ['label' => 'Status', 'render' => fn($r) => $r['status'] === 'on'
            ? '<span class="badge-ok"><i data-lucide="check" class="h-3 w-3"></i><span data-i18n="common.on">Aktif</span></span>'
            : '<span class="badge-off"><i data-lucide="x" class="h-3 w-3"></i><span data-i18n="common.off">Nonaktif</span></span>'],
        ['label' => 'Auto SSL', 'render' => fn($r) => $r['ssl'] === 'active'
            ? '<span class="badge-ok"><i data-lucide="lock" class="h-3 w-3"></i>Active</span>'
            : '<span class="badge-warn"><i data-lucide="lock-open" class="h-3 w-3"></i>None</span>'],
        ['label' => 'Dibuat', 'render' => fn($r) => '<span class="text-xs text-slate-500">' . htmlspecialchars($r['created']) . '</span>'],
      ];
      require __DIR__ . '/partials/table-wrapper.php';
      ?>
    </div>
    <?php require __DIR__ . '/partials/sidebar-user.php'; ?>
    </div>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
