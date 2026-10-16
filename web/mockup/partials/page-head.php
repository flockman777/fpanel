<?php /* Page title + action button. $titleText, $descText, $btnHref, $btnText, $btnIcon */ ?>
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h1 class="text-xl font-bold text-slate-900 dark:text-white" data-i18n="<?= htmlspecialchars($titleText) ?>"><?= htmlspecialchars($titleText) ?></h1>
    <?php if (!empty($descText)): ?>
      <p class="text-sm text-slate-500" data-i18n="<?= htmlspecialchars($descText) ?>"><?= htmlspecialchars($descText) ?></p>
    <?php endif; ?>
  </div>
  <?php if (!empty($btnHref)): ?>
    <a href="<?= htmlspecialchars($btnHref) ?>" class="btn-primary">
      <i data-lucide="<?= htmlspecialchars($btnIcon ?? 'plus') ?>" class="h-4 w-4"></i>
      <span data-i18n="<?= htmlspecialchars($btnText) ?>"><?= htmlspecialchars($btnText) ?></span>
    </a>
  <?php endif; ?>
</div>
