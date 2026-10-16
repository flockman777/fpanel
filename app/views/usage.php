<?php
/**
 * Resource Usage — usage per domain.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $rows    usage per domain
 * @var array $summary total usage + quota
 * @var string|null $lastRun time of the last measurement
 * @var string|null $error, string|null $ok
 */
use Fpanel\Service\Usage;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$rows    = (array)($rows ?? []);
$sum     = (array)($summary ?? []);
$lastRun = (string)($lastRun ?? '');
$isAdmin = ($me['role'] ?? '') === 'admin';

$diskPct = Usage::percent((int)$sum['disk'], (int)$sum['max_disk']);
$bwPct   = Usage::percent((int)$sum['bandwidth'], (int)$sum['max_bandwidth']);

$bar = static function (int $pct, string $tone = 'brand'): string {
    $cls = match ($tone) {
        'rose'   => 'bg-rose-500',
        'amber'  => 'bg-amber-500',
        default  => 'bg-brand-500',
    };
    return '<div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">'
        . '<div class="h-full ' . $cls . ' rounded-full" style="width:' . $pct . '%"></div></div>';
};
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="gauge" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">Resource Usage</h1>
        <p class="mt-0.5 text-sm text-slate-500">
          Disk, bandwidth, and request count per domain.
          <?php if ($lastRun !== ''): ?>
            Last measured <?= $e($lastRun) ?> UTC.
          <?php else: ?>
            Never measured.
          <?php endif; ?>
        </p>
      </div>
      <?php if ($isAdmin): ?>
        <form method="post" action="/usage/collect">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn-secondary h-9 px-3 text-sm"><i data-lucide="refresh-cw" class="h-4 w-4"></i><span>Recalculate</span></button>
        </form>
      <?php endif; ?>
    </div>

    <?php foreach ([['error', 'rose', 'alert-circle'], ['ok', 'emerald', 'check-circle-2']] as [$k, $tone, $icon]): ?>
      <?php if ((${$k} ?? '') !== ''): ?>
        <div class="flex items-start gap-2 rounded-xl border border-<?= $tone ?>-200 bg-<?= $tone ?>-50 px-4 py-3 text-sm text-<?= $tone ?>-800 dark:border-<?= $tone ?>-500/30 dark:bg-<?= $tone ?>-500/10 dark:text-<?= $tone ?>-200">
          <i data-lucide="<?= $icon ?>" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= $e((string)${$k}) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <!-- ===== totals + quota ===== -->
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <?php
      $cards = [
          ['Disk', Usage::bytes((int)$sum['disk']), 'documents across all domains', $diskPct,
           (int)$sum['max_disk'] > 0 ? Usage::bytes((int)$sum['max_disk']) . ' quota' : 'no quota'],
          ['Bandwidth', Usage::bytes((int)$sum['bandwidth']), 'from the access log', $bwPct,
           (int)$sum['max_bandwidth'] > 0 ? Usage::bytes((int)$sum['max_bandwidth']) . ' quota' : 'no quota'],
          ['Requests', number_format((int)$sum['requests'], 0, ',', '.'), 'total requests', null, 'all statuses'],
          ['Error', number_format((int)$sum['errors_4xx'] + (int)$sum['errors_5xx'], 0, ',', '.'),
           number_format((int)$sum['errors_4xx'], 0, ',', '.') . '× 4xx &middot; '
           . number_format((int)$sum['errors_5xx'], 0, ',', '.') . '× 5xx', null, 'from the access log'],
      ];
      foreach ($cards as [$label, $value, $sub, $pct, $foot]): ?>
        <section class="card p-4">
          <p class="text-xs font-medium text-slate-500 dark:text-slate-400"><?= $e($label) ?></p>
          <p class="mt-1 text-xl font-bold text-slate-900 dark:text-slate-50"><?= $value ?></p>
          <p class="mt-0.5 text-[11px] text-slate-500"><?= $sub ?></p>
          <?php if ($pct !== null): ?>
            <div class="mt-2"><?= $bar($pct, $pct >= 90 ? 'rose' : ($pct >= 70 ? 'amber' : 'brand')) ?></div>
            <p class="mt-1 text-[11px] text-slate-500"><?= $e($foot) ?> &middot; <?= $e((string)$pct) ?>%</p>
          <?php else: ?>
            <p class="mt-2 text-[11px] text-slate-400"><?= $e($foot) ?></p>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>

    <!-- ===== per domain ===== -->
    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
              <th class="px-4 py-2.5 font-semibold">Domain</th>
              <th class="px-4 py-2.5 font-semibold">Disk</th>
              <th class="px-4 py-2.5 font-semibold">Bandwidth</th>
              <th class="px-4 py-2.5 font-semibold">Requests</th>
              <th class="px-4 py-2.5 font-semibold">Error</th>
              <th class="px-4 py-2.5 font-semibold">Last log</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($rows as $r):
              $name = (string)$r['name'];
              $e5   = (int)$r['errors_5xx'];
              $e4   = (int)$r['errors_4xx'];
            ?>
            <tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <td class="px-4 py-3">
                <a href="https://<?= $e($name) ?>/" target="_blank" rel="noopener"
                   class="font-medium text-slate-900 hover:underline dark:text-slate-100"><?= $e($name) ?></a>
                <div class="text-[11px] text-slate-500">
                  <?= $e((string)$r['type']) ?> &middot; <?= $e((string)$r['status']) ?>
                </div>
                <?php $top = (array)$r['top_paths']; ?>
                <?php if ($top !== []): ?>
                  <details class="mt-1">
                    <summary class="cursor-pointer text-[11px] text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">top paths</summary>
                    <ul class="mt-1 space-y-0.5">
                      <?php foreach ($top as $t): ?>
                        <li class="truncate font-mono text-[11px] text-slate-500">
                          <?= $e(Usage::bytes((int)$t['bytes'])) ?> &middot; <?= $e((string)$t['path']) ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </details>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 font-mono text-xs"><?= $e(Usage::bytes((int)$r['disk_bytes'])) ?></td>
              <td class="px-4 py-3 font-mono text-xs"><?= $e(Usage::bytes((int)$r['bandwidth_bytes'])) ?></td>
              <td class="px-4 py-3 font-mono text-xs"><?= $e(number_format((int)$r['requests'], 0, ',', '.')) ?></td>
              <td class="px-4 py-3">
                <?php if ($e5 > 0): ?>
                  <span class="rounded bg-rose-50 px-1.5 py-0.5 text-[11px] font-medium text-rose-700 dark:bg-rose-500/15 dark:text-rose-300">
                    <?= $e(number_format($e4 + $e5, 0, ',', '.')) ?>
                  </span>
                <?php elseif ($e4 > 0): ?>
                  <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">
                    <?= $e(number_format($e4, 0, ',', '.')) ?>
                  </span>
                <?php else: ?>
                  <span class="text-slate-400">0</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-[11px] text-slate-500">
                <?= trim((string)($r['log_updated_at'] ?? '')) !== '' ? $e((string)$r['log_updated_at']) : '-' ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
              <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-slate-500">No domains yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <p class="text-xs text-slate-500">
      Numbers come from the nginx access log (including rotated files) and <code class="font-mono">du</code> on the docroot.
      Normal collection runs automatically every hour.
    </p>
  </div>
</main>
</body>
</html>