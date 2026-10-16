<?php
$area  = 'user';
$title = 'Beranda';
$bodyAttrs = 'x-data';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/topbar-user.php';

require_once __DIR__ . '/data/features.php';
$catalog = fpanel_features();
$categories = $catalog['categories'];
$allFeatures = $catalog['features'];
?>
<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
  <?php require __DIR__ . '/partials/breadcrumb.php'; ?>

  <div class="grid gap-6 md:grid-cols-[minmax(0,1fr)_260px]">
    <div class="min-w-0 space-y-4">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 class="text-xl font-bold text-slate-900 dark:text-white">
            <span x-text="$store.i18n.t('home.greeting', { name: 'demo' })">Hello, demo</span>
          </h1>
          <p class="text-sm text-slate-500" data-i18n="home.subtitle">Manage your hosting from one place.</p>
        </div>
        <a href="domain-create.php" class="btn-primary">
          <i data-lucide="plus" class="h-4 w-4"></i>
          <span data-i18n="domains.create">Create Domain</span>
        </a>
      </div>

      <?php foreach ($categories as $c):
        $catKey   = $c['key'];
        $catIcon  = $c['icon'];
        $features = array_values(array_filter($allFeatures, fn($f) => $f['cat'] === $catKey));
        include __DIR__ . '/components/card-kategori.php';
      endforeach; ?>
    </div>

    <?php require __DIR__ . '/partials/sidebar-user.php'; ?>
    </div>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
