<?php
/**
 * @var string $title, string $lead, string $icon
 * @var array $me, array $s, string $csrf
 * @var array $rows, array $cols, string $empty
 * @var string $panel, bool $noTable
 */
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">
    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="<?= htmlspecialchars($icon) ?>" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl"><?= htmlspecialchars($title) ?></h1>
        <p class="mt-0.5 text-sm text-slate-500"><?= htmlspecialchars($lead) ?></p>
      </div>
    </div>

    <?php if (($panel ?? '') !== ''): require __DIR__ . '/partials/' . $panel . '.php'; endif; ?>

    <?php if (($noTable ?? false) === true) { /* tabel sudah ada di dalam panel */ } else { ?>
    <section class="card overflow-hidden">
      <?php if ($rows === []): ?>
        <div class="p-10 text-center">
          <i data-lucide="inbox" class="mx-auto h-8 w-8 text-slate-300"></i>
          <p class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200"><?= htmlspecialchars($empty) ?></p>
          <p class="mt-1 text-sm text-slate-500">Data akan muncul di sini setelah dibuat.</p>
        </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
              <?php foreach ($cols as $c): ?>
              <th class="px-4 py-2.5 font-semibold"><?= htmlspecialchars($c) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($rows as $r): ?>
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <?php foreach ($r as $cell): ?>
              <td class="px-4 py-2.5 text-slate-700 dark:text-slate-300"><?= htmlspecialchars((string)$cell) ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
    <?php } ?>
  </div>
</main>
</body>
</html>
