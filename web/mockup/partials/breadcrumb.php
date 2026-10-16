<?php /* $crumbs = [['label'=>..,'href'=>..], ...] */ ?>
<nav class="mb-4 flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400" aria-label="Breadcrumb">
  <a href="<?= $area === 'admin' ? 'admin-dashboard.php' : 'home.php' ?>"
     class="inline-flex items-center gap-1 hover:text-brand-600 hover:underline dark:hover:text-brand-400">
    <i data-lucide="house" class="h-3.5 w-3.5"></i><span data-i18n="common.home">Home</span>
  </a>
<?php foreach (($crumbs ?? []) as $i => $c): $last = $i === count($crumbs) - 1; ?>
  <i data-lucide="chevron-right" class="h-3.5 w-3.5 opacity-60"></i>
<?php if ($last): ?>
  <span class="font-medium text-slate-700 dark:text-slate-200" aria-current="page"><?= htmlspecialchars($c['label']) ?></span>
<?php else: ?>
  <a href="<?= htmlspecialchars($c['href']) ?>" class="hover:text-brand-600 hover:underline dark:hover:text-brand-400"><?= htmlspecialchars($c['label']) ?></a>
<?php endif; ?>
<?php endforeach; ?>
</nav>
