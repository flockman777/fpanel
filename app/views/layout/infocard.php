<?php
/**
 * Info card: Account, Statistics, Server Info.
 * Styling deliberately follows `.card` (white + slate border + teal accent) so it
 * stays consistent with the other pages. The previous version used a navy panel, which
 * looked heavy on a light page and made the teal bar (hue 173°) feel
 * off-key against the navy blue base (hue 217°).
 *
 * @var array $me
 * @var array $s
 */
$pc = static function (int $used, int $max): array {
    $pct = $max > 0 ? min(100, (int)round($used / $max * 100)) : 0;
    $bar = $pct >= 90 ? 'bg-rose-600' : ($pct >= 70 ? 'bg-amber-500' : 'bg-brand-600');
    return [$pct, $bar];
};
?>
<aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">

  <section class="card overflow-hidden">
    <h2 class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-[15px] font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">
      <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="circle-user" class="h-4 w-4"></i>
      </span>
      Account Info
    </h2>
    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-500">Username</dt>
        <dd class="font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($me['username']) ?></dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="shrink-0 text-slate-500"><?= htmlspecialchars($s['primary_label'] ?? 'Domain utama') ?></dt>
        <dd class="truncate font-semibold text-slate-800 dark:text-slate-100" title="<?= htmlspecialchars($s['primary_domain'] ?? '-') ?>"><?= htmlspecialchars($s['primary_domain'] ?? '-') ?></dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-500">IP Server</dt>
        <dd class="font-mono text-[13px] text-brand-700 dark:text-brand-300"><?= htmlspecialchars($s['server']['public_ip']) ?></dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-500">PHP</dt>
        <dd class="font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($s['php_label']) ?></dd>
      </div>
    </dl>
  </section>

  <section class="card overflow-hidden">
    <h2 class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-[15px] font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">
      <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="chart-pie" class="h-4 w-4"></i>
      </span>
      Statistik
    </h2>
    <div class="space-y-3.5 px-4 py-3.5 text-[13px]">
      <?php foreach ($s['gauges'] as $g):
        [$pct, $bar] = $pc($g['used'], $g['max']); ?>
      <div>
        <div class="flex items-baseline justify-between gap-2">
          <span class="font-medium text-slate-600 dark:text-slate-300"><?= htmlspecialchars($g['label']) ?></span>
          <span class="text-xs text-slate-500"><?= htmlspecialchars($g['text']) ?></span>
        </div>
        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
          <div class="h-full rounded-full <?= $bar ?>" style="width:<?= $pct ?>%"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card overflow-hidden">
    <h2 class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-[15px] font-semibold text-slate-800 dark:border-slate-800 dark:text-slate-100">
      <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="server" class="h-4 w-4"></i>
      </span>
      Info Server
    </h2>
    <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-500">Hostname</dt>
        <dd class="font-mono text-[13px] text-slate-700 dark:text-slate-200"><?= htmlspecialchars($s['server']['hostname']) ?></dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="shrink-0 text-slate-500">Sistem</dt>
        <dd class="truncate font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($s['server']['os']) ?></dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-500">Web server</dt>
        <dd class="font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($s['server']['nginx_label']) ?></dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-500">Uptime</dt>
        <dd class="font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($s['uptime']) ?></dd>
      </div>
    </dl>
  </section>

  <p class="px-1 text-center text-[11px] leading-relaxed text-slate-400">
    FPanel <?= htmlspecialchars($s['version']) ?> &middot;
    <a href="/mockup/home.php" class="underline hover:text-brand-600">Mockup UI-A</a>
  </p>
</aside>
