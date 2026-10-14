<?php
/**
 * Kelola satu instalasi WordPress.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $ov ringkasan dari Wp::overview()
 */
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$ov  = (array)($ov ?? []);
$up  = (array)($ov['updates'] ?? []);
$dom = (string)($ov['domain'] ?? '');
$id  = (int)($ov['id'] ?? 0);
$hasUpdates = ((int)($up['core'] ?? 0)) + ((int)($up['plugins'] ?? 0)) + ((int)($up['themes'] ?? 0)) > 0;
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-4xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <a href="/wordpress" class="btn-icon mt-1" title="Kembali ke WordPress Manager">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>
      </a>
      <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-xl ring-1 ring-slate-900/5 dark:ring-white/10">
        <img src="/assets/img/wordpress-wmark.png" alt="WordPress" width="44" height="44" class="h-11 w-11">
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="truncate text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">
          <?= $e($dom) ?>
        </h1>
        <p class="mt-0.5 text-sm text-slate-500">
          Core <span class="font-mono"><?= $e((string)($ov['core'] ?? '-')) ?></span>
          <?php if ((string)($ov['running_core'] ?? '-') !== '-'): ?>
            &middot;dilaporkan instalasi <span class="font-mono"><?= $e((string)$ov['running_core']) ?></span>
          <?php endif; ?>
          &middot; staging <span class="font-mono"><?= (int)($ov['is_staging'] ?? 0) === 1 ? 'ya' : 'tidak' ?></span>
        </p>
      </div>
      <a href="https://<?= $e($dom) ?>/wp-admin/" target="_blank" rel="noopener"
         class="btn-secondary h-9 px-3 text-sm">
        <i data-lucide="layout-dashboard" class="h-4 w-4"></i><span>Buka wp-admin</span>
      </a>
    </div>

    <!-- ===== status update ===== -->
    <section class="card p-4">
      <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Pembaruan</h2>
      <?php if (!$hasUpdates): ?>
        <p class="mt-1.5 flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300">
          <i data-lucide="check-circle-2" class="h-4 w-4"></i>
          Semua sudah terbaru
        </p>
        <p class="mt-0.5 text-xs text-slate-500">
          Core, plugin, dan tema tidak punya versi baru. Pemeriksaan ini memakai WP-CLI.
        </p>
      <?php else: ?>
        <ul class="mt-3 grid gap-2 sm:grid-cols-3">
          <?php foreach ([['core', 'Core', (int)($up['core'] ?? 0)],
                         ['plugins', 'Plugin', (int)($up['plugins'] ?? 0)],
                         ['themes', 'Tema', (int)($up['themes'] ?? 0)]] as [$key, $label, $n]): ?>
            <li class="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 <?= $n > 0
                ? 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10'
                : 'border-slate-200 dark:border-slate-700' ?>">
              <span class="text-sm font-medium text-slate-800 dark:text-slate-100"><?= $e($label) ?></span>
              <span class="text-xs font-medium <?= $n > 0
                  ? 'text-amber-700 dark:text-amber-300'
                  : 'text-slate-500' ?>"><?= $n > 0 ? $n . ' perlu update' : 'terbaru' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>

        <form method="post" action="/wordpress/update" class="mt-4 flex flex-wrap items-center gap-3">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <?php foreach ([['core', (int)($up['core'] ?? 0) > 0],
                         ['plugins', (int)($up['plugins'] ?? 0) > 0],
                         ['themes', (int)($up['themes'] ?? 0) > 0]] as [$key, $on]): ?>
            <label class="flex items-center gap-1.5 text-xs text-slate-600 dark:text-slate-400">
              <input type="checkbox" name="part_<?= $key ?>" value="1" <?= $on ? 'checked' : '' ?>
                     class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
              <span><?= $e(ucfirst($key)) ?></span>
            </label>
          <?php endforeach; ?>
          <button class="btn-primary h-9 px-3 text-sm"
                  onclick="return confirm('Perbarui sekarang? Situs bisa tidak tersedia sesaat.')">
            <i data-lucide="refresh-cw" class="h-4 w-4"></i><span>Perbarui</span>
          </button>
          <span class="text-xs text-slate-500">Berjalan di luar request, jadi halaman ini disegarkan sendiri.</span>
        </form>
      <?php endif; ?>
    </section>

    <!-- ===== detail ===== -->
    <section class="card overflow-hidden">
      <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Detail</h2>
      </div>
      <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
        <?php foreach ([
          ['Domain', $dom],
          ['Versi core terpasang', (string)($ov['core'] ?? '-')],
          ['Versi core terbaru', (string)($ov['latest'] ?? '-')],
          ['Docroot', (string)($ov['docroot'] ?? '-')],
          ['Dibuat', (string)($ov['created_at'] ?? '-')],
        ] as [$k, $v]): ?>
          <div class="flex flex-wrap items-baseline gap-2 px-4 py-2.5">
            <dt class="w-44 shrink-0 text-xs font-medium text-slate-500"><?= $e($k) ?></dt>
            <dd class="min-w-0 flex-1 break-all font-mono text-xs text-slate-800 dark:text-slate-100"><?= $e($v) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </section>

    <!-- ===== tindakan lain ===== -->
    <section class="card p-4">
      <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Tindakan lain</h2>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <a href="https://<?= $e($dom) ?>/" target="_blank" rel="noopener" class="btn-secondary h-9 px-3 text-sm">
          <i data-lucide="external-link" class="h-4 w-4"></i><span>Buka situs</span>
        </a>
        <form method="post" action="/wordpress/staging" class="inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn-secondary h-9 px-3 text-sm">
            <i data-lucide="<?= (int)($ov['is_staging'] ?? 0) === 1 ? 'rocket' : 'flask-conical' ?>" class="h-4 w-4"></i>
            <span><?= (int)($ov['is_staging'] ?? 0) === 1 ? 'Jadikan produksi' : 'Tandai staging' ?></span>
          </button>
        </form>
        <form method="post" action="/wordpress/forget" class="inline"
              onsubmit="return confirm('Hapus dari daftar? File dan database tidak akan dihapus.')">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn-secondary h-9 px-3 text-sm text-rose-600 hover:text-rose-700 dark:text-rose-400">
            <i data-lucide="trash-2" class="h-4 w-4"></i><span>Hapus dari daftar</span>
          </button>
        </form>
      </div>
    </section>
  </div>
</main>
</body>
</html>
