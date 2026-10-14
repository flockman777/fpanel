<?php
/* Kartu kategori + grid tile ikon.
   $catKey = key i18n kategori, $catIcon = nama ikon lucide, $features = array of features */
?>
<section class="card">
  <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
    <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
      <i data-lucide="<?= htmlspecialchars($catIcon) ?>" class="h-4 w-4"></i>
    </span>
    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100" data-i18n="<?= htmlspecialchars($catKey) ?>"><?= htmlspecialchars($catKey) ?></h2>
    <span class="ml-auto text-xs text-slate-400"><?= count($features) ?></span>
  </div>
  <div class="grid grid-cols-2 gap-1 p-2 sm:grid-cols-3 lg:grid-cols-4">
    <?php foreach ($features as $f): ?>
      <a href="<?= htmlspecialchars($f['href']) ?>"
         class="group flex flex-col items-center gap-2 rounded-lg px-2 py-4 text-center transition hover:bg-brand-50 dark:hover:bg-slate-800">
        <span class="grid h-10 w-10 place-items-center rounded-full border border-slate-200 bg-white text-slate-600 shadow-sm
                     transition group-hover:border-brand-300 group-hover:text-brand-600
                     dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300 dark:group-hover:text-brand-300">
          <i data-lucide="<?= htmlspecialchars($f['icon']) ?>" class="h-5 w-5"></i>
        </span>
        <span class="text-xs font-medium leading-tight text-slate-700 group-hover:text-brand-700 dark:text-slate-300 dark:group-hover:text-brand-300"
              data-i18n="<?= htmlspecialchars($f['tile']) ?>"><?= htmlspecialchars($f['tile']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
