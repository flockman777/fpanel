<?php
/**
 * Sidebar navigasi utama (gaya cPanel).
 * @var array $me
 * @var string $active route yang sedang aktif
 */
$groups = [
    ['label' => 'Hosting', 'items' => [
        ['/dashboard', 'Beranda',   'layout-dashboard'],
        ['/domains',   'Domains',   'globe'],
        ['/databases', 'Databases', 'database'],
        ['/cron',            'Cron Jobs', 'timer'],
        ['/multiphp',       'MultiPHP Manager', 'layers'],
        ['/php-extensions', 'Select PHP Version', 'settings-2'],
        ['/wordpress', 'WordPress Manager', 'globe'],
        ['/apps', 'Node.js / Python App', 'box'],
        ['/email',     'Email',     'mail'],
    ]],
    ['label' => 'Pengelolaan', 'items' => [
        ['/files',     'Files',     'folder-open'],
        ['/backups',   'Backups',   'archive'],
        ['/security',  'Security',  'shield'],
        ['/software',  'Software',  'package'],
    ]],
    ['label' => 'Akun', 'items' => [
        ['/account',   'Profil',    'user'],
    ]],
];
?>
<aside id="fp-nav"
       class="fp-nav fixed inset-y-0 left-0 z-40 w-[248px] -translate-x-full overflow-y-auto border-r border-slate-200 bg-white
              transition-transform duration-200 dark:border-slate-800 dark:bg-slate-900
              lg:translate-x-0">

  <div class="flex h-14 items-center gap-2 border-b border-slate-200 px-4 dark:border-slate-800">
    <a href="/dashboard" class="flex items-center" aria-label="FPanel">
      <img src="/assets/img/logo.svg" alt="FPanel" width="120" height="31"
           class="h-7 w-auto dark:[filter:invert(1)_hue-rotate(180deg)]">
    </a>
    <button data-nav-close
            class="ml-auto grid h-8 w-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 lg:hidden dark:hover:bg-slate-800"
            aria-label="Tutup menu">
      <i data-lucide="x" class="h-5 w-5"></i>
    </button>
  </div>

  <nav class="px-2.5 py-3" aria-label="Menu utama">
    <?php foreach ($groups as $g): ?>
      <p class="px-2.5 pb-1 pt-3 text-[10.5px] font-semibold uppercase tracking-wider text-slate-400 first:pt-0">
        <?= htmlspecialchars($g['label']) ?>
      </p>
      <ul class="space-y-0.5">
        <?php foreach ($g['items'] as [$href, $label, $icon]):
          $on = $active === $href; ?>
          <li>
            <a href="<?= htmlspecialchars($href) ?>"
               <?= $on ? 'aria-current="page"' : '' ?>
               class="fp-nav-link group flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition
                      <?= $on
                          ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300'
                          : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' ?>">
              <i data-lucide="<?= htmlspecialchars($icon) ?>" class="h-[18px] w-[18px] shrink-0"></i>
              <?= htmlspecialchars($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  </nav>

  <div class="mx-2.5 mt-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
    <p class="text-[11px] font-semibold text-slate-700 dark:text-slate-200"><?= htmlspecialchars($me['username']) ?></p>
    <p class="mt-0.5 truncate text-[11px] text-slate-500"><?= htmlspecialchars($me['email']) ?></p>
    <form method="post" action="/logout" class="mt-2">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf ?? '') ?>">
      <button type="submit"
              class="flex w-full items-center justify-center gap-1.5 rounded-md bg-white px-2 py-1.5 text-[11px] font-medium
                     text-slate-600 shadow-sm ring-1 ring-slate-200 transition hover:text-rose-600 hover:ring-rose-200
                     dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
        <i data-lucide="log-out" class="h-3.5 w-3.5"></i>Keluar
      </button>
    </form>
  </div>
</aside>
<div data-nav-backdrop hidden
     class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"></div>
