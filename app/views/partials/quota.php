<?php
/** Panel kuota nyata - dipanggil dari section.php dengan $q */
$q = ($q ?? []) ?: ($s['quota'] ?? []);
$t = static fn (int $a, int $b): string => $b > 0 ? $a . ' / ' . $b : (string)$a;
$items = [
  ['Domain',     (int)$s['gauges'][2]['used'], (int)$q['max_domains']    ?? 0],
  ['Subdomain',  0,                            (int)$q['max_subdomains'] ?? 0],
  ['Database',   (int)$s['gauges'][3]['used'], (int)$q['max_databases'] ?? 0],
  ['DB Users',   0,                            (int)$q['max_db_users']  ?? 0],
  ['App',        0,                            (int)$q['max_apps']      ?? 0],
  ['WordPress',  0,                            (int)$q['max_wp_sites']  ?? 0],
  ['Cron',       0,                            (int)$q['max_cron']      ?? 0],
  ['Node App',   0,                            (int)$q['max_node_apps'] ?? 0],
  ['RAM (MB)',   (int)($q['max_memory_mb'] ?? 0), (int)($q['max_memory_mb'] ?? 0)],
];
?>
<section class="card">
  <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
    <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
      <i data-lucide="gauge" class="h-4 w-4"></i></span>
    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Paket: <?= htmlspecialchars((string)($q['name'] ?? '-')) ?></h2>
    <span class="ml-auto text-xs text-slate-400">kuota &amp; pemakaian nyata</span>
  </div>
  <div class="grid grid-cols-2 gap-px bg-slate-100 dark:bg-slate-800 sm:grid-cols-3 lg:grid-cols-5">
    <?php foreach ($items as [$label, $used, $max]):
      $pct = $max > 0 ? min(100, (int)round($used / $max * 100)) : 0;
      $bar = $pct >= 90 ? 'bg-rose-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-brand-500'); ?>
    <div class="bg-white px-3.5 py-3 dark:bg-slate-900">
      <p class="truncate text-[11px] uppercase tracking-wide text-slate-500"><?= htmlspecialchars($label) ?></p>
      <p class="mt-0.5 text-sm font-semibold tabular-nums text-slate-800 dark:text-slate-100"><?= htmlspecialchars($t($used, $max)) ?></p>
      <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
        <div class="h-full rounded-full <?= $bar ?>" style="width:<?= $pct ?>%"></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>
