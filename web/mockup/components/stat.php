<?php /* Kartu statistik + progress bar. $label,$value,$pct,$sub,$tone */ $tone = $tone ?? 'bar-info'; ?>
<div class="card card-body">
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <p class="text-xs font-medium text-slate-500" data-i18n="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></p>
      <p class="mt-1 text-xl font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($value) ?></p>
      <?php if (!empty($sub)): ?><p class="mt-0.5 truncate text-xs text-slate-500"><?= htmlspecialchars($sub) ?></p><?php endif; ?>
    </div>
    <?php if (!empty($icon)): ?>
      <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
        <i data-lucide="<?= htmlspecialchars($icon) ?>" class="h-5 w-5"></i>
      </span>
    <?php endif; ?>
  </div>
  <?php if (isset($pct)): ?>
    <div class="progress mt-3"><span class="<?= htmlspecialchars($tone) ?>" style="width: <?= (int) $pct ?>%"></span></div>
  <?php endif; ?>
</div>
