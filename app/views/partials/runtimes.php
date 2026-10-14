<?php
/** Inventaris runtime nyata untuk section software */
$installed = \Fpanel\Service\Panel::installedPhp();
$cfg       = \Fpanel\Support\Config::get('php.versions', ['8.3']);
?>
<section class="card">
  <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
    <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
      <i data-lucide="layers" class="h-4 w-4"></i></span>
    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Versi PHP-FPM</h2>
    <span class="ml-auto text-xs text-slate-400"><?= count($installed) ?> terinstall</span>
  </div>
  <div class="grid grid-cols-2 gap-px bg-slate-100 dark:bg-slate-800 sm:grid-cols-3 lg:grid-cols-5">
    <?php foreach ($cfg as $v):
      $on = in_array((string)$v, $installed, true);
      $isDefault = (string)$v === (string)\Fpanel\Support\Config::get('php.default', '8.3'); ?>
    <div class="bg-white px-3.5 py-3 dark:bg-slate-900">
      <p class="text-sm font-semibold tabular-nums text-slate-800 dark:text-slate-100"><?= htmlspecialchars((string)$v) ?></p>
      <p class="mt-0.5 text-[11px] <?= $on ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400' ?>">
        <?= $on ? 'terinstall' : 'belum terinstall' ?>
      </p>
      <?php if ($isDefault): ?>
      <span class="mt-1.5 inline-block rounded-full bg-brand-50 px-2 py-0.5 text-[10px] font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">default</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</section>
