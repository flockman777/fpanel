<?php
$area = 'admin'; $title = 'Admin Dashboard'; $bodyAttrs = 'x-data';
require __DIR__ . '/partials/head.php';
require_once __DIR__ . '/data/dummy.php';
$q = '';
$services = dummy_services();
?>
<div class="flex min-h-full flex-col lg:flex-row">
  <div class="lg:w-64 lg:shrink-0" :class="$store.admin.open ? 'block shadow-xl' : 'hidden lg:block'">
    <?php require __DIR__ . '/partials/sidebar-admin.php'; ?>
  </div>
  <div class="flex min-w-0 flex-1 flex-col">
    <?php require __DIR__ . '/partials/topbar-admin.php'; ?>

    <main class="flex-1 space-y-6 px-4 py-6 sm:px-6">
      <div>
        <h2 class="text-xl font-bold text-slate-900 dark:text-white" data-i18n="admin.welcome">Ringkasan server</h2>
        <p class="text-sm text-slate-500">Dummy data for the UI mockup.</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <?php
        $label = 'admin.load'; $value = '0.42'; $sub = '1 min · 3 cores'; $pct = 14; $tone = 'bar-ok'; $icon = 'gauge';
        include __DIR__ . '/components/stat.php';
        $label = 'admin.ram'; $value = '1.8 / 2.9 GB'; $sub = 'Swap 0 / 1 GB'; $pct = 62; $tone = 'bar-warn'; $icon = 'memory-stick';
        include __DIR__ . '/components/stat.php';
        $label = 'admin.disk'; $value = '22 / 40 GB'; $sub = '/var 55% · /home 12%'; $pct = 55; $tone = 'bar-info'; $icon = 'hard-drive';
        include __DIR__ . '/components/stat.php';
        $label = 'admin.accounts'; $value = '12'; $sub = '10 active · 2 suspended'; $pct = null; $tone = null; $icon = 'users';
        include __DIR__ . '/components/stat.php';
        ?>
      </div>

      <section class="card">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100" data-i18n="admin.servicesStatus">Status Service</h3>
          <div class="ml-auto flex gap-1.5">
            <span class="badge-ok"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>5 up</span>
            <span class="badge-off"><span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>1 down</span>
          </div>
        </div>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
          <?php foreach ($services as $s): ?>
            <li class="flex items-center gap-3 px-4 py-3">
              <span class="grid h-8 w-8 place-items-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <i data-lucide="cog" class="h-4 w-4"></i>
              </span>
              <span class="flex-1 text-sm font-medium text-slate-800 dark:text-slate-200" data-i18n="<?= htmlspecialchars($s['key']) ?>"><?= htmlspecialchars($s['key']) ?></span>
              <?php if ($s['up']): ?>
                <span class="badge-ok"><i data-lucide="check" class="h-3 w-3"></i>Running</span>
              <?php else: ?>
                <span class="badge-off"><i data-lucide="x" class="h-3 w-3"></i>Stopped</span>
              <?php endif; ?>
              <button class="btn-icon" :title="$store.i18n.t('admin.services')"><i data-lucide="ellipsis-vertical" class="h-4 w-4"></i></button>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <div class="grid gap-4 lg:grid-cols-2">
        <section class="card">
          <h3 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-800">CPU Load (24h)</h3>
          <div class="card-body">
            <div class="flex h-32 items-end gap-1">
              <?php $bars = [18,22,15,30,26,35,42,38,55,48,62,58,70,52,66,74,60,48,55,62,44,38,46,32];
              foreach ($bars as $h): ?>
                <span class="flex-1 rounded-t bg-brand-400/70 dark:bg-brand-500/50" style="height: <?= $h ?>%"></span>
              <?php endforeach; ?>
            </div>
            <div class="mt-2 flex justify-between text-[11px] text-slate-400"><span>00:00</span><span>12:00</span><span>24:00</span></div>
          </div>
        </section>
        <section class="card">
          <h3 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-800">System</h3>
          <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
            <?php
            $info = [
              ['Hostname', 'fpanel-759'], ['Operating System', 'Ubuntu 24.04.5 LTS'],
              ['Kernel', '6.8.0-45-generic'], ['Web Server', 'Apache 2.4.62'],
              ['Database', 'MariaDB 10.11'], ['PHP', '8.3.6 (FPM)'],
            ];
            foreach ($info as $i): ?>
              <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                <dt class="text-slate-500"><?= htmlspecialchars($i[0]) ?></dt>
                <dd class="font-mono text-xs text-slate-700 dark:text-slate-300"><?= htmlspecialchars($i[1]) ?></dd>
              </div>
            <?php endforeach; ?>
            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
              <dt class="text-slate-500" data-i18n="admin.uptime">Uptime</dt>
              <dd class="font-mono text-xs text-slate-700 dark:text-slate-300">9 min (mockup)</dd>
            </div>
          </dl>
        </section>
      </div>
    </main>
  </div>
</div>
<?php require __DIR__ . '/partials/foot.php'; ?>
