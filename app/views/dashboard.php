<?php
/** @var array $me, array $s, array $catalog, string $csrf */
$tiles = static function (string $k): string {
    return \Fpanel\Service\I18n::t($k);
};
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto grid max-w-6xl gap-5 lg:grid-cols-[1fr_300px]">
  <div class="min-w-0 space-y-4">
    <div>
      <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">
        Halo, <?= htmlspecialchars($me['username']) ?>
      </h1>
      <p class="mt-0.5 text-sm text-slate-500">Kelola hosting Anda dari satu tempat.</p>
    </div>

    <?php if ($s['alerts'] !== []): ?>
    <div class="space-y-2">
      <?php foreach ($s['alerts'] as $a): ?>
      <div class="flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-3.5 py-2.5 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span><?= htmlspecialchars($a) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($catalog === []): ?>
    <section class="card p-8 text-center">
      <i data-lucide="mouse-pointer-click" class="mx-auto h-8 w-8 text-slate-300"></i>
      <p class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">Tidak ada fitur aktif di paket kamu.</p>
      <p class="mt-1 text-sm text-slate-500">Hubungi admin untuk mengubah paket.</p>
    </section>
    <?php endif; ?>

    <?php foreach ($catalog as $cat): ?>
    <section class="card">
      <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
          <i data-lucide="<?= htmlspecialchars($cat['icon']) ?>" class="h-4 w-4"></i>
        </span>
        <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($tiles($cat['key'])) ?></h2>
        <span class="ml-auto text-xs text-slate-400"><?= count($cat['tiles']) ?></span>
      </div>
      <div class="grid grid-cols-2 gap-1 p-2 sm:grid-cols-3 lg:grid-cols-4">
        <?php foreach ($cat['tiles'] as $t):
          $badge = $s['badges'][$t['href']] ?? null; ?>
        <a href="<?= htmlspecialchars($t['href']) ?>"
           class="group relative flex flex-col items-center gap-2 rounded-lg px-2 py-4 text-center transition hover:bg-brand-50 dark:hover:bg-slate-800">
          <span class="grid h-10 w-10 place-items-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm transition group-hover:border-brand-300 group-hover:text-brand-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:group-hover:text-brand-300">
            <i data-lucide="<?= htmlspecialchars($t['icon']) ?>" class="h-5 w-5"></i>
          </span>
          <span class="text-xs font-medium leading-tight text-slate-700 group-hover:text-brand-700 dark:text-slate-300 dark:group-hover:text-brand-300">
            <?= htmlspecialchars($tiles($t['tile'])) ?>
          </span>
          <?php if ($badge !== null && (int)$badge > 0): ?>
          <span class="absolute right-3 top-2.5 min-w-[18px] rounded-full bg-brand-500 px-1 text-center text-[10px] font-semibold leading-[18px] text-white"><?= (int)$badge ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>

  <?php require __DIR__ . '/layout/infocard.php'; ?>
  </div>
</main>
</body>
</html>
