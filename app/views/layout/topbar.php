<?php
/** Topbar: logo | search (tengah) | server + tema + akun (kanan). Menu utama ada di sidebar kiri. */
/** @var array $me, string $csrf, string $hostname */
$initials = strtoupper(mb_substr($me['username'], 0, 1) . mb_substr(explode(' ', $me['username'])[1] ?? '', 0, 1));
$hostname  = $hostname ?? ($s['server']['hostname'] ?? 'fpanel');
?>
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
  <div class="flex items-center gap-3 px-4 py-2.5 sm:px-6 lg:pl-[264px]">
    <button data-nav-open
            class="-ml-1 grid h-9 w-9 shrink-0 place-items-center rounded-lg text-slate-600 hover:bg-slate-100 lg:hidden dark:text-slate-300 dark:hover:bg-slate-800"
            aria-label="Buka menu" aria-controls="fp-nav" aria-expanded="false">
      <i data-lucide="menu" class="h-5 w-5"></i>
    </button>

    <form data-search class="relative mx-auto w-full max-w-xl" role="search" onsubmit="return false">
      <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
      <input data-search-input type="search" autocomplete="off" aria-label="Cari fitur, domain, database"
             placeholder="Cari fitur, domain, database…"
             class="input pl-9 pr-9">
      <kbd class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-slate-300 px-1.5 text-[10px] font-medium text-slate-400 dark:border-slate-700 sm:block">/</kbd>
      <div data-search-menu hidden
           class="absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-pop dark:border-slate-800 dark:bg-slate-900">
        <ul data-search-results class="max-h-80 overflow-y-auto py-1"></ul>
        <p data-search-empty hidden class="px-3 py-6 text-center text-sm text-slate-500">Tidak ada hasil.</p>
      </div>
    </form>

    <div class="flex shrink-0 items-center gap-1.5">
      <a href="/files" title="Server <?= htmlspecialchars($hostname) ?>"
         class="hidden items-center gap-1.5 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 sm:flex">
        <i data-lucide="server" class="h-4 w-4"></i><?= htmlspecialchars($hostname) ?>
      </a>

      <button data-theme-toggle class="btn-icon" title="Tema" aria-label="Ganti tema">
        <i data-lucide="sun" class="h-5 w-5 dark:hidden"></i>
        <i data-lucide="moon" class="hidden h-5 w-5 dark:block"></i>
      </button>

      <div>
        <button data-dd class="flex items-center gap-2 rounded-lg px-1.5 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800"
                aria-expanded="false" aria-haspopup="true">
          <span class="grid h-7 w-7 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300"><?= htmlspecialchars($initials) ?></span>
          <span class="hidden text-sm font-medium text-slate-700 dark:text-slate-200 md:block"><?= htmlspecialchars($me['username']) ?></span>
          <i data-lucide="chevron-down" class="hidden h-4 w-4 text-slate-400 md:block"></i>
        </button>
        <div data-dd-menu hidden class="absolute right-4 z-40 mt-2 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-pop dark:border-slate-800 dark:bg-slate-900 sm:right-6">
          <div class="border-b border-slate-100 px-3 py-2.5 dark:border-slate-800">
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100"><?= htmlspecialchars($me['username']) ?></p>
            <p class="text-xs text-slate-500"><?= htmlspecialchars($me['email']) ?></p>
            <p class="mt-1 inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
              <?= htmlspecialchars($me['role']) ?>
            </p>
          </div>
          <a href="/account" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"><i data-lucide="user" class="h-4 w-4"></i>Profil</a>
          <a href="/account" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"><i data-lucide="key-round" class="h-4 w-4"></i>Ganti Password</a>
          <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
          <form method="post" action="/logout" class="contents">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800">
              <i data-lucide="log-out" class="h-4 w-4"></i>Keluar
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</header>
